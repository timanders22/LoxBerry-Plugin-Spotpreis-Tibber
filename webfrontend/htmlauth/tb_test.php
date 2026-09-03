<?php
/**
 * Spotpreis Tibber - die Aktionen des Reiters Test
 *
 * Die Selbstpruefung beantwortet OHNE Loxone, ob die Einrichtung traegt. Was
 * sich nur mit Tibber-Konto pruefen liesse, wird als solches benannt statt
 * geraten.
 */

/**
 * Eine Zeile der Selbstpruefung.
 *
 * $stand hat DREI Werte, nicht zwei:
 *    1  Haken
 *    0  Kreuz
 *   -1  Strich: hier konnte nichts gemessen werden.
 *
 * Der dritte ist der wichtige. Ein Strich ist ausdruecklich KEIN Haken - er
 * sammelt sich beim Ueberfliegen nur so ein. Deshalb steht am Ende der Liste,
 * wie viele Striche darin stehen.
 */
function tb_pruefzeile($stand, $frage, $antwort)
{
    return array('stand' => $stand, 'frage' => $frage, 'antwort' => $antwort);
}

/**
 * Den EIGENEN Endpunkt ueber HTTP aufrufen.
 *
 * Das ist die einzige Zeile der ganzen Pruefung, die die Stelle anspricht, die
 * spaeter der Miniserver anspricht - und nur sie findet die teuerste
 * Fehlerklasse dieses Hauses: html/ und htmlauth/ liegen auf dem installierten
 * LoxBerry in GETRENNTEN Baeumen. Ein Endpunkt, der dort seine Bibliothek
 * nicht findet, antwortet mit HTTP 500 und leerem Rumpf; in Loxone sieht das
 * aus wie "kein Wert", und der virtuelle Eingang behaelt seinen letzten Stand.
 * Keine Leseprüfung sieht das.
 *
 * Serverseitig ist 127.0.0.1 dabei die RICHTIGE Adresse. Das widerspricht
 * nicht der Regel "ein Knopf auf 127.0.0.1 kann nie funktionieren" - die gilt
 * fuer einen Link, den ein Mensch im Browser anklickt.
 *
 * Drei Ausgaenge, und der dritte ist Pflicht: ein Webserver, der nur eine
 * Anfrage zugleich bearbeitet, kann sich waehrend des Seitenaufbaus nicht
 * selbst aufrufen. Ein Kreuz waere dort ein Kreuz, das nichts bedeutet.
 *
 * Zwischengespeichert, weil sonst bei jedem Klick auf Speichern ein
 * HTTP-Aufruf im Weg laege.
 */
function tb_endpunkt_probe($hoechstalter = 300)
{
    $p = tb_paths();
    $speicher = $p['datadir'] . '/.endpunkt_probe.json';
    $alt = tb_json_lesen($speicher);
    if (isset($alt['ts']) && (time() - (int) $alt['ts']) < $hoechstalter) {
        $alt['gepuffert'] = 1;
        return $alt;
    }
    $token = tb_aktionstoken();
    $url = 'http://127.0.0.1/plugins/' . $p['plugin'] . '/index.php?selftest=1&token='
         . rawurlencode($token);
    $code = 0;
    $rumpf = '';
    $konnte = 0;

    if (function_exists('curl_init')) {
        $konnte = 1;
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ));
        $rumpf = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $konnte = 1;
        /* @ unterdrueckt die Anzeige, nicht einen gesetzten Fehler-Aufnehmer -
         * und der Pruefstand rendern.py haengt genau so einen ein. Ein
         * Fehlschlag ist hier ein VORGESEHENER Ausgang, deshalb wird der
         * Aufnehmer fuer die Dauer des Aufrufs ausgetauscht. */
        $ctx = stream_context_create(array('http' => array(
            'timeout' => 3, 'ignore_errors' => true, 'follow_location' => 0)));
        set_error_handler(function () { return true; });
        $rumpf = (string) @file_get_contents($url, false, $ctx);
        restore_error_handler();
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $z) {
                if (preg_match('#^HTTP/\S+\s+([0-9]{3})#', $z, $m)) { $code = (int) $m[1]; }
            }
        }
    }
    $erg = array('ts' => time(), 'konnte' => $konnte, 'code' => $code,
                 'rumpf' => substr($rumpf, 0, 200), 'gepuffert' => 0);
    if (is_dir($p['datadir'])) { tb_json_schreiben($speicher, $erg); }
    return $erg;
}

/**
 * $voll schaltet die Zeilen frei, die etwas KOSTEN - hier den HTTP-Aufruf des
 * eigenen Endpunkts. Sie laufen nur, wenn der Reiter Test serverseitig der
 * offene ist: alle Reiter werden bei jedem Seitenaufbau mitgerendert, und
 * eine Zeitschranke bei jedem Klick auf Speichern ist kein Feinschliff,
 * sondern das, was die Oberflaeche langsam macht.
 */
