#!/bin/bash
# Spotpreis Tibber - Start, Stopp und Waechter des Pulse-Dienstes.
#
# Der Dienst haelt die WebSocket-Verbindung zu Tibber offen und schreibt die
# Momentanwerte nach data/live.json. Preise und Verbrauch laufen davon
# unabhaengig im Cron - wer keine Tibber Pulse hat, braucht diesen Dienst nicht.
#
# Die Pfade werden GELESEN ($LBHOMEDIR, $LBPPLUGINDIR) oder aufwaerts
# gesucht, nicht ueber LoxBerry::System: das leitet den Pluginordner aus dem
# Aufrufort ab und liefert aus postinstall.sh oder aus dem Cron heraus
# ueberall Leerstring.

# readlink -f loest Symlinks auf, BEVOR das Verzeichnis bestimmt wird.
# LoxBerry legt Daemons als Symlink unter system/daemons/plugins/ ab; von
# dort aufgerufen ergaebe dirname "$0" den Pfad .../system/daemons/plugins,
# der Pluginname waere buchstaeblich "plugins", und PID-Datei, Sollmerker
# und Logdatei landeten neben dem eigenen Ordner statt darin. Die
# Oberflaeche saehe den Dienst dann nie laufen, und der Waechter startete
# ihn im Minutentakt ein zweites Mal.
SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)          # <home>/bin/plugins/<ordner>

# Von root absteigen, BEVOR irgendetwas angelegt wird.
#
# Der minuetliche Waechter kommt aus dem Cron, und je nach Ablage laeuft der
# als root. Danach gehoerten PID-Datei, Sollmerker und Protokoll root - die
# Oberflaeche laeuft aber als loxberry und koennte den Dienst dann nicht mehr
# anhalten. Ein Startskript, das als root laufen KANN, ist deshalb eine Falle;
# gebraucht wird root hier nie, denn alles unter bin/, data/, config/ und log/
# des Plugins gehoert loxberry.
if [ "$(id -u)" = "0" ] && id loxberry >/dev/null 2>&1; then
    exec su -s /bin/bash loxberry -c "$(printf '%q ' "$0" "$@")"
fi

