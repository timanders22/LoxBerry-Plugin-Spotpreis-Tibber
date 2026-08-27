<?php
/**
 * Spotpreis Tibber - Echtzeitwerte der Tibber Pulse
 *
 * Aufrufe:
 *   tb_pulse.php              Daemon, laeuft bis SIGTERM
 *   tb_pulse.php --einmal     verbindet, wartet auf den ersten Messwert, Ende
 *   tb_pulse.php --pruefen    prueft nur, ob eine Pulse gemeldet wird
 *
 * ---------------------------------------------------------------------------
 * Warum ein eigener Daemon
 * ---------------------------------------------------------------------------
 * Preise und Verbrauch holt ein Cron-Lauf. Die Momentanwerte der Pulse kommen
 * dagegen im Sekundentakt ueber eine DAUERHAFTE WebSocket-Verbindung - das
 * geht mit einem Minutentakt nicht, und jede Neuverbindung kostet Tibber
 * gegenueber ein Zaehlwerk. Deshalb ein Prozess, der steht.
 *
 * ---------------------------------------------------------------------------
 * Das Protokoll
 * ---------------------------------------------------------------------------
 * Tibber spricht 'graphql-transport-ws' - den Nachfolger des alten
 * 'subscriptions-transport-ws'. Der Ablauf:
 *
 *   Client -> connection_init  { payload: { token: <Zugangstoken> } }
 *   Server -> connection_ack
 *   Client -> subscribe        { id, payload: { query: 'subscription {...}' } }
 *   Server -> next             { id, payload: { data: { liveMeasurement: {...} } } }
 *   Server -> ping             (dazwischen, muss mit pong beantwortet werden)
 *
 * Die WebSocket-Adresse steht NICHT fest, sondern wird bei jedem Start ueber
 * viewer.websocketSubscriptionUrl erfragt. Sie fest zu verdrahten waere ein
 * Fehler, der erst Monate spaeter auffaellt.
 *
 * Ein reiner PHP-Nachbau von RFC 6455 - ohne fremde Bibliothek, damit das
 * Plugin ohne composer und ohne PEP-668-Umweg auskommt. Der Rahmenbau ist im
 * Selbsttest gegen feste Pruefwerte gehalten.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$tb_gefunden = false;
foreach (array(
    dirname(__DIR__) . '/webfrontend/html/tb_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/tb_lib.php',
    dirname(dirname(__DIR__)) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/tb_lib.php',
) as $tb_kandidat) {
    if (is_file($tb_kandidat)) {
        require_once $tb_kandidat;
        $tb_gefunden = true;
        break;
    }
}
if (!$tb_gefunden) {
    fwrite(STDERR, "tb_lib.php nicht gefunden - Plugin neu installieren.\n");
    exit(1);
}

$tb_laeuft = true;

function tb_signal($signal)
{
    global $tb_laeuft;
    $tb_laeuft = false;
    tb_log('Pulse: Signal ' . (int) $signal . ' empfangen - der Dienst haelt an.');
}

/* ==================================================================
 * WebSocket nach RFC 6455
 * ================================================================== */

/**
 * Einen Rahmen bauen. Client-Rahmen sind IMMER maskiert - ein unmaskierter
 * Rahmen vom Client muss die Gegenstelle nach RFC 6455 verwerfen.
 *
 * $opcode: 1 Text, 8 Schliessen, 9 Ping, 10 Pong
 */
function tb_ws_rahmen($nutzdaten, $opcode = 1)
{
    $laenge = strlen($nutzdaten);
    $rahmen = chr(0x80 | ($opcode & 0x0F));
    if ($laenge < 126) {
        $rahmen .= chr(0x80 | $laenge);
    } elseif ($laenge < 65536) {
        $rahmen .= chr(0x80 | 126) . pack('n', $laenge);
    } else {
        // 64 Bit, obere Haelfte 0: mehr als 4 GB sendet hier niemand.
        $rahmen .= chr(0x80 | 127) . pack('NN', 0, $laenge);
    }
    $maske = random_bytes(4);
    $rahmen .= $maske;
    for ($i = 0; $i < $laenge; $i++) {
        $rahmen .= chr(ord($nutzdaten[$i]) ^ ord($maske[$i % 4]));
    }
    return $rahmen;
}

