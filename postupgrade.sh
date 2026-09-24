#!/bin/bash
# Spotpreis Tibber - postupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Bewusst KEIN Aufruf von postinstall.sh: der Installer fuehrt postinstall
# ohnehin ohne Bedingung aus. Ein Aufruf von hier aus ergaebe zwei Durchlaeufe
# (belegt in der Sprachsteuerungs-Sitzung).
#
# Hier steht nur, was ein Upgrade ZUSAETZLICH braucht.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-spotpreistibber}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Die Wurzel: $5 (vom Installer) oder $LBHOMEDIR, wenn dort config/plugins
# und data/plugins liegen - sonst vom eigenen Ablageort AUFWAERTS SUCHEN, bis
# ein Verzeichnis config/plugins, data/plugins UND config/system/general.json
# traegt. Keine feste Ebenenzahl und kein fest verdrahteter Systempfad danach.
#
# Bis 0.9.17 stand hier der Rueckfall $(cd "$SELF/../.."). Installiert liegt
# uninstall unter <Wurzel>/data/system/uninstall/<ordner>; zwei Ebenen hoch
# ist dort <Wurzel>/data, und die Deinstallation ohne $5 raeumte nichts ab.
# Aus einem Archiv zwei Ebenen unter einem fremden Baum loeschte sie dort, und
# die uebrigen Hakenskripte legten dort an, spielten zurueck oder loeschten
# (in WSL gemessen, Pruefung-Spotpreis-Tibber-0.9.18, messung_haken_vorher.txt,
# Faelle K1 bis K11). general.json ist die Bedingung aus dem Raumklima-Vorfall
# (Regeln/06): ein LoxBerry hat die Datei immer, ein Pruefstandsrest nie.
# Findet sich nichts, wird GEWARNT statt vollzogen.
tb_wurzel_suchen() {
    tb_v=$(cd "$1" 2>/dev/null && pwd -P) || return 1
    tb_i=0
    while [ -n "$tb_v" ] && [ "$tb_v" != "/" ] && [ "$tb_i" -lt 8 ]; do
        if [ -d "$tb_v/config/plugins" ] && [ -d "$tb_v/data/plugins" ] \
           && [ -f "$tb_v/config/system/general.json" ]; then
            echo "$tb_v"
            return 0
        fi
        tb_v=$(dirname "$tb_v")
        tb_i=$((tb_i + 1))
    done
    return 1
}
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ]; then
    BASE=$(tb_wurzel_suchen "$(dirname "$(readlink -f "$0")")") || BASE=""
fi
if [ -z "$BASE" ]; then
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden: weder als"
    echo "<WARNING> fuenftes Argument noch in \$LBHOMEDIR, und oberhalb von"
    echo "<WARNING> $(dirname "$(readlink -f "$0")") traegt kein Verzeichnis"
    echo "<WARNING> config/plugins, data/plugins und config/system/general.json."
    echo "<WARNING> Es wurde nichts geloescht."
    exit 1
fi

PDATA="$BASE/data/plugins/$PFOLDER"

# Der Zwischenspeicher der Preise kann nach einem Update ein anderes Format
# haben. Er wird beim naechsten Takt neu geholt - alte Daten stehen zu lassen
# waere schlimmer, weil die Oberflaeche sie dann als frisch anzeigt.
rm -f "$PDATA/stand.json" 2>/dev/null
# Taktmarker zuruecksetzen, damit gleich nach dem Update abgerufen wird.
rm -f "$PDATA/.letzter_"* 2>/dev/null

