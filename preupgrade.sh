#!/bin/bash
# Spotpreis Tibber - preupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Rettet Konfiguration UND Tibber-Token ueber die Installation. Beide liegen
# im Konfigurationsordner, und der ist beim Installieren weg, bevor ein Skript
# des Plugins laeuft.
#
# Die Sicherungen liegen NEBEN dem Ordner, nicht darin - ein Geschwister
# ueberlebt dessen Loeschung. Bewusst NICHT /tmp: das ist auf dem LoxBerry
# eine Ramdisk und fuer jeden lesbar. In token.json steht ein Geheimnis.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-spotpreistibber}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

PCONFIG="$BASE/config/plugins/$PFOLDER"

# Laufenden Pulse-Dienst anhalten - er haelt eine Verbindung offen.
PID="$BASE/data/plugins/$PFOLDER/pulse.pid"
if [ -f "$PID" ]; then
    kill "$(cat "$PID")" 2>/dev/null || true
    sleep 2
    kill -9 "$(cat "$PID")" 2>/dev/null || true
    rm -f "$PID"
    echo "<INFO> Laufender Pulse-Dienst angehalten."
fi

for PAAR in "tibber.json:.backup.json" "token.json:.backup.token.json"; do
    QUELLE="$PCONFIG/${PAAR%%:*}"
    ZIEL="$BASE/config/plugins/$PFOLDER${PAAR##*:}"
    if [ -s "$QUELLE" ]; then
        if cp -p "$QUELLE" "$ZIEL" && chmod 600 "$ZIEL"; then
            echo "<OK> $(basename "$QUELLE") gesichert (Rechte 0600)."
        else
            echo "<FAIL> $(basename "$QUELLE") liess sich nicht sichern."
        fi
    fi
done
exit 0
