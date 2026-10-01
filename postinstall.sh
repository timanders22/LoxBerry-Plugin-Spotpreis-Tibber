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

# Aktualisierung oder Neuinstallation - das sagt allein die Marke von
# preupgrade.sh (kein Altersvergleich, Entscheidung 1 und Nr. 8). Bis 0.9.24
# entschied das Vorhandensein einer Zweitschrift, und eine Neuinstallation
# spielte Tibber-Token und Aktionstoken einer frueheren Installation ein
# (Installerpruefer, Fall D). Eine liegengebliebene Zweitschrift hat
# preinstall.sh dann schon nach .alt gelegt.
TB_MARKE=0
[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && TB_MARKE=1

# ---------- Sicherungen zurueckspielen ----------
# Nur, wenn die vorhandene Datei leer ist oder fehlt. Eine bestehende
# Konfiguration wird NICHT ueberschrieben.
#
# Und nur aus einer Zweitschrift MIT Inhalt. Bis 0.9.20 wurde auch eine
# Zweitschrift ohne Inhalt kopiert und als "wiederhergestellt" gemeldet
# (gemessen 24.09.2026 in WSL, Pruefung-Spotpreis-Tibber-0.9.21, Fall c:
# token.json mit leerem Token). Inhalt heisst fuer token.json ein nicht
# leeres "token" (wie am Ende dieses Skripts und in tb_token_lesen()), fuer
# tibber.json mindestens ein nicht leerer Wert. PHP wird erst weiter unten
# verlangt - fehlt es hier, wird wie bisher kopiert (Rueckgabe 2).
tb_inhalt() {   # $1 Datei, $2 Art: token | konfig; 0 Inhalt, 1 keiner, 2 nicht pruefbar, 3 unlesbar
    command -v php >/dev/null 2>&1 || return 2
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
if (!is_array($d)) { exit(3); }
if ($argv[2] === "token") { exit(isset($d["token"]) && (string) $d["token"] !== "" ? 0 : 1); }
foreach ($d as $w) { if (is_scalar($w) && trim((string) $w) !== "") { exit(0); } }
exit(1);' -- "$1" "$2" >/dev/null 2>&1
    tb_rc=$?
    [ "$tb_rc" = 0 ] || [ "$tb_rc" = 1 ] || [ "$tb_rc" = 3 ] || return 2
    return "$tb_rc"
}
for PAAR in "tibber.json:.backup.json:konfig" "token.json:.backup.token.json:token"; do
    ZIEL="$PCONFIG/${PAAR%%:*}"
    TB_REST="${PAAR#*:}"
    QUELLE="$BASE/config/plugins/$PFOLDER${TB_REST%%:*}"
    TB_ART="${PAAR##*:}"
    # Nur bei einer Aktualisierung (Marke von preupgrade.sh).
    if [ "$TB_MARKE" = "1" ] && [ -f "$QUELLE" ]; then
        INHALT=$(cat "$ZIEL" 2>/dev/null)
        if [ ! -s "$ZIEL" ] || [ "$INHALT" = "{}" ]; then
            tb_inhalt "$QUELLE" "$TB_ART"
            TB_I=$?
            if [ "$TB_I" = 3 ]; then
                # Der Grund wird genannt (Bauliste I3): unlesbar, nicht "ohne Einstellungen".
                echo "<WARNING> $(basename "$ZIEL"): die Sicherung $(basename "$QUELLE") ist unlesbar - nichts zurueckgespielt."
            elif [ "$TB_I" = 1 ]; then
                echo "<INFO> $(basename "$ZIEL"): Sicherung ohne Einstellungen - nichts zurueckgespielt."
            else
                cp -p "$QUELLE" "$ZIEL" && chmod 600 "$ZIEL" \
                    && echo "<OK> $(basename "$ZIEL") aus der Sicherung wiederhergestellt."
            fi
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
# Nur bei einer Aktualisierung: einen Merker einer frueheren Installation hat
# preinstall.sh nach .alt gelegt (Entscheidung 1).
LIEF="$BASE/data/plugins/$PFOLDER.lief_vorher"
if [ "$TB_MARKE" = "1" ] && [ -f "$LIEF" ]; then
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

# ---------- Bestaende zurueckholen (Bauliste I2) ----------
# Gegenstueck zu preupgrade.sh. Nur bei einer Aktualisierung (Marke); eine
# Sicherung einer frueheren Installation hat preinstall.sh nach .alt gelegt.
#
# Uebernommen heisst: zurueckkopiert und mit cmp bestaetigt - oder, wenn der
# Minutentakt in der Luecke zwischen dem Kopieren und diesem Skript schon
# geschrieben hat, VEREINIGT. Der Takt holt in der Luecke Preise (die
# Taktmarker sind mit dem Datenordner weg) und schreibt dabei eine Zeile in
# die Verlaufsdatei des Monats; die Hysterese kann einen neuen Block
# eintragen. Bei aWATTar ging genau dabei die Historie verloren (Lehre aWATTar
# I4). Die Sicherung wird erst weggeraeumt, wenn ALLES uebernommen ist.
#
# Verlauf: Schluessel ist der Zeitpunkt (erste Spalte), jeder Zeitpunkt
# einmal, bei gleichem Zeitpunkt gilt die NEUE Zeile, nach Zeitpunkt sortiert.
# Geschrieben wird in eine Datei daneben mit den Rechten der bisherigen; erst
# nach der Pruefung kommt sie per mv an ihren Platz.
# Rueckgabe 0 = vereinigt und geprueft, sonst 1 (nichts veraendert).
tb_verlauf_vereinen() {
    tb_alt="$1"
    tb_neu="$2"
    tb_tmp="$2.vereint.$$"
    rm -f "${tb_tmp:?}" 2>/dev/null
    { awk -F';' '$1 ~ /^[0-9]+$/ && !($1 in s) { s[$1] = 1; print }' "$tb_neu" "$tb_alt" \
        | LC_ALL=C sort -t';' -k1,1n > "$tb_tmp"; } 2>/dev/null
    # Die Wirkung pruefen: jede Zeile der neuen Datei steht unveraendert darin,
    # jeder Zeitpunkt der gesicherten ebenfalls.
    tb_fehlt=$(awk -F';' '
        FNR == NR { z[$0] = 1; k[$1] = 1; next }
        FILENAME == ARGV[2] && $1 ~ /^[0-9]+$/ && !($0 in z) { n++ }
        FILENAME == ARGV[3] && $1 ~ /^[0-9]+$/ && !($1 in k) { n++ }
        END { print n + 0 }' "$tb_tmp" "$tb_neu" "$tb_alt" 2>/dev/null)
    if [ "$tb_fehlt" != "0" ] || [ ! -s "$tb_tmp" ]; then
        rm -f "${tb_tmp:?}" 2>/dev/null
        return 1
    fi
    chmod --reference="$tb_neu" "$tb_tmp" 2>/dev/null
    if ! mv -f "$tb_tmp" "$tb_neu" 2>/dev/null; then
        rm -f "${tb_tmp:?}" 2>/dev/null
        return 1
    fi
    return 0
}
# JSON-Dateien vereinigen: laufend.json (Regel => bis) - die inzwischen
# geschriebenen Eintraege gewinnen, die gesicherten kommen dazu;
# mqtt_praefixe.json - die Vereinigungsmenge. Rueckgabe 0 = geschrieben.
tb_json_vereinen() {
    command -v php >/dev/null 2>&1 || return 1
    php -r '$alt = json_decode((string) @file_get_contents($argv[1]), true);
$neu = json_decode((string) @file_get_contents($argv[2]), true);
if (!is_array($alt) || !is_array($neu)) { exit(1); }
if ($argv[3] === "praefixe") {
    $l = array();
    foreach (array($alt, $neu) as $d) {
        foreach ((array) (isset($d["praefixe"]) ? $d["praefixe"] : array()) as $x) {
            if (is_string($x) && $x !== "") { $l[$x] = true; }
        }
    }
    $erg = array("praefixe" => array_keys($l));
} else {
    $erg = $neu + $alt;
}
$j = json_encode($erg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$t = $argv[2] . ".vereint." . getmypid();
if ($j === false || @file_put_contents($t, $j) !== strlen($j) || !@rename($t, $argv[2])) { @unlink($t); exit(1); }
exit(0);' -- "$1" "$2" "$3" >/dev/null 2>&1
}
TB_SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
if [ "$TB_MARKE" = "1" ] && [ -d "$TB_SICHER" ]; then
    TB_GERETTET=1
    TB_NAMEN=""
    if [ -d "$TB_SICHER/verlauf" ]; then
        mkdir -p "$PDATA/verlauf" 2>/dev/null
        for TB_Q in "$TB_SICHER/verlauf/"*.csv; do
            [ -f "$TB_Q" ] || continue
            TB_Z="$PDATA/verlauf/$(basename "$TB_Q")"
            if [ -s "$TB_Z" ]; then
                tb_verlauf_vereinen "$TB_Q" "$TB_Z" || TB_GERETTET=0
            else
                cp -p "$TB_Q" "$TB_Z" 2>/dev/null
                cmp -s "$TB_Q" "$TB_Z" || TB_GERETTET=0
            fi
        done
        TB_NAMEN="$TB_NAMEN verlauf($(cat "$PDATA/verlauf/"*.csv 2>/dev/null | grep -c .) Punkte)"
    fi
    for TB_Q in "$TB_SICHER/laufend.json" "$TB_SICHER/mqtt_praefixe.json" "$TB_SICHER/"bericht_*.done; do
        [ -f "$TB_Q" ] || continue
        TB_Z="$PDATA/$(basename "$TB_Q")"
        if [ -s "$TB_Z" ]; then
            case "$(basename "$TB_Q")" in
                laufend.json)       tb_json_vereinen "$TB_Q" "$TB_Z" laufend || TB_GERETTET=0 ;;
                mqtt_praefixe.json) tb_json_vereinen "$TB_Q" "$TB_Z" praefixe || TB_GERETTET=0 ;;
                *) ;;   # bericht_*.done: die vorhandene Marke gilt
            esac
        else
            cp -p "$TB_Q" "$TB_Z" 2>/dev/null
            cmp -s "$TB_Q" "$TB_Z" || TB_GERETTET=0
        fi
        TB_NAMEN="$TB_NAMEN $(basename "$TB_Q")"
    done
    if [ "$TB_GERETTET" = "1" ]; then
        [ -n "$TB_NAMEN" ] && echo "<OK> Ueber das Update gerettet:$TB_NAMEN."
        case "$TB_SICHER" in
            */data/plugins/?*.upgrade_sicherung) rm -rf "${TB_SICHER:?}" 2>/dev/null ;;
        esac
    else
        echo "<WARNING> Preisverlauf oder Merker liessen sich nicht vollstaendig zurueckholen."
        echo "<WARNING> Die Sicherung bleibt liegen: $TB_SICHER"
    fi