function tb_pruefungen($voll = false)
{
    $p = tb_paths();
    $cfg = tb_config();
    $st = tb_stand();
    $vb = tb_verbrauch();
    $zeilen = array();

    // Token: die FORM darf beurteilt werden, der WERT nie.
    $token = tb_token_lesen();
    if ($token === '') {
        $zeilen[] = tb_pruefzeile(0, tb_t('TEST.F_TOKEN'), tb_t('TEST.A_TOKEN_LEER'));
    } else {
        $grund = tb_token_form($token);
        if ($grund === '') {
            $zeilen[] = tb_pruefzeile(1, tb_t('TEST.F_TOKEN'),
                sprintf(tb_t('TEST.A_TOKEN_OK'), strlen($token)));
        } else {
            $zeilen[] = tb_pruefzeile(0, tb_t('TEST.F_TOKEN'), tb_t($grund));
        }
        /* fileperms() gibt bei einem Fehler false zurueck, und false & 0777
         * ist 0 - daraus wurde bis 0.9.9 ein HAKEN mit der Meldung
         * "Rechte 00". Ein gruenes Haekchen an genau der Stelle, an der die
         * Rechte nicht ermittelt werden konnten. */
        $tb_perm = is_file($p['token']) ? @fileperms($p['token']) : false;
        $r = ($tb_perm === false) ? -1 : ($tb_perm & 0777);
        if ($r < 0) {
            $zeilen[] = tb_pruefzeile(0, tb_t('TEST.F_RECHTE'), tb_t('TEST.A_RECHTE_FEHLT'));
        } elseif (($r & 0077) === 0) {
            $zeilen[] = tb_pruefzeile(1, tb_t('TEST.F_RECHTE'),
                sprintf(tb_t('TEST.A_RECHTE_OK'), '0' . decoct($r)));
        } else {
            $zeilen[] = tb_pruefzeile(0, tb_t('TEST.F_RECHTE'),
                sprintf(tb_t('TEST.A_RECHTE_OFFEN'), '0' . decoct($r)));
        }
    }

    // Preise
    $alter = tb_alter();
    if ($alter < 0) {
        $zeilen[] = tb_pruefzeile(0, tb_t('TEST.F_PREISE'), tb_t('TEST.A_PREISE_NIE'));
    } elseif (!empty($st['ok'])) {
        $n = isset($st['liste_heute']) ? count($st['liste_heute']) : 0;
        $m = isset($st['liste_morgen']) ? count($st['liste_morgen']) : 0;
        $zeilen[] = tb_pruefzeile($alter < 7200 ? 1 : 0, tb_t('TEST.F_PREISE'),
            sprintf(tb_t('TEST.A_PREISE_OK'), (int) round($alter / 60), $n, $m));
    } else {
        $zeilen[] = tb_pruefzeile(0, tb_t('TEST.F_PREISE'),
            tb_e(isset($st['fehler']) && $st['fehler'] !== ''
                 ? $st['fehler'] : tb_t('TEST.A_PREISE_FEHLER')));
    }

    // Morgen: vor 13 Uhr ist das Fehlen normal und KEIN Befund.
    if (!empty($st['morgen_ok'])) {
        $zeilen[] = tb_pruefzeile(1, tb_t('TEST.F_MORGEN'), tb_t('TEST.A_MORGEN_DA'));
    } elseif ((int) date('G') < 13) {
        $zeilen[] = tb_pruefzeile(-1, tb_t('TEST.F_MORGEN'), tb_t('TEST.A_MORGEN_FRUEH'));
    } else {
        $zeilen[] = tb_pruefzeile(0, tb_t('TEST.F_MORGEN'), tb_t('TEST.A_MORGEN_FEHLT'));
    }

    // Verbrauch
    if (empty($cfg['verbrauch_ein'])) {
        $zeilen[] = tb_pruefzeile(-1, tb_t('TEST.F_VERBRAUCH'), tb_t('TEST.A_VERBRAUCH_AUS'));
    } elseif (isset($vb['tage']) && $vb['tage']) {
        $zeilen[] = tb_pruefzeile(1, tb_t('TEST.F_VERBRAUCH'),
            sprintf(tb_t('TEST.A_VERBRAUCH_OK'), count($vb['tage']),
                    (int) (isset($vb['tage_monat']) ? $vb['tage_monat'] : 0)));
    } else {
        $zeilen[] = tb_pruefzeile(0, tb_t('TEST.F_VERBRAUCH'), tb_t('TEST.A_VERBRAUCH_LEER'));
    }

    // Pulse
    $pid = tb_dienst_pid();
    $la = tb_live_alter();
    if (empty($cfg['pulse_ein'])) {
        $zeilen[] = tb_pruefzeile(-1, tb_t('TEST.F_PULSE'), tb_t('TEST.A_PULSE_AUS'));
    } elseif ($pid === 0) {
        $zeilen[] = tb_pruefzeile(0, tb_t('TEST.F_PULSE'),
            tb_dienst_soll() ? tb_t('TEST.A_PULSE_SOLL_TOT') : tb_t('TEST.A_PULSE_GESTOPPT'));
    } elseif ($la >= 0 && $la <= 120) {
        $zeilen[] = tb_pruefzeile(1, tb_t('TEST.F_PULSE'),
            sprintf(tb_t('TEST.A_PULSE_OK'), $pid, $la));
    } else {
        $zeilen[] = tb_pruefzeile(0, tb_t('TEST.F_PULSE'),
            sprintf(tb_t('TEST.A_PULSE_ALT'), $pid, $la < 0 ? -1 : $la));
    }

    // Schwellen
    $zeilen[] = tb_pruefzeile(
        ((float) $cfg['guenstig'] < (float) $cfg['teuer']) ? 1 : 0,
        tb_t('TEST.F_SCHWELLEN'),
        sprintf(tb_t('TEST.A_SCHWELLEN'), (float) $cfg['guenstig'], (float) $cfg['teuer']));

    // MQTT
    $m = tb_mqtt_zustand();
    if (!$m['gefunden']) {
        $zeilen[] = tb_pruefzeile(0, tb_t('TEST.F_MQTT'), tb_t('TEST.A_MQTT_NICHT_GEFUNDEN'));
    } elseif ($m['autostart']) {
        $zeilen[] = tb_pruefzeile(1, tb_t('TEST.F_MQTT'),
            tb_e($m['broker']) . ':' . tb_e($m['brokerport']) . ' (UDP ' . (int) $m['udpport'] . ')');
    } elseif (!empty($cfg['mqtt_ein'])) {
        $zeilen[] = tb_pruefzeile(0, tb_t('TEST.F_MQTT'), tb_t('TEST.A_MQTT_AUS'));
    } else {
        $zeilen[] = tb_pruefzeile(-1, tb_t('TEST.F_MQTT'), tb_t('TEST.A_MQTT_EGAL'));
    }

    /* PHP-Erweiterungen.
     *
     * sockets steht hier seit 0.9.10 NICHT mehr. Seit 0.9.7 benutzt keine
     * Zeile dieses Plugins eine Funktion aus ext/sockets - der Weg zum
     * MQTT-Gateway laeuft ueber stream_socket_client() aus dem PHP-Kern.
     * Eine Zeile "PHP-Erweiterung sockets: Nein" in einer Pruefliste
     * bedeutet dann nichts, sieht aber wie ein Mangel aus, und der Anwender
     * sucht dort. */
    foreach (array('curl' => 'TEST.F_CURL', 'openssl' => 'TEST.F_OPENSSL') as $erw => $frage) {
        $da = extension_loaded($erw);
        $noetig = ($erw === 'openssl') ? !empty($cfg['pulse_ein']) : false;
        $zeilen[] = tb_pruefzeile($da ? 1 : ($noetig ? 0 : -1), tb_t($frage),
            $da ? tb_t('TEST.A_ERW_DA') : tb_t('TEST.A_ERW_FEHLT_' . strtoupper($erw)));
    }

    // Die Loxone-Vorlage gegen den Parser halten. Das gehoert hierher und
    // nicht erst in die Pflichtpruefung: der Anwender merkt eine kaputte
    // Vorlage sonst erst in Loxone Config, und dort sucht er den Fehler bei sich.
    list($vname, $vinhalt) = tb_vorlage();
    $vorher = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($vinhalt);
    libxml_clear_errors();
    libxml_use_internal_errors($vorher);
    $zeilen[] = tb_pruefzeile($xml !== false ? 1 : 0, tb_t('TEST.F_XML'),
        $xml !== false ? sprintf(tb_t('TEST.A_XML_OK'), tb_e($vname), count($xml->VirtualInHttpCmd))
                       : tb_t('TEST.A_XML_KAPUTT'));

    /* ==============================================================
     * Die Zeilen, die das PLUGIN GEGEN SICH SELBST messen
     *
     * Sie sagen nichts ueber Tibber und nichts ueber das Netz - sie sagen,
     * ob das Plugin noch zusammenpasst. Sie kosten keine Verbindung und
     * finden genau die Klassen, die keine Leseprüfung sieht.
     * ============================================================== */

    /* --- Ist jedes Suchmuster eindeutig? ---
     *
     * Loxone sucht woertlich und nimmt den ERSTEN Treffer in der Zeile. Bis
     * 0.9.6 fehlte dem Suchtext das fuehrende Semikolon; 'ALTER=' steckt
     * damit in 'PULSE_ALTER=' und 'OK=' in 'MORGEN_OK=', und weil beide
     * frueher in der Zeile stehen, las der Miniserver an genau diesen zwei
     * Feldern den falschen Wert - ausgerechnet an denen, an denen die
     * Ausfallerkennung haengt.
     *
     * Gemessen wird an der GEBAUTEN Zeile, nicht an einer Namensliste. */
    list($anz, $falsch) = tb_suchtext_pruefen();
    $zeilen[] = tb_pruefzeile($falsch ? 0 : 1, tb_t('TEST.F_SUCHTEXT'),
        $falsch ? sprintf(tb_t('TEST.A_SUCHTEXT_FALSCH'), tb_e(implode(', ', $falsch)))
                : sprintf(tb_t('TEST.A_SUCHTEXT_OK'), $anz));

    /* --- Tragen alle Formulare das Merkmal? ---
     *
     * Ein Formular vergisst man. Gezaehlt wird am QUELLTEXT der Oberflaeche:
     * jedes <form> muss ein verstecktes fmt tragen. */
    $obf = __DIR__ . '/index.php';
    $quelle = is_file($obf) ? (string) @file_get_contents($obf) : '';
    if ($quelle === '') {
        $zeilen[] = tb_pruefzeile(-1, tb_t('TEST.F_FORMULARE'), tb_t('TEST.A_NICHTS_GELESEN'));
    } else {
        $nform = preg_match_all('/<form\b[^>]*>/', $quelle);
        $nfmt = substr_count($quelle, 'name="fmt"');
        $zeilen[] = tb_pruefzeile(($nform > 0 && $nform === $nfmt) ? 1 : 0,
            tb_t('TEST.F_FORMULARE'),
            sprintf(tb_t('TEST.A_FORMULARE'), $nfmt, $nform));
    }

    /* --- Passen Reiterliste, Leiste und Bereiche zusammen? ---
     *
     * Drei Stellen, die auseinanderlaufen koennen: die Positivliste (sonst
     * springt die Seite nach jedem Absenden zurueck), die Leiste (sonst ist
     * der Reiter nicht anklickbar) und die Bereiche (sonst bleibt er leer).
     * Die Leiste steht ausgeschrieben da, damit das Hauswerkzeug sie sieht -
     * also kann sie abweichen, also wird sie gemessen. */
    if ($quelle === '') {
        $zeilen[] = tb_pruefzeile(-1, tb_t('TEST.F_REITER'), tb_t('TEST.A_NICHTS_GELESEN'));
    } else {
        preg_match('/\$tb_muster\s*=\s*\'\/\^tab-\(([a-z|]+)\)/', $quelle, $mm);
        $liste = isset($mm[1]) ? explode('|', $mm[1]) : array();
        preg_match_all('/data-ziel="tab-([a-z]+)"/', $quelle, $ml);
        preg_match_all('/id="tab-([a-z]+)"/', $quelle, $mb);
        $leiste = isset($ml[1]) ? array_values(array_unique($ml[1])) : array();
        $bereiche = isset($mb[1]) ? array_values(array_unique($mb[1])) : array();
        sort($liste); sort($leiste); sort($bereiche);
        $gleich = ($liste === $leiste && $leiste === $bereiche && count($liste) > 0);
        $zeilen[] = tb_pruefzeile($gleich ? 1 : (count($liste) ? 0 : -1),
            tb_t('TEST.F_REITER'),
            sprintf(tb_t('TEST.A_REITER'), count($liste), count($leiste), count($bereiche)));

        /* --- Setzt der Server das sm-active? ---
         * Ohne serverseitiges sm-active ist die Seite ohne JavaScript nicht
         * bedienbar. Gezaehlt wird an beiden Stellen - Leiste und Bereiche. */
        $aktiv_leiste = substr_count($quelle, "sm-tab<?= \$tb_tab ===");
        $aktiv_seite = substr_count($quelle, "sm-seite<?= \$tb_tab ===");
        $zeilen[] = tb_pruefzeile(
            ($aktiv_leiste >= count($leiste) && $aktiv_seite >= count($bereiche)
             && count($bereiche) > 0) ? 1 : 0,
            tb_t('TEST.F_SMACTIVE'),
            sprintf(tb_t('TEST.A_SMACTIVE'), $aktiv_leiste, $aktiv_seite));
    }

    /* --- Ist die Konfiguration vollstaendig? ---
     *
     * Gemeldet werden BEIDE Richtungen: was fehlt (wird beim naechsten
     * Speichern ergaenzt) und was FREMD darin steht. Fremdes ist das
     * ueberraschendere: man hat etwas eingestellt, es steht in der Datei, und
     * es tut nichts. Geloescht wird es nicht - niemand weiss, ob dort der
     * Rest einer aelteren Fassung steht. */
    list($soll, $fehlend, $fremd) = tb_config_vervollstaendigen(false);
    if (!$fehlend && !$fremd) {
        $zeilen[] = tb_pruefzeile(1, tb_t('TEST.F_KONFIG'),
            sprintf(tb_t('TEST.A_KONFIG_OK'), $soll, $soll));
    } else {
        $text = sprintf(tb_t('TEST.A_KONFIG_LUECKE'), $soll - count($fehlend), $soll);
        if ($fehlend) { $text .= ' ' . sprintf(tb_t('TEST.A_KONFIG_FEHLT'), tb_e(implode(', ', $fehlend))); }
        if ($fremd)   { $text .= ' ' . sprintf(tb_t('TEST.A_KONFIG_FREMD'), tb_e(implode(', ', $fremd))); }
        $zeilen[] = tb_pruefzeile($fehlend ? 0 : 1, tb_t('TEST.F_KONFIG'), $text);
    }

    /* --- Nennt die Themenliste, was der Dienst wirklich sendet? ---
     *
     * Die Tabelle im Reiter MQTT ist die Anleitung. Eine Liste, die niemand
     * nachmisst, laeuft auseinander - bei Renault standen vier Stellen weit
     * Themen, die der Sendecode nie veroeffentlicht hat. */
    $themen = array_keys(tb_mqtt_themen());
    $gesendet = array_map('strtolower', array_keys(tb_status_felder()));
    /* Die Lebenszeichen kommen aus tb_mqtt_lebenszeichen() - der Funktion,
     * die sie WIRKLICH sendet -, nicht aus einer abgeschriebenen Liste.
     *
     * Bis 0.9.9 standen hier drei Namen von Hand, waehrend die Funktion vier
     * fuehrt: status/pulse_ts fehlte. Die Zeile war damit auf JEDER Anlage,
     * in JEDEM Zustand ein Kreuz - "diese Themen werden nicht gesendet:
     * status/pulse_ts" -, obwohl das Thema hinausgeht. Ein Kreuz, das keinen
     * Mangel bezeichnet, macht die ganze Liste unlesbar. */
    foreach (array_keys(tb_mqtt_lebenszeichen()) as $tb_lz) { $gesendet[] = $tb_lz; }
    $fehlt = array();
    foreach ($themen as $t) {
        if ($t === 'stunde/N/ct') { continue; }        // Platzhalter, keine Zeile
        if (!in_array($t, $gesendet, true)) { $fehlt[] = $t; }
    }
    $zeilen[] = tb_pruefzeile($fehlt ? 0 : 1, tb_t('TEST.F_THEMEN'),
        $fehlt ? sprintf(tb_t('TEST.A_THEMEN_FEHLT'), tb_e(implode(', ', $fehlt)))
               : sprintf(tb_t('TEST.A_THEMEN_OK'), count($themen) - 1));

    /* --- Der Kommentar der Vorlage wird zum ANZEIGENAMEN ---
     *
     * Loxone Config zeigt den Comment einer Importvorlage unter
     * Visualisierung als Anzeigenamen des Bausteins; das Feld Dokumentation
     * bleibt leer. Ein ganzer Satz steht dort also als NAME. Bis 0.9.6 waren
     * 25 von 38 Kommentaren laenger als 40 Zeichen, der laengste hatte 149. */
    if ($xml !== false) {
        preg_match_all('/<VirtualInHttpCmd [^>]*Comment="([^"]*)"/', $vinhalt, $mc);
        $lang = 0; $max = 0;
        foreach ((isset($mc[1]) ? $mc[1] : array()) as $k) {
            /* ZEICHEN, nicht Bytes. Die Grenze heisst "40 Zeichen", und ein
             * deutscher Umlaut belegt in UTF-8 zwei Bytes: mit strlen schlaegt
             * die Zeile schon bei 34 echten Zeichen an. mbstring ist auf einem
             * LoxBerry nicht zugesichert, deshalb mit Rueckfall. */
            $roh = html_entity_decode($k, ENT_QUOTES, 'UTF-8');
            $l = function_exists('mb_strlen') ? mb_strlen($roh, 'UTF-8') : strlen($roh);
            if ($l > $max) { $max = $l; }
            if ($l > 40) { $lang++; }
        }
        $zeilen[] = tb_pruefzeile($lang ? 0 : 1, tb_t('TEST.F_KOMMENTAR'),
            sprintf(tb_t('TEST.A_KOMMENTAR'), count(isset($mc[1]) ? $mc[1] : array()), $lang, $max));
    } else {
        /* Ein Strich mit Begruendung statt einer fehlenden Zeile. Bis 0.9.9
         * entfiel die Zeile ersatzlos, wenn die Vorlage nicht wohlgeformt
         * war - die Liste wurde einfach um eine Zeile kuerzer, und niemand
         * erfuhr, welche fehlt. */
        $zeilen[] = tb_pruefzeile(-1, tb_t('TEST.F_KOMMENTAR'),
            tb_t('TEST.A_KOMMENTAR_UNGEPRUEFT'));
    }

    /* --- Laeuft der Dienst, obwohl er laufen soll? ---
     *
     * Bis 0.9.6 lag der Sollmerker unter data/plugins/<ordner>/ - und den
     * Ordner raeumt der Installer bei JEDEM Upgrade ab. Der Waechter fand ihn
     * danach nicht mehr und startete nichts: der Pulse-Dienst stand nach jeder
     * Aktualisierung still, ohne dass irgendwo etwas stand. Diese Zeile
     * trennt "bewusst angehalten" von "nach dem Update nicht wiedergekommen". */
    if (empty($cfg['pulse_ein'])) {
        $zeilen[] = tb_pruefzeile(-1, tb_t('TEST.F_SOLL'), tb_t('TEST.A_SOLL_AUS'));
    } elseif (tb_dienst_soll() && !$pid) {
        $zeilen[] = tb_pruefzeile(0, tb_t('TEST.F_SOLL'), tb_t('TEST.A_SOLL_TOT'));
    } elseif (!tb_dienst_soll() && !$pid) {
        $zeilen[] = tb_pruefzeile(-1, tb_t('TEST.F_SOLL'), tb_t('TEST.A_SOLL_ANGEHALTEN'));
    } else {
        $zeilen[] = tb_pruefzeile(1, tb_t('TEST.F_SOLL'), tb_t('TEST.A_SOLL_OK'));
    }

    /* --- Arbeitet der Cron-Lauf noch? ---
     *
     * Eine Prozessnummer beantwortet das nicht: ein Prozess kann dastehen und
     * nichts mehr tun, und ein Cron-Lauf hinterlaesst ueberhaupt keinen. Was
     * er hinterlaesst, ist der Laufzaehler - er wird bei JEDEM Durchgang
     * weitergestellt, auch wenn kein Abruf faellig war. */
    $zaehler = tb_zaehler_lesen();
    $zalter = is_file($p['datadir'] . '/zaehler')
            ? time() - (int) @filemtime($p['datadir'] . '/zaehler') : -1;
    if ($zaehler < 0 || $zalter < 0) {
        $zeilen[] = tb_pruefzeile(0, tb_t('TEST.F_LEBEN'), tb_t('TEST.A_LEBEN_NIE'));
    } else {
        // Der Cron laeuft minuetlich. Fuenf Minuten sind ein grosszuegiger
        // Abstand und schlagen nicht schon bei einem verpassten Lauf an.
        $zeilen[] = tb_pruefzeile($zalter <= 300 ? 1 : 0, tb_t('TEST.F_LEBEN'),
            sprintf(tb_t('TEST.A_LEBEN'), $zaehler, $zalter));
    }

    /* --- Antwortet der eigene Endpunkt? ---
     * Steht am Ende, weil sie als einzige das Netz braucht. */
    if (!$voll) {
        $zeilen[] = tb_pruefzeile(-1, tb_t('TEST.F_ENDPUNKT'), tb_t('TEST.A_ENDPUNKT_UNGEPRUEFT'));
    } else {
        $e = tb_endpunkt_probe();
        $zusatz = !empty($e['gepuffert']) ? ' ' . sprintf(tb_t('TEST.A_ENDPUNKT_ALT'),
                                                          time() - (int) $e['ts']) : '';
        if (!$e['konnte']) {
            $zeilen[] = tb_pruefzeile(-1, tb_t('TEST.F_ENDPUNKT'),
                tb_t('TEST.A_ENDPUNKT_NICHTMESSBAR'));
        } elseif ((int) $e['code'] === 200 && strpos((string) $e['rumpf'], 'SELFTEST;OK=1') === 0) {
            $zeilen[] = tb_pruefzeile(1, tb_t('TEST.F_ENDPUNKT'),
                tb_t('TEST.A_ENDPUNKT_OK') . $zusatz);
        } elseif ((int) $e['code'] === 0) {
            // Keine Antwort. Ein einlaeufiger Webserver kann sich waehrend
            // des Seitenaufbaus nicht selbst aufrufen - das ist ein Hinweis,
            // kein Kreuz, und sagt nichts ueber den Prueflung.
            $zeilen[] = tb_pruefzeile(-1, tb_t('TEST.F_ENDPUNKT'),
                tb_t('TEST.A_ENDPUNKT_KEINE_ANTWORT') . $zusatz);
        } else {
            $zeilen[] = tb_pruefzeile(0, tb_t('TEST.F_ENDPUNKT'),
                sprintf(tb_t('TEST.A_ENDPUNKT_FALSCH'), (int) $e['code'],
                        tb_e(substr((string) $e['rumpf'], 0, 80))) . $zusatz);
        }
    }

    return $zeilen;
}

