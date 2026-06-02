# DAB EsyBox

Liest Betriebsdaten einer DAB E.SyBox (Mini 3 / Mini / 2.0 / etc.) über die DAB Live Cloud-API aus und legt sie als Variablen in IP-Symcon an.

## Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

## 1. Funktionsumfang

* Zyklischer Abruf der Pumpendaten über die kostenlose DAB Live Cloud
* Automatische Anlage von Variablen für Druck, Durchfluss, Leistung, Strom, Drehzahl, Spannung, Temperatur, Energie- und Durchflusszähler sowie Laufzeiten
* Optionale erweiterte Variablen (Konfigurationsparameter, Fehlerspeicher, Power Shower, Sleep Mode)
* Online-Erkennung anhand des letzten Cloud-Zeitstempels
* Token-Caching: Login nur bei Bedarf, nicht bei jedem Abruf

## 2. Voraussetzungen

* IP-Symcon ab Version 7.0
* Eine DAB E.SyBox, die per WLAN mit der DAB Cloud verbunden ist
* Ein DAB Live Account mit **E-Mail + Passwort** (kein Google-Login)

> **Hinweis zum Account:** Die API unterstützt nur Login mit E-Mail und Passwort. Wer sich in der App via Google angemeldet hat, legt am besten einen separaten Account an und teilt die Installation darüber. Das vermeidet außerdem, dass das Modul die App-Session beendet.

## 3. Software-Installation

* Über den Module Store das 'DAB EsyBox'-Modul installieren.
* Alternativ über das Module Control folgende URL hinzufügen:
  `https://github.com/badfrog18/symcon-dabpumps`

## 4. Einrichten der Instanzen in IP-Symcon

Unter "Instanz hinzufügen" kann das 'DAB EsyBox'-Modul mithilfe des Schnellfilters gefunden werden.

__Konfigurationsseite__:

Name           | Beschreibung
-------------- | ------------------------------------------------------------------
E-Mail         | E-Mail-Adresse des DAB Live Accounts
Passwort       | Passwort des DAB Live Accounts
Installations-ID | (optional) Feste Installation, sonst wird die erste verwendet
Geräte-Seriennummer | (optional) Festes Gerät, sonst wird das erste verwendet
Abrufintervall | Intervall in Sekunden (0 = aus)
Erweiterte Variablen | Legt zusätzlich Konfigurations-, Zähler- und Fehlervariablen an

Mit der Schaltfläche __Verbindung testen__ werden die verfügbaren Installationen und Geräte (inkl. IDs) aufgelistet.

## 5. Statusvariablen und Profile

Die Statusvariablen werden automatisch angelegt. Welche tatsächlich erscheinen, hängt vom Gerät und der Option "Erweiterte Variablen" ab.

Name                | Typ     | Profil            | Beschreibung
------------------- | ------- | ----------------- | -------------------------
Online              | Boolean | ~Switch           | Cloud-Erreichbarkeit
Letztes Update      | String  | -                 | Zeitstempel der Cloud
Aktueller Druck     | Float   | DABEsy.Pressure   | Ist-Druck in bar
Soll-Druck          | Float   | DABEsy.Pressure   | Soll-Druck in bar
Durchfluss          | Float   | DABEsy.Flow       | Durchfluss in l/min
Ausgangsleistung    | Integer | ~Watt             | Leistung in W
Pumpenstrom         | Float   | DABEsy.Ampere     | Strom in A
Drehzahl            | Integer | DABEsy.RPM        | Drehzahl in U/min
Versorgungsspannung | Integer | ~Volt             | Spannung in V
Kühlkörper Temp.    | Float   | ~Temperature      | Temperatur in °C
Gesamtdurchfluss    | Float   | DABEsy.FlowTotal  | in m³
Gesamtenergie       | Float   | DABEsy.kWh        | in kWh
Einschaltzeit       | Integer | DABEsy.Hours      | in h
Pumpenlaufzeit      | Integer | DABEsy.Hours      | in h

Folgende Profile werden angelegt: `DABEsy.Pressure`, `DABEsy.Flow`, `DABEsy.FlowTotal`, `DABEsy.kWh`, `DABEsy.RPM`, `DABEsy.Ampere`, `DABEsy.Signal`, `DABEsy.Seconds`, `DABEsy.Hours`.

## 6. PHP-Befehlsreferenz

