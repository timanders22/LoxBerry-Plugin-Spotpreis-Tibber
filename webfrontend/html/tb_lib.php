<?php
/**
 * Spotpreis Tibber - gemeinsame Bibliothek
 *
 * Liegt unter webfrontend/html/, weil der Miniserver-Endpunkt sie ebenso
 * braucht wie die Oberflaeche und die Dienste. So gibt es EINE Datei statt
 * dreier Kopien, die auseinanderlaufen.
 *
 * ---------------------------------------------------------------------------
 * Die Schnittstelle
 * ---------------------------------------------------------------------------
 * Tibber hat eine GraphQL-Schnittstelle:
 *
 *   POST https://api.tibber.com/v1-beta/gql
 *   Authorization: Bearer <persoenliches Zugangstoken>
 *
 * Das Token wird unter developer.tibber.com im eigenen Konto erzeugt. Es ist
 * ein Geheimnis und liegt deshalb in einer EIGENEN Datei mit Rechten 0600,
 * nicht in der Konfiguration, die die Oberflaeche anzeigt.
 *
 * Drei Wege werden benutzt:
 *
 *   priceInfo        Preise fuer heute und morgen, stuendlich.
 *                    Tibber liefert bereits den ENDPREIS (total) samt
 *                    Energieanteil (energy) und Steuer (tax) - anders als bei
 *                    aWATTar muss hier nichts hochgerechnet werden.
 *
 *   consumption      Verbrauchshistorie mit Kosten, stuendlich oder taeglich.
 *                    Kommt mit Verzug: Tibber liefert die Werte, wenn der
 *                    Messstellenbetreiber sie geliefert hat.
 *
 *   liveMeasurement  Momentanwerte im Sekundentakt ueber WebSocket.
 *                    Setzt eine Tibber Pulse oder Watty voraus. Laeuft NICHT
 *                    hier, sondern im Daemon bin/tb_pulse.php.
 *
 * Herkunft der Feldnamen: die oeffentliche Tibber-Schnittstellenbeschreibung
 * (developer.tibber.com), gegengelesen an zwei unabhaengigen Umsetzungen -
 * github.com/terjesannum/tibber-exporter und der Node-RED-Erweiterung
 * node-red-contrib-tibber-api. Was dort nicht steht, steht auch hier nicht.
 *
 * Praefix 'tb_', weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

if (!function_exists('tb_e')) {
    function tb_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

/* ==================================================================
 * Die Zeitzone - gemessen, nicht angenommen
 *
 * Bis 0.9.9 hat KEINE Zeile dieses Plugins eine Zeitzone gesetzt. Debian
 * liefert php.ini mit auskommentiertem date.timezone aus; PHP faellt dann
 * still auf UTC zurueck. Auf einem LoxBerry in Mitteleuropa heisst das:
 *
 *   - date('G', $ts) fuer 03:00 Ortszeit ergibt 1 statt 3. Damit stehen
 *     MINH_HEUTE, MAXH_HEUTE, FENSTER_H und FENSTER2_H um ein bis zwei
 *     Stunden daneben - und niemand sieht es, weil eine Stundenzahl immer
 *     plausibel aussieht.
 *   - date('Y-m-d', $ts) sortiert die Tagesknoten der Verbrauchshistorie in
 *     den falschen Topf: VERBR_GESTERN zeigt dann vorgestern.
 *
 * Deshalb wird die Zone gesetzt, aber NUR, wenn keine eingestellt ist. Eine
 * vom Anlagenbetreiber in der php.ini gewaehlte Zone wird nicht ueberstimmt -
 * das waere derselbe Fehler mit umgekehrtem Vorzeichen.
 *
 * Quelle ist /etc/timezone, die Debian selbst pflegt. Ist sie nicht lesbar
 * oder traegt sie Unsinn, bleibt es bei dem, was PHP vorgibt: geraten wird
 * nichts.
 * ================================================================== */
if (!function_exists('tb_zeitzone_setzen')) {
    function tb_zeitzone_setzen()
    {
        $ist = trim((string) @ini_get('date.timezone'));
        if ($ist !== '') { return $ist; }
        $zone = '';
        if (@is_readable('/etc/timezone')) {
            $zone = trim((string) @file_get_contents('/etc/timezone'));
        }
        if ($zone === '' || strpos($zone, '/') === false) { return date_default_timezone_get(); }
        /* Eine unbekannte Zone wirft unter 8.x eine Ausnahme und unter 7.4
         * eine Warnung - beides mitten in der Seite. Deshalb erst fragen. */
        if (!in_array($zone, timezone_identifiers_list(), true)) {
            return date_default_timezone_get();
        }
        @date_default_timezone_set($zone);
        return $zone;
    }
}
tb_zeitzone_setzen();

/**
 * Die Fassung des Plugins - GELESEN, nicht eingetragen.
 *
 * Sie stand bis 0.9.6 an zwei Stellen fest im Quelltext (User-Agent der
 * GraphQL-Anfragen und des WebSocket-Handschlags) und war dort auf 0.9.0
 * stehengeblieben, waehrend die plugin.cfg 0.9.6 fuehrte. Eine Fassungsangabe
 * im Quelltext veraltet still; eine gelesene kann es nicht.
 *
 * Zwei Quellen, in dieser Reihenfolge:
 *
 *   1. data/system/plugindatabase.json - das ist die Auskunft von LoxBerry
 *      SELBST und die einzige, die im INSTALLIERTEN Zustand vorliegt: die
 *      plugin.cfg wandert bei der Installation nicht mit, sie steht nur im
 *      Archiv. Gesucht wird ueber den ORDNERNAMEN, nie ueber den
 *      MD5-Schluessel: der wird aus Autorenname, E-Mail und Plugin-Name
 *      gebildet und aendert sich bei jedem Fork.
 *   2. die plugin.cfg drei Ebenen ueber dieser Datei - das trifft das
 *      entpackte Archiv, also den Prueffall.
 *
 * parse_ini_file() taugt fuer die plugin.cfg NICHT: sie kommentiert mit '#',
 * PHPs INI-Zerleger kennt nur ';' und bricht an der ersten Kommentarzeile ab.
 * Deshalb wird die eine Zeile selbst gesucht.
 */
function tb_fassung()
{
    static $v = null;
    if ($v !== null) { return $v; }
    $v = '';
    $p = tb_paths();
    if ($p['home'] !== '') {
        $db = tb_json_lesen($p['home'] . '/data/system/plugindatabase.json');
        $liste = isset($db['plugins']) && is_array($db['plugins']) ? $db['plugins'] : array();
        foreach ($liste as $eintrag) {
            if (is_array($eintrag) && isset($eintrag['folder'])
                && (string) $eintrag['folder'] === $p['plugin']
                && isset($eintrag['version'])) {
                $v = (string) $eintrag['version'];
                break;
            }
        }
    }
    if ($v === '') {
        $cfg = dirname(dirname(dirname(__FILE__))) . '/plugin.cfg';
        if (is_file($cfg)
            && preg_match('/(?m)^VERSION=([0-9][0-9.]*)/', (string) @file_get_contents($cfg), $m)) {
            $v = $m[1];
        }
    }
    // Kein Rueckfall auf eine erfundene Nummer: eine Fassungsangabe, die
    // niemand gemessen hat, darf nicht aussehen wie eine gemessene.
    if ($v === '') { $v = 'unbekannt'; }
    return $v;
}


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function tb_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
            if (is_dir($k)) { $home = $k; break; }
        }
        if (!$home) { $home = lb_wurzel_ermitteln(); }
    }
    // Der Ordnername ergibt sich aus dem Ablageort dieser Datei. Der
    // MD5-Schluessel aus der plugindatabase.json wird bewusst NICHT benutzt -
    // er wird aus Autorenname, E-Mail und Plugin-Name gebildet und aendert
    // sich bei jedem Fork.
    $dir = basename(dirname(__FILE__));
    /* LBPPLUGINDIR ist die Auskunft von LoxBerry SELBST und hat deshalb
     * Vorrang vor allem anderen.
     *
     * Der feste Name greift nur da, wo der abgeleitete nachweislich kein
     * Pluginordner sein KANN - aus dem ausgepackten Archiv heraus heisst er
     * 'html'. Frueher entschied hier zusaetzlich, ob config/plugins/<name>
     * schon existiert. Das ist bei einer Zweitinstallation gefaehrlich: heisst
     * der Ordner spotpreistibber_01 und ist sein Konfigordner noch nicht
     * angelegt - bei der ERSTEN Installation ist er das nie -, fiel die
     * Ermittlung auf 'spotpreistibber' zurueck. Beide Installationen haetten
     * dann auf dieselbe Konfiguration und dasselbe Tibber-Token gezeigt. */
    $lbp = getenv('LBPPLUGINDIR');
    if ($lbp) {
        $dir = $lbp;
    } elseif ($dir === '' || $dir === '.' || $dir === '/'
              || $dir === 'html' || $dir === 'plugins') {
        $dir = 'spotpreistibber';
    }
    $p = array(
        'home'       => $home,
        'plugin'     => $dir,
        'configdir'  => $home . '/config/plugins/' . $dir,
        'config'     => $home . '/config/plugins/' . $dir . '/tibber.json',
        'token'      => $home . '/config/plugins/' . $dir . '/token.json',
        'sicherung'  => $home . '/config/plugins/' . $dir . '.backup.json',
        'sicherungt' => $home . '/config/plugins/' . $dir . '.backup.token.json',
        'datadir'    => $home . '/data/plugins/' . $dir,
        'bindir'     => $home . '/bin/plugins/' . $dir,
        'logdir'     => $home . '/log/plugins/' . $dir,
        'log'        => $home . '/log/plugins/' . $dir . '/tibber.log',
        /* Der Merker "der Pulse-Dienst lief vor dem Update" liegt NEBEN dem
         * Datenordner, nicht darin.
         *
         * Bis 0.9.6 lag er unter data/plugins/<ordner>/soll_laufen. Das
         * ueberlebt kein Update: plugininstall.pl ruft purge_installation im
         * UPGRADE-Zweig (:886), und die entfernt data/plugins/<ordner>/
         * vollstaendig (:1631). Der Waechter fand den Merker danach nicht
         * mehr und startete nichts - der Dienst stand nach JEDEM Update
         * still, ohne dass irgendwo etwas stand. Ein Geschwister mit Punkt
         * im Namen trifft das rm -rf auf das Verzeichnis nicht. */
        'lief_vorher' => $home . '/data/plugins/' . $dir . '.lief_vorher',
    );
    return $p;
}

/* ==================================================================
 * Konfiguration
 * ================================================================== */

/* ==================================================================
 * Der Fahrplaner
 *
 * Dasselbe Rechenwerk steckt in Spotpreis aWATTar und Spotpreis Octopus -
 * byteweise dieselbe Datei. Naeheres im Kopf von planer.php; dort steht auch,
 * dass wer sie aendert, sie in allen drei Linien aendert.
 *
 * __DIR__ und nicht gerechnet: planer.php liegt im SELBEN Verzeichnis wie
 * diese Datei, auch installiert - beide unter webfrontend/html. Eine
 * Kandidatenliste braucht es hier also nicht; sie steht dort, wo tb_lib.php
 * aus einem anderen Baum geladen wird.
 * ================================================================== */
require_once __DIR__ . '/planer.php';

/**
 * Anzahl der Schaltregeln.
 *
 * Vier decken Wallbox, Speicher, Warmwasser und Waermepumpe ab. Dieselbe Zahl
 * wie in den Schwesterlinien, und das ist Absicht: wer den Anbieter wechselt,
 * tauscht das Plugin und laesst die Bausteine im Miniserver stehen. Eine
 * fuenfte Regel hier haette in Loxone keine Entsprechung drueben.
 */
define('TB_REGELN', 4);

/**
 * Vorgabe einer Schaltregel.
 *
 * Die Felder des Fahrplaners (Rang, Leistung, Energie, Frist, Sperren,
 * Taktschutz) kommen aus plan_regel_vorgabe() dazu. Ihre Vorgaben sind so
 * gewaehlt, dass eine Regel ohne Zutun genau das tut, was die Regelart allein
 * sagt - kein Budget, keine Frist, keine Sperre.
 *
 * 'aktiv' ist ab Werk 0: eine neue Fassung darf auf keiner bestehenden Anlage
 * von sich aus etwas schalten.
 *
 * Die Regelarten sind wortgleich mit denen der Schwesterlinien:
 *   fenster    das guenstigste zusammenhaengende Fenster
 *   stunden    die n guenstigsten Stunden im Horizont
 *   schwelle   laeuft, solange der Preis unter 'schwelle' liegt
 *   mittel     laeuft, solange der Preis um 'prozent' unter dem Tagesmittel
 *              liegt
 *
 * Die Art 'scheiben' aus planer.php kennt diese Linie nicht: Tibber liefert
 * Stundenpreise, und eine Regel ueber Viertelstundenscheiben haette hier
 * keinen Gegenstand.
 */
function tb_regel_vorgabe()
{
    return array(
        'aktiv'    => 0,
        'name'     => '',
        'art'      => 'fenster',
        'n'        => 3,      // Fensterlaenge bzw. Anzahl der Stunden
        'von'      => 0,      // Zeitfenster von (Stunde, einschliesslich)
        'bis'      => 0,      // Zeitfenster bis; von == bis heisst ganzer Tag
        'horizont' => 24,     // wie weit nach vorn gesucht wird (Stunden)
        'schwelle' => 20.0,   // ct/kWh fuer die Art 'schwelle'
        'prozent'  => 15,     // Prozent unter dem Tagesmittel fuer 'mittel'
        'neg'      => 0,      // bei negativem Energiepreis immer laufen
    ) + plan_regel_vorgabe();
}

function tb_vorgaben()
{
    return array(
        'home_id'          => '',      // leer = das erste Zuhause des Kontos
        'aufschlag'        => 0.0,     // ct/kWh, auf den Tibber-Endpreis
        'guenstig'         => 25.0,    // ct/kWh: darunter gilt guenstig
        'teuer'            => 35.0,    // ct/kWh: darueber gilt teuer
        'fensterstunden'   => 3,       // Laenge des guenstigsten Fensters
        'preistakt'        => 30,      // Minuten zwischen zwei Preisabrufen
        'verbrauchstakt'   => 180,     // Minuten zwischen zwei Verbrauchsabrufen
        'verbrauch_ein'    => 1,
        'pulse_ein'        => 0,
        'mqtt_ein'         => 0,
        'mqtt_topic'       => 'tibber',
        'festpreis'        => 34.0,    // ct/kWh zum Vergleich
        'grundpreis'       => 12.0,    // Euro im Monat, nur fuer die Anzeige
        'monatsbericht'    => 1,
        'verlauf_tage'     => 90,
        'aktionstoken'     => '',
        'zeitueberschreitung' => 15,
        /* ---------------- Fahrplaner (ab 0.9.11) ----------------
         *
         * Alles hier ist ab Werk AUS. Wer nichts einstellt, bekommt genau das
         * Verhalten von 0.9.10: die Schaltregelliste ist leer, also rechnet
         * der Planer nichts, und kein einziger Wert aendert sich.
         *
         * 'hysterese' steht auf 1 und ist damit die einzige Ausnahme von
         * "neue Funktionen ab Werk aus". Sie hat ohne Regeln keinen
         * Gegenstand: sie sorgt dafuer, dass ein BEGONNENER Block zu Ende
         * laeuft. Ohne Regel gibt es keinen Block. Ab Werk 0 hiesse dagegen,
         * dass die erste Regel, die jemand anlegt, ein Geraet mitten im
         * Betrieb abschalten kann - und das will niemand absichtlich.
         */
        'regeln'      => array(),
        'hysterese'   => 1,
        // Woher die PV-Prognose kommt. '' = aus.
        //   forecast_solar  fester Pfad result.watt_hours_period, Werte in Wh
        //   objekt          ein Objekt "Zeit => Wert" unter 'pv_pfad'
        //   liste           eine Liste von Saetzen mit 'pv_zeitfeld' und
        //                   'pv_wertfeld' - die Form von Solcast
        'pv_quelle'   => '',
        'pv_url'      => '',
        'pv_pfad'     => '',
        'pv_zeitfeld' => '',
        'pv_wertfeld' => '',
        'pv_einheit'  => 'wh',   // wh | w | kw
        // Speicherstand in Prozent, aus irgendeiner JSON-Auskunft.
        'soc_url'     => '',
        'soc_pfad'    => '',
    ) + plan_global_vorgabe();
}

function tb_json_lesen($pfad)
{
    if (!is_file($pfad)) { return array(); }
    $d = json_decode((string) @file_get_contents($pfad), true);
    return is_array($d) ? $d : array();
}

/**
 * Erst in eine Nebendatei, dann umbenennen - so liest niemand eine halb
 * geschriebene Datei.
 *
 * ZWEI Feinheiten, die beide schon einmal weh getan haben:
 *
 * 1. Die Nebendatei traegt die PID im Namen. Hier schreiben mehrere Prozesse:
 *    der Cron-Lauf, der Pulse-Dienst im Sekundentakt und die Oberflaeche. Mit
 *    einem festen '.tmp' fuer alle wuerde der eine die Nebendatei des anderen
 *    ueberschreiben, und umbenannt wuerde eine Mischung.
 *
 * 2. Die Rechte werden VOR dem Inhalt gesetzt. Andersherum steht das
 *    Tibber-Token fuer die Dauer eines Wimpernschlags mit den Rechten da, die
 *    die umask vorgibt - auf einem LoxBerry ist das 0644, also fuer jeden
 *    lesbar. Die Datei wird dafuer leer angelegt, dann chmod, dann gefuellt.
 */
function tb_json_schreiben($pfad, $daten, $rechte = null)
{
    $ordner = dirname($pfad);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return false;
    }
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) { return false; }
    $tmp = $pfad . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, '') === false) {
        @unlink($tmp);
        return false;
    }
    if ($rechte !== null) { @chmod($tmp, $rechte); }
    /* Der Vergleich mit strlen statt mit false: file_put_contents gibt die
     * Anzahl geschriebener Bytes zurueck, und eine kurze Schreibung ist
     * genauso kaputt wie gar keine - nur meldet sie sich nicht als Fehler. */
    if (@file_put_contents($tmp, $json) !== strlen($json)) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $pfad)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Die Konfiguration lesen.
 *
 * $erzeugen entscheidet, ob dabei geschrieben werden darf. Der unangemeldete
 * Endpunkt ruft tb_config(false) - und zwar nicht aus Vorsicht, sondern weil
 * er sonst schreibt, BEVOR sich jemand ausgewiesen hat: die Selbstheilung
 * unten kopiert eine Datei, und ein einziger Aufruf ohne Token legte damit
 * den Konfigurationsordner samt Inhalt an. Gemessen an EVCC, Govee und
 * Saugroboter; dieselbe Klasse, dreimal.
 */
