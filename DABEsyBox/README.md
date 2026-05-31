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
  `https://github.com/mbruckmoser/symcon-dabpumps`

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
Archiv-Logging | Aktiviert das Logging der angelegten Variablen

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

`boolean DABEsy_Update(integer $InstanzID);`
Ruft sofort die aktuellen Daten ab und aktualisiert die Variablen.

`DABEsy_TestConnection(integer $InstanzID);`
Testet die Anmeldung und listet verfügbare Installationen und Geräte auf.