/**
 * Wie viele Zeilen sind Haken, Kreuz, Strich?
 *
 * Ein Strich ist kein Haken. Wer ihn beim Ueberfliegen wie einen einsammelt,
 * hat eine Pruefung weniger, als er glaubt - deshalb steht die Zahl unter der
 * Liste. Und die Zusammenfassung darf nicht besser aussehen als ihr
 * schlechtester Punkt.
 */
function tb_pruef_bilanz(array $zeilen)
{
    $b = array('haken' => 0, 'kreuz' => 0, 'strich' => 0);
    foreach ($zeilen as $z) {
        if ($z['stand'] === 1) { $b['haken']++; }
        elseif ($z['stand'] === 0) { $b['kreuz']++; }
        else { $b['strich']++; }
    }
    return $b;
}

/** Ausgabe von tb_cron.php --selbsttest. */
function tb_selbsttest_ausgabe()
{
    $p = tb_paths();
    $skript = $p['bindir'] . '/tb_cron.php';
    if (!is_file($skript)) {
        return "[FEHL] tb_cron.php fehlt.\n       Erwartet: " . $skript
             . "\n       Abhilfe: Plugin neu installieren.";
    }
    $ausgabe = array();
    @exec('php ' . escapeshellarg($skript) . ' --selbsttest 2>&1', $ausgabe);
    return implode("\n", $ausgabe);
}

