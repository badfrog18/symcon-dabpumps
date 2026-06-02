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

    /**
     * Konfigurations-Button: Listet alle Parameter auf, die der aktuelle
     * Account schreiben darf (inkl. Typ, Einheit, Wertebereich).
     */
    public function ListWritableParams()
    {
        $token = $this->GetToken();
        if ($token === false) {
            echo $this->Translate('Login failed. Please check username and password.');
            return;
        }

        $cfg = $this->ResolveConfig($token);
        if ($cfg === false) {
            echo $this->Translate('Could not load device configuration.');
            return;
        }

        $params = $cfg['metadata']['params'] ?? $cfg['params'] ?? [];
        if (count($params) === 0) {
            echo $this->Translate('No parameters found in configuration.');
            return;
        }

        // Nach Rolle filtern: schreibbar, wenn "change" eine Customer-Rolle enthält
        $customerRoles = ['CUSTOMER', 'CUSTOMER-PRO', 'CUSTOMER_PRO', 'CUSTOMER_FREE'];
        $writable = [];
        foreach ($params as $p) {
            $change = $p['change'] ?? [];
            if (!is_array($change)) {
                $change = [];
            }
            $canWrite = count(array_intersect($change, $customerRoles)) > 0;
            if ($canWrite) {
                $writable[] = $p;
            }
        }

        if (count($writable) === 0) {
            echo $this->Translate('No writable parameters available for this account (you may need an installer account).');
            return;
        }

        $msg = $this->Translate('Writable parameters for this account:') . "\n\n";
        foreach ($writable as $p) {
            $key  = $p['name'] ?? '?';
            $type = $p['type'] ?? '?';
            $unit = $p['unit'] ?? '';
            $line = "• {$key}  [{$type}]";

            if ($type === 'measure') {
                $min = $p['min'] ?? $p['warn_low'] ?? '?';
                $max = $p['max'] ?? $p['warn_hi'] ?? '?';
                $w   = $p['weight'] ?? 1;
                $line .= "  Bereich {$min}–{$max} {$unit}  (weight {$w})";
            } elseif ($type === 'enum') {
                $vals = [];
                foreach (($p['values'] ?? []) as $v) {
                    if (is_array($v) && count($v) >= 2) {
                        $vals[] = "{$v[0]}={$v[1]}";
                    }
                }
                $line .= '  Werte: ' . implode(', ', $vals);
            }
            $msg .= $line . "\n";
        }
        $msg .= "\n" . $this->Translate('Use DABEsy_SetParameter(InstanceID, "Key", value) to write.');
        echo $msg;
    }

    /**
     * Schreibt einen Parameter auf die Pumpe.
     * $value ist der reale Wert (z.B. 3.5 für 3,5 bar) - die Codierung
     * (Skalierung, Enum-Auflösung) passiert anhand der Geräte-Metadaten.
     */
    public function SetParameter(string $Key, $Value): bool
    {
        $token = $this->GetToken();
        if ($token === false) {
            $this->SendDebug('SetParameter', 'Login fehlgeschlagen', 0);
            return false;
        }

        $serial = $this->ResolveSerial($token);
        if ($serial === false) {
            $this->SendDebug('SetParameter', 'Kein Gerät gefunden', 0);
            return false;
        }

        // Wert anhand der Metadaten codieren
        $code = $this->EncodeValue($token, $Key, $Value);

        $body = json_encode(['key' => $Key, 'value' => (string) $code]);
        $response = $this->HttpRequest('POST', self::API_BASE . "/dum/{$serial}", $body, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]);

        if ($response === false) {
            $this->SendDebug('SetParameter', "Schreiben von {$Key}={$Value} (code {$code}) fehlgeschlagen", 0);
            return false;
        }

        $this->SendDebug('SetParameter', "{$Key} = {$Value} (code {$code}) gesetzt", 0);
        // Direkt danach aktualisieren, damit die Variable den neuen Wert zeigt
        $this->Update();
        return true;
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Update') {
            $this->Update();
            return;
        }

        // Aktions-Variablen: Ident entspricht dem API-Key
        if (in_array($Ident, $this->GetActionKeys(), true)) {
            $ok = $this->SetParameter($Ident, $Value);
            if (!$ok) {
                $this->SendDebug('RequestAction', "Schreiben von {$Ident} fehlgeschlagen", 0);
            }
            // Bei Erfolg hat SetParameter bereits Update() aufgerufen und die
            // Variable aktualisiert. Bei Misserfolg bleibt der alte Wert stehen.
            return;
        }

        throw new Exception('Invalid Ident');
    }

    /**
     * Keys, die als bedienbare Standardaktion freigeschaltet werden.
     * Ob der Account sie tatsächlich schreiben darf, zeigt
     * "Schreibbare Parameter auflisten".
     */
    private function GetActionKeys(): array
    {
        return [
            'SP_SetpointPressureBar',   // Soll-Druck (Slider 1–5,5 bar)
            'SleepModeEnable',          // Sleep Mode an/aus (Schalter)
            'AY_AntiCycling',           // Anti-Cycling (Aus/Ein/Smart)
            'EK_LowPressEnable',        // Niederdruckschutz (Aus/Automatik/Manuell)
            'PowerShowerCommand',       // Power Shower (Aus/Start/Stopp)
        ];
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

    /**
     * Lädt die Parameter-Metadaten (Konfiguration) des Geräts.
     */
    private function ResolveConfig(string $token)
    {
        // Installation bestimmen
        $installId = $this->ReadPropertyString('InstallationID');
        if ($installId === '') {
            $installations = $this->ApiGet('/api/v1/installation', $token);
            $list = $installations['values'] ?? $installations['rows'] ?? $installations['installations'] ?? [];
            if (count($list) === 0) {
                return false;
            }
            $installId = $list[0]['installation_id'] ?? '';
        }
        if ($installId === '') {
            return false;
        }

        // configuration_id des (ersten/gewählten) Geräts ermitteln
        $devices = $this->ApiGet("/api/v1/installation/{$installId}", $token);
        $dums = $devices['dums'] ?? [];
        if (count($dums) === 0) {
            return false;
        }

        $serial = $this->ReadPropertyString('Serial');
        $configId = '';
        foreach ($dums as $dum) {
            if ($serial === '' || ($dum['serial'] ?? '') === $serial) {
                $configId = $dum['configuration_id'] ?? '';
                break;
            }
        }
        if ($configId === '') {
            return false;
        }

        return $this->ApiGet("/api/v1/configuration/{$configId}", $token);
    }

    /**
     * Codiert einen realen Wert in den von der API erwarteten Code,
     * basierend auf Typ und weight aus den Metadaten.
     */
    private function EncodeValue(string $token, string $key, $value)
    {
        $cfg = $this->ResolveConfig($token);
        $params = $cfg['metadata']['params'] ?? $cfg['params'] ?? [];

        foreach ($params as $p) {
            if (($p['name'] ?? '') !== $key) {
                continue;
            }
            $type = $p['type'] ?? '';
            if ($type === 'measure') {
                $weight = $p['weight'] ?? 1;
                if ($weight && $weight != 1 && $weight != 0) {
                    return (string) intval(round($value / $weight));
                }
                return (string) intval($value);
            }
            if ($type === 'enum') {
                // Boolean (z.B. von einem ~Switch) auf 1/0 abbilden
                if (is_bool($value)) {
                    return $value ? '1' : '0';
                }
                $valueStr = (string) $value;
                foreach (($p['values'] ?? []) as $v) {
                    if (!is_array($v) || count($v) < 2) {
                        continue;
                    }
                    // Direkter Code-Treffer (Wert ist bereits der Code, z.B. "1")
                    if ((string) $v[0] === $valueStr) {
                        return (string) $v[0];
                    }
                    // Label-Treffer (Wert ist der Klartext, z.B. "Enable")
                    if ((string) $v[1] === $valueStr) {
                        return (string) $v[0];
                    }
                }
            }
            break;
        }
        // Fallback: Wert unverändert
        return (string) $value;
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

            // Bedienbare Parameter als Standardaktion freischalten (Review-konform)
            if (in_array($key, $this->GetActionKeys(), true)) {
                $this->EnableAction($key);
            }

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
            'SP_SetpointPressureBar'       => ['Setpoint Pressure',      VARIABLETYPE_FLOAT,   'DABEsy.Setpoint',   10, false],
            'VF_FlowLiter'                 => ['Flow Rate',              VARIABLETYPE_FLOAT,   'DABEsy.Flow',        1, false],
            'PO_OutputPower'               => ['Output Power',           VARIABLETYPE_INTEGER, '~Watt',              1, false],
            'C1_PumpPhaseCurrent'          => ['Pump Current',           VARIABLETYPE_FLOAT,   'DABEsy.Ampere',     10, false],
            'RS_RotatingSpeed'             => ['Rotating Speed',         VARIABLETYPE_INTEGER, 'DABEsy.RPM',         1, false],
            'SV_SupplyVoltage'             => ['Supply Voltage',         VARIABLETYPE_INTEGER, '~Volt',              1, false],
            'TE_HeatsinkTemperatureC'      => ['Heatsink Temperature',   VARIABLETYPE_FLOAT,   '~Temperature',      10, false],

            // Zähler & Statistik
            'FCt_Total_Delivered_Flow_mc'  => ['Total Flow',             VARIABLETYPE_FLOAT,   'DABEsy.FlowTotal', 1000, false],
            'FCp_Partial_Delivered_Flow_mc'=> ['Partial Flow',           VARIABLETYPE_FLOAT,   'DABEsy.FlowTotal', 1000, true],
            'TotalEnergy'                  => ['Total Energy',           VARIABLETYPE_FLOAT,   'DABEsy.kWh',         10, false],
            'PartialEnergy'                => ['Partial Energy',         VARIABLETYPE_FLOAT,   'DABEsy.kWh',         10, true],
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
            'AY_AntiCycling'               => ['Anti-Cycling',           VARIABLETYPE_INTEGER, 'DABEsy.AntiCycling', 1, true],
            'EK_LowPressEnable'            => ['Low Pressure Protection', VARIABLETYPE_INTEGER, 'DABEsy.LowPress',   1, true],
            'PumpDisable'                  => ['Pump Disable',           VARIABLETYPE_INTEGER, 'DABEsy.PumpDisable', 1, true],

            // Power Shower / Sleep Mode
            'PowerShowerCommand'           => ['Power Shower Command',   VARIABLETYPE_INTEGER, 'DABEsy.PowerShower', 1, true],
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
        $this->CreateProfile('DABEsy.Pressure',  VARIABLETYPE_FLOAT,   '', ' bar',   1, 0, 8,    0.1, 'Gauge');
        $this->CreateProfile('DABEsy.Setpoint',  VARIABLETYPE_FLOAT,   '', ' bar',   1, 1, 5.5,  0.1, 'Gauge');
        $this->CreateProfile('DABEsy.Flow',      VARIABLETYPE_FLOAT,   '', ' l/min', 1, 0, 0,    0.1, 'Drops');
        $this->CreateProfile('DABEsy.FlowTotal', VARIABLETYPE_FLOAT,   '', ' m³',    1, 0, 0,    0.1, 'Drops');
        $this->CreateProfile('DABEsy.kWh',       VARIABLETYPE_FLOAT,   '', ' kWh',   1, 0, 0,    0.1, 'EnergyProduction');
        $this->CreateProfile('DABEsy.RPM',       VARIABLETYPE_INTEGER, '', ' RPM',   0, 0, 7000, 1,   'Gear');
        $this->CreateProfile('DABEsy.Ampere',    VARIABLETYPE_FLOAT,   '', ' A',     1, 0, 0,    0.1, 'Electricity');
        $this->CreateProfile('DABEsy.Signal',    VARIABLETYPE_INTEGER, '', ' %',     0, 0, 100,  1,   'Network');
        $this->CreateProfile('DABEsy.Seconds',   VARIABLETYPE_INTEGER, '', ' s',     0, 0, 0,    1,   'Clock');
        $this->CreateProfile('DABEsy.Hours',     VARIABLETYPE_INTEGER, '', ' h',     0, 0, 0,    1,   'Clock');

        // Enum-Profile mit Klartext-Beschriftung
        $this->CreateEnumProfile('DABEsy.PowerShower', 'Shower', [
            [0, $this->Translate('Off'),   -1],
            [1, $this->Translate('Start'), 0x00AA00],
            [2, $this->Translate('Stop'),  0xAA0000],
        ]);
        $this->CreateEnumProfile('DABEsy.AntiCycling', 'Repeat', [
            [0, $this->Translate('Disabled'), -1],
            [1, $this->Translate('Enabled'),  0x00AA00],
            [2, $this->Translate('Smart'),    0x0088FF],
        ]);
        $this->CreateEnumProfile('DABEsy.LowPress', 'Gauge', [
            [0, $this->Translate('Disabled'),  -1],
            [1, $this->Translate('Automatic'), 0x00AA00],
            [2, $this->Translate('Manual'),    0x0088FF],
        ]);
        $this->CreateEnumProfile('DABEsy.PumpDisable', 'Power', [
            [0, $this->Translate('Off'),      -1],
            [1, $this->Translate('Enabled'),  0x00AA00],
            [2, $this->Translate('Disabled'), 0xAA0000],
        ]);
    }

    /**
     * Erstellt ein Integer-Profil mit Wert-Assoziationen (Klartext + Farbe).
     * $assocs: Array aus [Wert, Text, Farbe]
     */
    private function CreateEnumProfile(string $name, string $icon, array $assocs)
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileIcon($name, $icon);
        foreach ($assocs as $a) {
            IPS_SetVariableProfileAssociation($name, $a[0], $a[1], '', $a[2]);
        }
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
