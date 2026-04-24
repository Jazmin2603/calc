<?php
define('EWS_HOST', getenv('EWS_HOST') ?: 'https://correo.fils.bo/EWS/Exchange.asmx');
define('EWS_USER', getenv('EWS_USER') ?: 'notificaciones');  
define('EWS_PASS',     getenv('EWS_PASS')     ?: 'N0t1f1c4c10n3$*');
define('EWS_TIMEZONE', 'SA Western Standard Time'); 

// ───────────────────────────────────────────────────────────
// Crea un evento en el calendario de $email_vendedor
// usando la cuenta de servicio con permisos de Impersonation.
//
// $evento = [
//   'subject'      => 'Llamada con cliente',
//   'body'         => 'Próximo paso: ...',
//   'start'        => '2026-04-01T10:00:00',   // datetime-local
//   'end'          => '2026-04-01T10:30:00',   // opcional, default start+30min
//   'location'     => 'Oficina central',        // opcional
// ]
//
// Retorna el ItemId del evento (string) o lanza RuntimeException
// ───────────────────────────────────────────────────────────
function ewsCrearEvento(string $email_vendedor, array $evento): string
{
    // Calcular hora de fin (30 min por defecto)
    $start_ts = strtotime($evento['start']);
    $end_ts   = isset($evento['end']) ? strtotime($evento['end']) : $start_ts + 1800;

    $subject  = _ewsEsc($evento['subject'] ?? 'Actividad CRM');
    $body     = _ewsEsc($evento['body']    ?? '');
    $location = _ewsEsc($evento['location'] ?? '');
    $start    = date('Y-m-d\TH:i:s', $start_ts);
    $end      = date('Y-m-d\TH:i:s', $end_ts);
    $tz       = EWS_TIMEZONE;

    $location_xml = $location
        ? "<t:Location>$location</t:Location>"
        : '';

    // SOAP envelope con ExchangeImpersonation en el header
    $soap = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope
    xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"
    xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages">
  <soap:Header>
    <t:RequestServerVersion Version="Exchange2019"/>
    <t:ExchangeImpersonation>
      <t:ConnectingSID>
        <t:PrimarySmtpAddress>{$email_vendedor}</t:PrimarySmtpAddress>
      </t:ConnectingSID>
    </t:ExchangeImpersonation>
  </soap:Header>
  <soap:Body>
    <m:CreateItem SendMeetingInvitations="SendToNone">
      <m:SavedItemFolderId>
        <t:DistinguishedFolderId Id="calendar"/>
      </m:SavedItemFolderId>
      <m:Items>
        <t:CalendarItem>
          <t:Subject>{$subject}</t:Subject>
          <t:Body BodyType="Text">{$body}</t:Body>
          <t:ReminderIsSet>true</t:ReminderIsSet>
          <t:ReminderMinutesBeforeStart>30</t:ReminderMinutesBeforeStart>
          <t:Start>{$start}</t:Start>
          <t:End>{$end}</t:End>
          {$location_xml}
          <t:StartTimeZone Id="{EWS_TIMEZONE}"/>
          <t:EndTimeZone Id="{EWS_TIMEZONE}"/>
        </t:CalendarItem>
      </m:Items>
    </m:CreateItem>
  </soap:Body>
</soap:Envelope>
XML;

    $xml_resp = _ewsRequest($soap);

    // Parsear respuesta
    $dom = new DOMDocument();
    $dom->loadXML($xml_resp);

    // Verificar ResponseClass
    $items = $dom->getElementsByTagNameNS(
        'http://schemas.microsoft.com/exchange/services/2006/messages',
        'CreateItemResponseMessage'
    );

    if ($items->length === 0) {
        throw new RuntimeException('EWS: respuesta inesperada — ' . substr($xml_resp, 0, 300));
    }

    $resp = $items->item(0);
    $class = $resp->getAttribute('ResponseClass');

    if ($class !== 'Success') {
        $msg_node = $resp->getElementsByTagNameNS(
            'http://schemas.microsoft.com/exchange/services/2006/messages',
            'MessageText'
        );
        $msg = $msg_node->length ? $msg_node->item(0)->textContent : 'Error desconocido';
        throw new RuntimeException("EWS CreateItem falló: $msg");
    }

    // Extraer ItemId
    $item_ids = $resp->getElementsByTagNameNS(
        'http://schemas.microsoft.com/exchange/services/2006/types',
        'ItemId'
    );

    if ($item_ids->length === 0) {
        throw new RuntimeException('EWS: no se pudo obtener el ItemId del evento creado');
    }

    // Devolver Id + ChangeKey concatenados (necesarios para eliminar)
    $id         = $item_ids->item(0)->getAttribute('Id');
    $change_key = $item_ids->item(0)->getAttribute('ChangeKey');

    return $id . '|' . $change_key;
}