/**
 * Fuehrt eine Aktion des Reiters Test aus.
 * Rueckgabe: array(stand, Meldung). 1 = erledigt, 0 = misslungen.
 */
function tb_test_aktion($aktion)
{
    $p = tb_paths();
    switch ($aktion) {
        case 'preise':
        case 'verbrauch':
            $skript = $p['bindir'] . '/tb_cron.php';
            if (!is_file($skript)) {
                return array(0, sprintf(tb_t('TEST.M_SKRIPT_FEHLT'), $skript));
            }
            $ausgabe = array();
            $code = 0;
            @exec('php ' . escapeshellarg($skript) . ' --' . $aktion . ' 2>&1', $ausgabe, $code);
            // Der Abruf selbst meldet seinen Erfolg ueber den Zwischenspeicher,
            // nicht ueber den Rueckgabewert - deshalb wird der Stand geprueft.
            if ($aktion === 'preise') {
                $st = tb_stand();
                if (!empty($st['ok'])) {
                    return array(1, sprintf(tb_t('TEST.M_PREISE_OK'),
                        isset($st['liste_heute']) ? count($st['liste_heute']) : 0,
                        isset($st['liste_morgen']) ? count($st['liste_morgen']) : 0));
                }
                return array(0, isset($st['fehler']) && $st['fehler'] !== ''
                    ? $st['fehler'] : implode(' ', $ausgabe));
            }
            $vb = tb_verbrauch();
            if (isset($vb['tage']) && $vb['tage']) {
                return array(1, sprintf(tb_t('TEST.M_VERBRAUCH_OK'), count($vb['tage'])));
            }
            return array(0, implode(' ', $ausgabe) !== ''
                ? implode(' ', $ausgabe) : tb_t('TEST.A_VERBRAUCH_LEER'));

        case 'konto':
            $skript = $p['bindir'] . '/tb_pulse.php';
            if (!is_file($skript)) {
                return array(0, sprintf(tb_t('TEST.M_SKRIPT_FEHLT'), $skript));
            }
            $ausgabe = array();
            $code = 0;
            @exec('php ' . escapeshellarg($skript) . ' --pruefen 2>&1', $ausgabe, $code);
            /* Rueckgabewert 2 heisst: Token und Zuhause tragen, nur eine
             * Tibber Pulse gibt es nicht. Das ist der REGELFALL und kein
             * Misserfolg - bis 0.9.9 bekam ein einwandfreies Konto ohne
             * Pulse "misslungen", waehrend im Text darunter dreimal [OK]
             * stand. Ein Strich statt eines Kreuzes. */
            if ($code === 2) { return array(-1, implode("\n", $ausgabe)); }
            return array($code === 0 ? 1 : 0, implode("\n", $ausgabe));

        default:
            return array(0, tb_t('TEST.M_UNBEKANNT'));
    }
}

