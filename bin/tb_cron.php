<?php
/**
 * Spotpreis Tibber - Abruf von Preisen und Verbrauch
 *
 * Aufrufe:
 *   tb_cron.php                minuetlich aus dem Cron; entscheidet selbst,
 *                              was faellig ist
 *   tb_cron.php --preise       Preise sofort holen
 *   tb_cron.php --verbrauch    Verbrauch sofort holen
 *   tb_cron.php --selbsttest   Pruefungen ohne Konto, Klartextausgabe
 *
 * Warum die Taktsteuerung hier steckt und nicht im Cron: Tibber bremst
 * haeufige Abrufe. Der Takt soll sich in der Oberflaeche einstellen lassen,
 * ohne dass jemand eine Crontab anfasst.
 *
 * Protokolliert wird NUR in die Logdatei, nicht nach stdout - das Cron-Skript
 * leitet stdout ohnehin dorthin um.
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

/* ==================================================================
 * Preise holen und auswerten
 * ================================================================== */

function tb_preise_holen()
{
    $cfg = tb_config();
    $p = tb_paths();
    $data = tb_gql(tb_abfrage_preise((string) $cfg['home_id']));
    if (isset($data['_fehler'])) {
        // Der alte Stand bleibt stehen; nur das Merkmal ok faellt.
        $st = tb_stand();
        $st['ok'] = 0;
        $st['fehler'] = $data['_fehler'];
        tb_json_schreiben($p['datadir'] . '/stand.json', $st);
        tb_log_gebremst('preise', 'Preisabruf misslungen: ' . $data['_fehler']);
        return array(0, $data['_fehler']);
    }
    $home = tb_erstes_home($data);
    if ($home === null || !isset($home['currentSubscription']['priceInfo'])) {
        $meldung = 'Die Antwort enthaelt keine Preisinformationen. Hat das Konto einen '
                 . 'laufenden Tibber-Vertrag? Ohne Vertrag liefert die Schnittstelle keine '
                 . 'Preise, auch wenn das Token gueltig ist.';
        tb_log_gebremst('preise_leer', $meldung);
        return array(0, $meldung);
    }
    $pi = $home['currentSubscription']['priceInfo'];

    $heute  = tb_preisliste(isset($pi['today']) ? $pi['today'] : array(), $cfg);
    $morgen = tb_preisliste(isset($pi['tomorrow']) ? $pi['tomorrow'] : array(), $cfg);
    $alle = array_merge($heute, $morgen);
    if (!$alle) {
        $meldung = 'Es kamen null Preisstunden zurueck. Das ist kurz nach Mitternacht '
                 . 'moeglich; beim naechsten Takt wird es erneut versucht.';
        tb_log_gebremst('preise_null', $meldung);
        return array(0, $meldung);
    }

    $jetzt = time();
    $cur = tb_preis_zur_zeit($alle, $jetzt);
    $next = tb_preis_zur_zeit($alle, $jetzt + 3600);
    // Energieanteil und Steuer der laufenden Stunde einzeln mitfuehren.
    $curE = null; $curS = null; $curL = -1;
    foreach ($alle as $e) {
        if ($e['ts'] <= $jetzt && $jetzt < $e['ts'] + 3600) {
            $curE = $e['energie']; $curS = $e['steuer']; $curL = $e['level'];
        }
    }
    // Tibbers eigenes Niveau der laufenden Stunde, falls current geliefert wurde.
    if (isset($pi['current']['level'])) {
        $curL = tb_tibber_level($pi['current']['level']);
    }
    list($rang, $rangd) = tb_rang($alle, $jetzt);
    $fenster = tb_fenster($alle, (int) $cfg['fensterstunden'], $jetzt);

    // Negativ ist der ENERGIEANTEIL, nicht der Endpreis: der Endpreis
    // enthaelt Steuer und wird deshalb praktisch nie negativ.
    $neg = 0;
    foreach ($alle as $e) {
        if ($e['ts'] <= $jetzt && $jetzt < $e['ts'] + 3600
            && $e['energie'] !== null && $e['energie'] < 0) { $neg = 1; }
    }

    $st = array(
        'ts'          => time(),
        'ok'          => 1,
        'fehler'      => '',
        'cur'         => $cur,
        'cur_energie' => $curE,
        'cur_steuer'  => $curS,
        'next'        => $next,
        'level'       => $cur === null ? null : tb_niveau($cur, $cfg),
        'tlevel'      => $curL,
        'rank'        => $rang,
        'rankd'       => $rangd,
        'neg'         => $neg,
        'heute'       => tb_kennzahlen($heute),
        'morgen'      => tb_kennzahlen($morgen),
        'morgen_ok'   => count($morgen) > 0 ? 1 : 0,
        'fenster_h'   => $fenster['h'],
        'fenster_in'  => $fenster['in'],
        'fenster_ct'  => $fenster['ct'],
        'waehrung'    => isset($pi['current']['currency']) ? (string) $pi['current']['currency'] : '',
        'liste_heute' => $heute,
        'liste_morgen' => $morgen,
    );
    tb_json_schreiben($p['datadir'] . '/stand.json', $st);
    tb_verlauf_schreiben($st);
    return array(1, count($heute) . ' Stunden heute, ' . count($morgen) . ' Stunden morgen.');
}

