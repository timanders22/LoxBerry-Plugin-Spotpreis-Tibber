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
- **Viertelstundenpreise.** Seit dem 01.10.2025 gibt es in Deutschland
  Viertelstundenprodukte. Die Schrittweite wird nicht mehr angenommen,
  sondern **aus der gelieferten Liste gemessen** — Fenstersuche, Rang und
  „Preis zur Zeit“ rechnen mit dem, was ankommt
- **Zweites günstiges Fenster** und ein **Fenster für morgen**, sobald die
  Preise für morgen da sind. Ein Waschgang, der nicht mehr in das erste
  Fenster passt, muss nicht bis übermorgen warten
- **30-Tage-Verlauf**: Durchschnitt und Rang des jetzigen Preises darin.
  Der Rang in den nächsten 24 h sagt „günstig für heute“; erst der Rang im
  Monat sagt „günstig überhaupt“
- **Günstiganteil und Ersparnis von gestern** aus dem eigenen Verbrauch:
  wie viel davon in die günstige Hälfte des Tages fiel, und was das
  gegenüber dem Tagesdurchschnitt ausgemacht hat
- **Sicherung und Zurückspielen** über zwei Knöpfe. Die Datei trägt
  **beide** Geheimnisse — das Tibber-Token und das Merkwort des Endpunkts.
  Ohne sie stünden nach dem Zurückspielen alle Felder richtig, und das
  Plugin käme trotzdem weder an die Preise noch an den Miniserver
- **Lebenszeichen über MQTT**: ein Zeitstempel, auch wenn sich sonst nichts
  geändert hat. Unveränderte Werte werden sonst nicht erneut gesendet
- **Gesundheitsprüfung** für die LoxBerry-Oberfläche (`bin/healthcheck`)
- Konfiguration und Token überleben Update und Neuinstallation

## Voraussetzungen

- Ein **Tibber-Vertrag**. Ohne laufenden Vertrag liefert die Schnittstelle
  keine Preise, auch wenn das Token gültig ist
- Ein **persönliches Zugangstoken** von `developer.tibber.com`
- `php-curl` empfohlen (sonst Ersatzweg über `file_get_contents`, wird
  angezeigt), `php-openssl` für die Pulse
- **`php-sockets` wird nicht gebraucht.** Bis 0.9.6 hat `tb_mqtt_senden()`
  wirklich `socket_create()` benutzt; seit 0.9.7 läuft der Weg zum
  MQTT-Gateway über `stream_socket_client()`, und das steckt im Kern von PHP.
  `postroot.sh` installiert die Erweiterung deshalb nicht mehr mit. Seit
  0.9.10 ist sie auch aus der Selbstprüfung, aus dem Reiter Test, aus dem
  MQTT-Reiter und aus `postinstall.sh` verschwunden — eine Anzeigezeile, die
  nichts bedeutet, ist schlimmer als keine, weil der Anwender dort sucht
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

Dazu eine Abfrage ohne `aktion`:

```
/plugins/spotpreistibber/index.php?selftest=1&token=<TOKEN>
```

Sie beantwortet die eine Frage, die man sonst nur durch Ausprobieren klärt:
**stimmt das Token, das im Miniserver steht, mit dem hier hinterlegten
überein?** Die Antwort nennt zusätzlich die Fassung. Ein falsches Token
bekommt 403 und `ERR=TOKEN`; ist gar keines eingerichtet, sagt sie das
ausdrücklich — das ist etwas anderes als ein falsches.

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
| Sprachdateien | 483 Werte je Sprache (gemessen am 02.09.2026 mit `parse_ini_file`), deckungsgleich, jede Wertzeile genau zwei Anführungszeichen, alle drei Lesarten gleich | vollständig |
| Syntax | `php -l` unter **7.4 und 8.4**, jede PHP-Datei | fehlerfrei |
| Doppelte Maskierung | `tb_e(tb_t(...))` gegen Werte mit HTML-Entitäten | keine Stelle |
| **Eindeutigkeit der Suchtexte** | jeder der 48 Feldnamen gegen die echte Statuszeile — welches Feld liest welchen Wert | eindeutig, geeicht |
| **Sicherung, Rundlauf** | die eigene Datei zurückspielen, 19 Fälle: Kopf, beide Geheimnisse, halb gültig, fremd, kein JSON, Zeilenumbruch im Thema | 19/19 |
| **Eichung** | jede neue Prüfung wird in einer Kopie einzeln zerbrochen und **muss** rot werden — an der richtigen Zeile | bestanden |

Die Prüfstücke selbst gehören **nicht** ins Plugin-Paket. Sie liegen im
Arbeitsordner daneben, unter `Pruefung-Spotpreis-Tibber-<Fassung>/`, samt
einer eigenen `README.md`, die zu jeder Prüfung den Aufruf **und ihre
Grenzen** nennt. Wer die Zeilen dieser Tabelle nachmessen will, findet sie
dort — aus dem Plugin-Ordner allein sind sie nicht belegbar.

