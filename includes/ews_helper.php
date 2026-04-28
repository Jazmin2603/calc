<?php
define('EWS_HOST', 'https://correo.fils.bo/EWS/Exchange.asmx');
define('EWS_USER', getenv('EWS_USER') ?: 'notificaciones');
define('EWS_PASS',     getenv('EWS_PASS')     ?: 'N0t1f1c4c10n3$*');
define('EWS_TIMEZONE', 'SA Western Standard Time'); 

function ewsCrearEvento(string $email_vendedor, array $evento): string
{
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

    $soap = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope
    xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"
    xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages">
  <soap:Header>
    <t:RequestServerVersion Version="Exchange2016"/>
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
          <t:StartTimeZone Id="{$tz}"/>
          <t:EndTimeZone Id="{$tz}"/>
        </t:CalendarItem>
      </m:Items>
    </m:CreateItem>
  </soap:Body>
</soap:Envelope>
XML;

    $xml_resp = _ewsRequest($soap);

    $dom = new DOMDocument();
    $dom->loadXML($xml_resp);

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

    $item_ids = $resp->getElementsByTagNameNS(
        'http://schemas.microsoft.com/exchange/services/2006/types',
        'ItemId'
    );

    if ($item_ids->length === 0) {
        throw new RuntimeException('EWS: no se pudo obtener el ItemId del evento creado');
    }

    $id         = $item_ids->item(0)->getAttribute('Id');
    $change_key = $item_ids->item(0)->getAttribute('ChangeKey');

    return $id . '|' . $change_key;
}

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
    <t:RequestServerVersion Version="Exchange2016"/>
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

    _ewsRequest($soap);
}

function ewsTestConexion(): array
{
    $soap = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope
    xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"
    xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages">
  <soap:Header>
    <t:RequestServerVersion Version="Exchange2016"/>
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


function _ewsRequest(string $soap_body): string
{
    // Stream temporal para capturar el verbose de cURL
    $verbose_log = fopen('php://temp', 'w+');

    $ch = curl_init(EWS_HOST);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $soap_body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "http://schemas.microsoft.com/exchange/services/2006/messages/CreateItem"',
        ],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => 'INTRANET\\' . EWS_USER . ':' . EWS_PASS,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_VERBOSE        => true,
        CURLOPT_STDERR         => $verbose_log,
        CURLOPT_HEADER         => true,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    // Leer el verbose log
    rewind($verbose_log);
    $verbose = stream_get_contents($verbose_log);
    fclose($verbose_log);

    // Guardar a archivo en la misma carpeta del helper
    file_put_contents(
        __DIR__ . '/ews_debug.log',
        "==== " . date('Y-m-d H:i:s') . " ====\n" .
        "HTTP code: $http_code\n" .
        "cURL error: $curl_err\n" .
        "EWS_USER: " . EWS_USER . "\n" .
        "EWS_PASS length: " . strlen(EWS_PASS) . "\n" .
        "EWS_PASS first/last char: " . substr(EWS_PASS, 0, 1) . "..." . substr(EWS_PASS, -1) . "\n" .
        "---- VERBOSE ----\n$verbose\n" .
        "---- RESPONSE (first 2000 chars) ----\n" . substr((string)$response, 0, 2000) . "\n\n",
        FILE_APPEND
    );

    if ($curl_err) {
        throw new RuntimeException("Error de red: $curl_err");
    }

    if ($http_code === 401) {
        throw new RuntimeException('401 — ver includes/ews_debug.log');
    }

    if ($http_code === 403) {
        throw new RuntimeException('Sin permiso de Impersonation (403)');
    }

    if ($http_code !== 200) {
        throw new RuntimeException("HTTP $http_code");
    }

    // Separar headers del body (porque CURLOPT_HEADER => true los incluyó)
    $header_size = 0;
    if (preg_match('/\r\n\r\n/', $response, $m, PREG_OFFSET_CAPTURE)) {
        $header_size = $m[0][1] + 4;
    }
    $body = substr($response, $header_size);

    if (strpos($body, 'soap:Fault') !== false) {
        $dom = new DOMDocument();
        @$dom->loadXML($body);
        $faults = $dom->getElementsByTagName('faultstring');
        $fault_msg = $faults->length ? $faults->item(0)->textContent : substr($body, 0, 200);
        throw new RuntimeException("SOAP Fault: $fault_msg");
    }

    return $body;
}

function _ewsEsc(string $s): string
{
    return htmlspecialchars($s, ENT_XML1, 'UTF-8');
}