/**
 * Einen Rahmen lesen. Rueckgabe: array(opcode, nutzdaten) oder
 * array(null, Grund) bei einem Fehler, oder array(-1, '') wenn gerade nichts
 * anliegt.
 */
function tb_ws_lesen($fh, $wartezeit = 1.0)
{
    $ende = microtime(true) + $wartezeit;
    $kopf = '';
    while (strlen($kopf) < 2) {
        $teil = @fread($fh, 2 - strlen($kopf));
        if ($teil === false) { return array(null, 'Die Verbindung ist abgerissen.'); }
        if ($teil === '') {
            if (feof($fh)) { return array(null, 'Die Gegenstelle hat geschlossen.'); }
            if (microtime(true) > $ende) { return array(-1, ''); }
            usleep(20000);
            continue;
        }
        $kopf .= $teil;
    }
    $b0 = ord($kopf[0]);
    $b1 = ord($kopf[1]);
    $fin = ($b0 & 0x80) !== 0;
    $opcode = $b0 & 0x0F;
    $maskiert = ($b1 & 0x80) !== 0;
    $laenge = $b1 & 0x7F;

    $hol = function ($n) use ($fh) {
        $puffer = '';
        $frist = microtime(true) + 10;
        while (strlen($puffer) < $n) {
            $t = @fread($fh, $n - strlen($puffer));
            if ($t === false) { return false; }
            if ($t === '') {
                if (feof($fh) || microtime(true) > $frist) { return false; }
                usleep(10000);
                continue;
            }
            $puffer .= $t;
        }
        return $puffer;
    };

    if ($laenge === 126) {
        $z = $hol(2);
        if ($z === false) { return array(null, 'Laengenfeld unvollstaendig.'); }
        $e = unpack('n', $z);
        $laenge = $e[1];
    } elseif ($laenge === 127) {
        $z = $hol(8);
        if ($z === false) { return array(null, 'Laengenfeld unvollstaendig.'); }
        $e = unpack('N2', $z);
        // Die obere Haelfte wird verworfen: Rahmen ueber 4 GB gibt es hier nicht.
        $laenge = $e[2];
    }
    if ($laenge > 4194304) {
        return array(null, 'Der Rahmen ist mit ' . $laenge . ' Bytes unglaubwuerdig gross.');
    }
    $maske = '';
    if ($maskiert) {
        $maske = $hol(4);
        if ($maske === false) { return array(null, 'Maske unvollstaendig.'); }
    }
    $daten = $laenge > 0 ? $hol($laenge) : '';
    if ($daten === false) { return array(null, 'Nutzdaten unvollstaendig.'); }
    if ($maskiert && $laenge > 0) {
        $klar = '';
        for ($i = 0; $i < $laenge; $i++) {
            $klar .= chr(ord($daten[$i]) ^ ord($maske[$i % 4]));
        }
        $daten = $klar;
    }
    /* Geteilte Nachrichten werden NICHT zusammengesetzt - ein liveMeasurement
     * ist unter tausend Bytes gross, und niemand teilt so etwas auf.
     *
     * Es wird aber auch nicht so getan, als waere nichts gewesen. Vorher fiel
     * ein Folgerahmen (Opcode 0) weiter unten durch "kein Text, wird
     * uebersprungen" und der erste Teil scheiterte still am json_decode: das
     * Ergebnis waere ein Dienst, der verbunden aussieht und keine Werte
     * liefert - der teuerste aller Fehler, weil niemand weiss, wo er suchen
     * soll. Jetzt steht der Grund im Protokoll. */
    if (!$fin || $opcode === 0) {
        return array(null, 'Die Gegenstelle sendet geteilte Rahmen (FIN=0 oder '
            . 'Opcode 0). Diese Fassung setzt sie nicht zusammen.');
    }
    return array($opcode, $daten);
}