## Umstieg von 0.9.6 — die Importdatei muss neu eingelesen werden

Bis 0.9.6 lautete der Suchtext eines Feldes `\i NAME=\i\v`. Loxone sucht
**wörtlich** und nimmt den ersten Treffer. Damit las `ALTER` in Wahrheit
`PULSE_ALTER` und `OK` las `MORGEN_OK` — die beiden Namen sind Endungen der
anderen. Behoben ist das durch ein `;` vor dem Namen.

Der Suchtext steckt aber in den **schon importierten** virtuellen Eingängen,
nicht im Plugin. Er ändert sich dort nicht von selbst:

1. Reiter **Einbindung in Loxone**, Importdatei neu erzeugen
2. In Loxone Config die alten Eingänge löschen und die Datei neu einlesen
3. Speichern in den Miniserver

Wer das überspringt, behält zwei falsch gelesene Werte — ohne Fehlermeldung,
denn beide Felder liefern ja eine plausible Zahl.

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

## Fassung 0.9.10 — was sich geändert hat

Eine Durchsicht Zeile für Zeile, mit vier unabhängigen Prüfern. Das Wichtigste
in der Reihenfolge dessen, was der Anwender davon merkt:

**Rechenwege**

- **Der eigene Aufschlag fehlte in allen Kostenkennzahlen.** `CUR` trug ihn,
  `DYN_MONAT`, `DIFF_MONAT`, `EURO_MONAT` und `ERSPARNIS_GESTERN` nicht — bei
  3 ct Aufschlag und 300 kWh wies das Plugin damit rund 9 Euro Ersparnis je
  Monat aus, die es nicht gab.
- **`FENSTER2_IN` wurde gegen das Ende des ersten Fensters gerechnet**, nicht
  gegen jetzt. Gemessen: um 11:01 stand dort 0, während das zweite Fenster
  wirklich in 17 Stunden begann. Eine Loxone-Regel „laden, wenn
  `FENSTER2_IN` = 0" löste damit mittags aus.
- **Keine Zeile setzte eine Zeitzone.** Debian liefert `php.ini` mit
  auskommentiertem `date.timezone`; PHP fällt dann still auf UTC zurück, und
  alle Stundenfelder stehen ein bis zwei Stunden daneben. Gesetzt wird jetzt
  aus `/etc/timezone`, aber **nur**, wenn keine eingestellt ist.
- **Tage mit Kosten, aber ohne Verbrauch** verzerrten den Durchschnittspreis:
  Kosten aus mehr Tagen geteilt durch Verbrauch aus weniger.
- **`NEXT`** rechnete mit einer festen Stunde statt mit der gemessenen
  Schrittweite.

**Was das Plugin über sich selbst sagte**

- **`OK` blieb auf 1**, wenn der Abruf an zwei von drei Stellen scheiterte —
  „kein laufender Vertrag" und „null Preisstunden" fassten `stand.json` gar
  nicht an. Über MQTT ging weiter eine 1 hinaus.
- **`ALTER` konnte 9999 senden, während die Importvorlage `MaxVal="1440"`
  zusagte.** Genau im Ausfall lag das Feld außerhalb des Bereichs, an dem die
  Ausfallerkennung hängt. Beides ist jetzt aufeinander abgestimmt und wird
  aus **einer** Quelle gedeckelt.
- **Die Themenzeile im Reiter Test konnte nie grün werden**: die Positivliste
  zählte drei Lebenszeichen auf, gesendet werden vier. Sie kommt jetzt aus
  der Funktion, die sie wirklich sendet.
- **Der Monatsbericht erfand einen Satz**, wenn Tageszeilen ohne Verbrauch
  vorlagen: „0.0 kWh an 31 Tagen … draufgezahlt 0.00 Euro."

**Speichern und Oberfläche**

- **Ein einziger Tippfehler verwarf alle übrigen Eingaben.** Jetzt gilt, was
  der Hausstandard verlangt: die beanstandete Zeile wird übergangen, alles
  Übrige gespeichert.
- **Ein gescheitertes Speichern im MQTT-Reiter blieb stumm**, ebenso ein
  gescheitertes „Protokoll leeren" — beides meldete Erfolg.
- **Eine beschädigte Konfiguration riss die Zweitschrift mit.** Ein einziges
  Öffnen der Seite nach einem Stromausfall genügte: Einstellungen auf Werk,
  Merkwort neu, und der Rettungsanker daneben ebenfalls überschrieben. Eine
  unlesbare Datei wird jetzt als `tibber.json.kaputt.<Zeit>` beiseitegelegt.
- **Die Sicherung eines Schwesterplugins wurde ohne Beanstandung
  übernommen** — aWATTar und Octopus benutzen dieselben Feldnamen. Der
  lesbare Kopf `_plugin` wird jetzt geprüft.
