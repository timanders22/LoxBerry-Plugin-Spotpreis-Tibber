#!/bin/bash
# Spotpreis Tibber - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Reines PHP - keine virtuelle Python-Umgebung, kein PEP-668-Umweg.
#
# Laeuft ohne Bedingung: der Installer fuehrt postinstall bei Erst- UND
# Neuinstallation aus. Es wird deshalb NICHT aus postupgrade.sh heraus noch
# einmal aufgerufen - das ergaebe zwei Durchlaeufe.

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
    echo "<WARNING> Es wurde nichts angelegt und nichts zurueckgespielt."
    exit 1
fi

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"

mkdir -p "$PDATA/verlauf" "$PLOG" "$PCONFIG" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null

# ---------- Sicherungen zurueckspielen ----------
# Nur, wenn die vorhandene Datei leer ist oder fehlt. Eine bestehende
# Konfiguration wird NICHT ueberschrieben.
for PAAR in "tibber.json:.backup.json" "token.json:.backup.token.json"; do
    ZIEL="$PCONFIG/${PAAR%%:*}"
    QUELLE="$BASE/config/plugins/$PFOLDER${PAAR##*:}"
    if [ -f "$QUELLE" ]; then
        INHALT=$(cat "$ZIEL" 2>/dev/null)
        if [ ! -s "$ZIEL" ] || [ "$INHALT" = "{}" ]; then
            cp -p "$QUELLE" "$ZIEL" && chmod 600 "$ZIEL" \
                && echo "<OK> $(basename "$ZIEL") aus der Sicherung wiederhergestellt."
        fi
    fi
done
[ -f "$PCONFIG/tibber.json" ] || echo '{}' > "$PCONFIG/tibber.json"
[ -f "$PCONFIG/token.json" ]  || echo '{}' > "$PCONFIG/token.json"
chmod 600 "$PCONFIG/tibber.json" "$PCONFIG/token.json" 2>/dev/null

# ---------- PHP und Erweiterungen ----------
if ! command -v php >/dev/null 2>&1; then
    echo "<FAIL> Es wurde kein PHP gefunden. Ohne PHP laeuft weder die Oberflaeche"
    echo "<FAIL> noch der Abruf."
    exit 1
fi
echo "<INFO> PHP: $(php -v 2>/dev/null | head -1)"

# sockets steht hier seit 0.9.10 nicht mehr: keine Zeile des Plugins benutzt
# eine Funktion aus ext/sockets, der Weg zum MQTT-Gateway laeuft ueber
# stream_socket_client() aus dem PHP-Kern.
for ERW in curl openssl; do
    if php -r "exit(extension_loaded('$ERW') ? 0 : 1);" 2>/dev/null; then
        echo "<OK> PHP-Erweiterung $ERW vorhanden."
    else
        case "$ERW" in
            # KEINE Handanweisung mit sudo hier.
            #
            # postroot.sh laeuft unmittelbar danach als root und installiert
            # genau diese beiden selbst - unter dem gemessenen Paketnamen
            # (php7.4-curl statt php-curl). Im Protokoll stand bis 0.9.9 erst
            # "mach das selbst mit php-curl" und wenige Zeilen spaeter
            # "php7.4-curl eingerichtet". Wer der ersten Anweisung folgte,
            # installierte das Metapaket, vor dem postroot.sh ausdruecklich
            # warnt: es zeigt auf die Vorgabefassung der Paketquelle, und die
            # ist auf einem Debian 12 mit sury PHP 8.x - waehrend LoxBerry
            # 7.4 faehrt.
            curl)
                echo "<INFO> PHP-Erweiterung curl fehlt. Der Abruf laeuft dann ueber"
                echo "<INFO> file_get_contents - das geht, ist aber der Ersatzweg."
                echo "<INFO> postroot versucht gleich, sie nachzuinstallieren." ;;
            openssl)
                echo "<INFO> PHP-Erweiterung openssl fehlt. Die Echtzeitwerte der Tibber"
                echo "<INFO> Pulse brauchen sie; Preise und Verbrauch laufen ohne sie weiter."
                echo "<INFO> postroot versucht gleich, sie nachzuinstallieren." ;;
        esac
    fi
done

chmod 755 "$PBIN/dienst.sh" "$PBIN/tb_cron.php" "$PBIN/tb_pulse.php" "$PBIN/healthcheck" 2>/dev/null
chmod 600 "$PCONFIG/tibber.json" "$PCONFIG/token.json" 2>/dev/null

# Kein chown: postinstall.sh laeuft als Benutzer loxberry, und der kann keine
# Eigentuemer aendern. Der Aufruf scheiterte hier IMMER - mit 2>/dev/null sah
# es niemand. Ein Befehl, der genau dann nichts tut, wenn er gebraucht wuerde,
# sieht nach Absicherung aus und ist keine. Gebraucht wird er ohnehin nicht:
# der Installateur legt alles unter bin/, data/, config/ und log/ des Plugins
# bereits als loxberry an.

# ---------- Lief der Pulse-Dienst vor dem Update? ----------
# Der Merker kommt aus preupgrade.sh und liegt NEBEN dem Datenordner - der
# Ordner selbst ist beim Upgrade weg (purge_installation im Upgrade-Zweig).
# Gestartet wird nur, wenn er wirklich lief: ein bewusst angehaltener Dienst
# bleibt angehalten.
#
# TB_START_TROTZ_MARKE=1: dienst.sh startet nicht, solange die Marke aus
# preupgrade.sh gilt. Hier ist sie die EIGENE - dieser Start ist der
# vorgesehene Abschluss der Aktualisierung. Entfernt wird die Marke erst in
# postroot.sh, also NACH diesem Start; so sieht kein Waechterlauf in dem
# Augenblick, in dem der neue Dienst noch nicht dasteht, weder Marke noch
# Dienst (bei Chromecast4lox 1.3.10 mit 400 Waechterlaeufen gemessen: die
# umgekehrte Reihenfolge ergab vier Dienste, diese Reihenfolge einen).
LIEF="$BASE/data/plugins/$PFOLDER.lief_vorher"
if [ -f "$LIEF" ]; then
    rm -f "$LIEF"
    if [ -x "$PBIN/dienst.sh" ]; then
        if TB_START_TROTZ_MARKE=1 "$PBIN/dienst.sh" start >/dev/null 2>&1; then
            echo "<OK> Der Pulse-Dienst lief vor dem Update und wurde neu gestartet."
        else
            echo "<INFO> Der Pulse-Dienst lief vor dem Update, liess sich aber nicht"
            echo "<INFO> starten. Reiter Einstellungen, Knopf Dienst starten."
        fi
    fi
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Plugin installiert oder aktualisiert." \
    >> "$PLOG/tibber.log" 2>/dev/null

echo "<OK> Installation abgeschlossen."
echo "<INFO> Jetzt die Oberflaeche oeffnen und das persoenliche Zugangstoken"
echo "<INFO> eintragen. Es gibt es unter developer.tibber.com im eigenen Konto."
exit 0