/** Einen Tagespunkt in die Verlaufsdatei schreiben, hoechstens stuendlich. */
function tb_verlauf_schreiben(array $st)
{
    $cfg = tb_config();
    $p = tb_paths();
    $ordner = $p['datadir'] . '/verlauf';
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) { return; }
    $datei = $ordner . '/' . date('Ym') . '.csv';
    if (is_file($datei) && (time() - filemtime($datei)) < 3300) { return; }
    if ($st['cur'] === null) { return; }
    @file_put_contents($datei, time() . ';' . $st['cur'] . ';'
        . ($st['heute']['avg'] === null ? '' : $st['heute']['avg']) . "\n", FILE_APPEND);
    $tage = max(1, min(3650, (int) $cfg['verlauf_tage']));
    foreach ((array) glob($ordner . '/*.csv') as $alt) {
        if (time() - filemtime($alt) > $tage * 86400) { @unlink($alt); }
    }
}

/* ==================================================================
 * Verbrauch holen und auswerten
 * ================================================================== */

function tb_verbrauch_holen()
{
    $cfg = tb_config();
    $p = tb_paths();
    if (empty($cfg['verbrauch_ein'])) {
        return array(-1, 'Der Verbrauchsabruf ist ausgeschaltet.');
    }
    // 62 Tage: genug fuer den laufenden und den vorigen Monat.
    $data = tb_gql(tb_abfrage_verbrauch((string) $cfg['home_id'], 'DAILY', 62));
    if (isset($data['_fehler'])) {
        tb_log_gebremst('verbrauch', 'Verbrauchsabruf misslungen: ' . $data['_fehler']);
        return array(0, $data['_fehler']);
    }
    $home = tb_erstes_home($data);
    $knoten = isset($home['consumption']['nodes']) ? $home['consumption']['nodes'] : array();
    if (!is_array($knoten) || !$knoten) {
        $meldung = 'Es kam keine Verbrauchshistorie zurueck. Tibber liefert sie erst, wenn '
                 . 'der Messstellenbetreiber die Werte uebermittelt hat - bei einem neuen '
                 . 'Vertrag kann das Tage dauern.';
        tb_log_gebremst('verbrauch_leer', $meldung);
        return array(0, $meldung);
    }

    $tage = array();
    foreach ($knoten as $n) {
        if (!isset($n['from'])) { continue; }
        $ts = strtotime((string) $n['from']);
        if ($ts === false) { continue; }
        $tage[date('Y-m-d', $ts)] = array(
            'kwh'   => isset($n['consumption']) && $n['consumption'] !== null
                       ? round((float) $n['consumption'], 3) : null,
            'kosten' => isset($n['cost']) && $n['cost'] !== null
                       ? round((float) $n['cost'], 3) : null,
        );
    }
    ksort($tage);

    $gestern = date('Y-m-d', strtotime('yesterday'));
    $monat = date('Y-m');
    $kwhM = 0.0; $kostenM = 0.0; $nM = 0;
    foreach ($tage as $tag => $w) {
        if (strpos($tag, $monat) !== 0) { continue; }
        if ($w['kwh'] !== null)   { $kwhM += $w['kwh']; $nM++; }
        if ($w['kosten'] !== null) { $kostenM += $w['kosten']; }
    }

    // Der lastprofil-gewichtete Durchschnittspreis des Monats ergibt sich aus
    // Kosten geteilt durch Verbrauch - das ist genauer als ein Mittelwert der
    // Stundenpreise, weil er den tatsaechlichen Verbrauch gewichtet.
    $dyn = ($kwhM > 0.01) ? round($kostenM / $kwhM * 100, 3) : null;
    $fix = (float) $cfg['festpreis'];
    $diff = ($dyn === null) ? null : round($dyn - $fix, 3);
    $euro = ($diff === null) ? null : round($diff * $kwhM / 100, 2);

    $vb = array(
        'ts'             => time(),
        'tage'           => $tage,
        'verbr_gestern'  => isset($tage[$gestern]['kwh']) ? $tage[$gestern]['kwh'] : null,
        'kosten_gestern' => isset($tage[$gestern]['kosten']) ? $tage[$gestern]['kosten'] : null,
        'verbr_monat'    => $nM > 0 ? round($kwhM, 2) : null,
        'kosten_monat'   => $nM > 0 ? round($kostenM, 2) : null,
        'tage_monat'     => $nM,
        'dyn_monat'      => $dyn,
        'diff_monat'     => $diff,
        'euro_monat'     => $euro,
    );
    tb_json_schreiben($p['datadir'] . '/verbrauch.json', $vb);
    return array(1, count($tage) . ' Tage Historie, ' . $nM . ' davon im laufenden Monat.');
}