/**
 * Verbindung aufbauen und den WebSocket-Handschlag fuehren.
 * Rueckgabe: Datenstrom oder array('_fehler' => Text)
 */
function tb_ws_verbinden($url, $tmo = 15)
{
    $t = parse_url($url);
    if (!$t || !isset($t['host'])) {
        return array('_fehler' => 'Die WebSocket-Adresse ist unbrauchbar: ' . $url);
    }
    $sicher = (isset($t['scheme']) && $t['scheme'] === 'wss');
    $port = isset($t['port']) ? (int) $t['port'] : ($sicher ? 443 : 80);
    $pfad = (isset($t['path']) ? $t['path'] : '/')
          . (isset($t['query']) ? '?' . $t['query'] : '');

    if ($sicher && !extension_loaded('openssl')) {
        return array('_fehler' => 'Die Verbindung verlangt Verschluesselung, aber die '
            . 'PHP-Erweiterung openssl fehlt. Abhilfe: sudo apt install php-openssl');
    }
    $ctx = stream_context_create(array('ssl' => array(
        'verify_peer'       => true,
        'verify_peer_name'  => true,
        'SNI_enabled'       => true,
        'peer_name'         => $t['host'],
    )));
    $errno = 0;
    $errstr = '';
    $fh = @stream_socket_client(($sicher ? 'ssl://' : 'tcp://') . $t['host'] . ':' . $port,
        $errno, $errstr, $tmo, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fh) {
        return array('_fehler' => tb_fehlertext($errstr, $errno));
    }
    stream_set_timeout($fh, $tmo);

    $schluessel = base64_encode(random_bytes(16));
    $anfrage = "GET " . $pfad . " HTTP/1.1\r\n"
             . "Host: " . $t['host'] . "\r\n"
             . "Upgrade: websocket\r\n"
             . "Connection: Upgrade\r\n"
             . "Sec-WebSocket-Key: " . $schluessel . "\r\n"
             . "Sec-WebSocket-Version: 13\r\n"
             . "Sec-WebSocket-Protocol: graphql-transport-ws\r\n"
             . "User-Agent: LoxBerry-Plugin-Spotpreis-Tibber/0.9.0 (+https://wiki.loxberry.de/)\r\n"
             . "Origin: https://api.tibber.com\r\n"
             . "\r\n";
    if (@fwrite($fh, $anfrage) === false) {
        fclose($fh);
        return array('_fehler' => 'Der Handschlag liess sich nicht senden.');
    }

    $kopf = '';
    $frist = microtime(true) + $tmo;
    while (strpos($kopf, "\r\n\r\n") === false) {
        $z = @fgets($fh, 2048);
        if ($z === false || $z === '') {
            if (microtime(true) > $frist) { break; }
            usleep(20000);
            continue;
        }
        $kopf .= $z;
    }
    if (!preg_match('#^HTTP/1\.1\s+101#i', $kopf)) {
        fclose($fh);
        $erste = trim(strtok($kopf, "\r\n"));
        return array('_fehler' => 'Der WebSocket-Handschlag wurde abgelehnt. Antwort: '
            . ($erste !== '' ? $erste : 'keine') . '. Bei HTTP 401 stimmt das Token nicht; '
            . 'bei HTTP 4xx sonst ist meist die Adresse veraltet - sie wird bei jedem '
            . 'Start neu erfragt, ein Neustart des Dienstes hilft also.');
    }
    // Die Gegenstelle muss das Unterprotokoll bestaetigen. Tut sie es nicht,
    // spricht sie etwas anderes, und das faellt sonst erst bei der ersten
    // unverstaendlichen Nachricht auf.
    if (stripos($kopf, 'graphql-transport-ws') === false) {
        fclose($fh);
        return array('_fehler' => 'Die Gegenstelle hat das Unterprotokoll '
            . 'graphql-transport-ws nicht bestaetigt. Vermutlich erwartet sie das '
            . 'aeltere subscriptions-transport-ws.');
    }
    $pruef = base64_encode(sha1($schluessel . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    if (stripos($kopf, $pruef) === false) {
        fclose($fh);
        return array('_fehler' => 'Die Bestaetigung des Handschlags passt nicht zum '
            . 'gesendeten Schluessel. Dazwischen sitzt vermutlich ein Zwischenrechner.');
    }
    stream_set_blocking($fh, false);
    return $fh;
}

function tb_ws_senden($fh, array $nachricht)
{
    return @fwrite($fh, tb_ws_rahmen(json_encode($nachricht), 1)) !== false;
}

/* ==================================================================
 * Die Abonnement-Abfrage
 *
 * Feldnamen aus der oeffentlichen Tibber-Beschreibung, gegengelesen an
 * github.com/terjesannum/tibber-exporter. Es werden bewusst nur Felder
 * abgefragt, die dort belegt sind.
 * ================================================================== */
function tb_pulse_abfrage($homeId)
{
    // Dieselbe Pruefung wie bei den anderen Abfragen. Hier kommt die Kennung
    // zwar aus der Antwort von Tibber und nicht aus der Konfiguration - aber
    // eine Abfrage aus einer fremden Antwort zusammenzubauen, ohne sie
    // anzusehen, ist genau der Fall, den man spaeter nicht mehr findet.
    $homeId = tb_gql_id($homeId);
    return 'subscription { liveMeasurement(homeId: "' . $homeId . '") {'
         . ' timestamp power powerProduction'
         . ' accumulatedConsumption accumulatedProduction accumulatedCost currency'
         . ' minPower maxPower averagePower'
         . ' lastMeterConsumption lastMeterProduction'
         . ' currentL1 currentL2 currentL3'
         . ' voltagePhase1 voltagePhase2 voltagePhase3'
         . ' signalStrength } }';
}

/**
 * Zuhause und WebSocket-Adresse erfragen.
 * Rueckgabe: array(homeId, wsUrl, echtzeit) oder array('_fehler' => Text)
 */
function tb_pulse_konto()
{
    $cfg = tb_config();
    $data = tb_gql(tb_abfrage_konto());
    if (isset($data['_fehler'])) { return $data; }
    $ws = isset($data['viewer']['websocketSubscriptionUrl'])
        ? (string) $data['viewer']['websocketSubscriptionUrl'] : '';
    $homes = isset($data['viewer']['homes']) && is_array($data['viewer']['homes'])
        ? $data['viewer']['homes'] : array();
    if (!$homes) {
        return array('_fehler' => 'Das Konto meldet kein Zuhause.');
    }
    $gewaehlt = null;
    $wunsch = trim((string) $cfg['home_id']);
    foreach ($homes as $h) {
        if ($wunsch !== '' && isset($h['id']) && (string) $h['id'] === $wunsch) {
            $gewaehlt = $h;
            break;
        }
    }
    if ($gewaehlt === null) { $gewaehlt = $homes[0]; }
    if ($ws === '') {
        return array('_fehler' => 'Das Konto liefert keine WebSocket-Adresse. Damit sind '
            . 'Echtzeitwerte nicht moeglich.');
    }
    $echtzeit = !empty($gewaehlt['features']['realTimeConsumptionEnabled']);
    return array(
        'home'     => isset($gewaehlt['id']) ? (string) $gewaehlt['id'] : '',
        'ws'       => $ws,
        'echtzeit' => $echtzeit ? 1 : 0,
        'name'     => isset($gewaehlt['appNickname']) ? (string) $gewaehlt['appNickname'] : '',
    );
}

/* ==================================================================
 * Hauptschleife
 * ================================================================== */

function tb_pulse_schleife($einmal = false)
{
    global $tb_laeuft;
    $p = tb_paths();
    foreach (array($p['datadir'], $p['logdir']) as $d) {
        if (!is_dir($d)) { @mkdir($d, 0775, true); }
    }
    $pause = 5;              // Sekunden bis zum naechsten Versuch
    $maxPause = 300;

    while ($tb_laeuft) {
        if (function_exists('pcntl_signal_dispatch')) { pcntl_signal_dispatch(); }
        $cfg = tb_config();
        if (empty($cfg['pulse_ein'])) {
            tb_log_gebremst('pulse_aus', 'Pulse: die Echtzeitwerte sind ausgeschaltet - '
                . 'der Dienst wartet.', 3600);
            sleep(30);
            continue;
        }

        $konto = tb_pulse_konto();
        if (isset($konto['_fehler'])) {
            tb_log_gebremst('pulse_konto', 'Pulse: ' . $konto['_fehler']);
            sleep($pause);
            $pause = min($maxPause, $pause * 2);
            continue;
        }
        if (!$konto['echtzeit']) {
            // Das ist KEIN Fehler des Plugins, und es wird auch nicht als
            // solcher gemeldet: ohne Pulse oder Watty gibt es keine Werte.
            tb_log_gebremst('pulse_keine', 'Pulse: fuer dieses Zuhause meldet Tibber '
                . 'realTimeConsumptionEnabled = false. Es ist also keine Tibber Pulse oder '
                . 'Watty angeschlossen, oder sie ist im Konto nicht freigeschaltet. Preise '
                . 'und Verbrauch laufen davon unabhaengig weiter.', 21600);
            sleep(300);
            continue;
        }

        $fh = tb_ws_verbinden($konto['ws']);
        if (is_array($fh)) {
            tb_log_gebremst('pulse_verbinden', 'Pulse: ' . $fh['_fehler']);
            sleep($pause);
            $pause = min($maxPause, $pause * 2);
            continue;
        }

        tb_log('Pulse: verbunden mit ' . parse_url($konto['ws'], PHP_URL_HOST)
            . ' fuer Zuhause ' . ($konto['name'] !== '' ? $konto['name'] : $konto['home']) . '.');
        $pause = 5;

        // connection_init mit dem Token im Nutzdatenteil - NICHT als
        // Kopfzeile: beim WebSocket gibt es nach dem Handschlag keine.
        tb_ws_senden($fh, array('type' => 'connection_init',
            'payload' => array('token' => tb_token_lesen())));

        $bestaetigt = false;
        $abonniert = false;
        $letzterWert = 0;
        $start = time();

        while ($tb_laeuft) {
            if (function_exists('pcntl_signal_dispatch')) { pcntl_signal_dispatch(); }
            list($opcode, $daten) = tb_ws_lesen($fh, 1.0);
            if ($opcode === null) {
                tb_log_gebremst('pulse_abriss', 'Pulse: ' . $daten . ' Es wird neu verbunden.');
                break;
            }
            if ($opcode === -1) {
                // Nichts angekommen. Kam laenger als zwei Minuten kein Wert,
                // ist die Verbindung tot, auch wenn sie offen aussieht.
                if ($bestaetigt && $letzterWert > 0 && time() - $letzterWert > 120) {
                    tb_log('Pulse: seit ' . (time() - $letzterWert) . ' s kein Messwert - '
                        . 'die Verbindung wird erneuert.');
                    break;
                }
                if (!$bestaetigt && time() - $start > 30) {
                    tb_log('Pulse: die Gegenstelle hat den Verbindungsaufbau nicht '
                        . 'bestaetigt. Es wird neu verbunden.');
                    break;
                }
                continue;
            }
            if ($opcode === 8) {                       // Schliessen
                tb_log_gebremst('pulse_zu', 'Pulse: die Gegenstelle hat die Verbindung '
                    . 'geschlossen.');
                break;
            }
            if ($opcode === 9) {                       // Ping auf WebSocket-Ebene
                @fwrite($fh, tb_ws_rahmen($daten, 10));
                continue;
            }
            if ($opcode !== 1) { continue; }           // Binaeres wird nicht erwartet

            $n = json_decode($daten, true);
            if (!is_array($n) || !isset($n['type'])) { continue; }

            switch ($n['type']) {
                case 'connection_ack':
                    $bestaetigt = true;
                    tb_ws_senden($fh, array(
                        'id' => '1', 'type' => 'subscribe',
                        'payload' => array('query' => tb_pulse_abfrage($konto['home'])),
                    ));
                    $abonniert = true;
                    break;

                case 'ping':                            // Ping auf GraphQL-Ebene
                    tb_ws_senden($fh, array('type' => 'pong'));
                    break;

                case 'next':
                    $m = isset($n['payload']['data']['liveMeasurement'])
                        ? $n['payload']['data']['liveMeasurement'] : null;
                    if (!is_array($m)) { break; }
                    $letzterWert = time();
                    $m['ts'] = time();
                    tb_json_schreiben(tb_paths()['datadir'] . '/live.json', $m);
                    if ($einmal) {
                        tb_log('Pulse: erster Messwert eingegangen ('
                            . (isset($m['power']) ? (int) $m['power'] : '?') . ' W).');
                        @fwrite($fh, tb_ws_rahmen('', 8));
                        fclose($fh);
                        return 0;
                    }
                    break;

                case 'error':
                    $txt = json_encode(isset($n['payload']) ? $n['payload'] : array());
                    tb_log_gebremst('pulse_fehler', 'Pulse: die Gegenstelle meldet einen '
                        . 'Fehler zum Abonnement: ' . $txt);
                    break;

                case 'complete':
                    tb_log('Pulse: das Abonnement wurde beendet. Es wird neu verbunden.');
                    break 2;
            }
        }

        if (is_resource($fh)) {
            @fwrite($fh, tb_ws_rahmen('', 8));
            fclose($fh);
        }
        if ($einmal) { return 1; }
        if ($tb_laeuft) { sleep(3); }
    }

    tb_log('Pulse: Dienst beendet.');
    return 0;
}

/* ------------------------------------------------------------------ */

/* Laeuft diese Datei DIREKT, oder wurde sie eingebunden?
 *
 * Verglichen werden die DATEINAMEN, nicht die aufgeloesten Pfade. Ein
 * realpath()-Vergleich sieht plausibel aus und faellt auseinander, sobald
 * der Aufrufpfad anders geschrieben ist als __FILE__ - unter Windows etwa
 * in der kurzen Form (CHRIST~1). Der Waechter hielt den Lauf dann fuer ein
 * Einbinden und kehrte zurueck: Rueckgabewert 0, keine Ausgabe, nichts
 * getan. Am 27.08.2026 hat das eine ganze Eichung wertlos gemacht, weil
 * jeder Rueckbau 'gruen' meldete, ohne dass etwas gelaufen waere.
 *
 * Der Name genuegt fuer die Frage: eingebunden wird diese Datei aus
 * index.php oder tb_test.php, und die heissen anders. */
$tb_direkt = !isset($_SERVER['SCRIPT_FILENAME'])
    || basename((string) $_SERVER['SCRIPT_FILENAME']) === basename(__FILE__);
if (!$tb_direkt) { return; }

$tb_argv = isset($argv) ? $argv : array();

if (in_array('--pruefen', $tb_argv, true)) {
    $k = tb_pulse_konto();
    if (isset($k['_fehler'])) {
        echo "[FEHL] " . $k['_fehler'] . "\n";
        exit(1);
    }
    echo "[OK]   Zuhause: " . ($k['name'] !== '' ? $k['name'] : $k['home']) . "\n";
    echo "[OK]   Kennung: " . $k['home'] . "\n";
    echo "[INFO] WebSocket-Adresse: " . preg_replace('#\?.*$#', '?…', $k['ws']) . "\n";
    echo ($k['echtzeit'] ? "[OK]   " : "[WARN] ")
       . "Echtzeitverbrauch laut Tibber: " . ($k['echtzeit'] ? 'ja' : 'nein') . "\n";
    if (!$k['echtzeit']) {
        echo "[INFO] Ohne Tibber Pulse oder Watty gibt es keine Echtzeitwerte. Preise und\n";
        echo "[INFO] Verbrauch laufen davon unabhaengig weiter.\n";
    }
    exit($k['echtzeit'] ? 0 : 1);
}

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, 'tb_signal');
    pcntl_signal(SIGINT, 'tb_signal');
}
exit(tb_pulse_schleife(in_array('--einmal', $tb_argv, true)));
