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
echo "<INFO> WICHTIG: Bitte die Loxone-Importdatei neu erzeugen (Reiter"
echo "<INFO> 'Einbindung in Loxone'), die alten virtuellen Eingaenge"
echo "<INFO> loeschen und die Datei erneut einlesen. Ohne das lesen die"
echo "<INFO> Felder ALTER und OK weiterhin den falschen Wert."
exit 0
