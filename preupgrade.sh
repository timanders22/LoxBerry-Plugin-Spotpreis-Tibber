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
#
# Seit dem Durchgang (Entscheidung 1, ohne Altersgrenze nach Nr. 8) ist sie
# AUCH das Merkmal "Aktualisierung": preinstall.sh legt ohne sie die
# Zweitschriften beiseite, postinstall.sh spielt nur mit ihr zurueck. Laesst
# sie sich nicht anlegen, endet dieses Skript deshalb mit Rueckgabewert 2 -
# sonst hielte die Installation das Update fuer eine Neuinstallation und
# legte die Einstellungen beiseite (Bauform Abfahrtsassistent 1.6.21).
# Vorher festhalten, ob schon eine Marke lag: dann ist ein frueherer Versuch
# DIESES Updates abgebrochen (siehe Update-Sicherung unten).
case "$PFOLDER" in
    ''|*/*|*..*)
        echo "<FAIL> Unzulaessiger Ordnername '$PFOLDER' - dieses Skript endet mit Rueckgabewert 2."
        exit 2 ;;
esac
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
TB_MARKE_VORHER=0
[ -f "$MARKE" ] && TB_MARKE_VORHER=1
mkdir -p "$BASE/data/plugins" 2>/dev/null
{ date +%s > "$MARKE"; } 2>/dev/null
if grep -qx '[0-9][0-9]*' "$MARKE" 2>/dev/null; then
    echo "<OK> Dienststart bis zum Ende der Installation gesperrt."
else
    echo "<FAIL> Die Marke $MARKE liess sich nicht anlegen."
    echo "<FAIL> Ohne sie hielte die Installation dieses Update fuer eine Neuinstallation und legte"
    echo "<FAIL> die Einstellungen beiseite. Dieses Skript endet mit Rueckgabewert 2."
    exit 2
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
# Zusaetzlich ARGUMENTWEISE ueber alle Prozesse, nicht nur ueber die
# PID-Datei (Bauliste I5): fehlt sie, waehrend der Dienst laeuft, blieb er bis
# 0.9.24 ueber das Update hinweg stehen (Installerpruefer, Fall G3). Danach
# wird nachgezaehlt und nur das Gemessene gemeldet (I4).
tb_dienste_suchen() {
    local d
    for d in /proc/[0-9]*; do
        tb_ist_dienst "${d#/proc/}" && echo "${d#/proc/}"
    done
    return 0
}
TB_P=$(cat "$PID" 2>/dev/null)
TB_GEFUNDEN=$(tb_dienste_suchen)
if [ -f "$PID" ] && tb_ist_dienst "$TB_P"; then
    TB_GEFUNDEN="$TB_GEFUNDEN $TB_P"
fi
if [ -n "$(echo $TB_GEFUNDEN)" ]; then
    LIEF_WIRKLICH=1
    for TB_P in $TB_GEFUNDEN; do kill "$TB_P" 2>/dev/null || true; done
    sleep 2
    for TB_P in $(tb_dienste_suchen); do kill -9 "$TB_P" 2>/dev/null || true; done
fi
rm -f "$PID"
TB_REST=$(tb_dienste_suchen | wc -w)
if [ "$LIEF_WIRKLICH" -eq 1 ] && [ "$TB_REST" -eq 0 ]; then
    echo "<INFO> Laufender Pulse-Dienst angehalten (nachgezaehlt: keiner laeuft mehr)."
elif [ "$TB_REST" -gt 0 ]; then
    echo "<WARNING> Es laufen noch $TB_REST Pulse-Dienst(e) - sie liessen sich nicht anhalten."
fi

# Nur eine Datei, die sich als JSON-Objekt lesen laesst, wird ueber die
# Zweitschrift gelegt (Bauliste I3). Bis 0.9.24 genuegte "nicht leer": eine
# abgeschnittene tibber.json ueberschrieb die einzige gute Kopie, und
# postinstall.sh spielte danach nichts zurueck (Installerpruefer, Fall C).
# Rueckgabe 0 lesbar, 1 unlesbar, 2 nicht pruefbar (kein PHP).
tb_json_lesbar() {
    command -v php >/dev/null 2>&1 || return 2
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true); exit(is_array($d) ? 0 : 1);' \
        -- "$1" >/dev/null 2>&1
    tb_rc=$?
    [ "$tb_rc" = 0 ] || [ "$tb_rc" = 1 ] || return 2
    return "$tb_rc"
}
for PAAR in "tibber.json:.backup.json" "token.json:.backup.token.json"; do
    QUELLE="$PCONFIG/${PAAR%%:*}"
    ZIEL="$BASE/config/plugins/$PFOLDER${PAAR##*:}"
    if [ -s "$QUELLE" ]; then
        tb_json_lesbar "$QUELLE"
        TB_L=$?
        if [ "$TB_L" = 1 ]; then
            echo "<WARNING> $(basename "$QUELLE") ist unlesbar und wurde NICHT gesichert - die bisherige"
            echo "<WARNING> Zweitschrift $(basename "$ZIEL") bleibt und wird nach dem Update zurueckgespielt."
            continue
        fi
        if cp -p "$QUELLE" "$ZIEL" && chmod 600 "$ZIEL"; then
            if [ "$TB_L" = 2 ]; then
                echo "<OK> $(basename "$QUELLE") gesichert (Rechte 0600; ohne PHP nicht auf Lesbarkeit geprueft)."
            else
                echo "<OK> $(basename "$QUELLE") gesichert (Rechte 0600)."
            fi
        else
            echo "<FAIL> $(basename "$QUELLE") liess sich nicht sichern."
        fi
    fi
done

# ---------- Bestaende retten (Bauliste I2) ----------
# purge_installation loescht data/plugins/<ordner>/ bei JEDEM Update. Darin
# liegen der Preisverlauf (verlauf/, bis zu 3650 Tage, Grundlage von AVG_30T
# und RANK_30T), die Hysterese (laufend.json - ein begonnener Block laeuft zu
# Ende), die Berichtsmarken (bericht_<JJJJMM>.done - ohne sie kaeme der
# Monatsbericht nach einem Update am Ersten ein zweites Mal) und die
# vorgemerkten MQTT-Praefixe (mqtt_praefixe.json). Bis 0.9.24 rettete dieses
# Skript nichts davon (Installerpruefer, Faelle B, B2, K: 60 Verlaufspunkte
# vorher, 0 nachher). Sie gehen NEBEN den Ordner; postinstall.sh holt sie bei
# vorhandener Marke zurueck.
#
# Eine Update-Sicherung aus einem FRUEHEREN Vorgang wird zuerst weggeraeumt
# (Entscheidung 1: bei einem Upgrade wird nie ein Bestand aus einem frueheren
# Vorgang eingespielt). Ausnahme: lag die Marke schon vor diesem Lauf, ist ein
# Versuch DIESES Updates abgebrochen, womoeglich nach dem Abraeumen des
# Datenordners - dann ist die alte Sicherung der einzige Stand und bleibt.
TB_SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
TB_DATEN="$BASE/data/plugins/$PFOLDER"
if [ "$TB_MARKE_VORHER" = "0" ] && { [ -e "$TB_SICHER" ] || [ -L "$TB_SICHER" ]; }; then
    case "$TB_SICHER" in
        */data/plugins/?*.upgrade_sicherung) rm -rf "${TB_SICHER:?}" 2>/dev/null ;;
    esac
    if [ -e "$TB_SICHER" ] || [ -L "$TB_SICHER" ]; then
        echo "<FAIL> Eine Update-Sicherung aus einem frueheren Vorgang liess sich nicht entfernen: $TB_SICHER"
        echo "<FAIL> postinstall.sh spielte sie sonst zurueck. Dieses Skript endet mit Rueckgabewert 2."
        exit 2
    fi
    echo "<INFO> Eine Update-Sicherung aus einem frueheren Vorgang wurde entfernt: $TB_SICHER"
