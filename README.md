# LoxBerry-Plugin: Spotpreis Tibber

Holt die stündlichen Strompreise aus dem eigenen **Tibber-Konto**, dazu die
Verbrauchshistorie samt Kosten und — mit einer **Tibber Pulse** — die
Momentanleistung im Sekundentakt. Alles geht an Loxone: über MQTT als Regelweg
und über einen tokengeschützten HTTP-Endpunkt.

Reines PHP, kein venv, kein PEP-668-Umweg. Läuft mit PHP 7.4 und 8.x,
LoxBerry 3.x und 4.

## Verhältnis zu den Plugins aWATTar und Octopus

Dieses Plugin ist das dritte in derselben Reihe. Wo es dieselben Größen sind,
tragen die Werte für Loxone **dieselben Namen** — `CUR`, `LEVEL`, `RANK`,
`FENSTER_*`, `MIN_HEUTE`, `ALTER`, `OK`. Wer den Anbieter wechselt, tauscht das
Plugin und lässt die Bausteine stehen.

Der wesentliche Unterschied liegt beim Preis selbst:

| | aWATTar, Octopus | **Tibber** |
|---|---|---|
| Was die Schnittstelle liefert | Börsenpreis | **Endpreis** inkl. Steuern und Abgaben |
| Preisbestandteile | müssen einzeln eingestellt und aufgeschlagen werden | rechnet Tibber selbst |
| Eigener Aufschlag | Netzentgelt, Stromsteuer, Umlagen, USt, §14a | **ein Feld**, ab Werk 0 |
| Verbrauch | nicht verfügbar | **aus dem Konto**, samt Kosten |
| Momentanleistung | nicht verfügbar | **Tibber Pulse**, im Sekundentakt |

Deshalb gibt es hier keinen Preisrechner. Was es gibt, ist ein Aufschlag für
den Fall, dass Tibber etwas nicht mitrechnet — er steht ab Werk auf 0.

## Funktionen

- **Preise** für heute und morgen: Endpreis, Energieanteil, Steueranteil je
  Stunde. Aktuelle Stunde, nächste Stunde, **Rang** in den nächsten 24 h,
  günstigste und teuerste Stunde, Tagesdurchschnitt für beide Tage
- **Günstigstes zusammenhängendes X-Stunden-Fenster**, Länge einstellbar —
  gesucht wird ab *jetzt*, nicht ab Mitternacht
- **Zwei Preisniveaus**: `LEVEL` nach den eigenen Schwellen, `TLEVEL` so wie
  Tibber es selbst einstuft. Tibber vergleicht mit den letzten Tagen, Ihre
  Schwelle mit einer festen Zahl — beides ist nützlich
- **Flag für negative Preise.** Negativ werden kann nur der *Energieanteil*,
  nie der Endpreis. Wer darauf reagieren will, wertet `NEG` aus, nicht `CUR`
- **Verbrauchshistorie** aus dem Konto: Tagesverbrauch und Kosten
- **Tarifvergleich** dynamisch gegen fest, aus **Kosten geteilt durch
  Verbrauch** — also nach dem eigenen Lastprofil gewichtet, nicht als Mittelwert
  der Stundenpreise
- **Monatsbericht** am Ersten ab 8 Uhr, mit Erledigt-Marker: fällt der Lauf um
  8 Uhr aus, holt der nächste ihn nach
- **Tibber Pulse**: Momentanleistung, Einspeisung, Tagesverbrauch, Tageskosten,
  Phasenströme und -spannungen über eine dauerhafte WebSocket-Verbindung
- **MQTT** über das LoxBerry-Gateway, **HTTP-Endpunkt** mit fünf Abfragen
- Preisdiagramm für heute und morgen in der Oberfläche
- Fünf Reiter: Einstellungen, MQTT, Einbindung in Loxone (Schritt für Schritt
  mit **kompletter Baustein-Liste**), Test, Logdateien
- Konfiguration und Token überleben Update und Neuinstallation

## Voraussetzungen

- Ein **Tibber-Vertrag**. Ohne laufenden Vertrag liefert die Schnittstelle
  keine Preise, auch wenn das Token gültig ist
- Ein **persönliches Zugangstoken** von `developer.tibber.com`
- `php-curl` empfohlen (sonst Ersatzweg über `file_get_contents`, wird
  angezeigt), `php-openssl` für die Pulse, `php-sockets` für MQTT
- Eine **Tibber Pulse** oder **Watty** nur für die Echtzeitwerte. Preise und
  Verbrauch laufen ohne

## Der Endpunkt für Loxone

