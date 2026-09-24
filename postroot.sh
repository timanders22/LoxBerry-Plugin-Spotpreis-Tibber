#!/bin/bash
# Spotpreis Tibber - postroot
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# postroot ist das LETZTE Skript, das LoxBerry ruft, und eines von zweien, die
# als root laufen. Es tut genau eine Sache: die beiden PHP-Erweiterungen
# nachinstallieren, die das Plugin gebrauchen kann.
#
# ---------------------------------------------------------------------------
# Warum nicht dpkg/apt
# ---------------------------------------------------------------------------
# Naheliegend waere eine Zeile "php-curl" in dpkg/apt. Das Metapaket zeigt aber
# auf die VORGABEFASSUNG der Paketquelle - auf einem Debian 12 mit sury also
# auf PHP 8.x, waehrend LoxBerry 3.x und 4.x mit PHP 7.4 fahren. Die
# Erweiterung landete dann bei einem Interpreter, den weder Apache noch die
# Kommandozeile benutzen, und die Installation meldete trotzdem Erfolg.
#
# Deshalb wird die Fassung GEMESSEN und danach installiert. Belegt in
# REGELN_2 an der SignalBot-Sitzung, dort fuer php-mbstring.
#
# ---------------------------------------------------------------------------
# Was NICHT mehr gebraucht wird
# ---------------------------------------------------------------------------
# php-sockets stand bis 0.9.6 auf dieser Liste. Seit 0.9.7 veroeffentlicht das
# Plugin ueber stream_socket_client(), und das gehoert zum PHP-Kern - die
# Erweiterung ist damit ueberfluessig. Eine Abhaengigkeit, die man streichen
# kann, ist besser als eine, die man nachinstalliert.
#
# curl und openssl sind BEIDE freiwillig: ohne curl laeuft der Abruf ueber
# file_get_contents (der Ersatzweg wird angezeigt), ohne openssl gibt es nur
# keine Echtzeitwerte der Pulse. Schlaegt hier etwas fehl, ist das deshalb ein
# Hinweis und keine Warnung: das Plugin ist vollstaendig installiert.

# ---------------------------------------------------------------------------
# Die Marke aus preupgrade.sh - hier faellt sie, und zwar ueber einen trap
# ---------------------------------------------------------------------------
# postroot.sh ist das LETZTE Skript, das LoxBerry ruft (Reihenfolge nach
# Regeln/06: preroot, preinstall, preupgrade, postinstall, postupgrade,
# postroot). Der Dienst ist zu diesem Zeitpunkt bereits gestartet -
# postinstall.sh tut es mit TB_START_TROTZ_MARKE=1. Die Marke lag also
# waehrend des ganzen Starts und hat jeden anderen Starter abgewiesen.
#
# Warum ein trap und nicht eine Zeile am Dateiende: dieses Skript steigt an
# fuenf Stellen mit "exit 0" aus - zweimal ueber exit_hinweis(), einmal wenn
# beide Erweiterungen vorhanden sind, einmal wenn es nicht als root laeuft.
# Ohne den trap bliebe die Marke in genau diesen Faellen liegen und sperrte
# den Dienst eine Stunde lang, ohne dass irgendwo stuende, warum. Dieselbe
# Begruendung wie bei der Sprachsteuerung (Regeln/06, Nachtrag 17.09.2026).
# Gemessen (bash 5.2): eine Kommandoersetzung und eine Unterschale loesen den
# EXIT-Trap nicht aus - die Marke faellt also nicht zu frueh.
TB_ARGV3=$3
TB_ARGV5=$5
TB_PFOLDER="${TB_ARGV3:-spotpreistibber}"
TB_BASE="${TB_ARGV5:-$LBHOMEDIR}"
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
if [ -z "$TB_BASE" ] || [ ! -d "$TB_BASE/config/plugins" ] || [ ! -d "$TB_BASE/data/plugins" ]; then
    TB_BASE=$(tb_wurzel_suchen "$(dirname "$(readlink -f "$0")")") || TB_BASE=""