fi
if [ -d "$TB_DATEN" ]; then
    mkdir -p "$TB_SICHER" 2>/dev/null
    TB_OK=1
    TB_NAMEN=""
    if [ -d "$TB_DATEN/verlauf" ]; then
        mkdir -p "$TB_SICHER/verlauf" 2>/dev/null
        for TB_Q in "$TB_DATEN/verlauf/"*.csv; do
            [ -f "$TB_Q" ] || continue
            cp -p "$TB_Q" "$TB_SICHER/verlauf/" 2>/dev/null
            cmp -s "$TB_Q" "$TB_SICHER/verlauf/$(basename "$TB_Q")" || TB_OK=0
        done
        TB_NAMEN="$TB_NAMEN verlauf($(cat "$TB_SICHER/verlauf/"*.csv 2>/dev/null | grep -c .) Punkte)"
    fi
    for TB_Q in "$TB_DATEN/laufend.json" "$TB_DATEN/mqtt_praefixe.json" "$TB_DATEN/"bericht_*.done; do
        [ -f "$TB_Q" ] || continue
        cp -p "$TB_Q" "$TB_SICHER/" 2>/dev/null
        if cmp -s "$TB_Q" "$TB_SICHER/$(basename "$TB_Q")"; then
            TB_NAMEN="$TB_NAMEN $(basename "$TB_Q")"
        else
            TB_OK=0
        fi
    done
    if [ "$TB_OK" = 1 ] && [ -n "$TB_NAMEN" ]; then
        echo "<OK> Fuer das Update gesichert:$TB_NAMEN."
    elif [ "$TB_OK" = 1 ]; then
        echo "<INFO> Es gab keinen Preisverlauf und keine Merker zu sichern."
    else
        echo "<WARNING> Nicht alles liess sich sichern ($TB_SICHER) - Preisverlauf oder Merker koennten nach dem Update fehlen."
    fi
fi
exit 0
