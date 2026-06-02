# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden hier dokumentiert.

## [1.4] - 2026-06-02

### Hinzugefuegt
- Anti-Cycling und Niederdruckschutz als bedienbare Standardaktionen (in der Visu umschaltbar)

## [1.3] - 2026-06-02

### Hinzugefügt
- Klartext-Profile (Assoziationen) für Power Shower, Anti-Cycling, Niederdruckschutz und Pumpe-sperren
- Variablen fuer Anti-Cycling, Niederdruckschutz und Pumpe-sperren

## [1.2] - 2026-06-02

### Geändert
- Soll-Druck mit eigenem Profil (Bereich 1–5,5 bar) für einen passenden Slider
- PowerShowerCommand als Integer statt Schalter (3-Zustand-Enum: --/Start/Stop)
- Schreib-Codierung robuster: Boolean, direkter Code- und Label-Treffer bei Enums

## [1.1] - 2026-06-02

### Hinzugefügt
- Schreibzugriff auf die Pumpe via DABEsy_SetParameter (automatische Codierung anhand der Geräte-Metadaten)
- Button "Schreibbare Parameter auflisten" zeigt account-abhängig die änderbaren Parameter mit Wertebereich
- Ausgewählte Variablen (z.B. Soll-Druck) als bedienbare Standardaktion freigeschaltet

## [1.0] - 2026-05-31

### Hinzugefügt
- Erstveröffentlichung des Moduls __DABEsyBox__
- Abruf der DAB E.SyBox Betriebsdaten über die DAB Live Cloud-API
- Automatische Variablenanlage mit passenden Profilen
- Token-Caching mit JWT-Ablaufprüfung
- Konfigurationsformular mit "Verbindung testen" und "Jetzt aktualisieren"
- Deutsche und englische Übersetzungen