function tb_config($erzeugen = true)
{
    $p = tb_paths();
    // Selbstheilung aus der Sicherung NEBEN dem Konfigurationsordner. Ein
    // Geschwister ueberlebt dessen Loeschung, ein Kind nicht.
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    /* Drei Zustaende, nicht zwei.
     *
     * Bis 0.9.9 galt nur "leer oder {}" als heilungsbeduerftig. Eine
     * ABGESCHNITTENE Datei - Stromausfall, volle Karte, Handbearbeitung - ist
     * weder das eine noch das andere. tb_json_lesen() gab dafuer stumm ein
     * leeres Feld zurueck, array_merge machte daraus die reinen Vorgabewerte,
     * und die naechste Zeile der Oberflaeche schrieb sie zurueck - samt
     * @copy ueber die INTAKTE Zweitschrift. Ein einziges Oeffnen der Seite
     * nach einem Stromausfall genuegte, und Einstellungen, Merkwort und
     * Rettungsanker waren zusammen weg. Gemessen am 02.09.2026.
     *
     * Deshalb wird jetzt unterschieden: kaputt ist etwas anderes als leer. */
    $kaputt = ($roh !== '' && $roh !== '{}' && !is_array(json_decode($roh, true)));
    if ($erzeugen && ($roh === '' || $roh === '{}' || $kaputt) && is_file($p['sicherung'])) {
        if (!is_dir($p['configdir'])) { @mkdir($p['configdir'], 0775, true); }
        if ($kaputt) {
            /* Die beschaedigte Datei wird beiseitegelegt, nicht ueberschrieben.
             * Wer sie spaeter braucht, findet sie; wer sie nicht braucht,
             * merkt nichts davon. */
            $beiseite = $p['config'] . '.kaputt.' . date('Ymd_His');
            @copy($p['config'], $beiseite);
            tb_log('Die Konfiguration war unlesbar und wurde als '
                   . basename($beiseite) . ' beiseitegelegt; es gilt wieder die '
                   . 'Zweitschrift.');
        }
        @copy($p['sicherung'], $p['config']);
        $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
        $kaputt = ($roh !== '' && $roh !== '{}' && !is_array(json_decode($roh, true)));
    }
    if ($kaputt) {
        /* Keine Zweitschrift, und die Datei ist unlesbar. Es gibt jetzt nichts
         * zu lesen - aber auch nichts zurueckzuschreiben: tb_config_lesbar()
         * sagt das den Schreibstellen. */
        tb_log_gebremst('config_kaputt', 'Die Konfigurationsdatei ' . $p['config']
            . ' laesst sich nicht lesen und es gibt keine Zweitschrift. Es gelten '
            . 'die Werkseinstellungen; gespeichert wird nichts, bis die Datei in '
            . 'Ordnung ist.');
    }
    return tb_fahrplan_normieren(array_merge(tb_vorgaben(), tb_json_lesen($p['config'])));
}

/**
 * Die Werte des Fahrplaners in ihren Bereich zwingen - beim LESEN.
 *
 * Hier wird GEKAPPT, nicht abgewiesen. Das ist kein Widerspruch zu "Eingaben
 * werden abgewiesen und gemeldet, nie still zurechtgebogen": abgewiesen wird
 * am EINGANG, also im Formular (tb_wert_pruefen) und beim Zurueckspielen
 * einer Sicherung. Was dagegen schon in der Datei steht - von Hand
 * bearbeitet, aus einer aelteren Fassung, halb geschrieben -, darf das Plugin
 * nicht zum Absturz bringen und schon gar nicht mit einer unsinnigen Zahl in
 * den Miniserver gehen.
 *
 * Dieselbe Aufteilung wie in den Schwesterlinien.
 */
function tb_fahrplan_normieren(array $cfg)
{
    // ---- global ----
    $cfg['budget_kw']   = max(0.0, min(200.0,   (float) $cfg['budget_kw']));
    $cfg['pv_bonus']    = max(0.0, min(100.0,   (float) $cfg['pv_bonus']));
    $cfg['pv_schwelle'] = max(1,   min(100000,  (int)   $cfg['pv_schwelle']));
    $cfg['budget2_kw']  = max(0.0, min(200.0,   (float) $cfg['budget2_kw']));
    $cfg['budget2_von'] = max(0,   min(23,      (int)   $cfg['budget2_von']));
    $cfg['budget2_bis'] = max(0,   min(23,      (int)   $cfg['budget2_bis']));
    $cfg['hysterese']   = empty($cfg['hysterese']) ? 0 : 1;
    if (!in_array($cfg['pv_quelle'], array('', 'forecast_solar', 'objekt', 'liste'), true)) {
        $cfg['pv_quelle'] = '';
    }
    if (!in_array($cfg['pv_einheit'], array('wh', 'w', 'kw'), true)) {
        $cfg['pv_einheit'] = 'wh';
    }

    // ---- je Regel ----
    if (!is_array($cfg['regeln'])) { $cfg['regeln'] = array(); }
    for ($i = 0; $i < TB_REGELN; $i++) {
        $r = isset($cfg['regeln'][$i]) && is_array($cfg['regeln'][$i])
           ? $cfg['regeln'][$i] : array();
        $r += tb_regel_vorgabe();
        $r['aktiv']    = empty($r['aktiv']) ? 0 : 1;
        $r['neg']      = empty($r['neg']) ? 0 : 1;
        $r['name']     = trim((string) $r['name']);
        $r['art']      = in_array($r['art'], array('fenster', 'stunden', 'schwelle', 'mittel'), true)
                       ? $r['art'] : 'fenster';
        $r['n']        = max(1, min(12, (int) $r['n']));
        $r['von']      = max(0, min(23, (int) $r['von']));
        $r['bis']      = max(0, min(23, (int) $r['bis']));
        $r['horizont'] = max(1, min(48, (int) $r['horizont']));
        $r['schwelle'] = (float) $r['schwelle'];
        $r['prozent']  = max(0, min(90, (int) $r['prozent']));
        $r['rang']     = max(1, min(99, (int) $r['rang']));
        $r['leistung'] = max(0.0, min(100.0, (float) $r['leistung']));
        $r['energie']  = max(0.0, min(500.0, (float) $r['energie']));
        $r['frist']    = (int) $r['frist'];
        if ($r['frist'] < 0 || $r['frist'] > 23) { $r['frist'] = -1; }
        $r['pv_sperre'] = max(0.0, min(500.0, (float) $r['pv_sperre']));
        $r['soc_min']   = max(0, min(100, (int) $r['soc_min']));
        $r['soc_max']   = max(0, min(100, (int) $r['soc_max']));
        /* Taktschutz. Beide in Minuten, 0 = aus. Bei Stundenpreisen ist eine
         * Mindestlaufzeit unter 60 Minuten wirkungslos - das steht in der
         * Hilfe und nicht in einer Schranke: abweisen waere bevormundend,
         * und 0 heisst ohnehin aus. */
        $r['min_lauf']  = max(0, min(720, (int) $r['min_lauf']));
        $r['min_pause'] = max(0, min(720, (int) $r['min_pause']));
        $cfg['regeln'][$i] = $r;
    }
    return $cfg;
}

/**
 * Ist die Konfigurationsdatei lesbar - oder liegt dort Unsinn?
 *
 * Eine fehlende Datei ist lesbar im Sinne dieser Frage: dann gelten die
 * Vorgaben, und die duerfen geschrieben werden. Unlesbar ist nur, was da ist
 * und sich nicht zerlegen laesst. In diesem Zustand darf NICHTS zurueck-
 * geschrieben werden, sonst ueberschreibt eine Vorgabeliste die Einstellungen
 * des Anwenders.
 */
function tb_config_lesbar()
{
    $p = tb_paths();
    if (!is_file($p['config'])) { return true; }
    $roh = trim((string) @file_get_contents($p['config']));
    if ($roh === '' || $roh === '{}') { return true; }
    return is_array(json_decode($roh, true));
}

/**
 * Fehlende Schluessel EINMAL in die Datei schreiben - nicht nur beim Lesen
 * ergaenzen.
 *
 * Der Unterschied klingt klein und ist der ganze Punkt. Ergaenzen heisst: die
 * Datei bleibt lueckenhaft, und "fehlt" ist von "steht auf dem Vorgabewert"
 * nicht zu unterscheiden. Vervollstaendigen heisst: es steht da. Danach ist
 * jede kuenftige Umbenennung harmlos, eine Sicherung traegt wirklich alles,
 * und wer die Datei ansieht, sieht SEINE Einstellungen und nicht die Haelfte
 * davon.
 *
 * Geschrieben wird nur, wenn wirklich etwas fehlte - sonst aendert sich die
 * Datei bei jedem Lauf ohne Anlass und das Protokoll laeuft voll.
 *
 * Rueckgabe: array(Zahl der Schluessel, ergaenzte Namen, fremde Namen).
 * Fremdes wird GENANNT und stehengelassen: niemand weiss, ob dort der Rest
 * einer alten Fassung steht oder etwas, das der naechsten schon gehoert.
 */
function tb_config_vervollstaendigen($schreiben = true)
{
    $p = tb_paths();
    $vorgaben = tb_vorgaben();
    $ist = tb_json_lesen($p['config']);
    $fehlend = array();
    foreach ($vorgaben as $k => $unbenutzt) {
        if (!array_key_exists($k, $ist)) { $fehlend[] = $k; }
    }
    $fremd = array();
    foreach ($ist as $k => $unbenutzt) {
        if (!array_key_exists($k, $vorgaben)) { $fremd[] = $k; }
    }
    /* Geschrieben wird nur ueber eine Datei, die sich auch LESEN liess.
     * Sonst ergaenzt diese Funktion die Vorgabewerte in eine beschaedigte
     * Datei hinein und macht aus einem Lesefehler einen Datenverlust. */
    if ($schreiben && $fehlend && is_file($p['config']) && tb_config_lesbar()) {
        $neu = array_merge($vorgaben, $ist);
        if (tb_config_speichern($neu)) {
            tb_log('Konfiguration vervollstaendigt, ergaenzt: ' . implode(', ', $fehlend));
        } else {
            tb_log_gebremst('config_ergaenzen', 'Die Konfiguration liess sich nicht '
                . 'vervollstaendigen - ' . $p['config'] . ' ist nicht beschreibbar.');
        }
    }
    return array(count($vorgaben), $fehlend, $fremd);
}

function tb_config_speichern($cfg)
{
    $p = tb_paths();
    // In dieser Datei steht KEIN Tibber-Token, aber das Aktionstoken fuer den
    // Endpunkt - deshalb 0600.
    if (!tb_json_schreiben($p['config'], $cfg, 0600)) { return false; }
    /* Die Zweitschrift ist der einzige Rettungsanker fuer den Fall, dass der
     * Konfigurationsordner beim Upgrade abgeraeumt wird. Ein stilles @copy
     * haette sie monatelang veralten lassen koennen, ohne dass es irgendwo
     * steht - die Konfiguration selbst gilt trotzdem als gespeichert. */
    if (!@copy($p['config'], $p['sicherung'])) {
        tb_log_gebremst('sicherung_kopie', 'Die Zweitschrift ' . $p['sicherung']
            . ' liess sich nicht schreiben. Die Einstellungen sind gespeichert, aber '
            . 'ein Upgrade koennte sie nicht wiederherstellen.');
    } else {
        @chmod($p['sicherung'], 0600);
    }
    return true;
}

/* ---------------- Das Tibber-Token ----------------
 *
 * Eigene Datei, Rechte 0600. Es wird NIE angezeigt und NIE ins Protokoll
 * geschrieben - beurteilt wird hoechstens seine FORM.
 */
function tb_token_lesen()
{
    $d = tb_json_lesen(tb_paths()['token']);
    return isset($d['token']) ? (string) $d['token'] : '';
}

function tb_token_speichern($token)
{
    $p = tb_paths();
    if (!tb_json_schreiben($p['token'], array('token' => (string) $token), 0600)) {
        return false;
    }
    @copy($p['token'], $p['sicherungt']);
    @chmod($p['sicherungt'], 0600);
    return true;
}

/**
 * Beurteilt die FORM eines Tokens, nie seinen Wert.
 *
 * Tibber gibt persoenliche Zugangstoken als lange Zeichenkette aus. Wie sie
 * genau aufgebaut ist, ist nicht dokumentiert und darf deshalb nicht eng
 * geprueft werden - eine zu strenge Pruefung weist gueltige Token ab. Geprueft
 * wird nur, was sicher falsch ist: zu kurz, Leerraum mittendrin, oder die
 * versehentlich mitkopierte Zeile 'Bearer '.
 *
 * Rueckgabe: Leerstring wenn in Ordnung, sonst der Sprachschluessel des Grundes.
 */
function tb_token_form($token)
{
    $t = (string) $token;
    if ($t === '') { return 'TOKEN.LEER'; }
    if (preg_match('/^bearer\s/i', $t)) { return 'TOKEN.BEARER'; }
    if (preg_match('/\s/', $t)) { return 'TOKEN.LEERRAUM'; }
    if (strlen($t) < 20) { return 'TOKEN.KURZ'; }
    if (!preg_match('#^[A-Za-z0-9._\-]+$#', $t)) { return 'TOKEN.ZEICHEN'; }
    return '';
}

/** Zufallstoken fuer den unangemeldeten Endpunkt. */
function tb_aktionstoken_erzeugen($laenge = 24)
{
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $t;
}

/**
 * Das Merkwort des Endpunkts, notfalls frisch gewuerfelt.
 *
 * Fail closed: laesst es sich nicht SPEICHERN, wird auch keines
 * zurueckgegeben. Bis 0.9.9 kam hier ein Merkwort heraus, das nur im
 * Arbeitsspeicher stand - und beim naechsten Aufruf ein anderes. Die
 * Oberflaeche zeigte damit eine Adresse zum Abschreiben, die Importvorlage
 * trug ein drittes, und jeder virtuelle Eingang bekam vom Endpunkt eine
 * Abweisung. Gemessen am 02.09.2026: zwei Aufrufe hintereinander, zwei
 * verschiedene Merkwoerter, keines davon in der Datei.
 */
function tb_aktionstoken()
{
    $cfg = tb_config();
    if (trim((string) $cfg['aktionstoken']) === '') {
        $cfg['aktionstoken'] = tb_aktionstoken_erzeugen();
        if (!tb_config_speichern($cfg)) {
            tb_log_gebremst('token_speichern', 'Das Merkwort fuer den Endpunkt liess '
                . 'sich nicht speichern (' . tb_paths()['config'] . '). Solange das so '
                . 'ist, gibt es kein Merkwort - eine Adresse mit einem nicht '
                . 'gespeicherten waere eine Adresse, die nie funktioniert.');
            return '';
        }
    }
    return (string) $cfg['aktionstoken'];
}

/* ---------------- Das Formularmerkmal (Wachposten) ----------------
 *
 * htmlauth schuetzt gegen den unangemeldeten Aufruf - nicht dagegen, dass der
 * Browser eines ANGEMELDETEN Bedieners ein Formular abschickt, das auf einer
 * fremden Seite steht. Der Browser schickt die hinterlegten Zugangsdaten bei
 * einer Anfrage von aussen mit.
 *
 * Der teuerste Knopf dieses Plugins ist dabei "Neues Merkwort erzeugen":
 * danach beantwortet der Endpunkt JEDEN virtuellen Eingang mit 403, und ein
 * virtueller Eingang wertet die Antwort nicht aus - der Ausfall bliebe still.
 * Ebenso erreichbar waeren "Protokoll leeren" (Spurenbeseitigung) und das
 * Anhalten des Pulse-Dienstes.
 *
 * Das Merkmal wird aus dem Aktionstoken ABGELEITET und nicht gespeichert.
 * Sonst haette die Konfiguration einen Schluessel mehr, den ein
 * Speichern-Handler vergessen kann - genau daran sind am 13.08.2026 drei
 * Aktionstoken verlorengegangen.
 */
function tb_formtoken($cfg = null)
{
    if ($cfg === null) { $cfg = tb_config(); }
    $t = trim((string) $cfg['aktionstoken']);
    return $t === '' ? '' : hash_hmac('sha256', 'formular-v1', $t);
}

/**
 * Fail closed: ohne hinterlegtes Aktionstoken gibt es nichts zu vergleichen,
 * und hash_equals('', '') waere WAHR - der Wachposten liesse dann ausgerechnet
 * die Anlage offen, bei der nie jemand ein Token gesetzt hat.
 */
function tb_formtoken_ok($cfg = null)
{
    $soll = tb_formtoken($cfg);
    if ($soll === '') { return false; }
    $ist = isset($_POST['fmt']) && is_string($_POST['fmt']) ? (string) $_POST['fmt'] : '';
    return $ist !== '' && hash_equals($soll, $ist);
}

/* ---------------- Der Suchtext fuer Loxone ----------------
 *
 * Loxone sucht WOERTLICH und nimmt den ERSTEN Treffer in der Zeile. Ohne das
 * fuehrende Semikolon steckt 'ALTER=' auch in 'PULSE_ALTER=' und 'OK=' auch
 * in 'MORGEN_OK=' - und weil beide frueher in der Zeile stehen, las der
 * Miniserver bis 0.9.6 an genau diesen zwei Feldern den falschen Wert.
 * Gemessen an der echten Statuszeile: 36 von 38 richtig, 2 falsch; mit dem
 * Semikolon 38 von 38.
 *
 * Getroffen hat es die beiden Felder, an denen die Ausfallerkennung haengt:
 * OK lieferte den Wert von MORGEN_OK (nachmittags dauerhaft 1) und ALTER das
 * Alter des Pulse-Wertes in Sekunden statt des Preisalters in Minuten.
 *
 * Diese Funktion ist die EINZIGE Stelle, an der der Suchtext entsteht.
 * Vorlage, Feldtabelle und Baustein-Liste rufen sie auf; in den Sprachdateien
 * steht nur noch ein Platzhalter. Eine berichtigte Abschrift waere immer noch
 * eine Abschrift und liefe beim naechsten neuen Feld wieder auseinander.
 */
function tb_check($feld)
{
    return '\i;' . $feld . '=\i\v';
}

/**
 * Ein umlaufender Laufzaehler als Lebenszeichen.
 *
 * Er beantwortet, was ein Zeitstempel nicht kann: ein Raspberry hat keine
 * Echtzeituhr, steht nach dem Booten in der Vergangenheit und springt, sobald
 * NTP greift. Ein Alter kann danach negativ oder stundenlang sein, obwohl
 * alles laeuft - eine umlaufende Zahl nicht.
 *
 * -1 heisst "noch nie gelaufen"; 0 waere ein gueltiger Stand und taugt dafuer
 * nicht.
 */
function tb_zaehler_lesen()
{
    $f = tb_paths()['datadir'] . '/zaehler';
    if (!is_file($f)) { return -1; }
    $n = trim((string) @file_get_contents($f));
    return preg_match('/^[0-9]{1,3}$/', $n) ? (int) $n : -1;
}

function tb_zaehler_weiter()
{
    $p = tb_paths();
    if (!is_dir($p['datadir'])) { @mkdir($p['datadir'], 0775, true); }
    $n = tb_zaehler_lesen();
    $n = ($n < 0) ? 0 : (($n + 1) % 1000);
    @file_put_contents($p['datadir'] . '/zaehler', (string) $n);
    return $n;
}

