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
exit 0
