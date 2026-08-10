<?php
/**
 * Spotpreis Tibber - die Aktionen des Reiters Test
 *
 * Die Selbstpruefung beantwortet OHNE Loxone, ob die Einrichtung traegt. Was
 * sich nur mit Tibber-Konto pruefen liesse, wird als solches benannt statt
 * geraten.
 */

function tb_pruefzeile($stand, $frage, $antwort)
{
    return array('stand' => $stand, 'frage' => $frage, 'antwort' => $antwort);
}

function tb_pruefungen()
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
        $r = is_file($p['token']) ? (fileperms($p['token']) & 0777) : -1;
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

    // PHP-Erweiterungen
    foreach (array('curl' => 'TEST.F_CURL', 'openssl' => 'TEST.F_OPENSSL',
                   'sockets' => 'TEST.F_SOCKETS') as $erw => $frage) {
        $da = extension_loaded($erw);
        $noetig = ($erw === 'openssl') ? !empty($cfg['pulse_ein'])
                : (($erw === 'sockets') ? !empty($cfg['mqtt_ein']) : false);
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
        $xml !== false ? sprintf(tb_t('TEST.A_XML_OK'), tb_e($vname), count($xml->children()))
                       : tb_t('TEST.A_XML_KAPUTT'));

    return $zeilen;
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
            return array($code === 0 ? 1 : 0, implode("\n", $ausgabe));

        default:
            return array(0, tb_t('TEST.M_UNBEKANNT'));
    }
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
