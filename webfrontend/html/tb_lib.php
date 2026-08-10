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
    );
    return $p;
}

/* ==================================================================
 * Konfiguration
 * ================================================================== */

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
    );
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

function tb_config()
{
    $p = tb_paths();
    // Selbstheilung aus der Sicherung NEBEN dem Konfigurationsordner. Ein
    // Geschwister ueberlebt dessen Loeschung, ein Kind nicht.
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    if (($roh === '' || $roh === '{}') && is_file($p['sicherung'])) {
        @mkdir($p['configdir'], 0775, true);
        @copy($p['sicherung'], $p['config']);
    }
    return array_merge(tb_vorgaben(), tb_json_lesen($p['config']));
}

function tb_config_speichern($cfg)
{
    $p = tb_paths();
    // In dieser Datei steht KEIN Tibber-Token, aber das Aktionstoken fuer den
    // Endpunkt - deshalb 0600.
    if (!tb_json_schreiben($p['config'], $cfg, 0600)) { return false; }
    @copy($p['config'], $p['sicherung']);
    @chmod($p['sicherung'], 0600);
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

function tb_aktionstoken()
{
    $cfg = tb_config();
    if (trim((string) $cfg['aktionstoken']) === '') {
        $cfg['aktionstoken'] = tb_aktionstoken_erzeugen();
        tb_config_speichern($cfg);
    }
    return (string) $cfg['aktionstoken'];
}

/* ---------------- Protokoll ---------------- */

function tb_log($text)
{
    $p = tb_paths();
    if (!is_dir($p['logdir'])) { @mkdir($p['logdir'], 0775, true); }
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
        'User-Agent: LoxBerry-Plugin-Spotpreis-Tibber/0.9.0 (+https://wiki.loxberry.de/)',
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
function tb_fenster(array $liste, $stunden, $ab = null)
{
    $stunden = max(1, min(12, (int) $stunden));
    if ($ab === null) { $ab = time(); }
    $k = array();
    foreach ($liste as $e) {
        // Die laufende Stunde zaehlt mit, deshalb 3600 Toleranz.
        if ($e['ts'] + 3600 > $ab) { $k[] = $e; }
    }
    if (count($k) < $stunden) {
        return array('ts' => null, 'h' => null, 'in' => null, 'ct' => null);
    }
    $best = null; $bestI = 0;
    for ($i = 0; $i + $stunden <= count($k); $i++) {
        $s = 0.0;
        for ($j = 0; $j < $stunden; $j++) { $s += $k[$i + $j]['ct']; }
        if ($best === null || $s < $best) { $best = $s; $bestI = $i; }
    }
    $ts = $k[$bestI]['ts'];
    return array(
        'ts' => $ts,
        'h'  => (int) date('G', $ts),
        'in' => (int) max(0, round(($ts - $ab) / 3600)),
        'ct' => round($best / $stunden, 3),
    );
}

/** Rang der laufenden Stunde in den naechsten 24 Stunden. 1 = guenstigste. */
function tb_rang(array $liste, $jetzt = null)
{
    if ($jetzt === null) { $jetzt = time(); }
    $k = array();
    foreach ($liste as $e) {
        if ($e['ts'] + 3600 > $jetzt && $e['ts'] < $jetzt + 24 * 3600) { $k[] = $e['ct']; }
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

/** Der Preis, der zu einem Zeitpunkt gilt. */
function tb_preis_zur_zeit(array $liste, $zeit)
{
    $treffer = null;
    foreach ($liste as $e) {
        if ($e['ts'] <= $zeit && $zeit < $e['ts'] + 3600) { $treffer = $e['ct']; }
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
    if ($pid <= 0 || !is_dir('/proc/' . $pid)) { return 0; }
    $cmd = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
    return strpos($cmd, 'tb_pulse.php') !== false ? $pid : 0;
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
function tb_status_felder()
{
    return array(
        'CUR'          => array('ct/kWh', 'TB_FELD.CUR',          0,    200),
        'CUR_ENERGIE'  => array('ct/kWh', 'TB_FELD.CUR_ENERGIE',  -100, 200),
        'CUR_STEUER'   => array('ct/kWh', 'TB_FELD.CUR_STEUER',   0,    100),
        'NEXT'         => array('ct/kWh', 'TB_FELD.NEXT',         0,    200),
        'LEVEL'        => array('',       'TB_FELD.LEVEL',        0,    2),
        'TLEVEL'       => array('',       'TB_FELD.TLEVEL',       -1,   4),
        'RANK'         => array('',       'TB_FELD.RANK',         0,    48),
        'RANKD'        => array('',       'TB_FELD.RANKD',        0,    48),
        'NEG'          => array('',       'TB_FELD.NEG',          0,    1),
        'AVG_HEUTE'    => array('ct/kWh', 'TB_FELD.AVG_HEUTE',    0,    200),
        'MIN_HEUTE'    => array('ct/kWh', 'TB_FELD.MIN_HEUTE',    -100, 200),
        'MINH_HEUTE'   => array('h',      'TB_FELD.MINH_HEUTE',   0,    23),
        'MAX_HEUTE'    => array('ct/kWh', 'TB_FELD.MAX_HEUTE',    0,    200),
        'MAXH_HEUTE'   => array('h',      'TB_FELD.MAXH_HEUTE',   0,    23),
        'MORGEN_OK'    => array('',       'TB_FELD.MORGEN_OK',    0,    1),
        'AVG_MORGEN'   => array('ct/kWh', 'TB_FELD.AVG_MORGEN',   0,    200),
        'MIN_MORGEN'   => array('ct/kWh', 'TB_FELD.MIN_MORGEN',   -100, 200),
        'MINH_MORGEN'  => array('h',      'TB_FELD.MINH_MORGEN',  0,    23),
        'MAX_MORGEN'   => array('ct/kWh', 'TB_FELD.MAX_MORGEN',   0,    200),
        'MAXH_MORGEN'  => array('h',      'TB_FELD.MAXH_MORGEN',  0,    23),
        'FENSTER_H'    => array('h',      'TB_FELD.FENSTER_H',    0,    23),
        'FENSTER_IN'   => array('h',      'TB_FELD.FENSTER_IN',   0,    47),
        'FENSTER_CT'   => array('ct/kWh', 'TB_FELD.FENSTER_CT',   0,    200),
        'PULSE'        => array('W',      'TB_FELD.PULSE',        -30000, 30000),
        'PULSE_ERZ'    => array('W',      'TB_FELD.PULSE_ERZ',    0,    30000),
        'PULSE_TAG'    => array('kWh',    'TB_FELD.PULSE_TAG',    0,    500),
        'PULSE_KOSTEN' => array('EUR',    'TB_FELD.PULSE_KOSTEN', 0,    500),
        'PULSE_ALTER'  => array('s',      'TB_FELD.PULSE_ALTER',  0,    86400),
        'VERBR_GESTERN' => array('kWh',   'TB_FELD.VERBR_GESTERN', 0,   1000),
        'KOSTEN_GESTERN' => array('EUR',  'TB_FELD.KOSTEN_GESTERN', 0,  1000),
        'VERBR_MONAT'  => array('kWh',    'TB_FELD.VERBR_MONAT',  0,    10000),
        'KOSTEN_MONAT' => array('EUR',    'TB_FELD.KOSTEN_MONAT', 0,    10000),
        'DYN_MONAT'    => array('ct/kWh', 'TB_FELD.DYN_MONAT',    0,    200),
        'FIX'          => array('ct/kWh', 'TB_FELD.FIX',          0,    200),
        'DIFF_MONAT'   => array('ct/kWh', 'TB_FELD.DIFF_MONAT',   -100, 100),
        'EURO_MONAT'   => array('EUR',    'TB_FELD.EURO_MONAT',   -1000, 1000),
        'ALTER'        => array('min',    'TB_FELD.ALTER',        0,    1440),
        'OK'           => array('',       'TB_FELD.OK',           0,    1),
    );
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

    $w['OK'] = !empty($st['ok']) ? 1 : 0;
    $alter = tb_alter();
    $w['ALTER'] = $alter < 0 ? 9999 : (int) round($alter / 60);

    foreach (array('CUR' => 'cur', 'CUR_ENERGIE' => 'cur_energie', 'CUR_STEUER' => 'cur_steuer',
                   'NEXT' => 'next', 'LEVEL' => 'level', 'TLEVEL' => 'tlevel',
                   'RANK' => 'rank', 'RANKD' => 'rankd', 'NEG' => 'neg',
                   'FENSTER_H' => 'fenster_h', 'FENSTER_IN' => 'fenster_in',
                   'FENSTER_CT' => 'fenster_ct', 'MORGEN_OK' => 'morgen_ok') as $gross => $klein) {
        if (isset($st[$klein])) { $w[$gross] = $st[$klein]; }
    }
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
    $w['PULSE_ALTER'] = $la < 0 ? null : $la;
    if ($la >= 0 && $la <= 120) {
        $w['PULSE']        = isset($live['power']) ? $live['power'] : null;
        $w['PULSE_ERZ']    = isset($live['powerProduction']) ? $live['powerProduction'] : null;
        $w['PULSE_TAG']    = isset($live['accumulatedConsumption']) ? $live['accumulatedConsumption'] : null;
        $w['PULSE_KOSTEN'] = isset($live['accumulatedCost']) ? $live['accumulatedCost'] : null;
    }

    foreach (array('VERBR_GESTERN' => 'verbr_gestern', 'KOSTEN_GESTERN' => 'kosten_gestern',
                   'VERBR_MONAT' => 'verbr_monat', 'KOSTEN_MONAT' => 'kosten_monat',
                   'DYN_MONAT' => 'dyn_monat', 'DIFF_MONAT' => 'diff_monat',
                   'EURO_MONAT' => 'euro_monat') as $gross => $klein) {
        if (isset($vb[$klein])) { $w[$gross] = $vb[$klein]; }
    }
    $w['FIX'] = (float) $cfg['festpreis'];
    return $w;
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
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

function tb_mqtt_zustand()
{
    $p = tb_paths();
    $leer = array('gefunden' => 0, 'autostart' => 0, 'udpport' => 0, 'broker' => '',
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
        'udpport'    => (int) $hol('Udpinport', 'udpinport'),
        'broker'     => (string) $hol('Brokerhost', 'brokerhost'),
        'brokerport' => (string) $hol('Brokerport', 'brokerport'),
        'user'       => (string) $hol('Brokeruser', 'brokeruser'),
        'lokal'      => in_array((string) $hol('Uselocalbroker', 'uselocalbroker'), array('1', 'true'), true) ? 1 : 0,
    );
}

/**
 * Werte ueber den UDP-Eingang des Gateways veroeffentlichen.
 * So braucht das Plugin keine Broker-Zugangsdaten zu kennen.
 */
function tb_mqtt_senden(array $paare, $praefix)
{
    if (!function_exists('socket_create')) {
        tb_log_gebremst('mqtt_keine_sockets',
            'MQTT: die PHP-Erweiterung sockets fehlt - es wird nichts veroeffentlicht. '
            . 'Abhilfe: sudo apt install php-sockets');
        return false;
    }
    $z = tb_mqtt_zustand();
    if (!$z['udpport']) {
        tb_log_gebremst('mqtt_kein_port',
            'MQTT: kein UDP-Eingangsport in der general.json gefunden - nichts gesendet.');
        return false;
    }
    if (!$z['autostart']) {
        tb_log_gebremst('mqtt_aus', 'MQTT: das Gateway ist nicht auf Autostart gestellt '
            . '(System, MQTT Gateway). Es wird gesendet, aber vermutlich hoert niemand zu.');
    }
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) {
        tb_log_gebremst('mqtt_socket', 'MQTT: Socket nicht moeglich.');
        return false;
    }
    foreach ($paare as $k => $v) {
        if ($v === null || $v === '') { continue; }   // fehlender Wert: nichts senden
        $msg = 'publish ' . $praefix . '/' . $k . ' ' . tb_mqtt_wert_saeubern($v);
        @socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $z['udpport']);
    }
    socket_close($s);
    return true;
}

/** Alle Themen, die veroeffentlicht werden, mit ihrer Bedeutung. */
function tb_mqtt_themen()
{
    $out = array(
        'ok'    => 'TB_MQTT.OK',
        'alter' => 'TB_MQTT.ALTER',
    );
    foreach (tb_status_felder() as $f => $info) {
        if ($f === 'OK' || $f === 'ALTER') { continue; }
        $out[strtolower($f)] = $info[1];
    }
    $out['stunde/N/ct'] = 'TB_MQTT.STUNDE';
    return $out;
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

function tb_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . tb_x($kopf['title']) . '" ';
    $o .= 'Comment="' . tb_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . tb_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . tb_x(isset($kopf['polling']) ? $kopf['polling'] : '300') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . tb_x($c['title']) . '" ';
        $o .= 'Comment="' . tb_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . tb_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        // Grenzen realistisch, nicht pauschal +/-2147483647: Loxone zieht
        // daraus die Reglergrenzen und die Plausibilitaetspruefung.
        $o .= 'MinVal="' . tb_x(isset($c['min']) ? $c['min'] : '-2147483647') . '" ';
        $o .= 'MaxVal="' . tb_x(isset($c['max']) ? $c['max'] : '2147483647') . '"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
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
        // Der Text laeuft gleich durch tb_x() und wuerde dort ein zweites Mal
        // maskiert. Deshalb erst Auszeichnung entfernen und Entitaeten
        // aufloesen.
        $bedeutung = trim(strip_tags(html_entity_decode(tb_t($info[1]), ENT_QUOTES, 'UTF-8')));
        $cmds[] = array(
            'title'   => 'TIBBER_' . $feld,
            'comment' => $bedeutung . ($info[0] !== '' ? ' [' . $info[0] . ']' : ''),
            'check'   => '\i' . $feld . '=\i\v',
            'min'     => (string) $info[2],
            'max'     => (string) $info[3],
        );
    }
    $adresse = 'http://' . $host . '/plugins/' . $p['plugin']
             . '/index.php?token=' . $token . '&aktion=status';
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