/**
 * Der Verlauf der letzten Wochen als Tagesbalken.
 *
 * Die CSV-Dateien unter data/plugins/<ordner>/verlauf/ wurden bis 0.9.6
 * geschrieben und von niemandem gelesen. Sie beantworten die Frage, die dem
 * Plugin bis dahin fehlte: IST 24 ct VIEL? Eine feste Schwelle kann das nicht
 * sagen - der Vergleich mit den letzten dreissig Tagen schon.
 *
 * Gezeichnet wird der TAGESDURCHSCHNITT, nicht jeder Punkt: die Reihe traegt
 * bis zu 24 Punkte je Tag, und dreissig Tage waeren 720 Balken auf 720 Pixeln.
 */
function tb_verlauf_svg(array $reihe, $breite = 720)
{
    if (count($reihe) < 2) { return ''; }
    $tage = array();
    foreach ($reihe as $e) {
        $t = date('Y-m-d', $e['ts']);
        if (!isset($tage[$t])) { $tage[$t] = array('summe' => 0.0, 'n' => 0); }
        $tage[$t]['summe'] += $e['ct'];
        $tage[$t]['n']++;
    }
    ksort($tage);
    $werte = array();
    foreach ($tage as $t => $w) { $werte[$t] = $w['summe'] / max(1, $w['n']); }
    $n = count($werte);
    if ($n < 2) { return ''; }
    $max = max(1.0, max($werte) * 1.12);
    $h = 150; $y0 = 8; $ph = $h - $y0 - 26;
    $bw = max(2.0, ($breite - 40) / $n - 2);
    $heute = date('Y-m-d');

    $svg = '<svg viewBox="0 0 ' . $breite . ' ' . $h . '" style="width:100%;max-width:'
         . $breite . 'px;height:auto;background:#fafafa;border:1px solid #e0e0e0;'
         . 'border-radius:8px;" xmlns="http://www.w3.org/2000/svg">';
    for ($ct = 0; $ct <= $max; $ct += 10) {
        $y = $y0 + $ph - $ph * ($ct / $max);
        if ($y < $y0) { break; }
        $svg .= '<line x1="26" y1="' . round($y, 1) . '" x2="' . ($breite - 4) . '" y2="'
              . round($y, 1) . '" stroke="#e8e8e8" stroke-width="1"/>';
        $svg .= '<text x="22" y="' . round($y + 3, 1)
              . '" font-size="9" fill="#999" text-anchor="end">' . (int) $ct . '</text>';
    }
    $i = 0;
    foreach ($werte as $tag => $ct) {
        $x = 28 + $i * (($breite - 34) / $n);
        $hoehe = $ph * (max(0.0, $ct) / $max);
        $y = $y0 + $ph - $hoehe;
        $farbe = ($tag === $heute) ? '#e0620d' : '#6dac20';
        $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="'
              . round($bw, 1) . '" height="' . round(max(1, $hoehe), 1)
              . '" fill="' . $farbe . '" rx="1"><title>' . tb_e($tag) . ': '
              . tb_e(number_format($ct, 2, ',', '.')) . ' ct</title></rect>';
        if ($i === 0 || $i === $n - 1) {
            $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($h - 10)
                  . '" font-size="9" fill="#999" text-anchor="middle">'
                  . tb_e(date('d.m.', strtotime($tag))) . '</text>';
        }
        $i++;
    }
    return $svg . '</svg>';
}