/* ==================================================================
 * Monatsbericht
 *
 * Am Ersten des Monats ab 8 Uhr, mit Erledigt-Marker. Der Marker liegt unter
 * data/ und NICHT unter /tmp: /tmp ist auf dem LoxBerry eine Ramdisk, und
 * nach einem Neustart waere der Bericht ein zweites Mal faellig.
 * ================================================================== */

function tb_monatsbericht()
{
    $cfg = tb_config();
    $p = tb_paths();
    if (empty($cfg['monatsbericht'])) { return array(-1, 'ausgeschaltet'); }
    if ((int) date('j') !== 1 || (int) date('G') < 8) { return array(-1, 'nicht faellig'); }
    $marker = $p['datadir'] . '/bericht_' . date('Ym') . '.done';
    if (is_file($marker)) { return array(-1, 'in diesem Monat schon erledigt'); }

    $vb = tb_verbrauch();
    if (!isset($vb['tage']) || !is_array($vb['tage'])) {
        return array(0, 'Es liegt keine Verbrauchshistorie vor.');
    }
    $vormonat = date('Y-m', strtotime('first day of last month'));
    $kwh = 0.0; $kosten = 0.0; $n = 0;
    foreach ($vb['tage'] as $tag => $w) {
        if (strpos($tag, $vormonat) !== 0) { continue; }
        if ($w['kwh'] !== null) { $kwh += $w['kwh']; $n++; }
        if ($w['kosten'] !== null) { $kosten += $w['kosten']; }
    }
    if ($n === 0) {
        return array(0, 'Fuer ' . $vormonat . ' liegen keine Tageswerte vor.');
    }
    $dyn = $kwh > 0.01 ? round($kosten / $kwh * 100, 3) : null;
    $fix = (float) $cfg['festpreis'];
    $grund = (float) $cfg['grundpreis'];
    $diff = $dyn === null ? null : round($dyn - $fix, 3);
    $euro = $diff === null ? null : round($diff * $kwh / 100, 2);

    $text = sprintf(
        'Monatsbericht %s: %.1f kWh an %d Tagen, %.2f Euro Arbeitspreis. '
        . 'Dynamisch %.3f ct/kWh gegen fest %.3f ct/kWh - %s %.2f Euro. '
        . 'Grundpreis %.2f Euro ist dabei nicht eingerechnet.',
        $vormonat, $kwh, $n, $kosten,
        $dyn === null ? 0 : $dyn, $fix,
        ($euro !== null && $euro < 0) ? 'gespart' : 'draufgezahlt',
        abs($euro === null ? 0 : $euro), $grund);

    tb_json_schreiben($p['datadir'] . '/bericht.json', array(
        'ts' => time(), 'monat' => $vormonat, 'kwh' => round($kwh, 2),
        'kosten' => round($kosten, 2), 'tage' => $n, 'dyn' => $dyn,
        'fix' => $fix, 'diff' => $diff, 'euro' => $euro, 'text' => $text,
    ));
    @file_put_contents($marker, (string) time());
    tb_log($text);
    return array(1, $text);
}