# Und den MQTT-Aenderungsmerker.
#
# Seit 0.9.13 gehen Zustaende ZURUECKBEHALTEN hinaus. Der Doppelt-senden-
# Filter vergleicht eine Signatur ueber die Werte; nach einem Update ist sie
# unveraendert, es ginge also nur das Lebenszeichen hinaus - und die
# zurueckbehaltenen Themen stuenden erst beim naechsten echten Preiswechsel
# im Broker. Dasselbe gilt beim erstmaligen Einschalten von MQTT und nach
# einem Wechsel des Themen-Praefixes (Regeln/07, EVCC 08.09.2026).
#
# Die Probe dafuer: unmittelbar nach dem naechsten Minutenlauf muessen unter
#   mosquitto_sub -t '<praefix>/#' --retained-only
# so viele Themen stehen, wie die Tabelle im Reiter MQTT zurueckbehaltene
# fuehrt - nicht weniger.
rm -f "$PDATA/.mqtt_signatur" 2>/dev/null

echo "<OK> Zwischenspeicher geleert - beim naechsten Minutenlauf wird neu geholt."

# Der Suchtext der virtuellen Eingaenge hat bis 0.9.6 kein Trennzeichen vor
# dem Feldnamen gehabt. Loxone sucht woertlich und nimmt den ersten Treffer:
# ALTER las PULSE_ALTER, OK las MORGEN_OK. Das ist im Plugin behoben - aber
# der Suchtext steckt in den SCHON IMPORTIERTEN Eingaengen, nicht hier. Wer
# es ueberliest, behaelt zwei falsch gelesene Werte, und zwar ohne
# Fehlermeldung: beide Felder liefern weiterhin eine plausible Zahl.
# ... und der Hinweis gilt NUR fuer den Umstieg von 0.9.6 oder aelter.
#
# Bis 0.9.9 stand er ohne Bedingung da und erschien auch beim Sprung
# 0.9.9 -> 0.9.10: die Aufforderung, alle 48 virtuellen Eingaenge zu loeschen
# und neu einzulesen - Arbeit, die laengst getan ist. Ein Hinweis, der immer
# erscheint, wird beim dritten Mal nicht mehr gelesen; dann wird auch der
# naechste echte ueberlesen.
#
# Die Vorgaengerfassung steht in der Plugin-Datenbank. Sie wird ueber den
# ORDNERNAMEN gesucht, nicht ueber den MD5-Schluessel: der entsteht aus
# Autorenname, E-Mail und Plugin-Name und aendert sich bei einem Fork.
VORHER=""
DB="$BASE/data/system/plugindatabase.json"
if [ -r "$DB" ] && command -v php >/dev/null 2>&1; then
    VORHER=$(php -r '
        $db = json_decode(@file_get_contents($argv[1]), true);
        if (!is_array($db) || !isset($db["plugins"])) { exit; }
        foreach ($db["plugins"] as $e) {
            if (isset($e["folder"], $e["version"]) && $e["folder"] === $argv[2]) {
                echo (string) $e["version"];
            }
        }' "$DB" "$PFOLDER" 2>/dev/null)
fi

# Kleiner als 0.9.7? Dann ist der Suchtext in Loxone noch der alte.
# Laesst sich die Vorgaengerfassung NICHT ermitteln, wird der Hinweis
# gezeigt - lieber einmal zu viel als einmal zu wenig, denn das Uebersehen
# kostet zwei dauerhaft falsch gelesene Werte.
ALT=1
if [ -n "$VORHER" ]; then
    ALT=$(php -r 'exit(version_compare($argv[1], "0.9.7", "<") ? 0 : 1);' "$VORHER" \
          >/dev/null 2>&1 && echo 1 || echo 0)
    echo "<INFO> Vorgaengerfassung laut Plugin-Datenbank: $VORHER"
fi

if [ "$ALT" = "1" ]; then
    echo "<INFO> WICHTIG: Bitte die Loxone-Importdatei neu erzeugen (Reiter"
    echo "<INFO> 'Einbindung in Loxone'), die alten virtuellen Eingaenge"
    echo "<INFO> loeschen und die Datei erneut einlesen. Ohne das lesen die"
    echo "<INFO> Felder ALTER und OK weiterhin den falschen Wert."
fi
exit 0
