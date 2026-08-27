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
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
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

for ERW in curl openssl sockets; do
    if php -r "exit(extension_loaded('$ERW') ? 0 : 1);" 2>/dev/null; then
        echo "<OK> PHP-Erweiterung $ERW vorhanden."
    else
        case "$ERW" in
            curl)
                echo "<INFO> PHP-Erweiterung curl fehlt. Der Abruf laeuft dann ueber"
                echo "<INFO> file_get_contents - das geht, ist aber der Ersatzweg."
                echo "<INFO> Nachholen mit: sudo apt install php-curl" ;;
            openssl)
                echo "<INFO> PHP-Erweiterung openssl fehlt. Die Echtzeitwerte der Tibber"
                echo "<INFO> Pulse brauchen sie; Preise und Verbrauch laufen ohne sie weiter."
                echo "<INFO> Nachholen mit: sudo apt install php-openssl" ;;
            sockets)
                echo "<INFO> PHP-Erweiterung sockets fehlt. Ohne sie wird nichts ueber"
                echo "<INFO> MQTT veroeffentlicht. Nachholen mit: sudo apt install php-sockets" ;;
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
LIEF="$BASE/data/plugins/$PFOLDER.lief_vorher"
if [ -f "$LIEF" ]; then
    rm -f "$LIEF"
    if [ -x "$PBIN/dienst.sh" ]; then
        if "$PBIN/dienst.sh" start >/dev/null 2>&1; then
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
