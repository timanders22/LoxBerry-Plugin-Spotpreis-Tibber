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
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

PDATA="$BASE/data/plugins/$PFOLDER"

# Der Zwischenspeicher der Preise kann nach einem Update ein anderes Format
# haben. Er wird beim naechsten Takt neu geholt - alte Daten stehen zu lassen
# waere schlimmer, weil die Oberflaeche sie dann als frisch anzeigt.
rm -f "$PDATA/stand.json" 2>/dev/null
# Taktmarker zuruecksetzen, damit gleich nach dem Update abgerufen wird.
rm -f "$PDATA/.letzter_"* 2>/dev/null

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