// ───────────────────────────────────────────────────────────
// Elimina un evento del calendario de $email_vendedor
// $event_ref = valor guardado en ms_event_id (Id|ChangeKey)
// ───────────────────────────────────────────────────────────
function ewsEliminarEvento(string $email_vendedor, string $event_ref): void
{
    [$item_id, $change_key] = explode('|', $event_ref, 2);

    $item_id_esc    = _ewsEsc($item_id);
    $change_key_esc = _ewsEsc($change_key);

    $soap = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope
    xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"
    xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages">
  <soap:Header>
    <t:RequestServerVersion Version="Exchange2019"/>
    <t:ExchangeImpersonation>
      <t:ConnectingSID>
        <t:PrimarySmtpAddress>{$email_vendedor}</t:PrimarySmtpAddress>
      </t:ConnectingSID>
    </t:ExchangeImpersonation>
  </soap:Header>
  <soap:Body>
    <m:DeleteItem DeleteType="MoveToDeletedItems" SendMeetingCancellations="SendToNone">
      <m:ItemIds>
        <t:ItemId Id="{$item_id_esc}" ChangeKey="{$change_key_esc}"/>
      </m:ItemIds>
    </m:DeleteItem>
  </soap:Body>
</soap:Envelope>
XML;

    _ewsRequest($soap); // Si falla lanza excepción, pero no es crítico
}

// ───────────────────────────────────────────────────────────
// Verifica la conexión al servidor EWS
// Útil para la pantalla de diagnóstico del admin
// ───────────────────────────────────────────────────────────
function ewsTestConexion(): array
{
    $soap = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope
    xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"
    xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages">
  <soap:Header>
    <t:RequestServerVersion Version="Exchange2019"/>
  </soap:Header>
  <soap:Body>
    <m:ResolveNames ReturnFullContactData="false">
      <m:UnresolvedEntry>SMTP:noreply@fils.bo</m:UnresolvedEntry>
    </m:ResolveNames>
  </soap:Body>
</soap:Envelope>
XML;

    try {
        _ewsRequest($soap);
        return ['ok' => true, 'message' => 'Conexión exitosa a ' . EWS_HOST];
    } catch (RuntimeException $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

// ═══════════════════════════════════════════════════════════
// FUNCIONES PRIVADAS
// ═══════════════════════════════════════════════════════════

function _ewsRequest(string $soap_body): string
{
    $ch = curl_init(EWS_HOST);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $soap_body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: ""',
        ],
        CURLOPT_HTTPAUTH       => CURLAUTH_NTLM,
        CURLOPT_USERPWD        => EWS_USER . ':' . EWS_PASS,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_FORBID_REUSE   => true,   // <-- fuerza conexión nueva por request
        CURLOPT_FRESH_CONNECT  => true,   // <-- no reutiliza conexión del pool
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,  // <-- NTLM requiere HTTP/1.1, no HTTP/2
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        throw new RuntimeException("Error de red al conectar con Exchange: $curl_err");
    }

    if ($http_code === 401) {
        throw new RuntimeException('Exchange rechazó las credenciales de la cuenta de servicio (401). Verifica EWS_USER y EWS_PASS.');
    }

    if ($http_code === 403) {
        throw new RuntimeException('Sin permiso de Impersonation (403). El admin debe asignar el rol ApplicationImpersonation.');
    }

    if ($http_code !== 200) {
        throw new RuntimeException("Exchange devolvió HTTP $http_code. Verifica la URL del servidor EWS.");
    }

    // Verificar error SOAP a nivel de Fault
    if (strpos($response, 'soap:Fault') !== false || strpos($response, 'Fault') !== false) {
        $dom = new DOMDocument();
        @$dom->loadXML($response);
        $faults = $dom->getElementsByTagName('faultstring');
        $fault_msg = $faults->length ? $faults->item(0)->textContent : substr($response, 0, 200);
        throw new RuntimeException("SOAP Fault: $fault_msg");
    }

    return $response;
}

function _ewsEsc(string $s): string
{
    return htmlspecialchars($s, ENT_XML1, 'UTF-8');
}