/**
 * Den roten Punkt am Plugin-Symbol setzen - beim WECHSEL des Befundes, nie
 * bei jedem Durchlauf.
 *
 * Eine Meldung je Minute ist keine Meldung, sondern Rauschen; und wer sie
 * abstellt, stellt auch die echte ab. Deshalb merkt sich diese Funktion den
 * zuletzt gemeldeten Stand je Thema und schweigt, solange er gleich bleibt.
 *
 * notify_ext() steckt in loxberry_log.php. Ein '@' hilft gegen "undefined
 * function" NICHT - das ist ein fataler Fehler, kein unterdrueckbarer.
 * Deshalb function_exists() davor.
 */
function tb_notify($thema, $stufe, $text)
{
    $p = tb_paths();
    if (!is_dir($p['datadir'])) { @mkdir($p['datadir'], 0775, true); }
    $f = $p['datadir'] . '/.notify_' . preg_replace('/[^a-z0-9_]/i', '', $thema);
    $neu = $stufe . '|' . md5($text);
    $alt = is_file($f) ? trim((string) @file_get_contents($f)) : '';
    if ($alt === $neu) { return false; }
    @file_put_contents($f, $neu);
    if ($stufe === 'ok') { return true; }        // Entwarnung: nur merken
    if (!function_exists('notify_ext')) {
        tb_log_gebremst('kein_notify', 'Der Hinweis "' . $text . '" konnte nicht an das '
            . 'Benachrichtigungszentrum gehen: notify_ext() gibt es in dieser '
            . 'LoxBerry-Fassung nicht.');
        return false;
    }
    notify_ext(array(
        'PACKAGE' => tb_paths()['plugin'],
        'NAME'    => 'spotpreistibber',
        'MESSAGE' => $text,
        'SEVERITY' => ($stufe === 'fehler') ? 3 : 4,
    ));
    return true;
}

/**
 * Laeuft unter dieser Prozessnummer wirklich UNSER Skript?
 *
 * /proc/<pid>/cmdline trennt die Argumente mit Nullbytes. Ein grep darueber
 * trifft JEDEN Prozess, der den Pfad irgendwo fuehrt - auch einen Editor, der
 * die Datei gerade offen hat. Richtig sind zwei Bedingungen: argv[1] ist genau
 * das Skript, und argv[0] ist ein Interpreter. Die zweite ist noetig, weil
 * "nano <pfad>" den Pfad ebenfalls als argv[1] traegt.
 */
function tb_prozess_ist($pid, $skriptname)
{
    $pid = (int) $pid;
    if ($pid <= 0 || !is_dir('/proc/' . $pid)) { return false; }
    $roh = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
    if ($roh === '') { return false; }
    $teile = explode("\0", $roh);
    if (count($teile) < 2) { return false; }
    if (basename((string) $teile[1]) !== $skriptname) { return false; }
    return preg_match('#(^|/)php[0-9.]*$#', (string) $teile[0]) === 1;
}

/* ---------------- Protokoll ---------------- */

function tb_log($text)
{
    $p = tb_paths();
    if (!is_dir($p['logdir'])) { @mkdir($p['logdir'], 0775, true); }
    clearstatcache(true, $p['log']);
    if (is_file($p['log']) && filesize($p['log']) > 512000) {
        $rest = array_slice(file($p['log'], FILE_IGNORE_NEW_LINES) ?: array(), -400);
        @file_put_contents($p['log'], implode("\n", $rest) . "\n");
    }
    @file_put_contents($p['log'], '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND);
}

/** Dieselbe Meldung hoechstens einmal je Zeitfenster. */
function tb_log_gebremst($schluessel, $text, $sekunden = 3600)
{
    $p = tb_paths();
    if (!is_dir($p['datadir'])) { @mkdir($p['datadir'], 0775, true); }
    $f = $p['datadir'] . '/.meld_' . preg_replace('/[^a-z0-9_]/i', '', $schluessel);
    $letzte = is_file($f) ? (int) @file_get_contents($f) : 0;
    if (time() - $letzte >= $sekunden) {
        @file_put_contents($f, (string) time());
        tb_log($text);
    }
}

/* ==================================================================
 * Die GraphQL-Schnittstelle
 * ================================================================== */

/**
 * Kopfzeilen fuer jede Anfrage.
 *
 * Vor mancher Schnittstelle sitzt ein Waechter, der die Vorgabe der
 * benutzten Bibliothek als User-Agent abweist. Deshalb gehoeren User-Agent,
 * Accept, Accept-Language und Accept-Encoding an JEDE Anfrage.
 */
function tb_kopfzeilen($token)
{
    return array(
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
        'Accept-Language: de-DE,de;q=0.9,en;q=0.8',
        'Accept-Encoding: identity',
        // Die Fassung wird GELESEN (tb_fassung), nicht eingetragen. Bis 0.9.6
        // stand hier fest '0.9.0', waehrend die plugin.cfg laengst 0.9.6 fuehrte.
        'User-Agent: LoxBerry-Plugin-Spotpreis-Tibber/' . tb_fassung()
            . ' (+https://wiki.loxberry.de/)',
    );
}

/**
 * Betriebssystem- und Protokollfehler uebersetzen.
 *
 * Der nackte Fehlertext hilft niemandem. Und kommt HTML statt JSON zurueck,
 * hat ein Gateway geantwortet und nicht die Schnittstelle - das gehoert
 * ausdruecklich in die Meldung, sonst sucht man den Fehler bei der Anmeldung,
 * die laengst funktioniert.
 */
function tb_fehlertext($text, $code = 0)
{
    $t = strtolower((string) $text);
    if ($code === 401 || $code === 403) {
        return 'Tibber hat die Anmeldung abgewiesen (HTTP ' . $code . '). Das Token ist '
             . 'falsch, abgelaufen oder gehoert zu einem anderen Konto. Ein neues gibt es '
             . 'unter developer.tibber.com im eigenen Konto.';
    }
    if ($code === 429) {
        return 'Tibber bremst die Abrufe (HTTP 429). Den Abruftakt vergroessern und '
             . 'abwarten - die Sperre loest sich von selbst.';
    }
    if ($code >= 500 && $code < 600) {
        return 'Tibber meldet einen Serverfehler (HTTP ' . $code . '). Das liegt nicht an '
             . 'dieser Einrichtung; beim naechsten Takt wird es erneut versucht.';
    }
    if (strpos($t, 'could not resolve host') !== false || strpos($t, 'getaddrinfo') !== false) {
        return 'Der Rechnername api.tibber.com liess sich nicht aufloesen. Hat der LoxBerry '
             . 'einen funktionierenden Namensdienst und Zugang ins Netz?';
    }
    if (strpos($t, 'timed out') !== false || strpos($t, 'timeout') !== false) {
        return 'Zeitueberschreitung: Tibber hat nicht rechtzeitig geantwortet.';
    }
    if (strpos($t, 'ssl') !== false || strpos($t, 'certificate') !== false) {
        return 'Die verschluesselte Verbindung kam nicht zustande: ' . $text
             . ' Meist ist die Uhr des LoxBerry falsch gestellt.';
    }
    return trim((string) $text) !== '' ? (string) $text : 'Unbekannter Fehler ohne Text.';
}

/**
 * Eine GraphQL-Abfrage absetzen.
 *
 * Rueckgabe: das Feld 'data' als Array, oder array('_fehler' => Text).
 * Es wird NIE ein leeres Ergebnis zurueckgegeben, wo ein Fehler hingehoert.
 */
function tb_gql($abfrage, $variablen = array(), $token = null)
{
    if ($token === null) { $token = tb_token_lesen(); }
    if ($token === '') {
        return array('_fehler' => 'Es ist kein Tibber-Token hinterlegt. '
            . 'Reiter Einstellungen.');
    }
    $cfg = tb_config();
    $tmo = max(5, min(60, (int) $cfg['zeitueberschreitung']));
    $rumpf = json_encode(array('query' => $abfrage, 'variables' => (object) $variablen));
    if ($rumpf === false) {
        /* Sonst ginge ein LEERER Rumpf hinaus, Tibber antwortete mit einem
         * Protokollfehler, und tb_fehlertext() zeigte auf die Schnittstelle -
         * waehrend der Fehler im eigenen Haus liegt. */
        return array('_fehler' => 'Die Abfrage liess sich nicht als JSON verpacken: '
            . json_last_error_msg());
    }

    $antwort = '';
    $code = 0;
    $fehler = '';

    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.tibber.com/v1-beta/gql');
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $rumpf,
            CURLOPT_HTTPHEADER     => tb_kopfzeilen($token),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $tmo,
            CURLOPT_CONNECTTIMEOUT => min(10, $tmo),
            CURLOPT_FOLLOWLOCATION => false,
        ));
        $antwort = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($antwort === false) { $fehler = curl_error($ch); }
        curl_close($ch);
    } else {
        // Rueckfallebene ohne curl. Sie wird ANGEZEIGT, damit aus dem Ersatz
        // nicht unbemerkt der Normalfall wird.
        tb_log_gebremst('kein_curl', 'Die PHP-Erweiterung curl fehlt - es wird ueber '
            . 'file_get_contents gearbeitet. Abhilfe: sudo apt install php-curl');
        $ctx = stream_context_create(array('http' => array(
            'method'        => 'POST',
            'header'        => implode("\r\n", tb_kopfzeilen($token)),
            'content'       => $rumpf,
            'timeout'       => $tmo,
            'ignore_errors' => true,
        )));
        $antwort = @file_get_contents('https://api.tibber.com/v1-beta/gql', false, $ctx);
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $z) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $z, $m)) { $code = (int) $m[1]; }
            }
        }
        if ($antwort === false) { $fehler = 'file_get_contents ist gescheitert.'; }
    }

    if ($antwort === false || $antwort === null) {
        return array('_fehler' => tb_fehlertext($fehler, $code));
    }
    if ($code >= 400) {
        return array('_fehler' => tb_fehlertext('HTTP ' . $code, $code));
    }
    $roh = ltrim((string) $antwort);
    if ($roh !== '' && $roh[0] === '<') {
        return array('_fehler' => 'Es kam HTML statt JSON zurueck. Damit hat nicht die '
            . 'Tibber-Schnittstelle geantwortet, sondern ein Zwischenrechner - etwa ein '
            . 'Portal des Netzes oder ein Filter. Die Anmeldung ist dabei nicht das Problem.');
    }
    $d = json_decode($roh, true);
    if (!is_array($d)) {
        return array('_fehler' => 'Die Antwort liess sich nicht lesen. Erste 200 Zeichen: '
            . substr($roh, 0, 200));
    }
    if (isset($d['errors']) && is_array($d['errors'])) {
        $texte = array();
        foreach ($d['errors'] as $e) {
            $texte[] = isset($e['message']) ? (string) $e['message'] : 'ohne Text';
        }
        return array('_fehler' => 'Tibber meldet: ' . implode(' | ', $texte));
    }
    if (!isset($d['data']) || !is_array($d['data'])) {
        return array('_fehler' => 'Die Antwort enthaelt kein Feld data.');
    }
    return $d['data'];
}

/* ---------------- Die Abfragen ---------------- */

/** Zuhause, Vertrag und ob eine Pulse angeschlossen ist. */
function tb_abfrage_konto()
{
    return '{ viewer { login name websocketSubscriptionUrl homes {'
         . ' id appNickname timeZone'
         . ' address { address1 postalCode city country }'
         . ' meteringPointData { gridCompany priceAreaCode }'
         . ' features { realTimeConsumptionEnabled }'
         . ' currentSubscription { id status priceInfo { current { currency } } }'
         . ' } } }';
}

/**
 * Eine Zuhause-Kennung, bevor sie in eine Abfrage eingesetzt wird.
 *
 * Die Kennung wird woertlich in den Abfragetext geschrieben - zwischen zwei
 * Anfuehrungszeichen. Ein Anfuehrungszeichen IN der Kennung bricht dort aus
 * und haengt an die Abfrage an, was der Betreiber hineinschreibt.
 *
 * Die Oberflaeche entfernt Anfuehrungszeichen zwar schon beim Speichern. Nur
 * steht diese Saeuberung in einer ANDEREN Datei als das Einsetzen, und die
 * Konfiguration laesst sich auch von Hand bearbeiten. Wer sich darauf
 * verlaesst, dass zwei getrennte Stellen fuer immer zusammenpassen, hat die
 * Pruefung nicht - er hofft nur.
 *
 * Tibber vergibt Kennungen in UUID-Form. Alles, was nicht danach aussieht,
 * wird verworfen; die Abfrage nimmt dann das erste Zuhause des Kontos, und
 * genau das ist auch die dokumentierte Bedeutung einer leeren Kennung.
 */
function tb_gql_id($id)
{
    $id = trim((string) $id);
    return preg_match('/^[A-Za-z0-9-]{1,64}$/', $id) ? $id : '';
}

/** Preise fuer heute und morgen. */
function tb_abfrage_preise($homeId)
{
    $homeId = tb_gql_id($homeId);
    $rumpf = 'currentSubscription { priceInfo {'
           . ' current { total energy tax startsAt level currency }'
           . ' today { total energy tax startsAt level }'
           . ' tomorrow { total energy tax startsAt level }'
           . ' } }';
    if ($homeId !== '') {
        return '{ viewer { home(id: "' . $homeId . '") { ' . $rumpf . ' } } }';
    }
    return '{ viewer { homes { ' . $rumpf . ' } } }';
}

/** Verbrauchshistorie. $aufloesung ist HOURLY oder DAILY. */
function tb_abfrage_verbrauch($homeId, $aufloesung, $anzahl)
{
    $homeId = tb_gql_id($homeId);
    $aufloesung = ($aufloesung === 'DAILY') ? 'DAILY' : 'HOURLY';
    $anzahl = max(1, min(744, (int) $anzahl));
    $rumpf = 'consumption(resolution: ' . $aufloesung . ', last: ' . $anzahl . ')'
           . ' { nodes { from to cost unitPrice unitPriceVAT consumption consumptionUnit } }';
    if ($homeId !== '') {
        return '{ viewer { home(id: "' . $homeId . '") { ' . $rumpf . ' } } }';
    }
    return '{ viewer { homes { ' . $rumpf . ' } } }';
}

/**
 * Das gewaehlte Zuhause aus einer Antwort holen.
 *
 * Die Abfragen liefern je nach Form entweder home (ein Objekt) oder homes
 * (eine Liste). Beides wird hier auf denselben Nenner gebracht.
 */
function tb_erstes_home(array $data)
{
    if (isset($data['viewer']['home']) && is_array($data['viewer']['home'])) {
        return $data['viewer']['home'];
    }
    if (isset($data['viewer']['homes'][0]) && is_array($data['viewer']['homes'][0])) {
        return $data['viewer']['homes'][0];
    }
    return null;
}

/* ==================================================================
 * Preise aufbereiten
 *
 * Tibber liefert den Endpreis in Euro je kWh. Gerechnet und angezeigt wird
 * hier durchgehend in ct/kWh - so wie in den Plugins Spotpreis aWATTar und
 * Octopus, damit die Loxone-Seite bei allen dreien gleich aussieht.
 * ================================================================== */

function tb_niveau($ct, array $cfg)
{
    if ($ct <= (float) $cfg['guenstig']) { return 0; }   // guenstig
    if ($ct >= (float) $cfg['teuer'])    { return 2; }   // teuer
    return 1;                                            // normal
}

/** Tibbers eigenes Preisniveau als Zahl. Es ist unabhaengig von den eigenen
 *  Schwellen und wird zusaetzlich mitgefuehrt, nicht statt ihrer. */
function tb_tibber_level($text)
{
    $t = array('VERY_CHEAP' => 0, 'CHEAP' => 1, 'NORMAL' => 2,
               'EXPENSIVE' => 3, 'VERY_EXPENSIVE' => 4);
    $s = strtoupper((string) $text);
    return isset($t[$s]) ? $t[$s] : -1;
}

/**
 * Eine Preisliste von Tibber in die eigene Form bringen.
 * Rueckgabe: Liste aus array(ts, ct, ct_energie, ct_steuer, level)
 */
function tb_preisliste($roh, array $cfg)
{
    $aufschlag = (float) $cfg['aufschlag'];
    $out = array();
    foreach ((array) $roh as $e) {
        if (!isset($e['startsAt'], $e['total'])) { continue; }
        $ts = strtotime((string) $e['startsAt']);
        if ($ts === false) { continue; }
        $out[] = array(
            'ts'      => $ts,
            'ct'      => round((float) $e['total'] * 100 + $aufschlag, 3),
            'energie' => isset($e['energy']) ? round((float) $e['energy'] * 100, 3) : null,
            'steuer'  => isset($e['tax']) ? round((float) $e['tax'] * 100, 3) : null,
            'level'   => tb_tibber_level(isset($e['level']) ? $e['level'] : ''),
        );
    }
    usort($out, function ($a, $b) { return $a['ts'] - $b['ts']; });
    return $out;
}

/**
 * Wie lang ist ein Eintrag der Preisliste - GEMESSEN, nicht angenommen.
 *
 * Bis 0.9.6 stand an vier Stellen die feste Zahl 3600: ein Eintrag ist eine
 * Stunde. Das war richtig, solange Tibber Stundenpreise liefert.
 *
 * Seit dem 01.10.2025 werden die Boersenpreise in Deutschland und weiteren
 * Maerkten VIERTELSTUENDLICH gestellt, und die Tibber-Schnittstelle kennt
 * dafuer priceInfo(resolution: QUARTER_HOURLY). Ohne das Argument liefert sie
 * weiterhin Stundenwerte - das Plugin fragt ohne, hier aendert sich also
 * heute nichts. Tibber hat aber angekuendigt, QUARTER_HOURLY zur VORGABE zu
 * machen. Ab dann kaemen 96 Eintraege statt 24, und jede feste 3600 waere
 * still falsch: aus einem Drei-Stunden-Fenster wuerden 45 Minuten, und der
 * Preis der laufenden Stunde waere der der letzten Viertelstunde darin.
 *
 * Die Schrittweite wird deshalb aus der Liste selbst gelesen. Genommen wird
 * der HAEUFIGSTE Abstand zweier Eintraege, nicht der erste: um eine
 * Zeitumstellung herum gibt es einen Ausreisser, und ein Mittelwert waere
 * davon verzogen. Bei weniger als zwei Eintraegen gibt es nichts zu messen -
 * dann gilt die Stunde, und das steht als Rueckfall auch so da.
 */
function tb_schrittweite(array $liste)
{
    if (count($liste) < 2) { return 3600; }
    $zaehl = array();
    for ($i = 1; $i < count($liste); $i++) {
        $d = (int) ($liste[$i]['ts'] - $liste[$i - 1]['ts']);
        if ($d <= 0 || $d > 86400) { continue; }
        if (!isset($zaehl[$d])) { $zaehl[$d] = 0; }
        $zaehl[$d]++;
    }
    if (!$zaehl) { return 3600; }
    arsort($zaehl);
    $haeufigster = (int) key($zaehl);
    // Nur plausible Schrittweiten: 5 Minuten bis 1 Stunde. Alles andere ist
    // eher eine Luecke in der Reihe als eine Aufloesung.
    return ($haeufigster >= 300 && $haeufigster <= 3600) ? $haeufigster : 3600;
}

