<?php

declare(strict_types=1);

class DABEsyBox extends IPSModule
{
    private const API_BASE      = 'https://dconnect.dabpumps.com';
    private const AUTH_URL      = self::API_BASE . '/auth/token?isDabLive=1';
    private const USER_AGENT    = 'python-requests/2.20.0';
    private const ONLINE_TIMEOUT = 300; // Sekunden ohne Update bis "offline"

    public function Create()
    {
        parent::Create();

        // Zugangsdaten
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');

        // Auswahl Installation / Gerät (leer = erstes verfügbares)
        $this->RegisterPropertyString('InstallationID', '');
        $this->RegisterPropertyString('Serial', '');

        // Abrufintervall
        $this->RegisterPropertyInteger('UpdateInterval', 60);

        // Erweiterte Werte mit anlegen
        $this->RegisterPropertyBoolean('CreateAdvanced', false);
        $this->RegisterPropertyBoolean('EnableLogging', true);

        // Token-Cache (Attribute überleben Neustart)
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeInteger('TokenExpiry', 0);

        // Variablenprofile
        $this->RegisterProfiles();

        // Timer für den zyklischen Abruf
        $this->RegisterTimer('Update', 0, 'DABEsy_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $username = $this->ReadPropertyString('Username');
        $password = $this->ReadPropertyString('Password');

        if ($username === '' || $password === '') {
            $this->SetStatus(104); // inaktiv: Konfiguration unvollständig
            $this->SetTimerInterval('Update', 0);
            return;
        }

        $this->SetStatus(102); // aktiv

        $interval = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('Update', $interval > 0 ? $interval * 1000 : 0);
    }

    /**
     * Zyklischer Datenabruf (vom Timer oder manuell aufgerufen)
     */
    public function Update()
    {
        $token = $this->GetToken();
        if ($token === false) {
            $this->SetStatus(201); // Login fehlgeschlagen
            return;
        }

        $serial = $this->ResolveSerial($token);
        if ($serial === false) {
            $this->SetStatus(202); // Kein Gerät gefunden
            return;
        }

        $status = $this->ApiGet("/dumstate/{$serial}", $token);
        if ($status === false) {
            $this->SetStatus(203); // Status-Abruf fehlgeschlagen
            return;
        }

        $this->SetStatus(102);
        $this->ProcessStatus($status);
    }

    /**
     * Konfigurations-Button: Verbindung testen
     */
    public function TestConnection()
    {
        $token = $this->GetToken(true);
        if ($token === false) {
            echo $this->Translate('Login failed. Please check username and password.');
            return;
        }

        $installations = $this->ApiGet('/api/v1/installation', $token);
        $list = $installations['values'] ?? $installations['rows'] ?? $installations['installations'] ?? [];

        if (count($list) === 0) {
            echo $this->Translate('Login successful, but no installations found.');
            return;
        }

        $msg = $this->Translate('Login successful!') . "\n\n";
        $msg .= $this->Translate('Found installations:') . "\n";
        foreach ($list as $inst) {
            $iid  = $inst['installation_id'] ?? '?';
            $name = $inst['name'] ?? $inst['description'] ?? $this->Translate('Unnamed');
            $msg .= "  • {$name} ({$iid})\n";
            $devices = $this->ApiGet("/api/v1/installation/{$iid}", $token);
            foreach (($devices['dums'] ?? []) as $dev) {
                $dn = $dev['name'] ?? $dev['ProductName'] ?? 'Device';
                $sn = $dev['serial'] ?? '?';
                $msg .= "      → {$dn} (SN: {$sn})\n";
            }
        }
        echo $msg;
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Update':
                $this->Update();
                break;
            default:
                throw new Exception('Invalid Ident');
        }
    }

    // ========================================================
    // TOKEN-VERWALTUNG (mit Caching)
    // ========================================================