fi

# ---------- Abschluss: Erstanleitung nur ohne eingetragenes Token ----------
# Dieses Skript laeuft bei der Erstinstallation UND bei jedem Upgrade (siehe
# Kopf). Bis 0.9.19 stand die Aufforderung, das Zugangstoken einzutragen,
# deshalb auch nach jedem gelungenen Upgrade da - das Token war zu dem
# Zeitpunkt laengst zurueckgespielt (gemessen 24.09.2026 in WSL,
# Pruefung-Spotpreis-Tibber-0.9.20, Fall b). Wer das liest, haelt es fuer
# verloren.
#
# Entschieden wird nach dem INHALT, nicht nach der Upgrade-Marke: steht in
# token.json ein nicht leeres "token" - dasselbe, was tb_token_lesen() in
# webfrontend/html/tb_lib.php liest und tb_token_form() als 'TOKEN.LEER'
# abweist, wenn es fehlt? Fehlt es nach einem Upgrade, ist die Rueckholung
# oben gescheitert, und dann ist die Anleitung genau richtig. PHP ist hier
# sicher da (Pruefung weiter oben).
#
# Seit dem Durchgang entscheidet ueber das Wort "Aktualisierung" die MARKE,
# nicht der Inhalt (Entscheidung 1; Installerpruefer Fall D: eine
# Neuinstallation meldete "Aktualisierung abgeschlossen"), und es wird auch
# tibber.json geprueft (Bauliste I3; Fall C: "Einstellungen uebernommen",
# waehrend tibber.json {} war).
TB_TOKEN_DA=0
if php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
exit(is_array($d) && isset($d["token"]) && (string) $d["token"] !== "" ? 0 : 1);' \
    -- "$PCONFIG/token.json" >/dev/null 2>&1; then
    TB_TOKEN_DA=1
fi
if [ "$TB_MARKE" = "1" ]; then
    tb_inhalt "$PCONFIG/tibber.json" konfig
    TB_K=$?
    if [ "$TB_TOKEN_DA" = 1 ] && [ "$TB_K" = 0 ]; then
        echo "<OK> Aktualisierung abgeschlossen, Einstellungen uebernommen."
    else
        TB_W=""
        [ "$TB_K" = 0 ] || TB_W="$TB_W Einstellungen"
        [ "$TB_TOKEN_DA" = 1 ] || TB_W="$TB_W Zugangstoken"
        echo "<WARNING> Aktualisierung abgeschlossen, aber nicht uebernommen:$TB_W."
        echo "<INFO> Bitte die Oberflaeche oeffnen und pruefen; ein Zugangstoken gibt es unter"
        echo "<INFO> developer.tibber.com im eigenen Konto."
    fi
else
    echo "<OK> Installation abgeschlossen."
    if [ "$TB_TOKEN_DA" != 1 ]; then
        echo "<INFO> Jetzt die Oberflaeche oeffnen und das persoenliche Zugangstoken"
        echo "<INFO> eintragen. Es gibt es unter developer.tibber.com im eigenen Konto."
    fi
fi
exit 0