/** Kennzahlen einer Preisliste: Schnitt, Minimum, Maximum samt Stunde. */
function tb_kennzahlen(array $liste)
{
    if (!$liste) {
        return array('n' => 0, 'avg' => null, 'minp' => null, 'minh' => null,
                     'maxp' => null, 'maxh' => null);
    }
    $summe = 0.0;
    $min = null; $max = null; $minh = null; $maxh = null;
    foreach ($liste as $e) {
        $summe += $e['ct'];
        if ($min === null || $e['ct'] < $min) { $min = $e['ct']; $minh = (int) date('G', $e['ts']); }
        if ($max === null || $e['ct'] > $max) { $max = $e['ct']; $maxh = (int) date('G', $e['ts']); }
    }
    return array(
        'n'    => count($liste),
        'avg'  => round($summe / count($liste), 3),
        'minp' => $min, 'minh' => $minh,
        'maxp' => $max, 'maxh' => $maxh,
    );
}

/**
 * Das guenstigste zusammenhaengende Fenster ueber $stunden Stunden.
 *
 * Gesucht wird ab JETZT, nicht ab Mitternacht: ein Fenster, das vor zwei
 * Stunden guenstig war, nuetzt niemandem mehr.
 */
/**
 * Die gueltige Fensterlaenge in Stunden.
 *
 * Die Grenzen 1 und 12 standen bis 0.9.9 an drei Stellen: hier, in
 * tb_feldregeln() und im Formular der Oberflaeche. bin/tb_cron.php nahm den
 * Wert aus der Konfiguration UNGEKLEMMT und rechnete damit den Versatz des
 * zweiten Fensters - bei einer von Hand eingetragenen 0 war das zweite
 * Fenster byteweise das erste.
 */
function tb_fensterlaenge($stunden)
{
    return max(1, min(12, (int) $stunden));
}

function tb_fenster(array $liste, $stunden, $ab = null)
{
    $stunden = tb_fensterlaenge($stunden);
    if ($ab === null) { $ab = time(); }
    /* Wie viele EINTRAEGE sind das? Bei Stundenpreisen genau $stunden, bei
     * Viertelstundenpreisen viermal so viele. Die feste Gleichsetzung
     * "ein Eintrag = eine Stunde" haette aus einem Drei-Stunden-Fenster
     * unbemerkt ein 45-Minuten-Fenster gemacht. */
    $schritt = tb_schrittweite($liste);
    $anzahl = max(1, (int) round($stunden * 3600 / $schritt));
    $k = array();
    foreach ($liste as $e) {
        // Der laufende Abschnitt zaehlt mit, deshalb die Schrittweite Toleranz.
        if ($e['ts'] + $schritt > $ab) { $k[] = $e; }
    }
    if (count($k) < $anzahl) {
        return array('ts' => null, 'h' => null, 'in' => null, 'ct' => null);
    }
    $best = null; $bestI = 0;
    for ($i = 0; $i + $anzahl <= count($k); $i++) {
        $s = 0.0;
        for ($j = 0; $j < $anzahl; $j++) { $s += $k[$i + $j]['ct']; }
        if ($best === null || $s < $best) { $best = $s; $bestI = $i; }
    }
    $ts = $k[$bestI]['ts'];
    return array(
        'ts' => $ts,
        'h'  => (int) date('G', $ts),
        'in' => (int) max(0, round(($ts - $ab) / 3600)),
        'ct' => round($best / $anzahl, 3),
    );
}

/**
 * Das zweite, sich mit dem ersten NICHT ueberschneidende Fenster.
 *
 * Gesucht wird ab dem Ende des ersten; der Abstand 'in' zaehlt aber ab
 * JETZT. Das ist der ganze Punkt dieser Funktion, und bis 0.9.9 stimmte es
 * nicht: tb_fenster() rechnet 'in' immer gegen seinen eigenen Startpunkt,
 * hier also gegen das Ende des ersten Fensters. Gemessen am 02.09.2026 an
 * einem Pruefstueck (nachts 10 ct, abends 40 ct, Fensterlaenge 3):
 *
 *     jetzt 01:01  FENSTER2_IN=21, wirklich in 24 h
 *     jetzt 11:01  FENSTER2_IN=0,  wirklich in 17 h
 *
 * Die Sprachdatei sagt zu FENSTER_IN "0 heisst: es laeuft gerade". Eine
 * Loxone-Regel "laden, wenn FENSTER2_IN = 0" loeste damit mittags aus,
 * statt siebzehn Stunden zu warten.
 *
 * Findet sich kein zweites Fenster, bleiben alle Felder LEER statt auf einer
 * 0, die wie ein Messwert aussaehe.
 */
function tb_fenster_zweites(array $liste, $stunden, array $erstes, $jetzt = null)
{
    $leer = array('ts' => null, 'h' => null, 'in' => null, 'ct' => null);
    if (!isset($erstes['ts']) || $erstes['ts'] === null) { return $leer; }
    if ($jetzt === null) { $jetzt = time(); }
    $laenge = tb_fensterlaenge($stunden);
    $zweites = tb_fenster($liste, $laenge, $erstes['ts'] + $laenge * 3600);
    if ($zweites['ts'] === null) { return $leer; }
    $zweites['in'] = (int) max(0, round(($zweites['ts'] - $jetzt) / 3600));
    return $zweites;
}

/** Rang der laufenden Stunde in den naechsten 24 Stunden. 1 = guenstigste. */
function tb_rang(array $liste, $jetzt = null)
{
    if ($jetzt === null) { $jetzt = time(); }
    $schritt = tb_schrittweite($liste);
    $k = array();
    foreach ($liste as $e) {
        if ($e['ts'] + $schritt > $jetzt && $e['ts'] < $jetzt + 24 * 3600) { $k[] = $e['ct']; }
    }
    if (!$k) { return array(null, 0); }
    $aktuell = tb_preis_zur_zeit($liste, $jetzt);
    if ($aktuell === null) { return array(null, count($k)); }
    sort($k);
    $rang = 1;
    foreach ($k as $ct) {
        if ($ct < $aktuell - 0.0001) { $rang++; }
    }
    return array($rang, count($k));
}

/**
 * Der Preis, der zu einem Zeitpunkt gilt.
 *
 * Die Gueltigkeitsdauer eines Eintrags ist die gemessene Schrittweite, nicht
 * eine feste Stunde. Bei Viertelstundenpreisen passten sonst VIER Eintraege
 * auf denselben Zeitpunkt, und genommen wuerde der letzte davon - also der
 * Preis der letzten Viertelstunde als Preis der ganzen Stunde. Ein falscher
 * Wert, der aussieht wie ein richtiger.
 */
function tb_preis_zur_zeit(array $liste, $zeit)
{
    $schritt = tb_schrittweite($liste);
    $treffer = null;
    foreach ($liste as $e) {
        if ($e['ts'] <= $zeit && $zeit < $e['ts'] + $schritt) { $treffer = $e['ct']; }
    }
    return $treffer;
}

/* ---------------- Zwischenspeicher ---------------- */

function tb_stand()
{
    return tb_json_lesen(tb_paths()['datadir'] . '/stand.json');
}

function tb_live()
{
    return tb_json_lesen(tb_paths()['datadir'] . '/live.json');
}

function tb_verbrauch()
{
    return tb_json_lesen(tb_paths()['datadir'] . '/verbrauch.json');
}

/**
 * Ab welchem Alter gilt der Preisabruf als haengengeblieben?
 *
 * Bis 0.9.9 standen dafuer zwei feste Zahlen an zwei Stellen: 7200 Sekunden
 * im Meldungsbaum des Crons und noch einmal 7200 in der Statuskachel der
 * Oberflaeche. Der Abfragetakt laesst aber bis 1440 Minuten zu - wer ihn
 * hochsetzt, bekam dauerhaft eine Meldung, obwohl alles genau wie
 * eingestellt lief. Drei versaeumte Takte sind die Schwelle, mindestens
 * aber zwei Stunden: ein einzelner verpasster Lauf soll nichts ausloesen.
 */
function tb_altersschranke($cfg = null)
{
    if (!is_array($cfg)) { $cfg = tb_config(); }
    $takt = isset($cfg['preistakt']) ? (int) $cfg['preistakt'] : 30;
    return max(7200, 3 * max(1, $takt) * 60);
}

function tb_alter()
{
    $s = tb_stand();
    return isset($s['ts']) ? max(0, time() - (int) $s['ts']) : -1;
}

/** Alter der Pulse-Werte in Sekunden, oder -1. */
function tb_live_alter()
{
    $l = tb_live();
    return isset($l['ts']) ? max(0, time() - (int) $l['ts']) : -1;
}

/* ---------------- Der Pulse-Dienst ---------------- */

function tb_dienst_pid()
{
    $f = tb_paths()['datadir'] . '/pulse.pid';
    if (!is_file($f)) { return 0; }
    $pid = (int) trim((string) @file_get_contents($f));
    return tb_prozess_ist($pid, 'tb_pulse.php') ? $pid : 0;
}

function tb_dienst_soll()
{
    return is_file(tb_paths()['datadir'] . '/soll_laufen') ? 1 : 0;
}

/** $befehl ist 'start', 'stop' oder 'restart'. Rueckgabe: array(ok, Ausgabe) */
function tb_dienst($befehl)
{
    if (!in_array($befehl, array('start', 'stop', 'restart'), true)) {
        return array(0, 'Unbekannter Befehl.');
    }
    $skript = tb_paths()['bindir'] . '/dienst.sh';
    if (!is_file($skript)) {
        return array(0, 'dienst.sh nicht gefunden: ' . $skript);
    }
    $ausgabe = array();
    $code = 0;
    @exec(escapeshellcmd($skript) . ' ' . escapeshellarg($befehl) . ' 2>&1', $ausgabe, $code);
    return array($code === 0 ? 1 : 0, implode("\n", $ausgabe));
}

/* ==================================================================
 * Die Werte fuer Loxone
 *
 * Die Namen sind bewusst dieselben wie in den Plugins Spotpreis aWATTar und
 * Octopus, soweit es dieselben Groessen sind. Wer den Anbieter wechselt,
 * tauscht das Plugin - nicht die Bausteine.
 * ================================================================== */
/**
 * Die Feldtabelle - EINE Quelle fuer Statuszeile, MQTT, Importvorlage,
 * Feldtabelle der Oberflaeche und Baustein-Liste.
 *
 * Je Feld:
 *   einheit  fuer die Anzeige und fuer Unit="<v.1> …" in der Importvorlage
 *   kurz     Sprachschluessel der BESCHRIFTUNG. Sie wird zum Comment der
 *            Vorlage - und Loxone Config macht daraus den ANZEIGENAMEN des
 *            Bausteins. Deshalb eine Beschriftung, kein Satz: bis 0.9.6
 *            waren 25 von 38 Kommentaren laenger als 40 Zeichen, der
 *            laengste hatte 149.
 *   text     Sprachschluessel der ERKLAERUNG. Sie bleibt in der Oberflaeche,
 *            wo Platz dafuer ist.
 *   min/max  Grenzen, realistisch statt pauschal +/-2147483647: Loxone zieht
 *            daraus die Reglergrenzen und die Plausibilitaetspruefung.
 *   analog   0 = digitaler Eingang (0/1). Loxone Config schreibt Analog nur
 *            bei den analogen Werten; bis 0.9.6 trugen ALLE Befehle
 *            Analog="true", auch die drei Merker (NEG, MORGEN_OK, OK).
 *
 * NEUE FELDER GEHOEREN ANS ENDE. In der Mitte verschoeben sie die Reihenfolge
 * der Statuszeile, und jede beim Anwender eingetragene Befehlserkennung zeigte
 * danach auf den falschen Wert.
 */
function tb_status_felder()
{
    $f = function ($einheit, $name, $min, $max, $analog = 1) {
        return array('einheit' => $einheit,
                     'kurz' => 'TB_FELD_KURZ.' . $name,
                     'text' => 'TB_FELD.' . $name,
                     'min' => $min, 'max' => $max, 'analog' => $analog);
    };
    $r = array(
        'CUR'            => $f('ct/kWh', 'CUR',            0,      200),
        'CUR_ENERGIE'    => $f('ct/kWh', 'CUR_ENERGIE',    -100,   200),
        'CUR_STEUER'     => $f('ct/kWh', 'CUR_STEUER',     0,      100),
        'NEXT'           => $f('ct/kWh', 'NEXT',           0,      200),
        'LEVEL'          => $f('',       'LEVEL',          0,      2),
        'TLEVEL'         => $f('',       'TLEVEL',         -1,     4),
        'RANK'           => $f('',       'RANK',           0,      48),
        'RANKD'          => $f('',       'RANKD',          0,      48),
        'NEG'            => $f('',       'NEG',            0,      1,     0),
        'AVG_HEUTE'      => $f('ct/kWh', 'AVG_HEUTE',      0,      200),
        'MIN_HEUTE'      => $f('ct/kWh', 'MIN_HEUTE',      -100,   200),
        'MINH_HEUTE'     => $f('h',      'MINH_HEUTE',     0,      23),
        'MAX_HEUTE'      => $f('ct/kWh', 'MAX_HEUTE',      0,      200),
        'MAXH_HEUTE'     => $f('h',      'MAXH_HEUTE',     0,      23),
        'MORGEN_OK'      => $f('',       'MORGEN_OK',      0,      1,     0),
        'AVG_MORGEN'     => $f('ct/kWh', 'AVG_MORGEN',     0,      200),
        'MIN_MORGEN'     => $f('ct/kWh', 'MIN_MORGEN',     -100,   200),
        'MINH_MORGEN'    => $f('h',      'MINH_MORGEN',    0,      23),
        'MAX_MORGEN'     => $f('ct/kWh', 'MAX_MORGEN',     0,      200),
        'MAXH_MORGEN'    => $f('h',      'MAXH_MORGEN',    0,      23),
        'FENSTER_H'      => $f('h',      'FENSTER_H',      0,      23),
        'FENSTER_IN'     => $f('h',      'FENSTER_IN',     0,      47),
        'FENSTER_CT'     => $f('ct/kWh', 'FENSTER_CT',     0,      200),
        'PULSE'          => $f('W',      'PULSE',          -30000, 30000),
        'PULSE_ERZ'      => $f('W',      'PULSE_ERZ',      0,      30000),
        'PULSE_TAG'      => $f('kWh',    'PULSE_TAG',      0,      500),
        'PULSE_KOSTEN'   => $f('EUR',    'PULSE_KOSTEN',   0,      500),
        /* Gedeckelt auf denselben Wert in tb_werte(): 86400 s heisst
         * "mindestens einen Tag alt". Ungedeckelt waeren nach zwei Tagen
         * ohne Pulse 172800 hinausgegangen - mehr, als die Vorlage zusagt. */
        'PULSE_ALTER'    => $f('s',      'PULSE_ALTER',    0,      86400),
        'VERBR_GESTERN'  => $f('kWh',    'VERBR_GESTERN',  0,      1000),
        'KOSTEN_GESTERN' => $f('EUR',    'KOSTEN_GESTERN', 0,      1000),
        'VERBR_MONAT'    => $f('kWh',    'VERBR_MONAT',    0,      10000),
        'KOSTEN_MONAT'   => $f('EUR',    'KOSTEN_MONAT',   0,      10000),
        'DYN_MONAT'      => $f('ct/kWh', 'DYN_MONAT',      0,      200),
        'FIX'            => $f('ct/kWh', 'FIX',            0,      200),
        'DIFF_MONAT'     => $f('ct/kWh', 'DIFF_MONAT',     -100,   100),
        'EURO_MONAT'     => $f('EUR',    'EURO_MONAT',     -1000,  1000),
        /* 9999 heisst "noch nie abgerufen" (tb_werte). Bis 0.9.9 stand hier
         * MaxVal 1440 - der Kennwert lag also ausserhalb des Bereichs, den
         * die eigene Importvorlage Loxone zusagt, und ein echtes Alter von
         * mehr als einem Tag ebenso. Genau im Ausfall war damit das Feld
         * unzuverlaessig, an dem die Ausfallerkennung haengt. */
        'ALTER'          => $f('min',    'ALTER',          0,      9999),
        'OK'             => $f('',       'OK',             0,      1,     0),
        /* --- ab 0.9.7, deshalb hinten --- */
        'ZAEHLER'        => $f('',       'ZAEHLER',        -1,     999),
        'FENSTER2_H'     => $f('h',      'FENSTER2_H',     0,      23),
        'FENSTER2_IN'    => $f('h',      'FENSTER2_IN',    0,      47),
        'FENSTER2_CT'    => $f('ct/kWh', 'FENSTER2_CT',    0,      200),
        'FENSTER_MORGEN_H'  => $f('h',      'FENSTER_MORGEN_H',  0, 23),
        'FENSTER_MORGEN_CT' => $f('ct/kWh', 'FENSTER_MORGEN_CT', 0, 200),
        'AVG_30T'        => $f('ct/kWh', 'AVG_30T',        0,      200),
        'RANK_30T'       => $f('%',      'RANK_30T',       0,      100),
        'GUENSTIGANTEIL' => $f('%',      'GUENSTIGANTEIL', 0,      100),
        'ERSPARNIS_GESTERN' => $f('EUR', 'ERSPARNIS_GESTERN', -100, 100),
    );
    /* --- ab 0.9.11: der Fahrplaner, deshalb hinten ---
     *
     * Angehaengt und nicht eingeschoben: die Befehlserkennung in Loxone sucht
     * Textstellen, kein bestehender virtueller Eingang merkt davon etwas.
     *
     * Die Namen sind WORTGLEICH mit denen der Schwesterlinien Spotpreis
     * aWATTar und Spotpreis Octopus - R1, R1IN, R1REST, R1CT, R1VERD,
     * R1SPERRE und die vier PLAN-Groessen. Das ist die Zusage, die im README
     * dieser Linie steht: wer den Anbieter wechselt, tauscht das Plugin und
     * laesst die Bausteine stehen. Sie stehen dort in einer eigenen Zeile
     * (REGEL;… und PLAN;…), hier in der einen Statuszeile - der Suchtext
     * \i;R1=\i\v findet sie so wie so, denn Loxone sucht Textstellen und
     * keine Zeilen.
     *
     * Warum 'analog' => 0 fuer R<n>: der Wert IST digital, und genau dafuer
     * steht die 0. Verdraengt ebenso; Sperre hat vier Zustaende und bleibt
     * analog.
     *
     * Die Sprachschluessel tragen die REGELNUMMER, sind also ausgeschrieben
     * und nicht aus einem Platzhalter gebildet. Der Grund steht in Loxone:
     * der Kommentar der Importvorlage wird dort zum Anzeigenamen des
     * Bausteins, und vier Bausteine, die alle "Schaltregel laeuft" heissen,
     * sind vier Bausteine, die niemand auseinanderhaelt. */
    $g = array();
    for ($i = 1; $i <= TB_REGELN; $i++) {
        $g['R' . $i]            = $f('',       'R' . $i . '_AKTIV',   0,  1, 0);
        $g['R' . $i . 'IN']     = $f('h',      'R' . $i . '_IN',     -1, 47);
        $g['R' . $i . 'REST']   = $f('h',      'R' . $i . '_REST',    0, 47);
        $g['R' . $i . 'CT']     = $f('ct/kWh', 'R' . $i . '_CT',   -100, 200);
        /* Kein Ja/Nein, sondern eine ANZAHL: 'verdraengt' zaehlt die
         * Zeitscheiben, die das Leistungsbudget dieser Regel weggenommen
         * hat - plan_rechnen() bildet sie als count(ohne) - count(mit).
         * Mit MaxVal 1 haette die Importvorlage Loxone einen Bereich
         * zugesagt, den der Wert verlaesst, und der Baustein waere
         * digital gewesen. Dieselbe Klasse wie ALTER bis 0.9.9. Am
         * Pruefstand gemessen: zwei 4-kW-Regeln unter einem 5-kW-Budget
         * ergaben fuer die zweite den Wert 3. */
        $g['R' . $i . 'VERD']   = $f('',       'R' . $i . '_VERD',    0, 48);
        /* 0 frei, 1 PV-Prognose, 2 Speicher zu leer, 3 Speicher zu voll -
         * eine Zahl, weil Loxone mit Zahlen rechnet und nicht mit Woertern. */
        $g['R' . $i . 'SPERRE'] = $f('',       'R' . $i . '_SPERRE',  0,  3);
    }
    /* PVSUM und SOC koennen "nicht gemessen" sein. Sie gehen dann als Strich
     * hinaus (tb_w in der Endpunktdatei) und nicht als 0 - eine 0 waere eine
     * stille Falschaussage, und Loxone behaelt beim Strich den letzten Wert.
     * Deshalb reicht der Bereich von SOC bis 0 und nicht bis -1. */
    $g['PVSUM']    = $f('kWh', 'PVSUM',    0, 1000);
    $g['SOC']      = $f('%',   'SOC',      0, 100);
    $g['BUDGET']   = $f('kW',  'BUDGET',   0, 200);
    $g['PLANLAST'] = $f('kW',  'PLANLAST', 0, 200);
    return array_merge($r, $g);
}