fi
# Ohne Wurzel wird die Marke NICHT angefasst (Fall K11) - ein rm auf einen
# geratenen Pfad loeschte die Marke eines fremden Baums. Die PHP-Erweiterungen
# darunter brauchen keine Wurzel und laufen weiter.
TB_MARKE=""
if [ -n "$TB_BASE" ]; then
    TB_MARKE="$TB_BASE/data/plugins/$TB_PFOLDER.upgrade_laeuft"
else
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden - die Marke"
    echo "<WARNING> der Aktualisierung wird deshalb nicht entfernt. Sie verfaellt nach"
    echo "<WARNING> einer Stunde von selbst (bin/dienst.sh)."
fi
tb_marke_weg() {
    [ -n "$TB_MARKE" ] || return 0
    rm -f "$TB_MARKE" 2>/dev/null
}
trap tb_marke_weg EXIT

exit_hinweis() {
    echo "<INFO> ------------------------------------------------------------"
    echo "<INFO> $1"
    exit 0
}

if ! command -v php >/dev/null 2>&1; then
    exit_hinweis "Es wurde kein PHP gefunden - die Erweiterungen wurden nicht angefasst."
fi

PHPV=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null)
case "$PHPV" in
    [0-9].[0-9]*) ;;
    *) exit_hinweis "Die PHP-Fassung liess sich nicht ermitteln - die Erweiterungen wurden nicht angefasst." ;;
esac
echo "<INFO> Gemessene PHP-Fassung: $PHPV"

FEHLT=""
for ERW in curl openssl; do
    if php -r "exit(extension_loaded('$ERW') ? 0 : 1);" 2>/dev/null; then
        echo "<OK> PHP-Erweiterung $ERW ist vorhanden."
    else
        FEHLT="$FEHLT $ERW"
    fi
done

if [ -z "$FEHLT" ]; then
    echo "<OK> Beide PHP-Erweiterungen sind vorhanden - es gibt nichts nachzuholen."
    exit 0
fi

if [ "$(id -u)" != "0" ]; then
    echo "<INFO> Es fehlen:$FEHLT - dieses Skript laeuft aber nicht als root und"
    echo "<INFO> kann sie nicht nachinstallieren. Von Hand:"
    for E in $FEHLT; do echo "<INFO>   sudo apt install php$PHPV-$E"; done
    exit 0
fi

for E in $FEHLT; do
    PAKET="php$PHPV-$E"
    echo "<INFO> Installiere $PAKET ..."
    # --force-confold und --force-confdef: bei einer geaenderten
    # Konfigurationsdatei fragt dpkg nach, und in einem nicht interaktiven Lauf
    # entschiede sonst eine Vorgabe ueber die Datei des Anwenders.
    if DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
            -o Dpkg::Options::=--force-confold \
            -o Dpkg::Options::=--force-confdef "$PAKET" >/dev/null 2>&1; then
        # Die WIRKUNG melden, nicht den Rueckgabewert des Aufrufs.
        if php -r "exit(extension_loaded('$E') ? 0 : 1);" 2>/dev/null; then
            echo "<OK> $PAKET eingerichtet, $E ist jetzt geladen."
        else
            echo "<INFO> $PAKET wurde eingespielt, $E ist aber noch nicht geladen."
            echo "<INFO> Meist hilft ein Neustart des Webservers."
        fi
    else
        echo "<INFO> $PAKET liess sich nicht einrichten. Das Plugin laeuft trotzdem:"
        case "$E" in
            curl)    echo "<INFO> ohne curl geht der Abruf ueber file_get_contents." ;;
            openssl) echo "<INFO> ohne openssl gibt es keine Echtzeitwerte der Pulse." ;;
        esac
    fi
done

echo "<INFO> ------------------------------------------------------------"
echo "<INFO> NOCH EIN SCHRITT, DANN IST ALLES BEREIT"
echo "<INFO>"
echo "<INFO> Das Plugin ist vollstaendig installiert. Was noch fehlt, gehoert"
echo "<INFO> nicht zum Plugin, sondern zu Ihrem Tibber-Konto: das persoenliche"
echo "<INFO> Zugangstoken von developer.tibber.com. Es wird im Reiter"
echo "<INFO> Einstellungen eingetragen; danach zeigt der Reiter Test in einer"
echo "<INFO> Zeile, ob Tibber es annimmt."
exit 0
