#!/bin/bash
# Spotpreis Tibber - preinstall: Reste einer frueheren Installation beiseitelegen
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>
#
# Anlass: Pruefbericht installer (Durchgang 01.10.2026), Fall D: eine
# Neuinstallation mit liegengebliebenen Zweitschriften einer frueheren
# Installation uebernahm still deren Tibber-Token und Aktionstoken und
# meldete "Aktualisierung abgeschlossen"; Fall D2: schon der erste Minutentakt
# nach dem Kopieren heilte die Konfiguration aus der alten Zweitschrift.
# Entscheidung 1 vom 29.09.2026; Bauform Abfahrtsassistent 1.6.21 und
# Spotpreis aWATTar (Durchgang 01.10.2026).
#
# Der Installer ruft dieses Skript bei JEDEM Einbau auf, nach dem Abraeumen
# der alten Fassung und VOR dem Kopieren von Cron-Datei und Oberflaeche.
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (KEIN Altersvergleich - Entscheidung 1 und Nr. 8). Dann tut es nichts:
# Zweitschriften und Update-Sicherung braucht postinstall.sh.
#
# Ohne Marke ist es eine NEUINSTALLATION. Liegengebliebene Zweitschriften
# (Konfiguration mit Aktionstoken, Tibber-Token), eine Update-Sicherung
# (Preisverlauf, Hysterese, Berichtsmarken) und der Merker "Dienst lief" einer
# frueheren Installation gehen nach <name>.alt, gemeldet mit genau einer
# <WARNING>. Die Selbstheilung der Bibliothek liest .alt nie; uninstall raeumt
# es ab.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-spotpreistibber}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Wurzelsuche wie in den uebrigen Hakenskripten. Weil dieses Skript Dateien
# verschiebt, wird config/system/general.json auch fuer $5 bzw. $LBHOMEDIR
# verlangt (Regeln/06, Raumklima-Vorfall).
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
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    BASE=$(tb_wurzel_suchen "$(dirname "$(readlink -f "$0")")") || BASE=""
fi
if [ -z "$BASE" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt - nichts beiseitegelegt."
    exit 0
fi
# Der Ordnername darf keinen Pfadtrenner tragen, sonst griffe mv/rm daneben.
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    # Aktualisierung: nichts zu tun, postinstall.sh spielt zurueck.
    exit 0
fi

BEISEITE=""
FEST=""
for ZIEL in "$BASE/config/plugins/$PFOLDER.backup.json" \
            "$BASE/config/plugins/$PFOLDER.backup.token.json" \
            "$BASE/data/plugins/$PFOLDER.upgrade_sicherung" \
            "$BASE/data/plugins/$PFOLDER.lief_vorher"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        # mv -T: liegt noch ein Ordner .alt da, wuerde mv sonst HINEIN verschieben.
        mv -fT "$ZIEL" "$ZIEL.alt" 2>/dev/null
        # Die Wirkung pruefen: das Original ist weg, die Ablage ist da.
        if [ ! -e "$ZIEL" ] && [ ! -L "$ZIEL" ] && { [ -e "$ZIEL.alt" ] || [ -L "$ZIEL.alt" ]; }; then
            BEISEITE="$BEISEITE $ZIEL.alt"
            # Zweitschriften tragen Geheimnisse - auch beiseitegelegt nur fuer den Eigentuemer.
            [ -f "$ZIEL.alt" ] && [ ! -L "$ZIEL.alt" ] && chmod 600 "$ZIEL.alt" 2>/dev/null
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done

if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    TB_TEXT="<WARNING> Neuinstallation: Einstellungen, Token und Bestaende einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && TB_TEXT="$TB_TEXT Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && TB_TEXT="$TB_TEXT Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$TB_TEXT"
fi
exit 0
