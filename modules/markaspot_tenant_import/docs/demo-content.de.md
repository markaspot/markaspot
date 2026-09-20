# Ausdrücklich synthetische Beispielmeldungen

`mas:tenant:demo-content` legt begrenzte synthetische Beispiele in einer bereits
konfigurierten Hauptjurisdiktion an. Der Befehl ist vom Konfigurationsimport
getrennt und erzeugt keine Nutzer, Rollen, Feldschemata oder SaaS-Mandanten.
Standard ist eine Vorschau.

```bash
drush mas:tenant:demo-content /lokal/demo.json \
  --assets-dir=/lokal/assets \
  --expected-site-uuid=UNABHAENGIG-GEPRUEFTE-SITE-UUID \
  --jurisdiction-id=1 --confirm-test-data --format=json
```

Zum Anlegen `--apply` ergänzen. Im Prozess müssen
`MARKASPOT_DEPLOY_CONTEXT=nonproduction` und `MARKASPOT_MAIL_MODE=mailpit`
gesetzt sein. Die aktuelle Compose-Konfiguration gibt die Kontextvariable
möglicherweise nicht weiter. Ein Operator muss sie nach Prüfung der
Testinstallation ausdrücklich für diesen Aufruf mitgeben. Produktivkonfiguration
nicht verändern, um diese Prüfung zu umgehen.

Die JSON-Datei enthält `version: 1`, `synthetic: true`, eine stabile `fixture_id`
und 1 bis 20 Einträge unter `requests`. Jede Meldung benötigt:

- Einen eindeutigen stabilen `key`, `title` und die exakten, zur Jurisdiktion
  gehörenden Bezeichnungen für `category` und `status`.
- `fields` mit unterstützten skalaren Werten, Adresse und Koordinaten. Text wird
  als Klartext gespeichert. Melderadressen enden auf `@example.invalid`, Namen
  lauten Demo, Test oder Synthetic. Falls das optionale Telefonfeld besteht,
  ist `+49 000 000000` der Platzhalter. Benachrichtigungen bleiben ausgeschaltet.
- `status_history` mit `status`, Klartext unter `note` und `author_email` eines
  vorhandenen aktiven Jurisdiktionsmitglieds. Der letzte Verlaufseintrag muss
  dem aktuellen Status entsprechen.

Optional sind `organisations` mit exakten Bezeichnungen, `assignee_email`,
`internal_status` als Bezeichnung eines vorhandenen eigenen Statusbegriffs,
`internal_remarks` mit `text` und `author_email` sowie `files`. Numerische
Referenz-IDs aus einer früheren Installation sind nicht vorgesehen.

Dateieinträge enthalten `field`, einen lokalen `basename` und den exakten
`sha256`. Fotos verwenden das moderne `field_request_media` und benötigen
Klartext unter `alt`. Anhänge werden für `field_attachment`,
`field_service_provider_files` und `field_sp_attachment` unterstützt; eine
Klartextbeschreibung unter `description` ist optional. Unterstützt sind
PNG/JPEG für Fotos sowie TXT/PDF/PNG/JPEG für Anhänge, soweit die installierten
Feldeinstellungen die Erweiterung erlauben. Beispielsweise benötigt
`field_sp_attachment` normalerweise PDF statt TXT. Dateinamen müssen pro
Meldung eindeutig sein. Die ausdrücklich synthetischen Dateien müssen lokal
vorliegen und dürfen höchstens 5 MiB groß sein. Es erfolgt kein Download; das
konfigurierte Public/Private-Dateischema bleibt erhalten.

Unterstützte skalare Felder umfassen Beschreibung, Adresse, Koordinaten,
synthetischen Kontakt, Benachrichtigungskennzeichen, interne Notiz, Objekt-ID,
Priorität, Freigabe, Quelle, Zusatzattribute, Feedback, Dienstleisterfeedback
und Gefährdungsstufe. Verfügbarkeit und erlaubte Werte bestimmt das installierte
Schema. Referenzen auf Bezirk, Ortsteil und Team unterstützt diese erste
Version nicht. Alte Bildfelder, alte interne Statuszeichenketten und GDPR-Felder
werden ausdrücklich nicht befüllt.

Die JSON-Ausgabe inventarisiert alle aktiven Meldungsfelder und die zugehörigen
Paragraph-, Medien- und Dateifelder. Sie unterscheidet befüllte, optionale leere,
berechnete/lesende, systemseitige, veraltete und nicht unterstützte Felder.
Angeforderte fehlende Felder und normale Validierungsfehler stoppen den Lauf.
Eine falsche Kartengrenze muss in der Konfiguration berichtigt werden; die
Prüfung wird nicht umgangen. Der Statusverlauf verwendet den kanonischen
Open311-Helfer. Vermerke, Dateien und Medien werden über Entity-APIs verknüpft.
Normale Hooks laufen weiterhin. Beispieldaten belegen deshalb noch nicht, dass
die gewöhnlichen UI- und API-Schreibwege funktionieren.

Der Eigentumsnachweis bindet Site-UUID, Jurisdiktions-UUID und Eingabehash.
Meldungen erhalten deterministische UUIDs. Eine unveränderte Wiederholung prüft
nur gespeicherte Entity- und Datei-Fingerprints und meldet `unchanged`.
Vorhandene fremde, bearbeitete oder entfernte Inhalte werden nicht übernommen
oder überschrieben. Eine neue Fixture-Kennung erzeugt bewusst einen anderen
Datensatz und darf nicht zum Verbergen eines fehlgeschlagenen Laufs dienen.

Ein abgebrochener Lauf hinterlässt `started` und erfordert eine ausdrückliche
Prüfung. Eine Datenbanktransaktion schützt Entity-Schreibvorgänge; Dateien und
Hook-Nebenwirkungen sind nicht durchgehend transaktional. Automatische
Bereinigung und Wiederholung gibt es nicht. Der Reset für leere Datenbanken
verweigert nach dem Anlegen der Beispiele absichtlich den Betrieb. Archivierung
oder Entfernung zugeordneter Beispieldaten ist ein eigener Vorgang.
