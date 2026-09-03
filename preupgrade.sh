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
# Der Programmordner - von dort wird dienst.sh gerufen, wenn der
# Pulse-Dienst angehalten werden muss. preupgrade laeuft VOR dem
# Auspacken, das Skript liegt zu diesem Zeitpunkt also noch da.
PBIN="$BASE/bin/plugins/$PFOLDER"

# Laufenden Pulse-Dienst anhalten - er haelt eine Verbindung offen.
#
# UND SICH MERKEN, DASS ER LIEF. Der Sollmerker liegt unter
# data/plugins/<ordner>/soll_laufen, und genau dieses Verzeichnis raeumt der
# Installateur beim Upgrade vollstaendig ab: plugininstall.pl ruft
# purge_installation im UPGRADE-Zweig (:886), und die entfernt
# data/plugins/<ordner>/ ohne Bedingung (:1631). Der minuetliche Waechter fand
# den Merker danach nicht mehr und startete nichts - der Dienst stand nach
# JEDEM Update still, ohne dass irgendwo etwas stand.
#
# Der Rettungsmerker liegt deshalb NEBEN dem Ordner. Ein Geschwister mit Punkt
# im Namen trifft das rm -rf auf das Verzeichnis nicht. Zurueckgelegt wird in
# postinstall.sh - das laeuft immer, postupgrade.sh waere zu spaet.
LIEF="$BASE/data/plugins/$PFOLDER.lief_vorher"
rm -f "$LIEF" 2>/dev/null
if [ -f "$BASE/data/plugins/$PFOLDER/soll_laufen" ]; then
    echo "1" > "$LIEF" 2>/dev/null \
        && echo "<INFO> Der Pulse-Dienst lief - er wird nach dem Update neu gestartet."
fi

PID="$BASE/data/plugins/$PFOLDER/pulse.pid"
if [ -f "$PID" ]; then
    # Ueber dienst.sh, nicht ueber die nackte Prozessnummer.
    #
    # bin/dienst.sh prueft ARGUMENTWEISE, ob die Nummer wirklich zu
    # tb_pulse.php gehoert (argv[1] ist das Skript, argv[0] ein
    # PHP-Interpreter), und begruendet das ueber zehn Zeilen. Genau diese
    # Pruefung fehlte hier - bei einer veralteten PID-Datei (Stromausfall,
    # Neustart ohne saubere Abmeldung) traf das kill -9 einen FREMDEN
    # Prozess, dem der Kernel die Nummer neu vergeben hatte.
    if [ -x "$PBIN/dienst.sh" ]; then
        "$PBIN/dienst.sh" stop >/dev/null 2>&1 || true
    fi
    # Rueckfall, falls dienst.sh fehlt: dann wenigstens hoeflich und ohne -9.
    if [ -f "$PID" ] && kill -0 "$(cat "$PID")" 2>/dev/null; then
        kill "$(cat "$PID")" 2>/dev/null || true
        sleep 2
    fi
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
