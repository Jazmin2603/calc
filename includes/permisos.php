<?php

class SistemaPermisos {
    private $conn;
    private $usuario_id;
    private $permisos_cache = null;
    
    public function __construct($conn, $usuario_id) {
        $this->conn = $conn;
        $this->usuario_id = $usuario_id;
    }
    
    public function tienePermiso($modulo, $accion) {
        if ($this->esSuperusuario()) {
            return true;
        }
        
        if ($this->permisos_cache === null) {
            $this->cargarPermisos();
        }
        
        $permiso_nombre = $accion . '_' . $modulo;
        
        if (isset($this->permisos_cache['usuario'][$permiso_nombre])) {
            return $this->permisos_cache['usuario'][$permiso_nombre] === 'conceder';
        }
        
        return isset($this->permisos_cache['rol'][$permiso_nombre]);
    }
    
    
    public function esSuperusuario() {
        $stmt = $this->conn->prepare("
            SELECT r.es_superusuario 
            FROM usuarios u 
            JOIN roles r ON u.rol_id = r.id 
            WHERE u.id = ? AND u.activo = 1
        ");
        $stmt->execute([$this->usuario_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result && $result['es_superusuario'] == 1;
    }
    
    
    private function cargarPermisos() {
        $cache_key = 'permisos_cache_user_' . $this->usuario_id;
        
        if (isset($_SESSION[$cache_key]) && is_array($_SESSION[$cache_key])) {
            $this->permisos_cache = $_SESSION[$cache_key];
            return;
        }
        
        $this->permisos_cache = [
            'rol' => [],
            'usuario' => []
        ];
        
        $stmt = $this->conn->prepare("
            SELECT p.nombre
            FROM usuarios u
            JOIN rol_permisos rp ON u.rol_id = rp.rol_id
            JOIN permisos p ON rp.permiso_id = p.id
            WHERE u.id = ? AND u.activo = 1
        ");
        $stmt->execute([$this->usuario_id]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->permisos_cache['rol'][$row['nombre']] = true;
        }
        
        $stmt = $this->conn->prepare("
            SELECT p.nombre, up.tipo
            FROM usuario_permisos up
            JOIN permisos p ON up.permiso_id = p.id
            WHERE up.usuario_id = ?
        ");
        $stmt->execute([$this->usuario_id]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->permisos_cache['usuario'][$row['nombre']] = $row['tipo'];
        }
        
        $_SESSION[$cache_key] = $this->permisos_cache;
    }
    
   
    public function obtenerPermisos() {
        if ($this->permisos_cache === null) {
            $this->cargarPermisos();
        }
        
        $permisos = [];
        
        foreach ($this->permisos_cache['rol'] as $nombre => $valor) {
            $permisos[$nombre] = true;
        }
        
        foreach ($this->permisos_cache['usuario'] as $nombre => $tipo) {
            if ($tipo === 'conceder') {
                $permisos[$nombre] = true;
            } elseif ($tipo === 'revocar') {
                unset($permisos[$nombre]);
            }
        }
        
        return array_keys($permisos);
    }
    
    public function obtenerModulosAccesibles() {
        if ($this->esSuperusuario()) {
            $stmt = $this->conn->query("SELECT * FROM modulos WHERE activo = 1 ORDER BY orden");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $stmt = $this->conn->prepare("
            SELECT DISTINCT m.*
            FROM modulos m
            JOIN permisos p ON m.id = p.modulo_id
            JOIN rol_permisos rp ON p.id = rp.permiso_id
            JOIN usuarios u ON rp.rol_id = u.rol_id
            WHERE u.id = ? AND m.activo = 1
            
            UNION
            
            SELECT DISTINCT m.*
            FROM modulos m
            JOIN permisos p ON m.id = p.modulo_id
            JOIN usuario_permisos up ON p.id = up.permiso_id
            WHERE up.usuario_id = ? AND up.tipo = 'conceder' AND m.activo = 1
            
            ORDER BY orden
        ");
        $stmt->execute([$this->usuario_id, $this->usuario_id]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    
    public function puedeAccederProyecto($proyecto_id) {
        if ($this->esSuperusuario()) {
            return true;
        }
        
        if (!$this->tienePermiso('presupuestos', 'ver')) {
            return false;
        }
        
        $stmt = $this->conn->prepare("
            SELECT p.id_proyecto
            FROM proyecto p
            JOIN usuarios u ON p.id_usuario = u.id
            WHERE p.id_proyecto = ?
            AND (
                p.id_usuario = ? -- Es su proyecto
                OR (u.sucursal_id = (SELECT sucursal_id FROM usuarios WHERE id = ?) 
                    AND EXISTS (
                        SELECT 1 FROM rol_permisos rp 
                        JOIN permisos perm ON rp.permiso_id = perm.id
                        WHERE rp.rol_id = (SELECT rol_id FROM usuarios WHERE id = ?)
                        AND perm.nombre = 'ver_todos_presupuestos_sucursal'
                    )
                )
            )
        ");
        $stmt->execute([$proyecto_id, $this->usuario_id, $this->usuario_id, $this->usuario_id]);
        
        return $stmt->rowCount() > 0;
    }
    
   
    public static function asignarPermisoRol($conn, $rol_id, $permiso_id) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO rol_permisos (rol_id, permiso_id) 
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE rol_id = rol_id
            ");
            $result = $stmt->execute([$rol_id, $permiso_id]);
            
            // Limpiar cache de permisos para usuarios con este rol
            self::limpiarCacheRol($rol_id);
            
            return $result;
        } catch (PDOException $e) {
            error_log("Error al asignar permiso a rol: " . $e->getMessage());
            return false;
        }
    }
    
    
    public static function revocarPermisoRol($conn, $rol_id, $permiso_id) {
        try {
            $stmt = $conn->prepare("DELETE FROM rol_permisos WHERE rol_id = ? AND permiso_id = ?");
            $result = $stmt->execute([$rol_id, $permiso_id]);
            
            self::limpiarCacheRol($rol_id);
            
            return $result;
        } catch (PDOException $e) {
            error_log("Error al revocar permiso de rol: " . $e->getMessage());
            return false;
        }
    }
    
    
    public static function asignarPermisoUsuario($conn, $usuario_id, $permiso_id, $tipo, $created_by) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO usuario_permisos (usuario_id, permiso_id, tipo, created_by) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE tipo = ?, created_by = ?, created_at = NOW()
            ");
            $result = $stmt->execute([$usuario_id, $permiso_id, $tipo, $created_by, $tipo, $created_by]);
            
            self::limpiarCacheUsuario($usuario_id);
            
            return $result;
        } catch (PDOException $e) {
            error_log("Error al asignar permiso a usuario: " . $e->getMessage());
            return false;
        }
    }
    
   
    public static function eliminarPermisoUsuario($conn, $usuario_id, $permiso_id) {
        try {
            $stmt = $conn->prepare("DELETE FROM usuario_permisos WHERE usuario_id = ? AND permiso_id = ?");
            $result = $stmt->execute([$usuario_id, $permiso_id]);
            
            self::limpiarCacheUsuario($usuario_id);
            
            return $result;
        } catch (PDOException $e) {
            error_log("Error al eliminar permiso de usuario: " . $e->getMessage());
            return false;
        }
    }
   
    private static function limpiarCacheUsuario($usuario_id) {
        $cache_key = 'permisos_cache_user_' . $usuario_id;
        if (isset($_SESSION[$cache_key])) {
            unset($_SESSION[$cache_key]);
        }
    }
    
    private static function limpiarCacheRol($rol_id) {
        
        foreach (array_keys($_SESSION) as $key) {
            if (strpos($key, 'permisos_cache_user_') === 0) {
                unset($_SESSION[$key]);
            }
        }
    }
    
    
    public static function obtenerRoles($conn) {
        $stmt = $conn->query("SELECT * FROM roles WHERE activo = 1 ORDER BY id");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    
    public static function obtenerPermisosRol($conn, $rol_id) {
        $stmt = $conn->prepare("
            SELECT p.*, m.nombre as modulo_nombre, a.nombre as accion_nombre
            FROM permisos p
            JOIN modulos m ON p.modulo_id = m.id
            JOIN acciones a ON p.accion_id = a.id
            JOIN rol_permisos rp ON p.id = rp.permiso_id
            WHERE rp.rol_id = ?
            ORDER BY m.orden, a.slug
        ");
        $stmt->execute([$rol_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    
    public static function obtenerTodosPermisos($conn) {
        $stmt = $conn->query("
            SELECT p.*, m.nombre as modulo_nombre, m.slug as modulo_slug, 
                   a.nombre as accion_nombre, a.slug as accion_slug
            FROM permisos p
            JOIN modulos m ON p.modulo_id = m.id
            JOIN acciones a ON p.accion_id = a.id
            WHERE m.activo = 1
            ORDER BY m.orden, a.slug
        ");
        
        $permisos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Agrupar por módulo
        $resultado = [];
        foreach ($permisos as $permiso) {
            $modulo = $permiso['modulo_nombre'];
            if (!isset($resultado[$modulo])) {
                $resultado[$modulo] = [
                    'slug' => $permiso['modulo_slug'],
                    'permisos' => []
                ];
            }
            $resultado[$modulo]['permisos'][] = $permiso;
        }
        
        return $resultado;
    }
}


if (!function_exists('tienePermiso')) {
    
    function tienePermiso($modulo, $accion) {
        global $conn;
        
        if (!isset($_SESSION['usuario']['id'])) {
            return false;
        }
        
        $sistema = new SistemaPermisos($conn, $_SESSION['usuario']['id']);
        return $sistema->tienePermiso($modulo, $accion);
    }
}

if (!function_exists('esSuperusuario')) {
    
    function esSuperusuario() {
        global $conn;
        
        if (!isset($_SESSION['usuario']['id'])) {
            return false;
        }
        
        // NO guardar en sesión - crear instancia temporal
        $sistema = new SistemaPermisos($conn, $_SESSION['usuario']['id']);
        return $sistema->esSuperusuario();
    }
}

if (!function_exists('obtenerModulosAccesibles')) {
    
    function obtenerModulosAccesibles() {
        global $conn;
        
        if (!isset($_SESSION['usuario']['id'])) {
            return [];
        }
        
        $sistema = new SistemaPermisos($conn, $_SESSION['usuario']['id']);
        return $sistema->obtenerModulosAccesibles();
    }
}

if (!function_exists('verificarPermiso')) {
    
    function verificarPermiso($modulo, $accion) {
        if (!tienePermiso($modulo, $accion)) {
            header("Location: dashboard.php?error=No tienes permisos para acceder a esta sección");
            exit();
        }
    }
}
?>