#!/bin/bash
# Spotpreis Tibber - Start, Stopp und Waechter des Pulse-Dienstes.
#
# Der Dienst haelt die WebSocket-Verbindung zu Tibber offen und schreibt die
# Momentanwerte nach data/live.json. Preise und Verbrauch laufen davon
# unabhaengig im Cron - wer keine Tibber Pulse hat, braucht diesen Dienst nicht.
#
# Die Pfade werden aus dem EIGENEN Ablageort abgeleitet, nicht ueber
# LoxBerry::System: das leitet den Pluginordner aus dem Aufrufort ab und
# liefert aus postinstall.sh oder aus dem Cron heraus ueberall Leerstring.

# readlink -f loest Symlinks auf, BEVOR das Verzeichnis bestimmt wird.
# LoxBerry legt Daemons als Symlink unter system/daemons/plugins/ ab; von
# dort aufgerufen ergaebe dirname "$0" den Pfad .../system/daemons/plugins,
# der Pluginname waere buchstaeblich "plugins", und PID-Datei, Sollmerker
# und Logdatei landeten neben dem eigenen Ordner statt darin. Die
# Oberflaeche saehe den Dienst dann nie laufen, und der Waechter startete
# ihn im Minutentakt ein zweites Mal.
SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)          # <home>/bin/plugins/<ordner>
PNAME=$(basename "$SELF")
LBHOMEDIR=$(cd "$SELF/../../.." && pwd)

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
PDATA="$LBHOMEDIR/data/plugins/$PNAME"
PLOG="$LBHOMEDIR/log/plugins/$PNAME"
PCONFIG="$LBHOMEDIR/config/plugins/$PNAME"
PID="$PDATA/pulse.pid"
SOLL="$PDATA/soll_laufen"
LOGDATEI="$PLOG/tibber.log"
SKRIPT="$SELF/tb_pulse.php"

if ! mkdir -p "$PDATA" "$PLOG" 2>/dev/null; then
    echo "FEHLER: $PDATA oder $PLOG laesst sich nicht anlegen." >&2
    exit 1
fi

laeuft() {
    # local P: ohne das ueberschreibt laeuft() die Variable P ihres
    # Aufrufers - anhalten() benutzt denselben Namen. Heute wertgleich,
    # aber eine Falle fuer die naechste Aenderung.
    local P
    [ -f "$PID" ] || return 1
    P=$(cat "$PID" 2>/dev/null)
    [ -n "$P" ] || return 1
    kill -0 "$P" 2>/dev/null || return 1
    # Nummernrecycling ausschliessen - ARGUMENTWEISE.
    #
    # /proc/<pid>/cmdline trennt die Argumente mit Nullbytes. Ein grep darueber
    # trifft JEDEN Prozess, der den Pfad irgendwo fuehrt - auch einen Editor,
    # der die Datei gerade offen hat. Dann haelt der Waechter einen fremden
    # Prozess fuer den Dienst und startet nie nach.
    #
    # Zwei Bedingungen, und die zweite ist noetig: argv[1] ist genau unser
    # Skript, UND argv[0] ist ein PHP-Interpreter. Sonst wuerde "nano
    # tb_pulse.php" als laufender Dienst gelten - der Pfad steht dort ebenfalls
    # als argv[1].
    ARGS=$(tr '\0' '\n' < "/proc/$P/cmdline" 2>/dev/null)
    [ -n "$ARGS" ] || return 1
    [ "$(basename "$(echo "$ARGS" | sed -n '2p')")" = "tb_pulse.php" ] || return 1
    echo "$ARGS" | sed -n '1p' | grep -qE '(^|/)php[0-9.]*$' || return 1
    return 0
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
