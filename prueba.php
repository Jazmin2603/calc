<?php
require_once 'includes/ews_helper.php';

// test_ews.php — BORRAR después del diagnóstico
$url  = 'https://correo.fils.bo/EWS/Exchange.asmx';
$user = 'notificaciones';   // prueba también: fils\notificaciones
$pass = 'N0t1f1c4c10n3$*';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_NOBODY         => true,
    CURLOPT_HTTPAUTH       => CURLAUTH_ANY,   // prueba todo
    CURLOPT_USERPWD        => "$user:$pass",
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_TIMEOUT        => 15,
]);

$resp      = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$auth_used = curl_getinfo($ch, CURLINFO_HTTPAUTH_AVAIL);
$error     = curl_error($ch);
curl_close($ch);

echo "<pre>";
echo "HTTP Code: $http_code\n";
echo "cURL error: " . ($error ?: 'ninguno') . "\n";
echo "Auth methods disponibles (bitmask): $auth_used\n";
echo "  BASIC    disponible: " . (($auth_used & CURLAUTH_BASIC)   ? 'SÍ' : 'no') . "\n";
echo "  NTLM     disponible: " . (($auth_used & CURLAUTH_NTLM)    ? 'SÍ' : 'no') . "\n";
echo "  DIGEST   disponible: " . (($auth_used & CURLAUTH_DIGEST)  ? 'SÍ' : 'no') . "\n";
echo "  NEGOTIATE disponible: " . (($auth_used & CURLAUTH_GSSNEGOTIATE) ? 'SÍ' : 'no') . "\n\n";
echo "Headers respuesta:\n";
echo htmlspecialchars(substr($resp, 0, 800));
echo "</pre>";