/* ==================================================================
 * MQTT
 * ================================================================== */

function tb_veroeffentlichen()
{
    $cfg = tb_config();
    if (empty($cfg['mqtt_ein'])) { return; }
    $topic = trim((string) $cfg['mqtt_topic'], '/');
    if ($topic === '') { $topic = 'tibber'; }
    $w = tb_werte();
    $paare = array();
    foreach ($w as $k => $v) { $paare[strtolower($k)] = $v; }
    // Die Stundenpreise von heute einzeln, damit Loxone einen Tagesverlauf
    // zeichnen kann, ohne den JSON-Endpunkt zu bemuehen.
    $st = tb_stand();
    foreach ((array) (isset($st['liste_heute']) ? $st['liste_heute'] : array()) as $e) {
        $paare['stunde/' . (int) date('G', $e['ts']) . '/ct'] = $e['ct'];
    }
    tb_mqtt_senden($paare, $topic);
}

/* ==================================================================
 * Selbstpruefung ohne Konto
 * ================================================================== */

function tb_selbsttest()
{
    $p = tb_paths();
    $cfg = tb_config();
    $zeilen = array();
    $fehler = 0;

    $zeilen[] = 'Spotpreis Tibber - Selbstpruefung';
    $zeilen[] = str_repeat('-', 62);
    $zeilen[] = '[OK]   PHP ' . PHP_VERSION;
    foreach (array('json', 'curl') as $erw) {
        if (extension_loaded($erw)) {
            $zeilen[] = '[OK]   PHP-Erweiterung ' . $erw . ' geladen';
        } elseif ($erw === 'curl') {
            $zeilen[] = '[WARN] PHP-Erweiterung curl fehlt. Es wird ueber file_get_contents '
                      . 'gearbeitet - das geht, ist aber der Ersatzweg. '
                      . 'Abhilfe: sudo apt install php-curl';
        } else {
            $fehler++;
            $zeilen[] = '[FEHL] PHP-Erweiterung ' . $erw . ' fehlt';
        }
    }
    if (extension_loaded('sockets')) {
        $zeilen[] = '[OK]   PHP-Erweiterung sockets geladen (fuer MQTT)';
    } else {
        $zeilen[] = '[INFO] PHP-Erweiterung sockets fehlt - die Veroeffentlichung ueber '
                  . 'MQTT geht dann nicht. Abhilfe: sudo apt install php-sockets';
    }
    if (extension_loaded('openssl')) {
        $zeilen[] = '[OK]   PHP-Erweiterung openssl geladen (fuer die Pulse-Verbindung)';
    } else {
        $zeilen[] = '[WARN] PHP-Erweiterung openssl fehlt - die Pulse-Echtzeitwerte '
                  . 'brauchen sie. Preise und Verbrauch laufen trotzdem.';
    }

    foreach (array('Konfiguration' => $p['configdir'], 'Daten' => $p['datadir'],
                   'Log' => $p['logdir']) as $name => $pfad) {
        $ok = is_dir($pfad) && is_writable($pfad);
        $zeilen[] = ($ok ? '[OK]   ' : '[FEHL] ') . 'Ordner ' . $name . ' beschreibbar: ' . $pfad;
        if (!$ok) { $fehler++; }
    }

    // Rechte der Tokendatei. Die FORM eines Geheimnisses darf beurteilt
    // werden, sein Wert nie.
    $token = tb_token_lesen();
    if ($token === '') {
        $fehler++;
        $zeilen[] = '[FEHL] Es ist kein Tibber-Token hinterlegt';
    } else {
        $grund = tb_token_form($token);
        if ($grund === '') {
            $zeilen[] = '[OK]   Tibber-Token hinterlegt: ' . strlen($token)
                      . ' Zeichen (der Inhalt wird nicht angezeigt)';
        } else {
            $fehler++;
            $zeilen[] = '[FEHL] Das hinterlegte Token hat eine unglaubwuerdige Form ('
                      . $grund . ')';
        }
        $r = is_file($p['token']) ? (fileperms($p['token']) & 0777) : -1;
        $ok = $r >= 0 && ($r & 0077) === 0;
        $zeilen[] = ($ok ? '[OK]   ' : '[FEHL] ') . 'Rechte der Tokendatei: '
                  . ($r >= 0 ? '0' . decoct($r) : 'Datei fehlt') . ' (erwartet 0600)';
        if (!$ok) { $fehler++; }
    }

    $m = tb_mqtt_zustand();
    if (!$m['gefunden']) {
        $fehler++;
        $zeilen[] = '[FEHL] In der general.json ist kein MQTT-Abschnitt zu finden';
    } elseif ($m['autostart']) {
        $zeilen[] = '[OK]   MQTT-Gateway auf Autostart, UDP-Eingang ' . $m['udpport'];
    } elseif (!empty($cfg['mqtt_ein'])) {
        $fehler++;
        $zeilen[] = '[FEHL] MQTT ist im Plugin eingeschaltet, das Gateway steht aber nicht '
                  . 'auf Autostart. So kommt am Miniserver nichts an.';
    } else {
        $zeilen[] = '[INFO] MQTT-Gateway nicht auf Autostart - hier aber auch nicht gebraucht';
    }

    /* Rechenwege pruefen, die sich OHNE Konto pruefen lassen. Genau hier ist
     * ein Nachbau typischerweise falsch, und ein Fehler sieht von aussen aus
     * wie ein Problem der Schnittstelle. */
    $zeilen[] = '';
    $zeilen[] = 'Rechenwege gegen feste Pruefwerte:';

    $t0 = strtotime('today 00:00');
    $roh = array();
    // Ein Tag mit bekanntem Verlauf: 20 ct, nachts 10 ct, abends 40 ct.
    for ($h = 0; $h < 24; $h++) {
        $eur = 0.20;
        if ($h >= 2 && $h <= 5) { $eur = 0.10; }
        if ($h >= 18 && $h <= 20) { $eur = 0.40; }
        $roh[] = array('startsAt' => date('c', $t0 + $h * 3600),
                       'total' => $eur, 'energy' => $eur * 0.6, 'tax' => $eur * 0.4,
                       'level' => 'NORMAL');
    }
    $probe = array_merge(tb_vorgaben(), array('aufschlag' => 0.0));
    $liste = tb_preisliste($roh, $probe);
    $kz = tb_kennzahlen($liste);
    $ok1 = (count($liste) === 24) && (abs($kz['minp'] - 10.0) < 0.001)
           && (abs($kz['maxp'] - 40.0) < 0.001) && ($kz['minh'] === 2) && ($kz['maxh'] === 18);
    $zeilen[] = ($ok1 ? '[OK]   ' : '[FEHL] ') . 'Preisliste und Kennzahlen: 24 Stunden, '
              . 'Minimum ' . $kz['minp'] . ' ct um ' . $kz['minh'] . ' Uhr, Maximum '
              . $kz['maxp'] . ' ct um ' . $kz['maxh'] . ' Uhr (erwartet 10/2 und 40/18)';
    if (!$ok1) { $fehler++; }

    // Guenstigstes 3-Stunden-Fenster ab Mitternacht: 3 bis 5 Uhr, Schnitt 10 ct.
    $f = tb_fenster($liste, 3, $t0);
    $ok2 = ($f['h'] === 2) && (abs($f['ct'] - 10.0) < 0.001);
    $zeilen[] = ($ok2 ? '[OK]   ' : '[FEHL] ') . 'Guenstigstes 3-Stunden-Fenster ab '
              . 'Mitternacht: ab ' . var_export($f['h'], true) . ' Uhr zu '
              . var_export($f['ct'], true) . ' ct (erwartet 2 Uhr, 10 ct)';
    if (!$ok2) { $fehler++; }

    // Der Aufschlag muss durchschlagen.
    $probe2 = array_merge(tb_vorgaben(), array('aufschlag' => 1.5));
    $liste2 = tb_preisliste($roh, $probe2);
    $ok3 = abs($liste2[0]['ct'] - 21.5) < 0.001;
    $zeilen[] = ($ok3 ? '[OK]   ' : '[FEHL] ') . 'Aufschlag 1,5 ct auf 20 ct ergibt '
              . $liste2[0]['ct'] . ' ct (erwartet 21,5)';
    if (!$ok3) { $fehler++; }

    // Rang: um 3 Uhr muss die laufende Stunde die guenstigste sein.
    list($rg, $rgd) = tb_rang($liste, $t0 + 3 * 3600 + 60);
    $ok4 = ($rg === 1);
    $zeilen[] = ($ok4 ? '[OK]   ' : '[FEHL] ') . 'Rang um 03:01 Uhr: ' . var_export($rg, true)
              . ' von ' . $rgd . ' (erwartet 1)';
    if (!$ok4) { $fehler++; }

    // Niveau gegen die eigenen Schwellen.
    $ok5 = (tb_niveau(10.0, $probe) === 0) && (tb_niveau(30.0, $probe) === 1)
           && (tb_niveau(40.0, $probe) === 2);
    $zeilen[] = ($ok5 ? '[OK]   ' : '[FEHL] ') . 'Preisniveau bei 10/30/40 ct: '
              . tb_niveau(10.0, $probe) . '/' . tb_niveau(30.0, $probe) . '/'
              . tb_niveau(40.0, $probe) . ' (erwartet 0/1/2)';
    if (!$ok5) { $fehler++; }

    // Tibbers eigenes Niveau.
    $ok6 = (tb_tibber_level('VERY_CHEAP') === 0) && (tb_tibber_level('VERY_EXPENSIVE') === 4)
           && (tb_tibber_level('quatsch') === -1);
    $zeilen[] = ($ok6 ? '[OK]   ' : '[FEHL] ') . 'Tibber-Preisniveau VERY_CHEAP/'
              . 'VERY_EXPENSIVE/unbekannt ergibt 0/4/-1';
    if (!$ok6) { $fehler++; }

    // Die Loxone-Vorlage muss wohlgeformt sein.
    list($vname, $vinhalt) = tb_vorlage();
    $vorher = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($vinhalt);
    libxml_clear_errors();
    libxml_use_internal_errors($vorher);
    $zeilen[] = ($xml !== false ? '[OK]   ' : '[FEHL] ') . 'Loxone-Vorlage ' . $vname
              . ($xml !== false ? ' ist wohlgeformt, ' . count($xml->children())
                                  . ' Befehlserkennungen'
                                : ' ist NICHT wohlgeformt');
    if ($xml === false) { $fehler++; }

    $zeilen[] = '';
    $alter = tb_alter();
    $zeilen[] = $alter < 0 ? '[INFO] Es hat noch kein Preisabruf stattgefunden'
                           : '[INFO] Letzter Preisabruf vor ' . round($alter / 60) . ' Minuten';
    $la = tb_live_alter();
    $zeilen[] = $la < 0 ? '[INFO] Es sind noch keine Pulse-Werte eingegangen'
                        : '[INFO] Letzter Pulse-Wert vor ' . $la . ' s';
    $pid = tb_dienst_pid();
    $zeilen[] = '[INFO] Pulse-Dienst: ' . ($pid ? 'laeuft, PID ' . $pid : 'laeuft nicht')
              . ', eingeschaltet: ' . (!empty($cfg['pulse_ein']) ? 'ja' : 'nein');

    $zeilen[] = '';
    $zeilen[] = 'Nicht geprueft, weil dafuer ein Tibber-Konto noetig ist:';
    $zeilen[] = '  - ob das Token angenommen wird';
    $zeilen[] = '  - ob das Konto einen laufenden Vertrag hat (ohne Vertrag keine Preise)';
    $zeilen[] = '  - ob die Feldnamen dieser Fassung der Schnittstelle noch entsprechen';
    $zeilen[] = '  - ob eine Pulse angeschlossen ist und die Echtzeitwerte liefert';
    $zeilen[] = '  - in welcher Waehrung das Konto abrechnet (hier wird ct/kWh angenommen)';
    echo implode("\n", $zeilen) . "\n";
    return $fehler ? 1 : 0;
}

