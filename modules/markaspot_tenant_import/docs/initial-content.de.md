# Startinhalte und Laufzeitkonfiguration

Die Einrichtung eines eigenen Stacks übernimmt die geprüfte Konfiguration nach einer leeren Installation. Die Hub-Abnahme führt einen Konfigurationsimport und anschließend einen synthetischen Demo-Import aus. Für einen Neuaufbau dienen der Ausgangsbogen und seine Asset-Dateien.

`tenant.boilerplates` ist eine optionale Liste mit bis zu 100 Textbausteinen. Jede Zeile enthält eine stabile `key`, Klartext für `title` und `text`, `type` (`status_notes` oder `remarks`) und einen booleschen Wert `active`. Importierte Nodes gehören zur Jurisdiktion und verwenden `plain_text`. Eigentumsnachweis und Group-Beziehungen müssen übereinstimmen. Fehlende eigene Nodes, fremde oder doppelte Beziehungen und ausgelassene eigene Kennungen führen zum Abbruch. Einen bestehenden Textbaustein ausdrücklich deaktivieren, statt seine Kennung wegzulassen.

Lesende benötigen Mitarbeitendenrechte und die Mitgliedschaft in der Jurisdiktion. Organisationsgrenzen gelten, sofern das Konto nicht diese Jurisdiktion administriert. Berechtigte Verwaltende können inaktive Vorlagen behalten und erneut aktivieren. Der Einfüge-Endpunkt und die Vorlagenauswahl verwenden ausschließlich aktive Vorlagen. Schreibzugriffe prüfen ursprünglichen Besitz und vorgesehene Organisationszuordnung.

`tenant.features.unifiedReporting` enthält `enabled` als booleschen Wert, `aiMode` (`disabled`, `opt_in`, `opt_out`) und `photoPolicy` (`optional`, `required`, `required_by_category`). Diese Werte gelangen in die Laufzeitkonfiguration des gemeinsamen Frontends.

`tenant.features.operationsDashboard` steuert die Fachadmin-Berechtigung `access dashboard kpis`. Die Aktivierung setzt `markaspot_dashboard` voraus; die gespeicherte Berechtigung wird geprüft. Die Anmeldeantwort übermittelt sie an das Frontend. Optionale Rechte zur Vorlagenverwaltung werden einmalig nach der Konfigurationsinstallation gesetzt. Ein abgeschlossenes Setup stellt später entzogene Rechte nicht erneut her.

Statusfarben stehen ausdrücklich in `statuses[].hex`. Der Excel-Konverter übernimmt sechsstellige Hex-Werte aus dem Statusblatt. Ohne Farbangabe bleibt der bisherige Standardwert erhalten. Bestehende kommunale Bezeichnungen benötigen eine Quelle oder Bestätigung und dürfen nicht aus einer Farbe abgeleitet werden.

Deutsche Feldbeschreibungen werden als Drupal-Sprachkonfiguration ausgeliefert. Die englischen Ausgangsdefinitionen bleiben für andere Oberflächensprachen erhalten.