- **`tb_aktionstoken()` gab ein Merkwort zurück, das nie gespeichert wurde**,
  und beim nächsten Aufruf ein anderes. Jetzt fail closed.
- **Das Formularmerkmal blieb leer, wenn das Merkwort gerade erst entstand.**
  Bei einer `tibber.json` mit Schlüsseln, aber ohne `aktionstoken` — dem
  Zustand jeder Anlage, die aus einer älteren Sicherung kommt — trugen alle
  15 Formulare `value=""`. Der erste Klick lief danach in den Wachposten
  („kein gültiges Merkmal"), obwohl der Anwender alles richtig gemacht hatte;
  erst ein Neuladen half.
- Die Legende steht oben im Reiter Einstellungen, nicht in seiner Mitte.

**Dienst und Installation**

- **Der Pulse-Dienst hatte keine Totmann-Schaltung für den Fall, dass die
  Verbindung bestätigt und nie liefert** — die Schleife lief unbegrenzt
  weiter, während `live.json` leer blieb.
- **Ein halb gelesener WebSocket-Rahmenkopf ging verloren**; der nächste
  Aufruf setzte an der falschen Byte-Grenze auf.
- **Nach einer Sitzung, die gleich nach dem Handschlag starb, gab es keinen
  Rückzug**: rund zwanzig Verbindungsversuche je Minute, jeder mit einer
  GraphQL-Anfrage davor.
- **`preupgrade.sh` und die Deinstallation schickten `kill -9` an eine nackte
  Prozessnummer.** Bei veralteter PID-Datei traf das einen fremden Prozess.
  Beide gehen jetzt über `bin/dienst.sh stop`, das argumentweise prüft.
- **Der Wächter hatte keine Obergrenze**: startete der Dienst dauerhaft
  nicht, versuchte er es jede Minute neu und schrieb dabei an der
  Protokollkappung vorbei. Jetzt mit Rückzug 1, 2, 4 … 60 Minuten.
- **Die Sperre des Cron wurde gezogen, bevor die Schalter ausgewertet
  waren.** „Selbsttest" lieferte während eines laufenden Minutentakts ein
  leeres Feld, und „Preise jetzt holen" meldete danach den **alten** Stand
  als Erfolg.
- **Der Umstiegshinweis aus 0.9.6 erschien bei jedem Update.** Er hängt jetzt
  an der Vorgängerfassung aus der Plugin-Datenbank.

**Kleineres**

`json_encode` wird an neun Stellen geprüft (der Endpunkt `aktion=json` gab
sonst HTTP 200 mit leerem Rumpf; die MQTT-Signatur fiel auf `md5('')`
zusammen und hätte den Doppelt-senden-Filter eingefroren); `INF` und `NAN`
gehen als Strich hinaus statt als Wert; die Themen-Ausschlussliste, die
Fensterlänge, die Mindestpunktzahl des Verlaufs, die Altersschranke und die
Endpunktadresse stehen jetzt an je **einer** Stelle statt an zwei bis drei;
der Verlauf liest alle Monatsdateien des eingestellten Zeitraums statt nur
zwei; `Cache-Control: no-store` am Endpunkt; drei tote Sprachschlüssel
entfernt.

## Fassung 0.9.8 — der Stat-Zwischenspeicher
Die Protokollkappung (512 000 Byte) stand in
`webfrontend/html/tb_lib.php:570`. PHP merkt sich aber die Antworten von
`stat()`: innerhalb **eines** Prozesses sieht `filesize()` die erste Größe
und danach nie wieder eine neue — `file_put_contents(…, FILE_APPEND)` macht
den Eintrag nicht ungültig. Die Kappung fällt dann still aus.

Gemessen am 29.08.2026, 20 000 Zeilen im selben Prozess:

| | ohne `clearstatcache` | mit |
|---|---|---|
| PHP 7.4.33 | 1 220 000 Byte, **nicht gekappt** | 220 332 Byte, gekappt |
| PHP 8.4.24 | 220 332 Byte, gekappt | 220 332 Byte, gekappt |

Die beiden PHP-Fassungen verhalten sich also verschieden — und LoxBerry 3.x
fährt 7.4. Wer nur unter 8.4 misst, sieht den Fehler nie. Folgen hatte das
hier nicht: die Aufrufer sind kurzlebig, und ein **frischer** Prozess kappt
richtig. Eine Funktion darf aber nicht davon abhängen, wer sie wie oft ruft.

Abhilfe: `clearstatcache(true, …)` **vor** dem Tor; der zweite Parameter
beschränkt das Leeren auf diese eine Datei. Dasselbe Muster tragen Robonect,
Saugroboter, SignalBot, Octopus, Sprachsteuerung und WärmepumpeCloud schon
länger — es ist am 29.08.2026 im ganzen Bestand nachgezogen worden.

## Lizenz

MIT.