# ---------- Wurzel und Ordnername: GELESEN, nicht geraten ----------
#
# Bis 0.9.17 stand hier
#     PNAME=$(basename "$SELF")
#     LBHOMEDIR=$(cd "$SELF/../../.." && pwd)
# und weiter unten ein 'mkdir -p' auf oberster Ebene. Ein gesetztes
# $LBHOMEDIR wurde damit UEBERSCHRIEBEN, der Ordnername kam allein aus dem
# Ablageort, und der geratene Pfad wurde bei JEDEM Aufruf angelegt - auch
# bei 'status'. In WSL gemessen (24.09.2026, Pruefung-Spotpreis-Tibber-0.9.18,
# messung_h1_vorher.txt): 'status' aus einem Pruefarchiv unter
# <Wurzel>/pruefung/<ordner>/bin legte in der Installation data/plugins/bin
# an (Fall H4), 'stop' aus einem ausgepackten Archiv liess den Dienst der
# Anlage laufen und meldete Erfolg (H3, H10), und in einem fremden Baum ohne
# general.json startete 'start' einen Dienst (F1).
#
# Zwei Stufen, danach NICHTS mehr (Regeln/06; Bauart wie VolkswagenID
# 0.9.24 und Govee 0.9.20):
#   1. $LBHOMEDIR aus der Umgebung, wenn es config/plugins und data/plugins
#      traegt - am Geraet steht es in /etc/environment;
#   2. aufwaerts suchen, bis ein Verzeichnis config/plugins, data/plugins UND
#      config/system/general.json traegt. Die dritte Bedingung stammt aus dem
#      Raumklima-Vorfall (Regeln/06): eine Suche ueber die ersten beiden
#      allein trifft auf einem Arbeitsrechner das Laufwerk selbst.
# Eine dritte Stufe "drei Ebenen ueber dem Ablageort" gibt es nicht - genau
# dieser Rueckfall machte die Suche in sechs Linien wirkungslos
# (Stand-Protokolle/2026-09-18_Welle1, "Neue Lehre fuer alle H1-Linien").
# Die Wurzel steht in WURZEL; ein gesetztes $LBHOMEDIR wird nie ueberschrieben.
# 'pwd -P': ist die Wurzel ein Verweis, zaehlt der aufgeloeste Pfad - so
# steht er auch in der Befehlszeile des Dienstes, denn SELF ist ueber
# readlink -f ebenfalls aufgeloest (Fall G4).
tb_wurzel_suchen() {
    tb_v="$SELF"
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
if [ -n "${LBHOMEDIR:-}" ] && [ -d "$LBHOMEDIR/config/plugins" ] \
   && [ -d "$LBHOMEDIR/data/plugins" ]; then
    WURZEL=$(cd "$LBHOMEDIR" && pwd -P)
else
    WURZEL=$(tb_wurzel_suchen) || WURZEL=""
fi
# Ohne Wurzel: nichts anlegen, nichts starten, nichts anhalten. "status"
# antwortet mit 4 ("Zustand unbekannt"), damit es sich von 1 ("gestoppt")
# unterscheidet; alles andere mit 1. Die Meldung geht nur auf die Ausgabe -
# ohne Wurzel gibt es keine Protokolldatei (Faelle F1 bis F4).
if [ -z "$WURZEL" ]; then
    echo "FEHLER: Es wurde kein LoxBerry-Wurzelverzeichnis gefunden."
    echo "FEHLER: \$LBHOMEDIR ist nicht gesetzt, und oberhalb von $SELF traegt"
    echo "FEHLER: kein Verzeichnis config/plugins, data/plugins und config/system/general.json."
    echo "FEHLER: Es wurde nichts angelegt, nichts gestartet und nichts angehalten."
    [ "${1:-}" = "status" ] && exit 4
    exit 1
fi
# Der Ordnername kommt aus $LBPPLUGINDIR, sonst aus dem Ablageort. Am Geraet
# steht $LBPPLUGINDIR in keiner Cron-Schale (Regeln/06, "Pfade sind die aus
# /etc/environment") - dann traegt der Ablageort, und bei einer regulaeren
# Installation ist das richtig.
PNAME="${LBPPLUGINDIR:-}"
PNAME="${PNAME%/}"
PNAME="${PNAME##*/}"
[ -n "$PNAME" ] || PNAME=$(basename "$SELF")
PBIN="$WURZEL/bin/plugins/$PNAME"

# Die Gegenprobe steht VOR allem, was schreibt (Vorbild VolkswagenID 0.9.24,
# Govee 0.9.20): liegt dieses Skript nicht im bin-Ordner der Anlage, und ist
# <ordner> dort auch kein eingerichtetes Plugin, dann kommt der Aufruf aus
# einem ausgepackten Archiv oder einem Pruefordner - es wird nichts angelegt
# und nichts angefasst (Faelle H1, H4, H5, H8, H10).
if [ "$SELF" != "$(readlink -f "$PBIN" 2>/dev/null)" ] \
   && [ ! -d "$WURZEL/config/plugins/$PNAME" ]; then
    echo "FEHLER: '$PNAME' ist unter $WURZEL kein eingerichtetes Plugin,"
    echo "        und $SELF ist nicht dessen bin-Ordner."
    echo "        Der Aufruf kommt offenbar aus einem ausgepackten Archiv oder"
    echo "        einem Pruefordner. Es wurde nichts angelegt."
    echo "        Abhilfe: LBHOMEDIR und LBPPLUGINDIR setzen oder dienst.sh"
    echo "        aus <LoxBerry-Wurzel>/bin/plugins/<ordner> aufrufen."
    [ "${1:-}" = "status" ] && exit 4
    exit 1
fi

PDATA="$WURZEL/data/plugins/$PNAME"
PLOG="$WURZEL/log/plugins/$PNAME"
PCONFIG="$WURZEL/config/plugins/$PNAME"
PID="$PDATA/pulse.pid"
SOLL="$PDATA/soll_laufen"
LOGDATEI="$PLOG/tibber.log"
# Das Dienstskript DER ANLAGE, nicht das neben dieser Datei. Sonst
# verwaltete ein dienst.sh aus einem ausgepackten Archiv den Dienst des
# Archivs, waehrend der Aufrufer mit LBHOMEDIR/LBPPLUGINDIR die Anlage
# meinte (Faelle H2, H3). Installiert ist PBIN derselbe Ordner wie SELF.
SKRIPT="$PBIN/tb_pulse.php"

# Die Marke einer laufenden Aktualisierung.
#
# Sie liegt NEBEN dem Datenordner, nicht darin: purge_installation raeumt
# data/plugins/<ordner>/ beim Upgrade vollstaendig ab (Regeln/06), ein
# Geschwister mit Punkt im Namen ueberlebt das. preupgrade.sh legt sie als
# Erstes an, postroot.sh entfernt sie als Letztes, uninstall raeumt sie weg.
MARKE="$WURZEL/data/plugins/$PNAME.upgrade_laeuft"

# Angelegt wird erst beim START - in starten() nach der Markenpruefung und
# im Waechter, bevor er ins Protokoll schreibt -, nicht bei jedem Aufruf. Bis
# 0.9.17 stand dieses mkdir auf oberster Ebene: 'status' legte nach einem
# purge_installation den Datenordner wieder an (Faelle H6, H7). Der Waechter
# braucht es trotzdem: log/plugins ist eine Ramdisk; fehlt der Ordner nach
# einem Neustart, liefe die Umlenkung ins Leere (Fall G8).
ordner_anlegen() {
    if ! mkdir -p "$PDATA" "$PLOG" 2>/dev/null; then
        echo "FEHLER: $PDATA oder $PLOG laesst sich nicht anlegen." >&2
        return 1
    fi
    return 0
}

# Ist <pid> UNSER Pulse-Dienst? ARGUMENTWEISE ueber /proc/<pid>/cmdline.
#
# /proc/<pid>/cmdline trennt die Argumente mit Nullbytes. Ein Treffer hat
# GENAU zwei Argumente: argv[0] ist ein PHP-Interpreter, argv[1] ist
# zeichengenau das Dienstskript DIESER Anlage (relativ gestartet gegen
# /proc/<pid>/cwd aufgeloest, zusaetzlich ueber readlink -f verglichen), und
# der Prozess gehoert dem Dienstbenutzer (loxberry, wo es ihn nicht gibt der
# eigene). Bis 0.9.17 genuegte der DATEINAME von argv[1]: unter der Nummer aus
# der PID-Datei galt ein "php <andere Installation>/tb_pulse.php" oder ein
# "php <dienstpfad> --einmal" als Dienst - status meldete "laeuft", start
# startete nichts, stop beendete den fremden Prozess (in WSL gemessen,
# Pruefung-Spotpreis-Tibber-0.9.18, messe_prozess.sh, Faelle P2, P3, P5).
# Bauart wie AudiConnect 0.9.20 (ist_unser_dienst) und Einspeisebremse
# 0.9.22 (laeuft). Umleitungen stehen VOR dem Lesen: endet der Prozess
# zwischen Pruefung und Lesen, darf keine Meldung der Schale in der Ausgabe
# von stop stehen (Einspeisebremse 0.9.22).
DIENST_UID=$(id -u loxberry 2>/dev/null || id -u)
SKRIPT_R=$(readlink -f "$SKRIPT" 2>/dev/null)
[ -n "$SKRIPT_R" ] || SKRIPT_R="$SKRIPT"
tb_ist_dienst() {
    local a0 a1 a2 ziel wd
    [ -r "/proc/$1/cmdline" ] || return 1
    [ "$(stat -c %u "/proc/$1" 2>/dev/null)" = "$DIENST_UID" ] || return 1
    a0=""; a1=""; a2=""
    { IFS= read -r -d "" a0; IFS= read -r -d "" a1; IFS= read -r -d "" a2; } 2>/dev/null < "/proc/$1/cmdline"
    [ -n "$a0" ] && [ -n "$a1" ] && [ -z "$a2" ] || return 1
    case "${a0##*/}" in php|php[0-9]*) ;; *) return 1 ;; esac
    case "$a1" in
        /*) ziel="$a1" ;;
        *)  wd=$(readlink "/proc/$1/cwd" 2>/dev/null) || return 1
            ziel="${wd% (deleted)}/$a1" ;;
    esac
    [ "$ziel" = "$SKRIPT" ] && return 0
    [ "$(readlink -f "$ziel" 2>/dev/null)" = "$SKRIPT_R" ]
}

laeuft() {
    # local P: ohne das ueberschreibt laeuft() die Variable P ihres
    # Aufrufers - anhalten() benutzt denselben Namen. Heute wertgleich,
    # aber eine Falle fuer die naechste Aenderung.
    local P
    [ -f "$PID" ] || return 1
    P=$(cat "$PID" 2>/dev/null)
    [ -n "$P" ] || return 1
    case "$P" in *[!0-9]*) return 1 ;; esac
    kill -0 "$P" 2>/dev/null || return 1
    tb_ist_dienst "$P"
}

# Laeuft gerade eine Aktualisierung dieses Plugins?
#
# Gemessen am Geraet an der Einspeisebremse (Regeln/06): zwischen der neuen
# Cron-Datei und postinstall.sh liegen fast 60 Sekunden. In dieser Luecke sind
# config/plugins/<ordner>/ und data/plugins/<ordner>/ bereits geloescht - ein
# Dienst, der dort anlaeuft, arbeitet ohne Einstellungen und ohne Token.
#
# Drei Regeln, alle drei aus Regeln/06:
#   * Nur eine Marke, die hoechstens 3600 s alt ist, gilt. Eine abgebrochene
#     Installation darf den Dienst nicht fuer immer stilllegen.
#   * Eine Marke aus der ZUKUNFT gilt nicht (Uhr nachgestellt) - erst ab
#     300 s. Bis dahin gilt sie noch: die Uhr kann ein Stueck zurueckspringen,
#     nachdem preupgrade.sh die Marke gesetzt hat (in WSL gemessen,
#     Pruefung-VolkswagenID-0.9.23/messprotokoll_uhr.txt: die Wanduhr sprang
#     rund alle 30 s um 0,6 s zurueck). Mit der strengen Regel bis 0.9.17 fiel
#     eine 2 s vorauseilende Marke aus, und der Dienst startete mitten in der
#     Aktualisierung (Pruefung-Spotpreis-Tibber-0.9.18, Faelle V1, V2).
#   * OHNE LESBARE UHR FAELLT DIE PRUEFUNG GESCHLOSSEN AUS. Liefert "date"
#     nichts - unter Last kann ein fork scheitern -, rechnete die Schale mit
#     einer leeren Zeichenkette, das Alter wuerde negativ, die Bedingung fiele
#     durch, und der Dienst startete mitten in der Aktualisierung. Ein Schutz
#     faellt geschlossen aus (CLAUDE.md, Abschnitt 4).
marke_gilt() {
    [ -f "$MARKE" ] || return 1
    local SEIT JETZT ALTER
    SEIT=$(cat "$MARKE" 2>/dev/null)
    case "$SEIT" in
        ''|*[!0-9]*) SEIT=0 ;;
    esac
    JETZT=$(date +%s 2>/dev/null)
    case "$JETZT" in
        ''|*[!0-9]*) return 0 ;;   # keine lesbare Uhr -> die Marke gilt
    esac
    ALTER=$((JETZT - SEIT))
    [ "$ALTER" -ge -300 ] && [ "$ALTER" -lt 3600 ] && return 0
    return 1
}

# Eine Sperre um das Starten.
#
# Zwischen "laeuft?" und dem Schreiben der PID-Datei liegt ein Fenster ohne
# jede Absicherung. cron.01min ruft in DERSELBEN Zeile erst tb_cron.php und
# dann diesen Waechter; braucht der Cron laenger als eine Minute (die
# Zeitschranke je Abruf steht bei bis zu 60 s), laufen zwei Waechter dicht
# hintereinander. Das Ergebnis waeren zwei Pulse-Dienste, zwei WebSocket-
# Verbindungen zu Tibber und zwei Prozesse, die live.json schreiben - die
# PID-Datei zeigt nur auf den zweiten, der erste ist ein Waise, den niemand
# je anhaelt. tb_cron.php begruendet seine eigene Sperre genauso.
#
# flock ist auf einem LoxBerry (util-linux) vorhanden; fehlt es, wird ohne
# Sperre weitergemacht statt gar nicht zu starten - eine fehlende Sperre ist
# schlechter als keine Sperre, aber besser als ein toter Dienst.
sperre_holen() {
    command -v flock >/dev/null 2>&1 || return 0
    exec 9>"$PDATA/dienst.lock" 2>/dev/null || return 0
    flock -n 9 || return 1
    return 0
}

starten() {
    # Die Marke VOR allem anderen - auch vor der Sperrdatei. Sonst legte
    # dieser Aufruf noch data/plugins/<ordner>/dienst.lock in einem Ordner an,
    # den purge_installation gerade geloescht hat (am 18.09.2026 in WSL
    # gemessen: "dienst.lock" war nach einem Startversuch in der Luecke das
    # einzige, was im frisch angelegten Datenordner stand).
    #
    # TB_START_TROTZ_MARKE=1 setzt allein postinstall.sh. Dort ist die Marke
    # die eigene, und der Start ist der vorgesehene Abschluss der
    # Aktualisierung; die Marke selbst faellt erst in postroot.sh, also NACH
    # diesem Start. Ohne die Ausnahme bliebe der Dienst bis zum naechsten
    # Waechterlauf aus.
    if [ "${TB_START_TROTZ_MARKE:-0}" != "1" ] && marke_gilt; then
        echo "eine Aktualisierung dieses Plugins laeuft - der Dienst wird danach gestartet"
        return 0
    fi
    ordner_anlegen || return 1
    if ! sperre_holen; then
        echo "ein anderer Aufruf startet gerade - dieser Lauf tut nichts"
        return 0
    fi
    if laeuft; then
        echo "laeuft bereits (PID $(cat "$PID"))"
        return 0
    fi
    if ! command -v php >/dev/null 2>&1; then
        echo "FEHLER: PHP nicht gefunden - ohne PHP laeuft der Dienst nicht."
        return 1
    fi
    if [ ! -f "$SKRIPT" ]; then
        echo "FEHLER: $SKRIPT fehlt. Plugin neu installieren."
        return 1
    fi
    if [ ! -s "$PCONFIG/token.json" ]; then
        echo "FEHLER: Es ist kein Tibber-Token hinterlegt. Erst die Oberflaeche oeffnen."
        return 1
    fi
    touch "$SOLL"
    # Ausgabe geht in die Logdatei. Das PHP-Skript protokolliert deshalb NICHT
    # zusaetzlich nach stdout - sonst stuende jede Zeile doppelt darin.
    nohup php "$SKRIPT" >> "$LOGDATEI" 2>&1 &
    echo $! > "$PID"
    sleep 1
    if laeuft; then
        echo "gestartet (PID $(cat "$PID"))"
        return 0
    fi
    echo "FEHLER: Start fehlgeschlagen - siehe $LOGDATEI"
    rm -f "$PID"
    return 1
}

# Der Waechter mit Rueckzug.
#
# Bis 0.9.9 versuchte der Waechter jede Minute neu und schrieb dabei je
# Versuch mindestens zwei Zeilen ins Protokoll - unter Umgehung der Kappung,
# denn die greift nur, wenn PHP schreibt; hier schreibt die Schale mit >>.
# Startet der Dienst dauerhaft nicht (kaputte token.json, fehlendes PHP),
# waren das 1440 Versuche und rund 3000 Zeilen am Tag.
#
# Der Abstand verdoppelt sich: 1, 2, 4 ... hoechstens 60 Minuten. Beim
# ersten geglueckten Start faellt er auf 1 zurueck. Ist der Zaehler
# unlesbar, gilt der GROESSTE Abstand - ein Rueckfall auf "also jede
# Minute" waere genau die Untergrenze, die REGELN_1 verbietet.
WARTE="$PDATA/.waechter_warte"
waechter_lauf() {
    [ -f "$SOLL" ] || return 0
    # Die Marke schon HIER, nicht erst in starten(). Sonst schriebe der
    # Waechter erst seine Zeile "Pulse-Dienst lief nicht, wird neu gestartet"
    # und stiege danach wortlos aus - eine Protokollzeile, die etwas anderes
    # behauptet als das, was geschah. Der Rueckzugszaehler wird dabei nicht
    # angefasst: eine Aktualisierung ist kein fehlgeschlagener Start.
    if marke_gilt; then return 0; fi
    if laeuft; then
        rm -f "$WARTE"
        return 0
    fi
    # Keine Datei heisst ERSTER Versuch - der laeuft sofort.
    # Eine Datei mit Unsinn darin heisst UNLESBAR - dann gilt der GROESSTE
    # Abstand, nicht der kleinste. Eine Untergrenze waere hier kein Fail
    # safe, sondern ein Zuschlagen im Minutentakt (REGELN_1, Abschnitt 4).
    if [ -f "$WARTE" ]; then
        N=$(cat "$WARTE" 2>/dev/null)
        case "$N" in
            ''|*[!0-9]*) N=60 ;;
        esac
        [ "$N" -lt 1 ] && N=60
        [ "$N" -gt 60 ] && N=60
    else
        N=1
    fi
    LETZTER=$(cat "$PDATA/.waechter_zeit" 2>/dev/null)
    case "$LETZTER" in
        ''|*[!0-9]*) LETZTER=0 ;;
    esac
    JETZT=$(date +%s)
    if [ "$LETZTER" -gt 0 ] && [ $((JETZT - LETZTER)) -lt $((N * 60)) ]; then
        return 0
    fi
    ordner_anlegen || return 1
    echo "$JETZT" > "$PDATA/.waechter_zeit"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Pulse-Dienst lief nicht, wird neu gestartet." >> "$LOGDATEI"
    if starten >> "$LOGDATEI" 2>&1; then
        rm -f "$WARTE" "$PDATA/.waechter_zeit"
    else
        NEU=$((N * 2))
        [ "$NEU" -gt 60 ] && NEU=60
        echo "$NEU" > "$WARTE"
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: naechster Versuch fruehestens in $NEU Minute(n)." >> "$LOGDATEI"
    fi
}

anhalten() {
    rm -f "$SOLL"
    if ! laeuft; then
        rm -f "$PID"
        echo "laeuft nicht"
        return 0
    fi
    P=$(cat "$PID")
    # SIGTERM, damit der Dienst die Verbindung ordentlich schliessen kann.
    kill "$P" 2>/dev/null
    for i in 1 2 3 4 5 6 7 8 9 10; do
        laeuft || break
        sleep 1
    done
    # Auch vor kill -9 wieder die volle Probe - laeuft() prueft argumentweise.
    if laeuft; then
        kill -9 "$P" 2>/dev/null
        sleep 1
    fi
    rm -f "$PID"
    echo "angehalten"
    return 0
}

case "$1" in
    start)   starten ;;
    stop)    anhalten ;;
    restart) anhalten; sleep 1; starten ;;
    status)
        if laeuft; then
            echo "laeuft $(cat "$PID")"
            exit 0
        fi
        echo "gestoppt"
        exit 1
        ;;
    waechter)
        # Nur neu starten, wenn der Dienst laufen SOLL. Ein bewusst
        # angehaltener Dienst bleibt angehalten. Der Rueckzug steckt in
        # waechter_lauf().
        waechter_lauf
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status|waechter}"
        exit 2
        ;;
esac