/* ------------------------------------------------------------------ */

$tb_direkt = !isset($_SERVER['SCRIPT_FILENAME'])
    || realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__);
if (!$tb_direkt) { return; }

$tb_argv = isset($argv) ? $argv : array();
if (in_array('--selbsttest', $tb_argv, true)) { exit(tb_selbsttest()); }

$tb_p = tb_paths();
foreach (array($tb_p['datadir'], $tb_p['datadir'] . '/verlauf', $tb_p['logdir']) as $tb_d) {
    if (!is_dir($tb_d)) { @mkdir($tb_d, 0775, true); }
}
$tb_cfg = tb_config();

$tb_preise = in_array('--preise', $tb_argv, true);
$tb_verbrauch = in_array('--verbrauch', $tb_argv, true);
$tb_auto = !$tb_preise && !$tb_verbrauch;

/* Taktsteuerung. Ein Marker je Aufgabe, damit ein verpasster Lauf nicht die
 * ganze Stunde kostet - der naechste Minutenlauf holt es nach. */
function tb_faellig($name, $minuten)
{
    $f = tb_paths()['datadir'] . '/.letzter_' . preg_replace('/[^a-z]/', '', $name);
    $letzte = is_file($f) ? (int) @file_get_contents($f) : 0;
    if (time() - $letzte < max(1, (int) $minuten) * 60) { return false; }
    @file_put_contents($f, (string) time());
    return true;
}

if ($tb_preise || ($tb_auto && tb_faellig('preise', (int) $tb_cfg['preistakt']))) {
    list($ok, $meldung) = tb_preise_holen();
    if ($ok) { tb_log('Preise geholt: ' . $meldung); }
}
if ($tb_verbrauch || ($tb_auto && tb_faellig('verbrauch', (int) $tb_cfg['verbrauchstakt']))) {
    list($ok, $meldung) = tb_verbrauch_holen();
    if ($ok === 1) { tb_log('Verbrauch geholt: ' . $meldung); }
}
if ($tb_auto) {
    list($ok, $meldung) = tb_monatsbericht();
    // Nur ein wirklich erstellter Bericht wird protokolliert; 'nicht faellig'
    // waere jede Minute eine Zeile.
    if ($ok === 0) { tb_log_gebremst('bericht', 'Monatsbericht: ' . $meldung); }
}
tb_veroeffentlichen();
exit(0);