/**
 * Die Felder der Statuszeile in ihrer Reihenfolge - und die Antwort auf die
 * Frage, ob ein Suchtext eindeutig ist.
 *
 * Geprueft wird nicht der Name gegen den Namen, sondern der SUCHTEXT gegen
 * die gebaute Zeile: Loxone sucht woertlich und nimmt den ersten Treffer.
 * Genau das ist bis 0.9.6 bei ALTER und OK schiefgegangen.
 *
 * Rueckgabe: array(Zahl der Felder, Liste der Felder, die falsch treffen).
 */
function tb_suchtext_pruefen()
{
    $felder = array_keys(tb_status_felder());
    $zeile = 'TIBBER';
    $stelle = array();
    foreach ($felder as $name) {
        $stelle[$name] = strlen($zeile);       // Stelle des ';' vor dem Namen
        $zeile .= ';' . $name . '=1';
    }
    $falsch = array();
    foreach ($felder as $name) {
        /* Der wirklich gesuchte Text kommt aus tb_check() - er wird NICHT hier
         * noch einmal nachgebaut.
         *
         * Der erste Anlauf dieser Zeile hat genau das getan und ';NAME='
         * selbst zusammengesetzt. Sie blieb damit gruen, als die Eichung das
         * Trennzeichen aus tb_check() entfernte: die Pruefung mass ihre eigene
         * Annahme statt des Musters, das in die Vorlage wandert. Zwei Stellen,
         * die dasselbe zusammensetzen, laufen auseinander - auch dann, wenn
         * die eine die andere pruefen soll.
         *
         * In einem Check steht der Literaltext zwischen dem ersten \i und dem
         * naechsten; \v ist der Wert. */
        $such = tb_check_literal(tb_check($name));
        if ($such === '') { $falsch[] = $name; continue; }
        $treffer = strpos($zeile, $such);
        if ($treffer === false) { $falsch[] = $name; continue; }
        /* Nicht die Stelle vergleichen, sondern das FELD.
         *
         * Gemeldet wird, wer den falschen WERT liest - nicht, wer um ein
         * Zeichen daneben trifft. Ohne diese Unterscheidung meldete die Zeile
         * beim Ruecknahme-Versuch alle 48 Felder als falsch, obwohl nur zwei
         * wirklich den Wert eines anderen bekommen. Eine Zahl, die zu gross
         * ist, wird beim naechsten Mal nicht mehr gelesen. */
        $ende = strpos($zeile, '=', $treffer);
        $anfang = strrpos(substr($zeile, 0, $ende), ';');
        $getroffen = ($anfang === false) ? '' : substr($zeile, $anfang + 1, $ende - $anfang - 1);
        if ($getroffen !== $name) {
            $falsch[] = $name . ' (liest ' . $getroffen . ')';
        }
    }
    return array(count($felder), $falsch);
}

/**
 * Aus einem Loxone-Check den Text holen, nach dem der Miniserver WOERTLICH
 * sucht - also alles zwischen dem ersten Steuerzeichen \i und dem naechsten.
 */
function tb_check_literal($check)
{
    $marke = chr(92) . 'i';                       // die zwei Zeichen \ und i
    $a = strpos($check, $marke);
    if ($a === false) { return ''; }
    $a += strlen($marke);
    $b = strpos($check, $marke, $a);
    if ($b === false) { return ''; }
    return substr($check, $a, $b - $a);
}

/** Alle Werte in einem flachen Feld, so wie sie der Endpunkt ausgibt. */
function tb_werte()
{
    $cfg = tb_config();
    $st = tb_stand();
    $live = tb_live();
    $vb = tb_verbrauch();
    $w = array();
    foreach (tb_status_felder() as $f => $unbenutzt) { $w[$f] = null; }

    $felder = tb_status_felder();
    $w['OK'] = !empty($st['ok']) ? 1 : 0;
    /* Die Obergrenze kommt aus der Feldtabelle, nicht aus einer zweiten Zahl.
     * 9999 (= MaxVal) heisst "noch nie abgerufen oder hoffnungslos alt" -
     * beides loest jede Ausfallschwelle aus, und beides bleibt innerhalb des
     * Bereichs, den die Importvorlage Loxone zusagt. */
    $alter = tb_alter();
    $w['ALTER'] = $alter < 0 ? (int) $felder['ALTER']['max']
                : (int) min((float) $felder['ALTER']['max'], round($alter / 60));

    foreach (array('CUR' => 'cur', 'CUR_ENERGIE' => 'cur_energie', 'CUR_STEUER' => 'cur_steuer',
                   'NEXT' => 'next', 'LEVEL' => 'level', 'TLEVEL' => 'tlevel',
                   'RANK' => 'rank', 'RANKD' => 'rankd', 'NEG' => 'neg',
                   'FENSTER_H' => 'fenster_h', 'FENSTER_IN' => 'fenster_in',
                   'FENSTER_CT' => 'fenster_ct', 'MORGEN_OK' => 'morgen_ok',
                   'FENSTER2_H' => 'fenster2_h', 'FENSTER2_IN' => 'fenster2_in',
                   'FENSTER2_CT' => 'fenster2_ct',
                   'FENSTER_MORGEN_H' => 'fenster_morgen_h',
                   'FENSTER_MORGEN_CT' => 'fenster_morgen_ct',
                   'AVG_30T' => 'avg_30t', 'RANK_30T' => 'rank_30t') as $gross => $klein) {
        if (isset($st[$klein])) { $w[$gross] = $st[$klein]; }
    }

    /* Der Laufzaehler wird zur LESEZEIT geholt, nicht beim Schreiben
     * eingefroren - genauso wie ALTER. Ein eingefrorenes Lebenszeichen kann
     * einen toten Dienst nicht von einer frischen Messung unterscheiden. */
    $w['ZAEHLER'] = tb_zaehler_lesen();
    foreach (array('heute' => 'HEUTE', 'morgen' => 'MORGEN') as $tag => $gross) {
        if (!isset($st[$tag]) || !is_array($st[$tag])) { continue; }
        $w['AVG_' . $gross]  = $st[$tag]['avg'];
        $w['MIN_' . $gross]  = $st[$tag]['minp'];
        $w['MINH_' . $gross] = $st[$tag]['minh'];
        $w['MAX_' . $gross]  = $st[$tag]['maxp'];
        $w['MAXH_' . $gross] = $st[$tag]['maxh'];
    }

    // Pulse. Ein Wert, der aelter als zwei Minuten ist, gilt als nicht
    // vorhanden - er bleibt null statt eine Momentanleistung vorzutaeuschen,
    // die es seit Stunden nicht mehr gibt.
    $la = tb_live_alter();
    $w['PULSE_ALTER'] = $la < 0 ? null
                      : (int) min((float) $felder['PULSE_ALTER']['max'], $la);
    if ($la >= 0 && $la <= 120) {
        $w['PULSE']        = isset($live['power']) ? $live['power'] : null;
        $w['PULSE_ERZ']    = isset($live['powerProduction']) ? $live['powerProduction'] : null;
        $w['PULSE_TAG']    = isset($live['accumulatedConsumption']) ? $live['accumulatedConsumption'] : null;
        $w['PULSE_KOSTEN'] = isset($live['accumulatedCost']) ? $live['accumulatedCost'] : null;
    }

    foreach (array('VERBR_GESTERN' => 'verbr_gestern', 'KOSTEN_GESTERN' => 'kosten_gestern',
                   'VERBR_MONAT' => 'verbr_monat', 'KOSTEN_MONAT' => 'kosten_monat',
                   'DYN_MONAT' => 'dyn_monat', 'DIFF_MONAT' => 'diff_monat',
                   'EURO_MONAT' => 'euro_monat',
                   'GUENSTIGANTEIL' => 'guenstiganteil',
                   'ERSPARNIS_GESTERN' => 'ersparnis_gestern') as $gross => $klein) {
        if (isset($vb[$klein])) { $w[$gross] = $vb[$klein]; }
    }
    $w['FIX'] = (float) $cfg['festpreis'];

    /* ---- Fahrplaner (ab 0.9.11) ----
     *
     * Gerechnet wird HIER, zur Lesezeit, und nicht im Cron.
     *
     * Der Unterschied ist keine Geschmacksfrage. stand.json wird nur
     * geschrieben, wenn wirklich Preise geholt wurden - ab Werk alle 30
     * Minuten, einstellbar bis 1440. Ein im Cron gerechneter Fahrplan stuende
     * also bis zu einem Tag lang still, waehrend 'in' und 'rest'
     * herunterzaehlen muessen und eine Regel zur vollen Stunde umschaltet.
     * Die Rechnung selbst ist reine Arithmetik ueber hoechstens 48 Werte und
     * braucht weder Netz noch Datei.
     *
     * Was der Cron sehr wohl tut: die fremden Auskuenfte auffrischen
     * (tb_umwelt(true)) und die Hysterese fortschreiben. Beides hat eine
     * Wirkung nach aussen und gehoert deshalb nicht in einen Lesepfad, den
     * der unangemeldete Endpunkt anstoesst.
     */
    $fp = tb_fahrplan($st);
    /* ZUERST die Vorgaben fuer ALLE Regeln, dann erst das Ergebnis darueber.
     *
     * Ohne diesen Block blieben die Felder null, sobald der Fahrplan leer ist
     * - keine Preise, Schnittstelle ausgefallen, Konfiguration frisch. Der
     * Endpunkt macht aus null einen Strich, und Loxone behaelt beim Strich
     * den LETZTEN Wert. Stand dort eine 1, laedt die Wallbox weiter, waehrend
     * das Plugin nichts mehr weiss. Am 04.09.2026 an einer Anlage ohne Preise
     * gemessen: R1 bis R4 gingen als Strich hinaus.
     *
     * R<n>CT bleibt ausdruecklich null: das ist ein Preis, also ein Messwert,
     * und "nicht gemessen" ist dort die Wahrheit. */
    for ($i = 1; $i <= TB_REGELN; $i++) {
        $w['R' . $i]            = 0;
        $w['R' . $i . 'IN']     = -1;    // kein Block im Horizont
        $w['R' . $i . 'REST']   = 0;
        $w['R' . $i . 'VERD']   = 0;
        $w['R' . $i . 'SPERRE'] = 0;
    }
    foreach ($fp['regeln'] as $r) {
        $n = (int) $r['nr'];
        if ($n < 1 || $n > TB_REGELN) { continue; }
        $w['R' . $n]            = (int) $r['aktiv'];
        $w['R' . $n . 'IN']     = (int) $r['in'];
        $w['R' . $n . 'REST']   = (int) $r['rest'];
        $w['R' . $n . 'CT']     = $r['ct'];
        $w['R' . $n . 'VERD']   = (int) $r['verdraengt'];
        $w['R' . $n . 'SPERRE'] = tb_sperre_zahl($r['gesperrt']);
    }
    /* Nicht gemessen bleibt null und wird NICHT zu 0. Eine PV-Prognose von 0
     * kWh ist eine Aussage (Nacht, Nebel); "keine Quelle eingerichtet" ist
     * keine. Der Endpunkt macht daraus einen Strich, und Loxone behaelt den
     * letzten Wert. */
    $w['PVSUM']    = $fp['pv_summe'];
    $w['SOC']      = $fp['soc'];
    $w['BUDGET']   = (float) $cfg['budget_kw'];
    $w['PLANLAST'] = $fp['planlast'];
    return $w;
}

/* ==================================================================
 * Der Verlauf
 *
 * bin/tb_cron.php schreibt stuendlich eine Zeile nach
 * data/plugins/<ordner>/verlauf/<JJJJMM>.csv:  ts;ct;tagesschnitt
 *
 * Bis 0.9.6 hat diese Dateien NIEMAND gelesen - gemessen ueber den ganzen
 * Ordner: die Zeichenfolge 'verlauf' kam ausserhalb des Schreibens nirgends
 * vor. Dazu gab es ein Eingabefeld "Verlauf aufbewahren (Tage)", dessen
 * einzige Wirkung war, wie lange ungelesene Dateien liegen bleiben.
 *
 * Seit 0.9.7 beantworten sie die Frage, die dem Plugin bis dahin fehlte:
 * IST 24 ct VIEL? Eine feste Schwelle kann das nicht sagen, ein Vergleich mit
 * den letzten dreissig Tagen schon.
 * ================================================================== */

/* ==================================================================
 * Der Fahrplaner - Anschluss an diese Linie
 *
 * Das Rechenwerk steht in planer.php und ist dort ohne Netz durchgeprueft.
 * Hier steht nur, was diese Linie beitraegt: die Preisreihe, die fremden
 * Auskuenfte und das Gedaechtnis.
 * ================================================================== */

/**
 * Eine JSON-Auskunft holen. Rueckgabe: Feld oder null.
 *
 * Zwei Zeitschranken, nicht eine: 'timeout' im Kontext gilt nur fuer das
 * LESEN. Fuer den Verbindungsaufbau gilt default_socket_timeout, und der
 * steht auf dem LoxBerry auf 60 Sekunden. Eine tote Gegenstelle haette den
 * Aufruf also eine Minute lang aufgehalten.
 */
function tb_holen($url, $sekunden = 12)
{
    $url = trim((string) $url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) { return null; }
    $sekunden = max(2, min(30, (int) $sekunden));
    $alt = @ini_set('default_socket_timeout', (string) $sekunden);
    $ctx = stream_context_create(array('http' => array(
        'timeout'         => $sekunden,
        'method'          => 'GET',
        'user_agent'      => 'LoxBerry Spotpreis Tibber',
        'header'          => "Accept: application/json\r\nAccept-Language: de\r\n",
        /* Keiner Umleitung folgen. Diese Adressen tragen zwar keine
         * Anmeldung, aber die Regel gilt fuer jeden Abruf dieser Linie -
         * eine Umleitung auf einen fremden Rechner ist nichts, was ein
         * Plugin von sich aus mitmacht. */
        'follow_location' => 0,
        'max_redirects'   => 1,
        'ignore_errors'   => true)));
    $r = @file_get_contents($url, false, $ctx);
    if ($alt !== false) { @ini_set('default_socket_timeout', $alt); }
    if ($r === false) { return null; }
    /* Eine Antwort ist noch kein Lebenszeichen. Mit ignore_errors kommt auch
     * eine 404 als Zeichenkette an; gilt die LETZTE Statuszeile, denn bei
     * einer Umleitung stehen mehrere im Feld. */
    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $z) {
            if (preg_match('#^HTTP/[0-9.]+\s+([0-9]{3})#', (string) $z, $m)) {
                $code = (int) $m[1];
            }
        }
    }
    if ($code !== 0 && ($code < 200 || $code > 299)) { return null; }
    $d = json_decode($r, true);
    return is_array($d) ? $d : null;
}

/**
 * PV-Prognose und Speicherstand.
 *
 * Der Zwischenspeicher liegt unter data/ und nicht unter /tmp: /tmp ist auf
 * dem LoxBerry eine Ramdisk, und nach jedem Neustart stuende die Prognose
 * wieder auf "nicht gemessen", bis der naechste Abruf gelingt.
 *
 * $holen entscheidet, ob ueberhaupt ins Netz gegangen wird - und das ist der
 * Unterschied zu den Schwesterlinien. Dort holt die Lesefunktion selbst nach,
 * sobald der Speicher alt ist; hier tut das NUR der Cron. Der Grund steht in
 * der Regel "der unangemeldete Endpunkt darf nichts ausloesen": tb_werte()
 * wird vom Loxone-Endpunkt gerufen, und ein Miniserver, der alle fuenf
 * Minuten fragt, wuerde sonst bei kaltem Speicher zwei fremde Dienste
 * anfragen und bis zu 24 Sekunden auf seine Antwort warten.
 *
 * Rueckgabe immer vollstaendig, auch wenn nichts gemessen wurde.
 */
function tb_umwelt($holen = false, $slotlen = 3600)
{
    $cfg = tb_config();
    $leer = array('pv' => null, 'pv_summe' => null, 'soc' => null,
                  'pv_meldung' => '', 'soc_meldung' => '', 'ts' => 0);
    $cache = tb_paths()['datadir'] . '/umwelt.json';
    $alt = tb_json_lesen($cache);
    $frisch = is_array($alt) && isset($alt['ts'])
              && time() - (int) $alt['ts'] < 900;
    if (!$holen || $frisch) {
        return is_array($alt) && $alt ? $alt + $leer : $leer;
    }

    $erg = $leer;
    $erg['ts'] = time();
    $slotlen = max(60, (int) $slotlen);
    $jetzt = time() - (time() % $slotlen);
    $zeit = isset($cfg['zeitueberschreitung']) ? (int) $cfg['zeitueberschreitung'] : 15;

    if ($cfg['pv_quelle'] !== '' && trim((string) $cfg['pv_url']) !== '') {
        $roh = tb_holen($cfg['pv_url'], $zeit);
        if ($roh === null) {
            $erg['pv_meldung'] = 'NICHT_ERREICHBAR';
        } else {
            list($pv, $m) = plan_pv_lesen($roh, $cfg['pv_quelle'], $cfg['pv_pfad'],
                $cfg['pv_zeitfeld'], $cfg['pv_wertfeld'], $cfg['pv_einheit'], $slotlen);
            $erg['pv_meldung'] = $m;
            if ($pv) {
                $erg['pv'] = $pv;
                $erg['pv_summe'] = plan_pv_summe($pv, $jetzt, 24);
            }
        }
    }
    if (trim((string) $cfg['soc_url']) !== '') {
        $roh = tb_holen($cfg['soc_url'], $zeit);
        if ($roh === null) {
            $erg['soc_meldung'] = 'NICHT_ERREICHBAR';
        } else {
            list($soc, $m) = plan_soc_lesen($roh, $cfg['soc_pfad']);
            $erg['soc_meldung'] = $m;
            $erg['soc'] = $soc;
        }
    }
    /* Eine Stoerung ueberschreibt die zuletzt gemessenen Werte nicht: was
     * gestern um zwoelf an Prognose vorlag, ist eine bessere Auskunft als
     * gar keine - aber nur, wenn die Meldung dazu sichtbar bleibt. Deshalb
     * wird der ALTE Wert uebernommen und die Meldung NICHT. */
    if ($erg['pv'] === null && is_array($alt) && isset($alt['pv']) && $alt['pv']) {
        $erg['pv'] = $alt['pv'];
        $erg['pv_summe'] = isset($alt['pv_summe']) ? $alt['pv_summe'] : null;
    }
    if ($erg['soc'] === null && is_array($alt) && isset($alt['soc']) && $alt['soc'] !== null) {
        $erg['soc'] = $alt['soc'];
    }
    tb_json_schreiben($cache, $erg);
    return $erg;
}