Alle Befehle brauchen als erstes die `InstanzID` deiner DAB-EsyBox-Instanz. Diese findest du, indem du im Objektbaum auf die Instanz klickst – die ID (z.B. 12345) steht oben bzw. in den Objekteigenschaften. In den Beispielen unten steht stellvertretend `12345`.

### Befehle

`boolean DABEsy_Update(integer $InstanzID);`
Ruft sofort die aktuellen Daten von der Pumpe ab und aktualisiert alle Variablen. Wird normalerweise automatisch vom Timer aufgerufen.
```php
DABEsy_Update(12345);
```

`DABEsy_TestConnection(integer $InstanzID);`
Testet die Anmeldung und listet alle gefundenen Installationen und Geräte mit ihren IDs und Seriennummern auf. Praktisch zum Einrichten.
```php
DABEsy_TestConnection(12345);
```

`DABEsy_ListWritableParams(integer $InstanzID);`
Listet alle Parameter auf, die dein Account auf dieser Pumpe schreiben darf – inklusive Typ, Wertebereich und möglichen Werten. Das Ergebnis hängt von deiner Account-Rolle ab (Customer/Installateur).
```php
DABEsy_ListWritableParams(12345);
```

`boolean DABEsy_SetParameter(integer $InstanzID, string $Key, mixed $Value);`
Schreibt einen Parameter auf die Pumpe. Du übergibst den **realen Wert** (z.B. `3.5` für 3,5 bar) – die Umrechnung in den von der Pumpe erwarteten Code erfolgt automatisch anhand der Geräte-Metadaten. Gibt `true` bei Erfolg zurück.
```php
// Soll-Druck auf 3,5 bar setzen
DABEsy_SetParameter(12345, "SP_SetpointPressureBar", 3.5);
```

### Häufige schreibbare Parameter

Welche Parameter dein Account tatsächlich schreiben darf, zeigt dir `DABEsy_ListWritableParams`. Typische Beispiele (Werte je nach Modell/Firmware):

| Key                         | Bedeutung               | Beispielaufruf                                              |
| --------------------------- | ----------------------- | ---------------------------------------------------------- |
| `SP_SetpointPressureBar`    | Soll-Druck (1–5,5 bar)  | `DABEsy_SetParameter(12345, "SP_SetpointPressureBar", 3.5);` |
| `RP_PressureFallToRestartBar` | Restart-Druckabfall   | `DABEsy_SetParameter(12345, "RP_PressureFallToRestartBar", 0.5);` |
| `SleepModeEnable`           | Sleep Mode an/aus       | `DABEsy_SetParameter(12345, "SleepModeEnable", 1);`        |
| `AY_AntiCycling`            | Anti-Cycling (0/1/2)    | `DABEsy_SetParameter(12345, "AY_AntiCycling", 2);`         |
| `EK_LowPressEnable`         | Niederdruckschutz (0/1/2) | `DABEsy_SetParameter(12345, "EK_LowPressEnable", 1);`     |
| `AF_AntiFreeze`             | Frostschutz an/aus      | `DABEsy_SetParameter(12345, "AF_AntiFreeze", 1);`          |
| `AE_AntiLock`               | Anti-Lock an/aus        | `DABEsy_SetParameter(12345, "AE_AntiLock", 1);`            |
| `PowerShowerCommand`        | Power Shower (0=--, 1=Start, 2=Stop) | `DABEsy_SetParameter(12345, "PowerShowerCommand", 1);` |
| `ErasePartialFlowCounter`   | Teil-Durchflusszähler zurücksetzen | `DABEsy_SetParameter(12345, "ErasePartialFlowCounter", 1);` |
| `ResetActualFault`          | Aktuellen Fehler quittieren | `DABEsy_SetParameter(12345, "ResetActualFault", 1);`   |

> **Vorsicht:** Parameter wie `PumpDisable` (sperrt die Pumpe), `Reboot` und `UpdateFirmware` sind ebenfalls schreibbar. Diese nur bewusst verwenden – ein versehentliches `UpdateFirmware` oder `PumpDisable` willst du nicht in einer Automation haben.

### Bedienen direkt in der Visualisierung

Folgende Werte sind als Standardaktion freigeschaltet und lassen sich ohne PHP direkt in der Visualisierung verstellen (sofern dein Account die Schreibrechte hat): Soll-Druck (Slider), Sleep Mode (Schalter), Anti-Cycling, Niederdruckschutz und Power Shower (jeweils Auswahl). Alle anderen schreibbaren Parameter erreichst du über `DABEsy_SetParameter`.