```
/plugins/spotpreistibber/index.php?token=<TOKEN>&aktion=<Befehl>
```

| Aktion | Liefert |
|---|---|
| `status` | alle Kennzahlen in einer Zeile — die Adresse für den virtuellen Eingang |
| `stunden` | alle Stundenpreise von heute und morgen, eine Zeile je Stunde |
| `verbrauch` | Tagesverbrauch und Kosten aus der Historie |
| `pulse` | Momentanwerte. `OK=0` heißt: der letzte Wert ist älter als zwei Minuten |
| `json` | alles auf einmal, für eigene Auswertungen |

**Rein lesend.** Es gibt hier nichts zu schalten, und ein Endpunkt im
unangemeldeten Bereich, der mehr könnte als lesen, wäre eine Angriffsfläche
ohne Gegenwert. Verglichen wird mit `hash_equals`, also in gleichbleibender
Zeit; unbekannte Aktionen werden abgewiesen, nicht zurechtgebogen.

Ein **Strich** statt einer Zahl heißt: dieser Wert liegt nicht vor. Es wird
bewusst keine 0 gesendet — bei einem Strompreis ganz besonders, weil 0 ct nach
einem sehr guten Preis aussieht.

## Umgang mit dem Token

Das Tibber-Token liegt in einer **eigenen Datei** mit Rechten 0600, nicht in
der Konfiguration, die die Oberfläche anzeigt. Es wird **nie angezeigt** und
**nie ins Protokoll geschrieben**; beurteilt wird nur seine *Form* — zu kurz,
Leerraum mittendrin, oder das versehentlich mitkopierte Wort `Bearer`. Ein
leeres Eingabefeld ändert nichts; zum Entfernen gibt es einen eigenen Haken.

Beim Deinstallieren werden Token und Sicherung **überschrieben**, bevor sie
gelöscht werden.

## Was geprüft ist

| Prüfung | Verfahren | Ergebnis |
|---|---|---|
| Rechenwege | Preisliste, Kennzahlen, günstigstes Fenster, Rang, Aufschlag, Preisniveau gegen einen konstruierten Tag mit bekanntem Verlauf | im Selbsttest, alle richtig |
| **WebSocket-Handschlag** | der Prüffall aus RFC 6455 Abschnitt 1.3 (`dGhlIHNhbXBsZSBub25jZQ==` → `s3pPLMBiTxaQ9kYGzzhZRbK+xOo=`) | stimmt |
| **WebSocket-Rahmenbau** | byteweise gegen eine unabhängige RFC-6455-Umsetzung, 2000 Proben × 4 Opcodes, alle Längengrenzen (0, 125, 126, 65535, 65536) | byteweise gleich |
| Rundlauf Schreiben/Lesen | über dieselben Längengrenzen | gleich |
| Loxone-Importdatei | wohlgeformt, CRLF, Tabulator, Attributreihenfolge wie `ap_xml_virtual_in_http()` | bestanden |
| Sprachdateien | 350 Werte je Sprache, deckungsgleich, jede Wertzeile genau zwei Anführungszeichen | vollständig |
| Klammer- und Tagbilanz | eigener Zähler statt `php -l` (kein PHP-Aufruf verfügbar) | fehlerfrei |
| Doppelte Maskierung | `tb_e(tb_t(...))` gegen Werte mit HTML-Entitäten | keine Stelle |

## Was diese Fassung nicht belegen kann

Sie ist an **keinem** Tibber-Konto gemessen worden. Offen bleibt:

- ob das Token angenommen wird;
- ob das Konto einen laufenden Vertrag hat — ohne Vertrag keine Preise;
- ob die Feldnamen dieser Fassung der Schnittstelle noch entsprechen;
- ob die Pulse-Verbindung mit einem echten Konto zustande kommt und ob Tibber
  das Unterprotokoll `graphql-transport-ws` bestätigt;
- in welcher Währung das Konto abrechnet — hier wird durchgehend ct/kWh
  angenommen.

Der Knopf **Kontodaten prüfen** im Reiter Test beantwortet die ersten vier
Punkte in einer Zeile. Das ist der erste Schritt nach dem Eintragen des Tokens.

## Herkunft der Schnittstellenangaben

Die öffentliche Tibber-Beschreibung (`developer.tibber.com`), gegengelesen an
zwei unabhängigen Umsetzungen: `github.com/terjesannum/tibber-exporter` und der
Node-RED-Erweiterung `node-red-contrib-tibber-api`. Felder, die dort nicht
stehen, stehen auch hier nicht.

## Lizenz

MIT.