/**
 * Den Sperrgrund als Zahl - Loxone rechnet mit Zahlen, nicht mit Woertern.
 * 0 frei, 1 PV-Prognose, 2 Speicher zu leer, 3 Speicher zu voll.
 */
function tb_sperre_zahl($grund)
{
    if ($grund === 'pv') { return 1; }
    if ($grund === 'soc_min') { return 2; }
    if ($grund === 'soc_max') { return 3; }
    return 0;
}

/* ---------------- Hysterese: was laeuft, laeuft zu Ende ----------------
 *
 * Der Planer bekommt bei jedem Lauf eine frische Preisreihe und hat kein
 * Gedaechtnis. Ohne eines kann er zu jedem Abruf ein anderes Ergebnis
 * liefern - und die Wallbox schaltet mitten im Laden ab, weil in drei
 * Stunden eine Stunde billiger geworden ist.
 *
 * Gemerkt wird EINE Zahl je Regel: bis wann der begonnene Block laeuft. Sie
 * wird gesetzt, wenn ein Block ANFAENGT, und bis zu seinem Ende nicht mehr
 * angefasst - sonst koennte sie sich selbst verlaengern, und das waere eine
 * Regel, die nie wieder ausgeht.
 *
 * Die Ablage liegt unter data/ und nicht unter /tmp. Die Schwesterlinien
 * legen sie nach /tmp und begruenden das damit, dass nach einem Neustart
 * ohnehin nichts mehr faehrt. Das stimmt fuer den LoxBerry, nicht aber fuer
 * das Geraet an der Steckdose: eine Waschmaschine laeuft weiter, waehrend
 * der Raspberry neu startet. Nach dem Neustart wuerde die Regel dann neu
 * entscheiden - genau das, was die Hysterese verhindern soll.
 */

/** array(Regelindex => bis_ts). Abgelaufene Eintraege fallen weg. */
function tb_laufend_lesen()
{
    $cfg = tb_config();
    if (empty($cfg['hysterese'])) { return array(); }
    $d = tb_json_lesen(tb_paths()['datadir'] . '/laufend.json');
    if (!is_array($d)) { return array(); }
    $jetzt = time();
    $out = array();
    foreach ($d as $i => $bis) {
        if (is_array($bis)) { continue; }
        $bis = (int) $bis;
        // Harte Obergrenze: kein Block laeuft laenger als 24 Stunden.
        if ($bis > $jetzt && $bis <= $jetzt + 86400) { $out[(int) $i] = $bis; }
    }
    return $out;
}

/**
 * Nach der Rechnung fortschreiben. Drei Faelle je Regel:
 *   laeuft und war noch nicht vermerkt  -> Ende eintragen
 *   laeuft und war vermerkt             -> unveraendert stehen lassen
 *   laeuft nicht                        -> Eintrag entfernen
 *
 * Nur der Cron ruft das auf. Ein Lesepfad, der ein Gedaechtnis fortschreibt,
 * waere von der Zahl der Abrufe abhaengig.
 */
function tb_laufend_fortschreiben($regeln, $jetzt)
{
    $cfg = tb_config();
    $f = tb_paths()['datadir'] . '/laufend.json';
    if (empty($cfg['hysterese'])) {
        /* is_file() VOR unlink(). Das @ genuegt nicht, wenn ein eigener
         * Fehlerbehandler gesetzt ist: der wird unabhaengig von
         * error_reporting gerufen, und "No such file or directory" stuende
         * als Befund im Protokoll, obwohl nichts fehlt. */
        if (is_file($f)) { @unlink($f); }
        return;
    }
    $alt = tb_laufend_lesen();
    $neu = array();
    foreach ((array) $regeln as $r) {
        if (!is_array($r) || empty($r['aktiv'])) { continue; }
        $i = (int) $r['nr'] - 1;
        if (isset($alt[$i])) { $neu[$i] = $alt[$i]; continue; }
        // 'rest' kommt hier in STUNDEN an - tb_fahrplan() rechnet die Minuten
        // des Planers dort um, und diese Liste ist das Ergebnis davon.
        $rest = isset($r['rest']) ? (int) $r['rest'] : 0;
        if ($rest > 0) { $neu[$i] = (int) $jetzt + $rest * 3600; }
    }
    tb_json_schreiben($f, $neu);
}

/**
 * Der Fahrplan.
 *
 * Rueckgabe:
 *   'regeln'   je Regel nr, aktiv, in, rest, ct, verdraengt, gesperrt, ein,
 *              name, art, rang, fehlt, spart_ct, spart_eur
 *   'plan'     dasselbe MIT den Zeitscheiben - nur fuer die Anzeige
 *   'belegung' ts => kW, was zu welcher Scheibe verplant ist
 *   'preise'   ts => ct, die Reihe, mit der gerechnet wurde
 *   'slotlen'  Sekunden je Zeitscheibe, gemessen an der Preisreihe
 *   'pv_summe' kWh der naechsten 24 h, oder null
 *   'soc'      Prozent, oder null
 *   'planlast' kW, die in der laufenden Scheibe verplant sind
 *
 * 'in' und 'rest' kommen aus dem Planer in MINUTEN und gehen hier in STUNDEN
 * hinaus - diese Linie rechnet seit jeher in Stunden, und daran haengen die
 * virtuellen Eingaenge im Miniserver.
 */
function tb_fahrplan($st = null)
{
    $cfg = tb_config();
    if (!is_array($st)) { $st = tb_stand(); }
    $leer = array('regeln' => array(), 'plan' => array(), 'belegung' => array(),
                  'preise' => array(), 'slotlen' => 3600,
                  'pv_summe' => null, 'soc' => null, 'planlast' => 0.0);

    // Die Preisreihe aus heute und morgen, in ct/kWh - so, wie der Planer
    // sie erwartet, und so, wie tb_stand() sie ablegt.
    $liste = array();
    foreach (array('liste_heute', 'liste_morgen') as $tag) {
        foreach ((array) (isset($st[$tag]) ? $st[$tag] : array()) as $e) {
            if (!is_array($e) || !isset($e['ts']) || !isset($e['ct'])) { continue; }
            if ($e['ct'] === null) { continue; }
            $liste[(int) $e['ts']] = (float) $e['ct'];
        }
    }
    if (!$liste) { return $leer; }
    ksort($liste);

    /* Die Scheibenlaenge wird GEMESSEN, nicht gesetzt. Tibber liefert heute
     * Stundenpreise; liefert das Konto eines Tages Viertelstunden, rechnet
     * der Planer damit, ohne dass jemand etwas einstellt. tb_schrittweite()
     * beantwortet dieselbe Frage schon fuer die Fensterrechnung - es waere
     * eine zweite Wahrheit, sie hier noch einmal zu bilden. */
    $roh = array();
    foreach ($liste as $ts => $ct) { $roh[] = array('ts' => $ts, 'ct' => $ct); }
    $slotlen = tb_schrittweite($roh);
    if ($slotlen < 60) { $slotlen = 3600; }

    $jetzt = time() - (time() % $slotlen);
    $umwelt = tb_umwelt(false, $slotlen);

    /* Das Tagesmittel nur uebergeben, wenn es eines GIBT. 0.0 waere ein Wert
     * und kein Nichtwissen - und bei negativen Preisen ist der Unterschied
     * entscheidend. */
    $mittel = null;
    if (!empty($st['ok']) && isset($st['heute']['avg']) && $st['heute']['avg'] !== null
        && !empty($st['heute']['n'])) {
        $mittel = (float) $st['heute']['avg'];
    }

    $plan = plan_rechnen($liste, $slotlen, $jetzt, $cfg['regeln'], array(
        'pv'       => isset($umwelt['pv']) ? $umwelt['pv'] : null,
        'pv_summe' => isset($umwelt['pv_summe']) ? $umwelt['pv_summe'] : null,
        'soc'      => isset($umwelt['soc']) ? $umwelt['soc'] : null,
        'neg'      => !empty($st['neg']) ? 1 : 0,
        'mittel'   => $mittel,
        'laufend'  => tb_laufend_lesen(),
    ), array(
        'budget_kw'   => $cfg['budget_kw'],
        'pv_bonus'    => $cfg['pv_bonus'],
        'pv_schwelle' => $cfg['pv_schwelle'],
        'budget2_kw'  => $cfg['budget2_kw'],
        'budget2_von' => $cfg['budget2_von'],
        'budget2_bis' => $cfg['budget2_bis'],
    ));

    $regeln = array();
    foreach ($plan as $i => $w) {
        $r = isset($cfg['regeln'][$i]) ? $cfg['regeln'][$i] : array();
        $w['in']   = $w['in'] < 0 ? -1 : (int) round($w['in'] / 60);
        $w['rest'] = (int) round($w['rest'] / 60);
        $w['name'] = (isset($r['name']) && $r['name'] !== '')
                   ? $r['name'] : sprintf(tb_t('FP.REGEL_N'), $i + 1);
        $w['art']  = isset($r['art']) ? $r['art'] : 'fenster';
        $w['ein']  = empty($r['aktiv']) ? 0 : 1;
        if (!isset($w['verdraengt'])) { $w['verdraengt'] = 0; }
        if (!isset($w['gesperrt'])) { $w['gesperrt'] = ''; }
        $plan[$i] = $w;
        $ohne = $w;
        unset($ohne['slots']);      // die Scheibenliste braucht Loxone nicht
        $regeln[] = $ohne;
    }

    $belegung = plan_belegung($plan);
    $planlast = isset($belegung[$jetzt]) ? (float) $belegung[$jetzt] : 0.0;

    return array(
        'regeln'   => $regeln,
        'plan'     => $plan,
        'belegung' => $belegung,
        'preise'   => $liste,
        'slotlen'  => $slotlen,
        'pv_summe' => isset($umwelt['pv_summe']) ? $umwelt['pv_summe'] : null,
        'soc'      => isset($umwelt['soc']) ? $umwelt['soc'] : null,
        'planlast' => $planlast,
    );
}

/**
 * Eine Regelliste aus einer Sicherungsdatei beurteilen.
 *
 * Rueckgabe: array(bereinigte Liste, Beanstandungen).
 *
 * Enger als die Normierung beim Lesen, und das ist Absicht: was aus einer
 * fremden Datei kommt, wird abgewiesen und nicht zurechtgebogen. Alle
 * Beanstandungen werden gesammelt - die erste zu melden und abzubrechen
 * verschweigt die uebrigen.
 */
function tb_regeln_pruefen($wert)
{
    $mangel = array();
    if (!is_array($wert)) {
        return array(array(), array(sprintf(tb_t('EINST.SICH_WERT_REGELN'), 'regeln')));
    }
    if (count($wert) > TB_REGELN) {
        $mangel[] = sprintf(tb_t('EINST.SICH_REGELN_ZUVIEL'), count($wert), TB_REGELN);
    }
    /* Ganzzahlen, Kommazahlen und Auswahlwerte - je Feld eine Regel, und die
     * Grenzen sind dieselben wie in tb_fahrplan_normieren(). */
    $zahl = array('n' => array(1, 12), 'von' => array(0, 23), 'bis' => array(0, 23),
                  'horizont' => array(1, 48), 'prozent' => array(0, 90),
                  'rang' => array(1, 99), 'frist' => array(-1, 23),
                  'soc_min' => array(0, 100), 'soc_max' => array(0, 100),
                  'min_lauf' => array(0, 720), 'min_pause' => array(0, 720));
    $komma = array('schwelle' => array(-100.0, 200.0), 'leistung' => array(0.0, 100.0),
                   'energie' => array(0.0, 500.0), 'pv_sperre' => array(0.0, 500.0));
    $haken = array('aktiv', 'neg');

    $rein = array();
    foreach ($wert as $i => $r) {
        $i = (int) $i;
        if ($i < 0 || $i >= TB_REGELN) {
            $mangel[] = sprintf(tb_t('EINST.SICH_REGELN_INDEX'), $i);
            continue;
        }
        if (!is_array($r)) {
            $mangel[] = sprintf(tb_t('EINST.SICH_WERT_REGELN'), 'regeln[' . $i . ']');
            continue;
        }
        $neu = tb_regel_vorgabe();
        foreach ($r as $k => $v) {
            $k = (string) $k;
            if (!array_key_exists($k, $neu)) {
                $mangel[] = sprintf(tb_t('EINST.SICH_REGELN_FREMD'),
                                    htmlspecialchars($k, ENT_QUOTES, 'UTF-8'), $i + 1);
                continue;
            }
            if (!tb_wert_taugt($v)) {
                $mangel[] = sprintf(tb_t('EINST.SICH_REGELN_UNTAUGLICH'),
                                    htmlspecialchars($k, ENT_QUOTES, 'UTF-8'), $i + 1);
                continue;
            }
            $s = trim((string) $v);
            if (in_array($k, $haken, true)) {
                if (!in_array($s, array('0', '1'), true)) {
                    $mangel[] = sprintf(tb_t('EINST.SICH_REGELN_FELD'), $k, $i + 1);
                    continue;
                }
                $neu[$k] = (int) $s;
            } elseif (isset($zahl[$k])) {
                if (!preg_match('/^-?[0-9]{1,7}$/', $s)
                    || (int) $s < $zahl[$k][0] || (int) $s > $zahl[$k][1]) {
                    $mangel[] = sprintf(tb_t('EINST.SICH_REGELN_BEREICH'), $k, $i + 1,
                                        $zahl[$k][0], $zahl[$k][1]);
                    continue;
                }
                $neu[$k] = (int) $s;
            } elseif (isset($komma[$k])) {
                $s2 = str_replace(',', '.', $s);
                if (!preg_match('/^-?[0-9]{1,4}(\.[0-9]{1,3})?$/', $s2)
                    || (float) $s2 < $komma[$k][0] || (float) $s2 > $komma[$k][1]) {
                    $mangel[] = sprintf(tb_t('EINST.SICH_REGELN_BEREICH'), $k, $i + 1,
                                        $komma[$k][0], $komma[$k][1]);
                    continue;
                }
                $neu[$k] = (float) $s2;
            } elseif ($k === 'art') {
                if (!in_array($s, array('fenster', 'stunden', 'schwelle', 'mittel'), true)) {
                    $mangel[] = sprintf(tb_t('EINST.SICH_REGELN_FELD'), $k, $i + 1);
                    continue;
                }
                $neu[$k] = $s;
            } elseif ($k === 'name') {
                if (strlen($s) > 40) {
                    $mangel[] = sprintf(tb_t('EINST.SICH_REGELN_FELD'), $k, $i + 1);
                    continue;
                }
                $neu[$k] = $s;
            }
        }
        $rein[$i] = $neu;
    }
    /* Luecken auffuellen, damit die Liste immer TB_REGELN Eintraege hat -
     * sonst haengt die Nummer einer Regel an ihrer Position in der Datei,
     * und eine ausgelassene Regel 2 machte aus Regel 3 die Regel 2. Daran
     * haengen MQTT-Thema, virtueller Eingang und Endpunktadresse. */
    if (!$mangel) {
        for ($i = 0; $i < TB_REGELN; $i++) {
            if (!isset($rein[$i])) { $rein[$i] = tb_regel_vorgabe(); }
        }
        ksort($rein);
    }
    return array($rein, $mangel);
}

/**
 * Liegt in den Schwesterlinien dieselbe planer.php?
 *
 * Die Regel "diese Datei ist in drei Plugins byteweise gleich" hatte bis
 * 0.9.11 kein Werkzeug, das sie findet - sie stand als Bitte im Dateikopf.
 * Hier ist es, und es misst am INSTALLIERTEN Zustand.
 *
 * Rueckgabe: Liste aus array(ordner, lage, sha), lage ist eines von
 *   'gleich' | 'verschieden' | 'fehlt'
 * 'fehlt' heisst NICHT "in Ordnung" und nicht "Befund": das Schwesterplugin
 * ist auf diesem LoxBerry schlicht nicht installiert, und ueber eine leere
 * Menge wird nicht geurteilt.
 */
function tb_planer_pruefsummen()
{
    /* Gerechnet wird in planer.php, nicht hier.
     *
     * Der erste Entwurf hatte die Rechnung an dieser Stelle - und damit in
     * genau EINER der drei Linien, die dieselbe Datei fuehren. Die beiden
     * anderen haetten entweder keine Pruefung bekommen oder eine zweite
     * Kopie davon; eine Pruefung, die Kopien vergleicht, in Kopien zu
     * fuehren waere der Witz gewesen. Seit planer.php 1.1.6 steht sie dort,
     * und alle drei rufen dieselbe.
     *
     * Hier bleiben nur die zwei Angaben, die diese Linie kennt: wo LoxBerry
     * wohnt und wo die eigene Datei liegt. */
    return plan_pruefsummen(tb_paths()['home']);
}

