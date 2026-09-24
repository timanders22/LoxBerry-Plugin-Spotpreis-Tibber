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
    echo "<WARNING> Es wurde nichts angelegt, nichts gesichert und kein Dienst angehalten."
    exit 1
fi

PCONFIG="$BASE/config/plugins/$PFOLDER"
# Der Programmordner - von dort wird dienst.sh gerufen, wenn der
# Pulse-Dienst angehalten werden muss. preupgrade laeuft VOR dem
# Auspacken, das Skript liegt zu diesem Zeitpunkt also noch da.
PBIN="$BASE/bin/plugins/$PFOLDER"

# ---------- Die Marke: ALS ERSTES, noch vor allem anderen ----------
#
# Zwischen der neuen Cron-Datei und postinstall.sh liegt fast eine Minute (am
# Geraet an der Einspeisebremse gemessen, Regeln/06). In dieser Luecke sind
# config/plugins/<ordner>/ und data/plugins/<ordner>/ schon geloescht. Was
# dann startet, laeuft ohne Einstellungen und ohne Tibber-Token.
#
# Bei DIESER Linie ist am 18.09.2026 in WSL gemessen, dass in der Luecke
# nichts anlaeuft: der Waechter verlangt data/plugins/<ordner>/soll_laufen,
# und "dienst.sh start" verlangt config/plugins/<ordner>/token.json - beide
# Dateien loescht purge_installation. Die Marke ist deshalb VORSORGE. Sie
# deckt die Wege ab, die dort nicht gemessen sind: ein Systemstart mitten in
# der Aktualisierung, ein Aufruf aus einem Hakenskript, und den Knopf
# "Dienst starten" in der Oberflaeche, nachdem ein Minutenlauf die
# Konfiguration aus der Zweitschrift geheilt hat.
#
# Sie liegt NEBEN dem Datenordner - ein Kind von data/plugins/<ordner>/
# loeschte purge_installation mit.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
mkdir -p "$BASE/data/plugins" 2>/dev/null
date +%s > "$MARKE" 2>/dev/null
if [ -s "$MARKE" ]; then
    echo "<OK> Dienststart bis zum Ende der Installation gesperrt."
else
    echo "<WARNING> Die Marke $MARKE liess sich nicht anlegen - ein Startweg"
    echo "<WARNING> koennte den Pulse-Dienst waehrend der Installation anwerfen."
fi

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

# ERST FRAGEN, DANN ANHALTEN - und nur das melden, was die Antwort hergibt.
#
# Bis 0.9.14 hing dieser ganze Block an [ -f "$PID" ], und die Meldung stand
# bedingungslos darin. Das ist der Befund vom 11.09.2026 (das Protokoll meldete
# einen Dienst angehalten, der stand) eine Tuer weiter: eine PID-Datei ist KEIN
# Beleg, dass ein Prozess lebt. Ueberlebt sie einen Stromausfall oder einen
# Neustart ohne saubere Abmeldung, meldete dieses Skript einen Dienst
# angehalten, den es nie gab - am 15.09.2026 im Wegwerfhof des Pruefstands
# gemessen: veraltete PID-Datei, kein Prozess, trotzdem
# "<INFO> Laufender Pulse-Dienst angehalten."
#
# Die Gegenrichtung war ebenso falsch und waere teurer: fehlt die PID-Datei,
# waehrend der Dienst laeuft, wurde vor dem Auspacken NICHTS angehalten - die
# WebSocket-Verbindung blieb offen, wogegen dieser Block ueberhaupt steht.
#
# Gefragt wird deshalb dienst.sh status. Das prueft ARGUMENTWEISE, ob die
# Nummer wirklich zu tb_pulse.php gehoert (argv[1] ist das Skript, argv[0] ein
# PHP-Interpreter), und begruendet das dort ueber zehn Zeilen. Angehalten wird
# anschliessend in JEDEM Fall - stop raeumt auch den Sollmerker ab, und der
# Rettungsmerker oben ist zu diesem Zeitpunkt bereits geschrieben. Dieselbe
# Bauart tragen BYD Autos 0.9.12 und Matter2Lox 0.9.23.
LIEF_WIRKLICH=0
if [ -x "$PBIN/dienst.sh" ]; then
    "$PBIN/dienst.sh" status >/dev/null 2>&1 && LIEF_WIRKLICH=1
    "$PBIN/dienst.sh" stop >/dev/null 2>&1 || true
fi
# Rueckfall, falls dienst.sh fehlt: erst hoeflich, -9 nur nach erneuter Probe.
# Ist <pid> UNSER Pulse-Dienst? Dieselbe argumentweise Probe wie
# tb_ist_dienst() in bin/dienst.sh: argv[0] ein PHP, argv[1] zeichengenau
# <bin-Ordner>/tb_pulse.php (relativ gegen /proc/<pid>/cwd), kein drittes
# Argument, der Prozess gehoert dem Dienstbenutzer. Bis 0.9.17 stand hier nur
# "kill -0" - jeder Prozess unter der Nummer aus der PID-Datei wurde beendet,
# auch ein "tail -f <dienstpfad>" (in WSL gemessen, messe_prozess.sh, P7/P9).
TB_SKRIPT="$PBIN/tb_pulse.php"
TB_DIENST_UID=$(id -u loxberry 2>/dev/null || id -u)
tb_ist_dienst() {
    local a0 a1 a2 ziel wd
    case "$1" in ''|*[!0-9]*) return 1 ;; esac
    [ -r "/proc/$1/cmdline" ] || return 1
    [ "$(stat -c %u "/proc/$1" 2>/dev/null)" = "$TB_DIENST_UID" ] || return 1
    a0=""; a1=""; a2=""
    { IFS= read -r -d "" a0; IFS= read -r -d "" a1; IFS= read -r -d "" a2; } 2>/dev/null < "/proc/$1/cmdline"
    [ -n "$a0" ] && [ -n "$a1" ] && [ -z "$a2" ] || return 1
    case "${a0##*/}" in php|php[0-9]*) ;; *) return 1 ;; esac
    case "$a1" in
        /*) ziel="$a1" ;;
        *)  wd=$(readlink "/proc/$1/cwd" 2>/dev/null) || return 1
            ziel="${wd% (deleted)}/$a1" ;;
    esac
    [ "$ziel" = "$TB_SKRIPT" ] && return 0
    [ "$(readlink -f "$ziel" 2>/dev/null)" = "$(readlink -f "$TB_SKRIPT" 2>/dev/null)" ]
}
TB_P=$(cat "$PID" 2>/dev/null)
if [ -f "$PID" ] && tb_ist_dienst "$TB_P"; then
    LIEF_WIRKLICH=1
    kill "$TB_P" 2>/dev/null || true
    sleep 2
    if tb_ist_dienst "$TB_P"; then
        kill -9 "$TB_P" 2>/dev/null || true
    fi
fi
rm -f "$PID"
if [ "$LIEF_WIRKLICH" -eq 1 ]; then
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