    private function GetToken(bool $forceNew = false)
    {
        if (!$forceNew) {
            $cached = $this->ReadAttributeString('AccessToken');
            $expiry = $this->ReadAttributeInteger('TokenExpiry');
            if ($cached !== '' && $expiry > time() + 30) {
                return $cached;
            }
        }

        $username = $this->ReadPropertyString('Username');
        $password = $this->ReadPropertyString('Password');
        if ($username === '' || $password === '') {
            return false;
        }

        $body = http_build_query(['username' => $username, 'password' => $password]);
        $response = $this->HttpRequest('POST', self::AUTH_URL, $body, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        $token = $data['access_token'] ?? false;
        if ($token === false) {
            return false;
        }

        // Gültigkeit: aus JWT lesen, sonst 5 Minuten annehmen
        $expiry = $this->ParseJwtExpiry($token) ?: (time() + 300);
        $this->WriteAttributeString('AccessToken', $token);
        $this->WriteAttributeInteger('TokenExpiry', $expiry);

        return $token;
    }

    private function ParseJwtExpiry(string $jwt): int
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return 0;
        }
        $payload = json_decode($this->Base64UrlDecode($parts[1]), true);
        return intval($payload['exp'] ?? 0);
    }

    private function Base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }

    // ========================================================
    // GERÄTE-AUFLÖSUNG
    // ========================================================

    private function ResolveSerial(string $token)
    {
        // Fest konfiguriert?
        $serial = $this->ReadPropertyString('Serial');
        if ($serial !== '') {
            return $serial;
        }

        // Erste Installation, erstes Gerät automatisch wählen
        $installId = $this->ReadPropertyString('InstallationID');
        if ($installId === '') {
            $installations = $this->ApiGet('/api/v1/installation', $token);
            $list = $installations['values'] ?? $installations['rows'] ?? $installations['installations'] ?? [];
            if (count($list) === 0) {
                return false;
            }
            $installId = $list[0]['installation_id'] ?? '';
            if ($installId === '') {
                return false;
            }
        }

        $devices = $this->ApiGet("/api/v1/installation/{$installId}", $token);
        $dums = $devices['dums'] ?? [];
        if (count($dums) === 0) {
            return false;
        }
        return $dums[0]['serial'] ?? false;
    }

    // ========================================================
    // STATUS-VERARBEITUNG
    // ========================================================

    private function ProcessStatus(array $status)
    {
        // Online-Status anhand des Zeitstempels
        $online = false;
        if (isset($status['statusts'])) {
            $online = (time() - strtotime($status['statusts'])) < self::ONLINE_TIMEOUT;
        }
        $this->MaintainVariable('Online', $this->Translate('Online'), VARIABLETYPE_BOOLEAN, '~Switch', 1, true);
        $this->EnableArchiveLogging('Online');
        $this->SetValueSafe('Online', $online);

        $this->MaintainVariable('LastUpdate', $this->Translate('Last Update'), VARIABLETYPE_STRING, '', 2, true);
        $this->SetValueSafe('LastUpdate', $status['statusts'] ?? '');

        // Statuswerte parsen
        $values = $status['status'] ?? '{}';
        if (is_string($values)) {
            $values = json_decode($values, true);
        }
        if (!is_array($values)) {
            return;
        }

        $advanced = $this->ReadPropertyBoolean('CreateAdvanced');
        $pos = 10;
        foreach ($this->GetVariableMap() as $key => $def) {
            [$name, $type, $profile, $divisor, $isAdvanced] = $def;

            if ($isAdvanced && !$advanced) {
                continue;
            }
            if (!isset($values[$key])) {
                continue;
            }
            $raw = $values[$key];
            if ($raw === 'd' || $raw === 'h') {
                continue;
            }

            $this->MaintainVariable($key, $this->Translate($name), $type, $profile, $pos++, true);
            $this->EnableArchiveLogging($key);
            $this->SetValueSafe($key, $this->ConvertValue($raw, $type, $divisor));
        }
    }

    private function SetValueSafe(string $ident, $value)
    {
        if ($value === null) {
            return;
        }
        $vid = @$this->GetIDForIdent($ident);
        if ($vid !== false) {
            $this->SetValue($ident, $value);
        }
    }

    private function ConvertValue($raw, int $type, int $divisor)
    {
        switch ($type) {
            case VARIABLETYPE_BOOLEAN: return (bool) intval($raw);
            case VARIABLETYPE_INTEGER: return intval(intval($raw) / max(1, $divisor));
            case VARIABLETYPE_FLOAT:   return round(floatval($raw) / max(1, $divisor), 2);
            case VARIABLETYPE_STRING:  return strval($raw);
            default:                   return null;
        }
    }

    /**
     * Aktiviert das Archiv-Logging für eine bereits angelegte Variable
     */
    private function EnableArchiveLogging(string $ident)
    {
        if (!$this->ReadPropertyBoolean('EnableLogging')) {
            return;
        }
        $vid = @$this->GetIDForIdent($ident);
        if ($vid === false || !function_exists('AC_GetLoggingStatus')) {
            return;
        }
        $archiveIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($archiveIDs) === 0) {
            return;
        }
        $aid = $archiveIDs[0];
        if (!AC_GetLoggingStatus($aid, $vid)) {
            AC_SetLoggingStatus($aid, $vid, true);
            IPS_ApplyChanges($aid);
        }
    }

    // ========================================================
    // HTTP / API
    // ========================================================

    private function ApiGet(string $path, string $token)
    {
        $response = $this->HttpRequest('GET', self::API_BASE . $path, null, [
            'Authorization: Bearer ' . $token,
        ]);
        if ($response === false) {
            return false;
        }
        return json_decode($response, true);
    }

    private function HttpRequest(string $method, string $url, ?string $body, array $headers)
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => array_merge([
                'User-Agent: ' . self::USER_AGENT,
                'Cache-Control: no-store, no-cache, max-age=0',
                'Connection: close',
            ], $headers),
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }
        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || $code >= 400) {
            // Bei 401 Token verwerfen, damit beim nächsten Lauf neu eingeloggt wird
            if ($code === 401) {
                $this->WriteAttributeString('AccessToken', '');
                $this->WriteAttributeInteger('TokenExpiry', 0);
            }
            $this->SendDebug('HTTP Error', "HTTP {$code} bei {$url}: {$error}", 0);
            return false;
        }
        return $response;
    }

    // ========================================================
    // VARIABLEN-MAPPING
    // Key => [Name, Typ, Profil, Divisor, Erweitert]
    // ========================================================

    private function GetVariableMap(): array
    {
        return [
            // Betriebswerte
            'VP_PressureBar'               => ['Current Pressure',       VARIABLETYPE_FLOAT,   'DABEsy.Pressure',   10, false],
            'SP_SetpointPressureBar'       => ['Setpoint Pressure',      VARIABLETYPE_FLOAT,   'DABEsy.Pressure',   10, false],
            'VF_FlowLiter'                 => ['Flow Rate',              VARIABLETYPE_FLOAT,   'DABEsy.Flow',        1, false],
            'PO_OutputPower'               => ['Output Power',           VARIABLETYPE_INTEGER, '~Watt',              1, false],
            'C1_PumpPhaseCurrent'          => ['Pump Current',           VARIABLETYPE_FLOAT,   'DABEsy.Ampere',     10, false],
            'RS_RotatingSpeed'             => ['Rotating Speed',         VARIABLETYPE_INTEGER, 'DABEsy.RPM',         1, false],
            'SV_SupplyVoltage'             => ['Supply Voltage',         VARIABLETYPE_INTEGER, '~Volt',              1, false],
            'TE_HeatsinkTemperatureC'      => ['Heatsink Temperature',   VARIABLETYPE_FLOAT,   '~Temperature',      10, false],

            // Zähler & Statistik
            'FCt_Total_Delivered_Flow_mc'  => ['Total Flow',             VARIABLETYPE_FLOAT,   'DABEsy.FlowTotal',   1000, false],
            'FCp_Partial_Delivered_Flow_mc'=> ['Partial Flow',           VARIABLETYPE_FLOAT,   'DABEsy.FlowTotal',   1000, true],
            'TotalEnergy'                  => ['Total Energy',           VARIABLETYPE_FLOAT,   'DABEsy.kWh',         1, false],
            'PartialEnergy'                => ['Partial Energy',         VARIABLETYPE_FLOAT,   'DABEsy.kWh',         1, true],
            'StartNumber'                  => ['Start Count',            VARIABLETYPE_INTEGER, '',                   1, true],
            'SO_PowerOnSeconds'            => ['Power-On Time',          VARIABLETYPE_INTEGER, 'DABEsy.Hours',    3600, false],
            'SO_PumpRunSeconds'            => ['Pump Run Time',          VARIABLETYPE_INTEGER, 'DABEsy.Hours',    3600, false],
            'Saving'                       => ['Energy Saving',          VARIABLETYPE_INTEGER, '~Intensity.100',     1, true],

            // Status & Fehler
            'SystemStatus'                 => ['System Status',          VARIABLETYPE_INTEGER, '',                   1, false],
            'RunningPumpsNumber'           => ['Running Pumps',          VARIABLETYPE_INTEGER, '',                   1, true],
            'FaultPumpsNumber'             => ['Faulted Pumps',          VARIABLETYPE_INTEGER, '',                   1, false],
            'InverterOnlineNumber'         => ['Inverters Online',       VARIABLETYPE_INTEGER, '',                   1, true],
            'Error1'                       => ['Error 1',                VARIABLETYPE_INTEGER, '',                   1, true],
            'Error2'                       => ['Error 2',                VARIABLETYPE_INTEGER, '',                   1, true],
            'Error3'                       => ['Error 3',                VARIABLETYPE_INTEGER, '',                   1, true],

            // Konfiguration
            'RP_PressureFallToRestartBar'  => ['Restart Pressure Drop',  VARIABLETYPE_FLOAT,   'DABEsy.Pressure',   10, true],
            'RM_MaximumSpeed'              => ['Maximum Speed',          VARIABLETYPE_INTEGER, 'DABEsy.RPM',         1, true],
            'TB_DryRunDetectTime'          => ['Dry Run Detect Time',    VARIABLETYPE_INTEGER, 'DABEsy.Seconds',     1, true],
            'AE_AntiLock'                  => ['Anti-Lock',              VARIABLETYPE_BOOLEAN, '~Switch',            1, true],
            'AF_AntiFreeze'                => ['Anti-Freeze',            VARIABLETYPE_BOOLEAN, '~Switch',            1, true],

            // Power Shower / Sleep Mode
            'PowerShowerCommand'           => ['Power Shower Active',    VARIABLETYPE_BOOLEAN, '~Switch',            1, true],
            'PowerShowerPressureBar'       => ['Power Shower Pressure',  VARIABLETYPE_FLOAT,   'DABEsy.Pressure',   10, true],
            'SleepModeEnable'              => ['Sleep Mode Active',      VARIABLETYPE_BOOLEAN, '~Switch',            1, true],
            'SleepModePressureBar'         => ['Sleep Mode Pressure',    VARIABLETYPE_FLOAT,   'DABEsy.Pressure',   10, true],

            // Netzwerk / System
            'SignLevel'                    => ['WiFi Signal',            VARIABLETYPE_INTEGER, 'DABEsy.Signal',      1, true],
            'CpuLoad'                      => ['CPU Load',               VARIABLETYPE_INTEGER, '~Intensity.100',     1, true],
        ];
    }

    // ========================================================
    // VARIABLENPROFILE
    // ========================================================

    private function RegisterProfiles()
    {
        $this->CreateProfile('DABEsy.Pressure',  VARIABLETYPE_FLOAT,   '', ' bar',   1, 0, 0,    0.1, 'Gauge');
        $this->CreateProfile('DABEsy.Flow',      VARIABLETYPE_FLOAT,   '', ' l/min', 1, 0, 0,    0.1, 'Drops');
        $this->CreateProfile('DABEsy.FlowTotal', VARIABLETYPE_FLOAT,   '', ' m³',    1, 0, 0,    0.1, 'Drops');
        $this->CreateProfile('DABEsy.kWh',       VARIABLETYPE_FLOAT,   '', ' kWh',   1, 0, 0,    0.1, 'EnergyProduction');
        $this->CreateProfile('DABEsy.RPM',       VARIABLETYPE_INTEGER, '', ' RPM',   0, 0, 7000, 1,   'Gear');
        $this->CreateProfile('DABEsy.Ampere',    VARIABLETYPE_FLOAT,   '', ' A',     1, 0, 0,    0.1, 'Electricity');
        $this->CreateProfile('DABEsy.Signal',    VARIABLETYPE_INTEGER, '', ' %',     0, 0, 100,  1,   'Network');
        $this->CreateProfile('DABEsy.Seconds',   VARIABLETYPE_INTEGER, '', ' s',     0, 0, 0,    1,   'Clock');
        $this->CreateProfile('DABEsy.Hours',     VARIABLETYPE_INTEGER, '', ' h',     0, 0, 0,    1,   'Clock');
    }

    private function CreateProfile(string $name, int $type, string $prefix, string $suffix, int $digits, float $min, float $max, float $step, string $icon)
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $type);
        }
        IPS_SetVariableProfileText($name, $prefix, $suffix);
        if ($type === VARIABLETYPE_FLOAT) {
            IPS_SetVariableProfileDigits($name, $digits);
        }
        IPS_SetVariableProfileValues($name, $min, $max, $step);
        IPS_SetVariableProfileIcon($name, $icon);
    }
}