/** Alle Punkte der letzten $tage Tage. Rueckgabe: Liste aus array(ts, ct). */
function tb_verlauf_lesen($tage = 30)
{
    $ordner = tb_paths()['datadir'] . '/verlauf';
    if (!is_dir($ordner)) { return array(); }
    $tage = max(1, (int) $tage);
    $ab = time() - $tage * 86400;
    $out = array();
    /* ALLE Monatsdateien des Zeitraums, nicht nur zwei.
     *
     * Bis 0.9.9 standen hier der laufende und der vorige Monat fest. Fuer die
     * 30-Tage-Kennzahl reicht das; das Eingabefeld "Verlauf aufbewahren"
     * laesst aber bis 3650 Tage zu, und die Oberflaeche reicht diesen Wert
     * durch. Ein Anwender, der 365 eintrug, bekam trotzdem hoechstens zwei
     * Monatsdateien zu sehen - ohne dass irgendwo stand, warum. */
    $monate = array();
    $zeiger = strtotime('first day of this month 00:00');
    for ($i = 0; $i <= (int) ceil($tage / 28) + 1; $i++) {
        $monate[date('Ym', $zeiger)] = true;
        $zeiger = strtotime('-1 month', $zeiger);
    }
    foreach (array_keys($monate) as $m) {
        $datei = $ordner . '/' . $m . '.csv';
        if (!is_file($datei)) { continue; }
        foreach ((array) @file($datei, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $z) {
            $t = explode(';', $z);
            if (count($t) < 2) { continue; }
            $ts = (int) $t[0];
            if ($ts < $ab) { continue; }
            if (!is_numeric($t[1])) { continue; }
            $out[] = array('ts' => $ts, 'ct' => (float) $t[1]);
        }
    }
    usort($out, function ($a, $b) { return $a['ts'] - $b['ts']; });
    return $out;
}

/**
 * Durchschnitt und Rang der laufenden Stunde im Verlauf.
 *
 * Rueckgabe: array(avg, rangprozent, anzahl). avg und rangprozent sind NULL,
 * solange die Reihe den Zeitraum nicht traegt - eine Zahl aus drei Punkten
 * saehe aus wie ein Monatsdurchschnitt und waere keiner. Die Schranke ist
 * bewusst grob: unter 48 Punkten (also rund zwei Tagen) gibt es nichts.
 *
 * rangprozent = 0 heisst "so guenstig wie nie in diesem Zeitraum",
 * 100 heisst "so teuer wie nie". Das ist die Aussage, die eine feste
 * Schwelle nicht liefern kann.
 */
/**
 * Ab wie vielen Punkten traegt die Reihe eine Aussage?
 *
 * 48 Punkte sind rund zwei Tage. Die Zahl stand bis 0.9.9 an drei Stellen:
 * hier, im Selbsttest des Crons und in dessen Pruefsatz. Ein Selbsttest, der
 * seine Vergleichszahl selbst mitbringt, bleibt gruen, wenn die Bibliothek
 * sich aendert - und die Aussage ist dann falsch.
 */
function tb_verlauf_mindestpunkte()
{
    return 48;
}

function tb_verlauf_kennzahlen($jetztpreis, $tage = 30)
{
    $reihe = tb_verlauf_lesen($tage);
    $n = count($reihe);
    if ($n < tb_verlauf_mindestpunkte()) { return array(null, null, $n); }
    $summe = 0.0;
    $kleiner = 0;
    foreach ($reihe as $e) {
        $summe += $e['ct'];
        if ($jetztpreis !== null && $e['ct'] < (float) $jetztpreis) { $kleiner++; }
    }
    $avg = round($summe / $n, 3);
    $rang = ($jetztpreis === null) ? null : (int) round($kleiner * 100.0 / $n);
    return array($avg, $rang, $n);
}

/* ==================================================================
 * MQTT-Gateway des LoxBerry
 *
 * Das MQTT-Gateway ist seit LoxBerry 3 Bestandteil des Systems, kein Plugin.
 * Mqtt.Brokerhost ist ab Werk auf 'localhost' gesetzt - eine Pruefung darauf
 * beantwortet also NICHT die Frage, ob Nachrichten ankommen koennen.
 * Massgeblich ist Gatewayautostart.
 * ================================================================== */
/**
 * Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.
 *
 * Das Gateway liest ZEILENWEISE. Ein Zeilenumbruch im Wert - aus einer
 * Fehlermeldung des Betriebssystems, einem Geraetenamen oder der Ausgabe
 * eines Systembefehls - zerlegt die Uebertragung, und aus den Bruchstuecken
 * bildet das Gateway erfundene Themen. Ein Tabulator schadet ebenso, weil
 * Leerzeichen Thema und Wert trennt.
 */
function tb_mqtt_wert_saeubern($v)
{
    /* ALLE Steuerzeichen, nicht nur die vier auffaelligen. Bis 0.9.9 standen
     * hier CR, LF und TAB; Nullbyte, 0x0B, 0x0C und 0x7F gingen unveraendert
     * ins Datagramm. Die Zeichenklasse ist jetzt dieselbe, die
     * tb_wert_taugt() benutzt - zwei Wachen fuer dieselbe Gefahr sollen
     * dieselbe Grenze ziehen. */
    $wert = preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

function tb_mqtt_zustand()
{
    $p = tb_paths();
    $leer = array('gefunden' => 0, 'autostart' => 0, 'fassung' => 0, 'udpport' => 0, 'broker' => '',
                  'brokerport' => '', 'user' => '', 'lokal' => 0);
    if ($p['home'] === '') { return $leer; }
    $gen = tb_json_lesen($p['home'] . '/config/system/general.json');
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) { $m = $gen['Mqtt']; }
    elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) { $m = $gen['mqtt']; }
    if (!$m) { return $leer; }
    $hol = function ($gross, $klein) use ($m) {
        if (isset($m[$gross])) { return $m[$gross]; }
        return isset($m[$klein]) ? $m[$klein] : '';
    };
    return array(
        'gefunden'   => 1,
        'autostart'  => in_array((string) $hol('Gatewayautostart', 'gatewayautostart'), array('1', 'true'), true) ? 1 : 0,
        /* Die FASSUNG des MQTT-Gateways, ab Werk 1. Sie entscheidet, was der
         * Anwender eintragen muss: unter V1 jedes Thema von Hand, ab V2
         * erscheint die Themengruppe von selbst in den Subscriptions.
         * 0 heisst "nicht feststellbar" - dann wird nichts behauptet,
         * sondern es werden beide Faelle genannt. */
        'fassung'    => (int) $hol('Gatewayversion', 'gatewayversion'),
        'udpport'    => (int) $hol('Udpinport', 'udpinport'),
        'broker'     => (string) $hol('Brokerhost', 'brokerhost'),
        'brokerport' => (string) $hol('Brokerport', 'brokerport'),
        'user'       => (string) $hol('Brokeruser', 'brokeruser'),
        'lokal'      => in_array((string) $hol('Uselocalbroker', 'uselocalbroker'), array('1', 'true'), true) ? 1 : 0,
    );
}

/**
 * Der Hinweis zum MQTT-Abo - in der Fassung, die zum GATEWAY passt.
 *
 * Bis hierher stand an den Ausgabestellen unbedingt "Ohne diesen Eintrag
 * kommt am Miniserver nichts an". Das gilt fuer Gateway V1, wo jedes Thema
 * von Hand einzutragen ist. Ab V2 erscheint die Themengruppe von selbst in
 * den Subscriptions - der Satz schickte jeden V2-Anwender zu einem
 * Eingabeplatz, den es nicht gibt.
 *
 * Drei Ausgaenge, nicht zwei: ist die Fassung nicht feststellbar, werden
 * BEIDE Faelle genannt statt einer behauptet.
 */
function tb_abo_text()
{
    $m = tb_mqtt_zustand();
    $f = isset($m['fassung']) ? (int) $m['fassung'] : 0;
    if ($f <= 0) {
        return tb_t('MQTT.ABO_UNBEKANNT');
    }
    $gemessen = ' <span class="sm-mono">'
              . sprintf(tb_t('MQTT.ABO_GEMESSEN'), $f) . '</span>';
    return tb_t($f >= 2 ? 'MQTT.ABO_V2' : 'MQTT.ABO_WARNUNG') . $gemessen;
}


/**
 * Werte ueber den UDP-Eingang des Gateways veroeffentlichen.
 * So braucht das Plugin keine Broker-Zugangsdaten zu kennen.
 *
 * Zwei Dinge, die bis 0.9.6 anders waren:
 *
 * 1. socket_create() ist raus. Die Erweiterung 'sockets' ist auf einem
 *    LoxBerry nicht zugesichert; fehlte sie, wurde stillschweigend GAR NICHTS
 *    veroeffentlicht, und der Hinweis stand nur gebremst im Protokoll.
 *    stream_socket_client() gehoert zum PHP-Kern und tut dasselbe - damit
 *    braucht das Plugin die Erweiterung ueberhaupt nicht mehr.
 *
 * 2. Das PRAEFIX wird gesaeubert, nicht nur der Wert. Das Gateway liest
 *    zeilenweise; ein Zeilenumbruch im Themennamen erzeugt eine ZWEITE
 *    publish-Zeile in ein fremdes Thema. Nachgemessen mit einem Praefix aus
 *    einer zurueckgespielten Sicherungsdatei:
 *
 *        publish tibber
 *        publish loxberry/heizung/soll 30/cur 23.4
 *
 *    Ueber das Formular kommt so etwas nicht herein (dort greift ein enges
 *    Muster), ueber eine Sicherungsdatei und ueber eine von Hand bearbeitete
 *    tibber.json aber sehr wohl. Wer eine Wache fuer die eine Haelfte einer
 *    Zeile baut, baut sie fuer die andere gleich mit.
 *
 * Rueckgabe: array(versucht, gescheitert) - der Aufrufer zaehlt beides und
 * meldet beides getrennt. Ein "n Werte versendet" ohne die Zahl der
 * Fehlschlaege ist eine Zusammenfassung, die besser aussieht als ihr
 * schlechtester Punkt.
 */
function tb_mqtt_senden(array $paare, $praefix)
{
    $z = tb_mqtt_zustand();
    if (!$z['udpport']) {
        tb_log_gebremst('mqtt_kein_port',
            'MQTT: kein UDP-Eingangsport in der general.json gefunden - nichts gesendet.');
        return array(0, 0);
    }
    if (!$z['autostart']) {
        tb_log_gebremst('mqtt_aus', 'MQTT: das Gateway ist nicht auf Autostart gestellt '
            . '(System, MQTT Gateway). Es wird gesendet, aber vermutlich hoert niemand zu.');
    }
    $praefix = trim(tb_mqtt_wert_saeubern($praefix), '/ ');
    if ($praefix === '' || strpos($praefix, ' ') !== false) {
        tb_log_gebremst('mqtt_praefix', 'MQTT: das Themen-Praefix ist unbrauchbar '
            . '(leer oder mit Leerraum) - es wird nichts veroeffentlicht.');
        return array(0, 0);
    }
    /* Erst filtern, dann zaehlen. Ein fehlender Wert wird nicht gesendet -
     * er darf deshalb im Fehlerzweig auch nicht als Fehlschlag erscheinen.
     * Bis 0.9.9 stand bei nicht erreichbarem Eingang "0 versucht, 49
     * gescheitert" im Protokoll, obwohl nur 31 Werte ueberhaupt vorlagen. */
    $fehler = 0;
    $sendbar = array();
    foreach ($paare as $k => $v) {
        if ($v === null || $v === '') { continue; }   // fehlender Wert: nichts senden
        $thema = tb_mqtt_wert_saeubern($k);
        if ($thema === '' || strpos($thema, ' ') !== false) { $fehler++; continue; }
        $sendbar[$thema] = $v;
    }
    $strom = @stream_socket_client('udp://127.0.0.1:' . (int) $z['udpport'],
                                   $errno, $errstr, 2);
    if (!$strom) {
        tb_log_gebremst('mqtt_socket', 'MQTT: der UDP-Eingang des Gateways war nicht '
            . 'erreichbar (' . $errstr . ').');
        return array(0, $fehler + count($sendbar));
    }
    $versucht = 0;
    foreach ($sendbar as $thema => $v) {
        $msg = 'publish ' . $praefix . '/' . $thema . ' ' . tb_mqtt_wert_saeubern($v);
        $versucht++;
        if (@fwrite($strom, $msg) === false) { $fehler++; }
    }
    fclose($strom);
    return array($versucht, $fehler);
}

/**
 * Alle Themen, die veroeffentlicht werden, mit ihrer Bedeutung.
 *
 * Die drei Lebenszeichen stehen VORNE und gehen bei jedem Durchgang hinaus -
 * auch unveraendert. Siehe tb_mqtt_signatur().
 */
/**
 * Welche Statusfelder gehen NICHT unter ihrem eigenen Namen hinaus?
 *
 * Sie stehen als Lebenszeichen unter status/ - siehe tb_mqtt_lebenszeichen().
 * Bis 0.9.9 stand diese Liste zweimal: hier und in bin/tb_cron.php. Zwei
 * Listen, die dasselbe aufzaehlen, laufen auseinander, und dann zeigt der
 * Reiter MQTT ein Thema an, das nie gesendet wird - oder umgekehrt.
 */
function tb_mqtt_ausgeschlossen()
{
    return array('OK', 'ALTER', 'PULSE_ALTER', 'ZAEHLER');
}

function tb_mqtt_themen()
{
    $out = array(
        'status/ok'       => 'TB_MQTT.S_OK',
        'status/ts'       => 'TB_MQTT.S_TS',
        'status/zaehler'  => 'TB_MQTT.S_ZAEHLER',
        'status/pulse_ts' => 'TB_MQTT.S_PULSE_TS',
    );
    $aus = tb_mqtt_ausgeschlossen();
    foreach (tb_status_felder() as $f => $info) {
        if (in_array($f, $aus, true)) { continue; }
        $out[strtolower($f)] = $info['text'];
    }
    $out['stunde/N/ct'] = 'TB_MQTT.STUNDE';
    return $out;
}

/**
 * Die Themen, die an der Signatur VORBEIGEHEN.
 *
 * Ueber MQTT gibt es kein "Alter": beim Senden ist es immer null. Es geht
 * deshalb ein ZEITSTEMPEL hinaus, und der Miniserver rechnet selbst -
 * Alter = (Loxone-Zeit + 1230768000) - ts.
 *
 * Und genau diese drei duerfen NICHT in die Signatur: ALTER aendert sich jede
 * Sekunde, und eine Signatur, die sich jede Sekunde aendert, macht den
 * Doppelt-senden-Filter wirkungslos - dann geht bei jedem Minutenlauf wieder
 * alles hinaus. Gemessen am Saugroboter-Plugin: Lauf 2 ohne Aenderung am
 * Geraet schickte 48 statt 3 Datagramme.
 */
function tb_mqtt_lebenszeichen()
{
    $st = tb_stand();
    $live = tb_live();
    return array(
        'status/ok'       => !empty($st['ok']) ? 1 : 0,
        'status/ts'       => isset($st['ts']) ? (int) $st['ts'] : 0,
        'status/zaehler'  => tb_zaehler_lesen(),
        /* Der Zeitstempel der Pulse - aus demselben Grund wie status/ts und
         * mit demselben Nachdruck. PULSE_ALTER stand bis 0.9.7 als gewoehnliches
         * Feld in der Signatur, und der Pulse-Dienst schreibt live.json im
         * Sekundentakt: damit war der Wert bei JEDEM Minutenlauf ein anderer,
         * die Signatur damit auch, und der Doppelt-senden-Filter wirkungslos.
         * Gemessen mit Pruefung/mqtt_gegen_http.py - Lauf 2 ohne jede
         * Aenderung schickte 69 von 72 Themen erneut. */
        'status/pulse_ts' => isset($live['ts']) ? (int) $live['ts'] : 0,
    );
}

/**
 * Die Signatur ueber die WERTE - ohne die Lebenszeichen.
 *
 * Gesendet wird nur, wenn sie sich geaendert hat. Bis 0.9.6 gab es keinen
 * Filter: bei bis zu 38 Statusfeldern und 24 Stundenpreisen waren das rund
 * 62 Datagramme je Minute, also gegen 89 000 am Tag, auch wenn sich am Preis
 * eine Stunde lang nichts ruehrte.
 */
function tb_mqtt_signatur(array $paare)
{
    $ohne = array();
    $lz = tb_mqtt_lebenszeichen();
    foreach ($paare as $k => $v) {
        if (array_key_exists($k, $lz)) { continue; }
        $ohne[$k] = $v;
    }
    ksort($ohne);
    $json = json_encode($ohne);
    if ($json === false) {
        /* md5(false) ist md5('') - fuer JEDE Wertemenge derselbe Wert. Der
         * Doppelt-senden-Filter haette danach alles fuer unveraendert
         * gehalten, und es gingen nur noch die Lebenszeichen hinaus: im
         * Broker steht ein ruhiger Markt, in Wahrheit ein Stillstand.
         * Deshalb hier fail OPEN - ein nie wiederkehrender Wert erzwingt das
         * Senden, statt es dauerhaft zu unterbinden. */
        tb_log_gebremst('mqtt_signatur', 'Die MQTT-Signatur liess sich nicht bilden ('
            . json_last_error_msg() . ') - es wird sicherheitshalber alles gesendet.');
        return 'unbekannt-' . microtime(true);
    }
    return md5($json);
}

/* ==================================================================
 * Loxone-Vorlagen
 *
 * Nachbau der Bausteine aus LoxBerry::LoxoneTemplateBuilder; das Modul gibt es
 * nur in Perl. Attributreihenfolge, CRLF als Zeilenende und der Tabulator vor
 * den Kindelementen entsprechen dem Original. Wortgleich uebernommen aus
 * ap_xml_virtual_in_http() im APC-UPS-Plugin.
 * ================================================================== */

function tb_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Der Nachbau, gegen die Ausfuhren aus Loxone Config gehalten.
 *
 * Bis 0.9.6 fehlten hier vier Dinge, die jede echte Ausfuhr traegt und die
 * 34 von 49 Plugin-Ordnern dieses Hauses setzen:
 *
 *   HintText=""                                 am Wurzelelement
 *   <Info templateType="2" minVersion="…"/>     als ERSTES Kindelement
 *   Unit="<v.1> <Einheit>"                      je Befehl
 *   HintText=""                                 je Befehl
 *
 * Ohne Unit steht am virtuellen Eingang eine nackte Zahl, und die Einheit
 * findet nur, wer den Kommentar aufklappt. Wortgleich uebernommen aus
 * spot_lib.php des Schwesterplugins Spotpreis aWATTar 1.2.12, das alles vier
 * bereits fuehrt.
 *
 * Analog/Signed kommen jetzt JE BEFEHL aus der Feldtabelle. Vorher trugen
 * alle Befehle Analog="true", auch die drei Merker (NEG, MORGEN_OK, OK) -
 * Loxone Config schreibt das Attribut nur bei den analogen Werten.
 */
function tb_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText="" ';
    $o .= 'Title="' . tb_x($kopf['title']) . '" ';
    $o .= 'Comment="' . tb_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . tb_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . tb_x(isset($kopf['polling']) ? $kopf['polling'] : '300') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $analog = !isset($c['analog']) || $c['analog'];
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . tb_x($c['title']) . '" ';
        $o .= 'Comment="' . tb_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . tb_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        /* Signed gehoert an das VORZEICHEN, nicht an den Analog-Haken.
         * Gemessen an einer eingelesenen Vorlage (REGELN_2, Weissware-Sitzung):
         * "Signed=true genau dort, wo die Untergrenze negativ ist". Bis 0.9.9
         * trug hier jedes analoge Feld Signed="true", auch die mit MinVal="0". */
        $o .= 'Signed="' . (((float) (isset($c['min']) ? $c['min'] : 0)) < 0
                            ? 'true' : 'false') . '" ';
        $o .= 'Analog="' . ($analog ? 'true' : 'false') . '" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        // Grenzen realistisch, nicht pauschal +/-2147483647: Loxone zieht
        // daraus die Reglergrenzen und die Plausibilitaetspruefung.
        $o .= 'MinVal="' . tb_x(isset($c['min']) ? $c['min'] : '-2147483647') . '" ';
        $o .= 'MaxVal="' . tb_x(isset($c['max']) ? $c['max'] : '2147483647') . '" ';
        $o .= 'Unit="' . tb_x(isset($c['unit']) ? $c['unit'] : '<v.1>') . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Die Adresse des Endpunkts - EIN Bauteil fuer alle, die sie zeigen.
 *
 * REGELN_2, "Angezeigte Adressen zum Abschreiben": eine Adresse, die
 * angezeigt wird, wird aus demselben Bauteil gebildet wie die, die das Plugin
 * selbst benutzt. Bis 0.9.9 setzten die Oberflaeche und tb_vorlage() dieselbe
 * Adresse getrennt zusammen. Sie liefen noch nicht auseinander - aber eine
 * offene Falle ist eine Falle.
 */