/**
 * Preisdiagramm fuer heute und morgen.
 *
 * Die Skala setzt bei 0 an, weil ein Endpreis nun einmal bei 0 beginnt - und
 * anders als bei Zellspannungen ist die absolute Hoehe hier die Aussage.
 * Die laufende Stunde ist abgesetzt.
 */
function tb_preis_svg(array $heute, array $morgen, $breite = 720)
{
    $alle = array_merge($heute, $morgen);
    if (!$alle) { return ''; }
    $n = count($alle);
    $max = 0.0;
    $min = 0.0;
    foreach ($alle as $e) {
        if ($e['ct'] > $max) { $max = $e['ct']; }
        if ($e['ct'] < $min) { $min = $e['ct']; }
    }
    $max = max(1.0, $max * 1.12);
    $h = 170; $y0 = 8; $ph = $h - $y0 - 26;
    $null = $y0 + $ph * ($max / max(0.001, $max - $min));
    $bw = max(2.0, ($breite - 10) / $n - 2);
    $jetzt = time();

    $svg = '<svg viewBox="0 0 ' . $breite . ' ' . $h . '" style="width:100%;max-width:'
         . $breite . 'px;height:auto;background:#fafafa;border:1px solid #e0e0e0;'
         . 'border-radius:8px;" xmlns="http://www.w3.org/2000/svg">';
    // Waagerechte Hilfslinien alle 10 ct
    for ($ct = 0; $ct <= $max; $ct += 10) {
        $y = $null - $ph * ($ct / max(0.001, $max - $min));
        if ($y < $y0) { break; }
        $svg .= '<line x1="26" y1="' . round($y, 1) . '" x2="' . ($breite - 4) . '" y2="'
              . round($y, 1) . '" stroke="#e8e8e8" stroke-width="1"/>';
        $svg .= '<text x="22" y="' . round($y + 3, 1)
              . '" font-size="9" fill="#999" text-anchor="end">' . (int) $ct . '</text>';
    }
    $i = 0;
    foreach ($alle as $e) {
        $x = 28 + $i * (($breite - 34) / $n);
        $hoehe = $ph * (abs($e['ct']) / max(0.001, $max - $min));
        $y = $e['ct'] >= 0 ? $null - $hoehe : $null;
        $aktuell = ($e['ts'] <= $jetzt && $jetzt < $e['ts'] + 3600);
        $farbe = $aktuell ? '#e0620d' : ($i < count($heute) ? '#6dac20' : '#8fbf5a');
        $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="'
              . round($bw, 1) . '" height="' . round(max(1, $hoehe), 1)
              . '" fill="' . $farbe . '" rx="1"/>';
        $st = (int) date('G', $e['ts']);
        if ($st % 6 === 0) {
            $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($h - 12)
                  . '" font-size="9" fill="#999" text-anchor="middle">' . $st . '</text>';
        }
        $i++;
    }
    if ($morgen) {
        $x = 28 + count($heute) * (($breite - 34) / $n);
        $svg .= '<line x1="' . round($x, 1) . '" y1="' . $y0 . '" x2="' . round($x, 1)
              . '" y2="' . round($null + 4, 1) . '" stroke="#bbb" stroke-width="1" '
              . 'stroke-dasharray="3,3"/>';
    }
    $svg .= '<text x="' . ($breite - 6) . '" y="' . ($h - 3)
          . '" font-size="9" fill="#777" text-anchor="end">'
          . tb_e(tb_t('TEST.DIAGRAMM_LEGENDE')) . '</text>';
    return $svg . '</svg>';
}