function tb_endpunkt_basis($host = null)
{
    if ($host === null) { $host = tb_hostname(); }
    return 'http://' . $host . '/plugins/' . tb_paths()['plugin'] . '/index.php';
}

/** Der Rechnername, unter dem der Miniserver den LoxBerry erreicht. */
function tb_hostname()
{
    if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '') {
        return preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST']);
    }
    $h = gethostname();
    return $h ? $h : 'loxberry';
}

/** Vorlage der virtuellen Eingaenge. Rueckgabe: array(name, inhalt) */
function tb_vorlage()
{
    $p = tb_paths();
    $host = tb_hostname();
    $token = tb_aktionstoken();
    $cmds = array();
    foreach (tb_status_felder() as $feld => $info) {
        /* Der Kommentar wird in Loxone Config zum ANZEIGENAMEN des Bausteins -
         * nicht zur Dokumentation, das Feld dort bleibt leer. Deshalb steht
         * hier die kurze Beschriftung und nicht die Erklaerung; die bleibt in
         * der Oberflaeche. Bis 0.9.6 stand hier der lange Text, und 25 von 38
         * Bausteinen hiessen danach nach einem ganzen Satz.
         *
         * Der Text laeuft gleich durch tb_x() und wuerde dort ein zweites Mal
         * maskiert. Deshalb erst Auszeichnung entfernen und Entitaeten
         * aufloesen. */
        $bezeichnung = trim(strip_tags(html_entity_decode(tb_t($info['kurz']), ENT_QUOTES, 'UTF-8')));
        $cmds[] = array(
            'title'   => 'TIBBER_' . $feld,
            'comment' => $bezeichnung,
            'check'   => tb_check($feld),
            'min'     => (string) $info['min'],
            'max'     => (string) $info['max'],
            'analog'  => $info['analog'],
            'unit'    => $info['einheit'] !== '' ? '<v.1> ' . $info['einheit'] : '<v.1>',
        );
    }
    $adresse = tb_endpunkt_basis($host) . '?token=' . $token . '&aktion=status';
    return array('VI_TIBBER.xml', tb_xml_virtual_in_http(array(
        'title'   => 'Spotpreis Tibber',
        'address' => $adresse,
        'polling' => '300',
        'comment' => 'Erzeugt vom LoxBerry-Plugin Spotpreis Tibber, ' . date('d.m.Y'),
    ), $cmds));
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch.
 * ================================================================== */

function tb_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

function tb_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) { $home = $k; break; }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . tb_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        // INI_SCANNER_RAW liefert die Anfuehrungszeichen mit zurueck.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) { $texte[$ab][$s] = trim((string) $w, '"'); }
        }
    }
    $teile = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$teile[0]][$teile[1]]) ? $texte[$teile[0]][$teile[1]] : $schluessel;
}


/* ==================================================================
 * Zulaessige Werte - EINE Liste fuer alle, die danach fragen
 *
 * Das Formular, das Zurueckspielen einer Sicherung und der Dienst beurteilen
 * dieselben Felder. Stuenden die Grenzen an drei Stellen, liefen sie
 * auseinander - und genau das war bis 0.9.6 schon der Fall: die Oberflaeche
 * prueft home_id gegen /^[0-9a-fA-F\-]{8,64}$/, die Bibliothek gegen
 * /^[A-Za-z0-9-]{1,64}$/. Zwei Regeln fuer dieselbe Angabe sind eine zu viel.
 * ================================================================== */

function tb_feldregeln()
{
    return array(
        'home_id'             => array('art' => 'id'),
        'aufschlag'           => array('art' => 'komma', 'min' => -50,  'max' => 50),
        'guenstig'            => array('art' => 'komma', 'min' => -50,  'max' => 200),
        'teuer'               => array('art' => 'komma', 'min' => -50,  'max' => 200),
        'festpreis'           => array('art' => 'komma', 'min' => 0,    'max' => 200),
        'grundpreis'          => array('art' => 'komma', 'min' => 0,    'max' => 500),
        'fensterstunden'      => array('art' => 'zahl',  'min' => 1,    'max' => 12),
        'preistakt'           => array('art' => 'zahl',  'min' => 5,    'max' => 1440),
        'verbrauchstakt'      => array('art' => 'zahl',  'min' => 30,   'max' => 1440),
        'verlauf_tage'        => array('art' => 'zahl',  'min' => 1,    'max' => 3650),
        'zeitueberschreitung' => array('art' => 'zahl',  'min' => 5,    'max' => 60),
        'verbrauch_ein'       => array('art' => 'haken'),
        'pulse_ein'           => array('art' => 'haken'),
        'monatsbericht'       => array('art' => 'haken'),
        'mqtt_ein'            => array('art' => 'haken'),
        'mqtt_topic'          => array('art' => 'thema'),
        'aktionstoken'        => array('art' => 'merkwort'),
        /* ---- Fahrplaner (ab 0.9.11) ----
         *
         * Dieselbe Positivliste, die das Formular benutzt - und dieselbe, die
         * eine zurueckgespielte Sicherung durchlaufen muss. Die Grenzen
         * stehen HIER und nur hier; tb_fahrplan_normieren() kappt mit
         * denselben Zahlen, weil es dieselbe Frage an anderer Stelle
         * beantwortet (was schon in der Datei steht). Wer eine Grenze
         * aendert, aendert beide - der Reiter Test haelt sie gegeneinander. */
        'budget_kw'    => array('art' => 'komma', 'min' => 0,  'max' => 200),
        'budget2_kw'   => array('art' => 'komma', 'min' => 0,  'max' => 200),
        'budget2_von'  => array('art' => 'zahl',  'min' => 0,  'max' => 23),
        'budget2_bis'  => array('art' => 'zahl',  'min' => 0,  'max' => 23),
        'pv_bonus'     => array('art' => 'komma', 'min' => 0,  'max' => 100),
        'pv_schwelle'  => array('art' => 'zahl',  'min' => 1,  'max' => 100000),
        'hysterese'    => array('art' => 'haken'),
        'pv_quelle'    => array('art' => 'auswahl',
                                'werte' => array('', 'forecast_solar', 'objekt', 'liste')),
        'pv_einheit'   => array('art' => 'auswahl', 'werte' => array('wh', 'w', 'kw')),
        'pv_url'       => array('art' => 'url'),
        'soc_url'      => array('art' => 'url'),
        'pv_pfad'      => array('art' => 'pfad'),
        'soc_pfad'     => array('art' => 'pfad'),
        'pv_zeitfeld'  => array('art' => 'pfad'),
        'pv_wertfeld'  => array('art' => 'pfad'),
    );
}

/**
 * Taugt der Wert ueberhaupt fuer eine Konfigurationsdatei?
 *
 * Die erste von zwei Wachen, und sie ist die grobe: kein Feld, kein Objekt,
 * kein Steuerzeichen, nicht endlos lang. Sie greift auch fuer Schluessel, fuer
 * die es keine Einzelregel gibt.
 *
 * Zeilenumbrueche sind hier kein Schoenheitsfehler: der Wert von mqtt_topic
 * geht in ein UDP-Datagramm, und das MQTT-Gateway liest zeilenweise.
 */
function tb_wert_taugt($v)
{
    if (is_array($v) || is_object($v) || is_bool($v) || is_null($v)) { return false; }
    $s = (string) $v;
    if (strlen($s) > 4096) { return false; }
    /* Bis 0.9.9 sprang die Klasse von \x08 auf \x0A - der Tabulator (\x09)
     * lag in der Luecke, obwohl er im UDP-Datagramm Thema und Wert trennt. */
    return preg_match('/[\x00-\x1F\x7F]/', $s) !== 1;
}

/**
 * Ist der Wert fuer DIESE Einstellung zulaessig?
 *
 * Die zweite Wache, gegen dieselbe Positivliste, die auch das Formular
 * benutzt. Rueckgabe: Leerstring wenn in Ordnung, sonst der Grund im Klartext.
 */
function tb_wert_pruefen($schluessel, $wert)
{
    $regeln = tb_feldregeln();
    if (!isset($regeln[$schluessel])) { return ''; }     // keine Einzelregel
    $r = $regeln[$schluessel];
    $s = trim((string) $wert);
    switch ($r['art']) {
        case 'id':
            return ($s === '' || tb_gql_id($s) !== '') ? ''
                : sprintf(tb_t('EINST.SICH_WERT_ID'), $schluessel);
        case 'zahl':
            if (!preg_match('/^-?[0-9]{1,7}$/', $s)) {
                return sprintf(tb_t('EINST.SICH_WERT_ZAHL'), $schluessel);
            }
            $n = (int) $s;
            return ($n >= $r['min'] && $n <= $r['max']) ? ''
                : sprintf(tb_t('EINST.SICH_WERT_BEREICH'), $schluessel, $r['min'], $r['max']);
        case 'komma':
            $s = str_replace(',', '.', $s);
            if (!preg_match('/^-?[0-9]{1,4}(\.[0-9]{1,3})?$/', $s)) {
                return sprintf(tb_t('EINST.SICH_WERT_KOMMA'), $schluessel);
            }
            $n = (float) $s;
            return ($n >= $r['min'] && $n <= $r['max']) ? ''
                : sprintf(tb_t('EINST.SICH_WERT_BEREICH'), $schluessel, $r['min'], $r['max']);
        case 'haken':
            return in_array($s, array('0', '1'), true) ? ''
                : sprintf(tb_t('EINST.SICH_WERT_HAKEN'), $schluessel);
        case 'thema':
            return preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $s) ? ''
                : sprintf(tb_t('EINST.SICH_WERT_THEMA'), $schluessel);
        case 'merkwort':
            return ($s === '' || preg_match('/^[A-Za-z0-9]{8,64}$/', $s)) ? ''
                : sprintf(tb_t('EINST.SICH_WERT_MERKWORT'), $schluessel);
        /* ---- ab 0.9.11, fuer den Fahrplaner ---- */
        case 'auswahl':
            /* Eine ZUORDNUNG, kein zweiwertiger Ausdruck: die Liste der
             * erlaubten Werte steht in der Feldregel. Kommt eine fuenfte
             * Quellenart dazu, steht sie an einer Stelle. */
            $erlaubt = isset($r['werte']) && is_array($r['werte']) ? $r['werte'] : array();
            return in_array($s, $erlaubt, true) ? ''
                : sprintf(tb_t('EINST.SICH_WERT_AUSWAHL'), $schluessel,
                          implode(', ', array_map(function ($w) {
                              return $w === '' ? '(leer)' : $w;
                          }, $erlaubt)));
        case 'url':
            /* Leer ist erlaubt und heisst "aus".
             *
             * NICHT hart filtern: aus einer Adresse Zeichen zu entfernen
             * ergibt eine andere Adresse, die aussieht wie eine richtige.
             * Abgewiesen wird, was kein http(s) ist oder zu lang - der Rest
             * geht unveraendert durch, denn Abfragezeichenfolgen enthalten
             * jedes erdenkliche Zeichen. Steuerzeichen hat tb_wert_taugt()
             * schon abgefangen. */
            if ($s === '') { return ''; }
            if (strlen($s) > 1024 || !preg_match('#^https?://[^\s]+$#i', $s)) {
                return sprintf(tb_t('EINST.SICH_WERT_URL'), $schluessel);
            }
            return '';
        case 'pfad':
            // Ein Punktpfad in einem JSON-Baum. Leer ist erlaubt.
            return ($s === '' || preg_match('/^[A-Za-z0-9_.\-]{1,128}$/', $s)) ? ''
                : sprintf(tb_t('EINST.SICH_WERT_PFAD'), $schluessel);
    }
    return '';
}

/**
 * Die Sicherungsdatei bauen.
 *
 * Sie traegt BEIDE Geheimnisse - das Aktionstoken fuer den Loxone-Endpunkt
 * UND das persoenliche Tibber-Zugangstoken. Ohne das zweite stuenden nach dem
 * Zurueckspielen alle Felder richtig, und das Plugin kaeme trotzdem nicht an
 * die Anlage: bis 0.9.6 war die Datei fuer ihren eigentlichen Zweck - den
 * UMZUG auf einen zweiten LoxBerry - damit unbrauchbar. Wer den Token
 * auslaesst, hat kein Sicherheitsmerkmal eingebaut, sondern die Funktion
 * halbiert.
 *
 * Das Formularmerkmal gehoert ausdruecklich NICHT hinein: es lebt eine
 * Sitzung und wird aus dem Aktionstoken abgeleitet. Wer beide verwechselt,
 * baut aus einer Umzugshilfe ein Leck.
 *
 * Der lesbare Kopf beginnt mit '_'. Wer die Datei in einem Jahr findet, muss
 * erkennen koennen, was sie ist - und die Leseseite unten uebergeht genau
 * diese Schluessel.
 */
function tb_sicherung_bauen()
{
    $daten = array(
        '_hinweis' => 'Sicherung des LoxBerry-Plugins Spotpreis Tibber. '
                    . 'Enthaelt das persoenliche Tibber-Zugangstoken und das Merkwort '
                    . 'fuer den Loxone-Endpunkt - wie ein Passwort behandeln.',
        '_stand'   => date('Y-m-d H:i:s'),
        '_plugin'  => 'spotpreistibber',
        '_fassung' => tb_fassung(),
    );
    $token = tb_token_lesen();
    if ($token !== '') { $daten['tibber_token'] = $token; }
    // Vollstaendig aus den Vorgaben heraus: ein Schluessel, der in der
    // Sicherung fehlt, kaeme beim Zurueckspielen aus der Vorgabe - und das
    // ist genau dann falsch, wenn der Anwender ihn bewusst auf den
    // Vorgabewert gesetzt hatte und die Vorgabe sich spaeter aendert.
    $cfg = tb_config();
    foreach (tb_vorgaben() as $k => $vorgabe) {
        $daten[$k] = array_key_exists($k, $cfg) ? $cfg[$k] : $vorgabe;
    }
    return $daten;
}

/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Die sieben Punkte aus REGELN_2, und der wichtigste ist der dritte: eine
 * halb gueltige Datei ueberschreibt GAR NICHTS. Wer eine Sicherung
 * zurueckspielt, will entweder den ganzen Stand oder gar keinen - eine zur
 * Haelfte uebernommene Konfiguration ist schlimmer als die alte, und man
 * sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Der lesbare Kopf ('_hinweis', '_stand', …) wird UEBERGANGEN, nicht
 * beanstandet. Ohne diese drei Zeilen wuerde die Funktion genau die Datei
 * ablehnen, die tb_sicherung_bauen() zwei Zeilen weiter oben erzeugt - der
 * Fall ist am 26.08.2026 im WiFi-Scanner-Plugin aufgetreten.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte,
 *                  Tibber-Token|null).
 *
 * Die Reihenfolge ist die HAUSFORM, und der Zusatz steht hinten. Das ist
 * nicht Geschmack: Werkzeuge/sicherung_wirkung.py erkennt die Bauart daran,
 * dass an Stelle 1 die Maengelliste steht - ein Token an dieser Stelle liess
 * es auf "andere Bauart, von Hand ansehen" fallen, und damit hatte das
 * Werkzeug NICHT gemessen, statt etwas zu finden. Spotpreis Octopus reicht
 * seine Zugangsdaten aus demselben Grund als VIERTEN Wert heraus.
 */
function tb_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(tb_t('EINST.SICH_KEIN_JSON')), 0, null);
    }
    /* Der lesbare Kopf ist nicht nur Zierat - er wird GEPRUEFT.
     *
     * Die Schwesterplugins Spotpreis aWATTar und Spotpreis Octopus tragen
     * absichtlich dieselben Schluesselnamen (guenstig, teuer, mqtt_topic, ...).
     * Deren Sicherungsdatei lief bis 0.9.9 hier ohne eine einzige Beanstandung
     * durch: "4 Werte uebernommen" - und die uebrigen dreizehn Einstellungen
     * standen danach auf Werk, das Merkwort war weg, und jeder virtuelle
     * Eingang in Loxone bekam eine Abweisung. Gemessen am 02.09.2026.
     *
     * Fehlt der Kopf ganz, ist die Datei aelter als 0.9.7 - die wird
     * angenommen, sonst waere ein Rueckspielen aus einer alten Sicherung
     * unmoeglich. Steht ein FREMDER Name darin, wird abgewiesen. */
    if (isset($daten['_plugin']) && is_string($daten['_plugin'])
        && $daten['_plugin'] !== '' && $daten['_plugin'] !== 'spotpreistibber') {
        return array(null, array(sprintf(tb_t('EINST.SICH_FREMDES_PLUGIN'),
                                 tb_e((string) $daten['_plugin']))), 0, null);
    }
    $neu = tb_vorgaben();
    $bekannt = array_keys($neu);
    $token = null;
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        if ($k !== '' && $k[0] === '_') { continue; }    // lesbarer Kopf
        if ($k === 'tibber_token') {
            if (!tb_wert_taugt($w)) {
                $mangel[] = sprintf(tb_t('EINST.SICH_WERT_UNTAUGLICH'), tb_e($k));
                continue;
            }
            $grund = tb_token_form((string) $w);
            if ((string) $w !== '' && $grund !== '') {
                $mangel[] = tb_t($grund);
                continue;
            }
            $token = (string) $w;
            $anzahl++;
            continue;
        }
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(tb_t('EINST.SICH_FREMD'),
                                 htmlspecialchars($k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        /* Die Schaltregeln sind der einzige Schluessel, dessen Wert ein FELD
         * ist. tb_wert_taugt() weist Felder ab - zu Recht, denn jeder andere
         * Wert dieser Konfiguration geht irgendwann in eine Zeile, und ein
         * Feld haette dort nichts zu suchen. Also bekommt dieser eine
         * Schluessel seine eigene Pruefung, und sie ist nicht laxer, sondern
         * enger: jedes Feld jeder Regel wird einzeln beurteilt.
         *
         * Ohne diesen Zweig waere die Sicherung fuer den Fahrplaner wertlos -
         * genau die Halbierung, die diese Datei bei den Zugangsdaten schon
         * einmal hatte. */
        if ($k === 'regeln') {
            list($rein, $rmangel) = tb_regeln_pruefen($w);
            if ($rmangel) {
                foreach ($rmangel as $m) { $mangel[] = $m; }
                continue;
            }
            $neu[$k] = $rein;
            $anzahl++;
            continue;
        }
        if (!tb_wert_taugt($w)) {
            $mangel[] = sprintf(tb_t('EINST.SICH_WERT_UNTAUGLICH'),
                                 htmlspecialchars($k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $grund = tb_wert_pruefen($k, $w);
        if ($grund !== '') {
            $mangel[] = $grund;
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = tb_t('EINST.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl, $token);
}
