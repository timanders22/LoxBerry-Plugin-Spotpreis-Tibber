<?php
/* ---- Sperre gegen Parallellaeufe (Muster fer_sperre, FerienFeiertage) ----
 *
 * Der Abruf bei Tibber wartet auf ein Netz. Dauert der Lauf laenger als der Cron-Takt,
 * startet der naechste, waehrend dieser noch laeuft: doppelte Abrufe,
 * doppelte Meldungen, im schlimmsten Fall zwei Schreibvorgaenge auf dieselbe
 * Datei. Die Sperre ist nicht blockierend - wer nicht drankommt, geht
 * kommentarlos wieder (der naechste Takt kommt ohnehin gleich).
 */
/* Der Name der Sperrdatei traegt den ERMITTELTEN Ordnernamen.
 * Ein festes /tmp/tb_cron.lock liessen sich zwei Installationen
 * teilen - LoxBerry haengt bei einer Zweitinstallation ein _01 an den
 * Ordner -, und dann laeuft je Minute nur EINE von beiden. Der
 * Ordnername steht erst nach dem Laden der Bibliothek fest, deshalb
 * wird die Sperre weiter unten gezogen. */

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

/* ---- Sperre gegen Parallellaeufe (Muster fer_sperre) ----
 *
 * Der Abruf bei Tibber wartet auf ein Netz. Dauert der Lauf laenger als
 * der Cron-Takt, startet der naechste, waehrend dieser noch laeuft:
 * doppelte Abrufe, doppelte Meldungen, im schlimmsten Fall zwei
 * Schreibvorgaenge auf dieselbe Datei. Nicht blockierend - wer nicht
 * drankommt, geht kommentarlos wieder; der naechste Takt kommt gleich.
 *
 * ---------------------------------------------------------------------------
 * Warum das eine FUNKTION ist und nicht mehr der Dateirumpf
 * ---------------------------------------------------------------------------
 * Bis 0.9.9 stand die Sperre hier oben, 740 Zeilen VOR der Auswertung der
 * Schalter. Wer waehrend eines laufenden Minutentakts von Hand
 * 'php tb_cron.php --selbsttest' aufrief, bekam deshalb den Ausstieg der
 * Sperre: Rueckgabewert 0, null Byte Ausgabe. Der Knopf "Selbsttest" im
 * Reiter Test lieferte ein leeres Feld.
 *
 * Schlimmer beim Knopf "Preise jetzt holen": danach sieht die Oberflaeche in
 * stand.json nach, findet dort den ALTEN gelungenen Stand und meldet
 * "Preise OK, 24/24 Stunden" - obwohl nichts geholt wurde. Bei einem
 * Cron-Takt von einer Minute ist das kein seltener Fall.
 *
 * Der Selbsttest rechnet nur und schreibt nichts; er braucht die Sperre
 * ueberhaupt nicht.
 */
function tb_sperre_ziehen()
{
    $datei = sys_get_temp_dir() . '/tb_cron_' . tb_paths()['plugin'] . '.lock';
    $griff = @fopen($datei, 'c');
    if ($griff === false) {
        /* Die Sperrdatei laesst sich nicht einmal anlegen. Ohne Sperre weiter
         * zu laufen waere falsch - dann holen zwei Laeufe gleichzeitig ab und
         * schreiben auf dieselben Dateien. Aber stumm mit 0 zu enden ist
         * schlimmer: der Cron liefe nie wieder, und niemand wuesste warum. */
        tb_log_gebremst('sperre', 'Die Sperrdatei ' . $datei . ' laesst sich '
            . 'nicht anlegen - dieser Lauf tut nichts. Ist das Verzeichnis '
            . 'beschreibbar?');
        fwrite(STDERR, 'Sperrdatei nicht anlegbar: ' . $datei . "\n");
        exit(1);
    }
    if (!flock($griff, LOCK_EX | LOCK_NB)) {
        return null;
    }
    return $griff;
}

/* ==================================================================
 * Preise holen und auswerten
 * ================================================================== */

/**
 * OK auf 0 setzen und den Grund hinterlegen - ohne die alten Werte zu loeschen.
 *
 * Bis 0.9.9 tat das nur der erste der drei Fehlerwege. Die beiden anderen -
 * "kein laufender Vertrag" und "null Preisstunden" - kehrten zurueck, ohne
 * stand.json anzufassen. Der alte ok=1 blieb stehen, ging weiter ueber MQTT
 * hinaus und stand in der Statuszeile, waehrend seit Stunden nichts mehr
 * geholt wurde. Die Sprachdatei sagt aber: "OK = 1, wenn der letzte
 * Preisabruf gelungen ist". Genau das stimmte dann nicht.
 *
 * Der Meldungsbaum am Dateiende haengt an denselben zwei Merkmalen und
 * landete deshalb in der Entwarnung.
 */
function tb_merkmal_ok_faellt($meldung)
{
    $st = tb_stand();
    $st['ok'] = 0;
    $st['fehler'] = $meldung;
    if (!tb_json_schreiben(tb_paths()['datadir'] . '/stand.json', $st)) {
        tb_log_gebremst('stand_schreiben', 'stand.json liess sich nicht schreiben - '
            . 'das Merkmal OK bleibt deshalb auf dem alten Wert stehen.');
    }
}

function tb_preise_holen()
{
    $cfg = tb_config();
    $p = tb_paths();
    $data = tb_gql(tb_abfrage_preise((string) $cfg['home_id']));
    if (isset($data['_fehler'])) {
        // Der alte Stand bleibt stehen; nur das Merkmal ok faellt.
        tb_merkmal_ok_faellt($data['_fehler']);
        tb_log_gebremst('preise', 'Preisabruf misslungen: ' . $data['_fehler']);
        return array(0, $data['_fehler']);
    }
    $home = tb_erstes_home($data);
    if ($home === null || !isset($home['currentSubscription']['priceInfo'])) {
        $meldung = 'Die Antwort enthaelt keine Preisinformationen. Hat das Konto einen '
                 . 'laufenden Tibber-Vertrag? Ohne Vertrag liefert die Schnittstelle keine '
                 . 'Preise, auch wenn das Token gueltig ist.';
        tb_merkmal_ok_faellt($meldung);
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
        tb_merkmal_ok_faellt($meldung);
        tb_log_gebremst('preise_null', $meldung);
        return array(0, $meldung);
    }

    $jetzt = time();
    $cur = tb_preis_zur_zeit($alle, $jetzt);
    /* Die letzte feste 3600 im Preisweg. Bei Viertelstundenpreisen lieferte
     * sie den Preis "in einer Stunde"; die Sprachdatei sagt aber "Endpreis
     * der naechsten Stunde". Genommen wird jetzt der naechste Eintrag nach
     * dem laufenden - bei Stundenpreisen ist das derselbe Wert wie bisher. */
    $next = tb_preis_zur_zeit($alle, $jetzt + max(1, tb_schrittweite($alle)));
    // Energieanteil und Steuer der laufenden Stunde einzeln mitfuehren.
    $curE = null; $curS = null; $curL = -1;
    /* Die Gueltigkeitsdauer eines Eintrags ist die GEMESSENE Schrittweite,
     * nicht eine feste Stunde. Bei Viertelstundenpreisen passten sonst vier
     * Eintraege auf denselben Zeitpunkt, und genommen wuerde der letzte. */
    $schritt = tb_schrittweite($alle);
    foreach ($alle as $e) {
        if ($e['ts'] <= $jetzt && $jetzt < $e['ts'] + $schritt) {
            $curE = $e['energie']; $curS = $e['steuer']; $curL = $e['level'];
        }
    }
    // Tibbers eigenes Niveau der laufenden Stunde, falls current geliefert wurde.
    if (isset($pi['current']['level'])) {
        $curL = tb_tibber_level($pi['current']['level']);
    }
    list($rang, $rangd) = tb_rang($alle, $jetzt);
    $fenster = tb_fenster($alle, (int) $cfg['fensterstunden'], $jetzt);

    /* Ein ZWEITES Fenster, das sich mit dem ersten nicht ueberschneidet.
     * Wer abends laedt, will heute Abend UND morgen frueh wissen, wann -
     * ein einziges Fenster ab jetzt beantwortet das nicht. Gesucht wird
     * ab dem Ende des ersten; findet sich keines, bleiben die Felder
     * LEER statt auf einer 0, die wie ein Messwert aussaehe. */
    /* Die Laenge wird GEKLEMMT wie in tb_fenster() selbst - sonst rechnet
     * eine von Hand eingetragene 0 in tibber.json den Versatz auf 0, und das
     * "zweite, sich nicht ueberschneidende" Fenster ist byteweise das erste. */
    /* Die Laenge wird GEKLEMMT wie in tb_fenster() selbst, und der Abstand
     * zaehlt ab jetzt - beides steckt in tb_fenster_zweites(). */
    $laenge = tb_fensterlaenge($cfg['fensterstunden']);
    $fenster2 = tb_fenster_zweites($alle, $laenge, $fenster, $jetzt);

    /* Das guenstigste Fenster des FOLGETAGS - sobald Tibber die Preise
     * fuer morgen liefert. Damit laesst sich abends entscheiden, ob man
     * heute noch laedt oder auf morgen wartet. */
    $fmorgen = array('ts' => null, 'h' => null, 'in' => null, 'ct' => null);
    if ($morgen) {
        $fmorgen = tb_fenster($morgen, $laenge, $morgen[0]['ts']);
    }

    /* Der Vergleich mit den letzten Wochen. Er beantwortet die Frage, die
     * eine feste Schwelle nicht beantworten kann: IST 24 ct VIEL? Die
     * Werte bleiben NULL, solange die Reihe den Zeitraum nicht traegt -
     * ein Durchschnitt aus drei Punkten saehe aus wie ein Monatsmittel
     * und waere keiner. */
    list($avg30, $rang30, $n30) = tb_verlauf_kennzahlen($cur, 30);

    // Negativ ist der ENERGIEANTEIL, nicht der Endpreis: der Endpreis
    // enthaelt Steuer und wird deshalb praktisch nie negativ.
    $neg = 0;
    foreach ($alle as $e) {
        if ($e['ts'] <= $jetzt && $jetzt < $e['ts'] + $schritt
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
        'fenster2_h'  => $fenster2['h'],
        'fenster2_in' => $fenster2['in'],
        'fenster2_ct' => $fenster2['ct'],
        'fenster_morgen_h'  => $fmorgen['h'],
        'fenster_morgen_ct' => $fmorgen['ct'],
        'avg_30t'     => $avg30,
        'rank_30t'    => $rang30,
        'n_30t'       => $n30,
        'waehrung'    => isset($pi['current']['currency']) ? (string) $pi['current']['currency'] : '',
        'liste_heute' => $heute,
        'liste_morgen' => $morgen,
    );
    /* Ohne diese Pruefung meldete die Funktion Erfolg und das Protokoll
     * schrieb "Preise geholt: 24 Stunden heute" - waehrend stand.json den
     * alten Stand trug. Ein Erfolg im Protokoll, den es nicht gab. */
    if (!tb_json_schreiben($p['datadir'] . '/stand.json', $st)) {
        $meldung = 'Der Zwischenspeicher ' . $p['datadir'] . '/stand.json liess sich '
                 . 'nicht schreiben. Die Preise sind geholt, aber nicht abgelegt.';
        tb_log_gebremst('stand_schreiben', $meldung);
        return array(0, $meldung);
    }
    tb_verlauf_schreiben($st);
    return array(1, count($heute) . ' Stunden heute, ' . count($morgen) . ' Stunden morgen.');
}

/** Einen Tagespunkt in die Verlaufsdatei schreiben, hoechstens stuendlich. */
function tb_verlauf_schreiben(array $st)
{
    $cfg = tb_config();
    $p = tb_paths();
    $ordner = $p['datadir'] . '/verlauf';
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        /* Ohne diese Zeile blieben AVG_30T und RANK_30T fuer immer leer, und
         * im Protokoll stand nichts. Der Selbsttest sagt dazu nur "0 Punkte" -
         * auf einer frischen Anlage richtig, hier irrefuehrend. */
        tb_log_gebremst('verlauf_ordner', 'Der Verlaufsordner ' . $ordner . ' liess '
            . 'sich nicht anlegen - der Vergleich mit den letzten Wochen '
            . '(AVG_30T, RANK_30T) bleibt deshalb leer.');
        return;
    }
    $datei = $ordner . '/' . date('Ym') . '.csv';
    if (is_file($datei) && (time() - filemtime($datei)) < 3300) { return; }
    if ($st['cur'] === null) { return; }
    if (@file_put_contents($datei, time() . ';' . $st['cur'] . ';'
        . ($st['heute']['avg'] === null ? '' : $st['heute']['avg']) . "\n",
        FILE_APPEND) === false) {
        tb_log_gebremst('verlauf_schreiben', 'Der Verlaufspunkt liess sich nicht nach '
            . $datei . ' schreiben - AVG_30T und RANK_30T bleiben leer.');
        return;
    }
    $tage = max(1, min(3650, (int) $cfg['verlauf_tage']));
    foreach ((array) glob($ordner . '/*.csv') as $alt) {
        if (time() - filemtime($alt) > $tage * 86400) { @unlink($alt); }
    }
}

/* ==================================================================
 * Verbrauch holen und auswerten
 * ================================================================== */

/**
 * Die Kostenkennzahlen eines Monats - als EIGENE Funktion, damit der
 * Selbsttest sie messen kann statt sie zu beschreiben.
 *
 * Zwei Dinge, die bis 0.9.9 anders waren:
 *
 * 1. Der eigene Aufschlag gehoert dazu. tb_preisliste() rechnet ihn in jeden
 *    angezeigten Preis ein, und die Niveaubeurteilung der Verbrauchsstunden
 *    tut es auch. Nur die Kostenkennzahlen nahmen Tibbers ROHE Kosten und
 *    verglichen sie mit dem Festpreis. Bei 3 ct Aufschlag und 300 kWh wies
 *    das Plugin damit rund 9 Euro Ersparnis je Monat aus, die es nicht gab:
 *    CUR zeigte 31 ct, DYN_MONAT rechnete mit 28.
 *
 * 2. BEIDE Zaehlreihen oder keine. Kosten und kWh wuchsen getrennt. Liefert
 *    Tibber fuer einen Tag Kosten, aber noch keinen Verbrauch - das kommt bei
 *    nachlaufender Messstellenuebermittlung vor -, teilte dyn = kostenM/kwhM
 *    die Kosten aus MEHR Tagen durch den Verbrauch aus WENIGER. Gemessen:
 *    10 kWh/3 EUR plus 0 kWh/3 EUR ergab 60 ct/kWh statt 30.
 *
 * Rueckgabe: array(kwh, kosten, tage, dyn, diff, euro). dyn/diff/euro sind
 * NULL, solange kein Verbrauch vorliegt - lieber kein Wert als eine Zahl,
 * die aussieht wie eine Messung.
 */
function tb_kosten_kennzahlen(array $tage, array $cfg, $monat)
{
    $kwh = 0.0; $kosten = 0.0; $n = 0;
    foreach ($tage as $tag => $w) {
        if (strpos((string) $tag, (string) $monat) !== 0) { continue; }
        if (!is_array($w)) { continue; }
        if (!isset($w['kwh'], $w['kosten'])
            || $w['kwh'] === null || $w['kosten'] === null) { continue; }
        $kwh += (float) $w['kwh'];
        $kosten += (float) $w['kosten'];
        $n++;
    }
    // Der lastprofil-gewichtete Durchschnittspreis: Kosten geteilt durch
    // Verbrauch ist genauer als ein Mittel der Stundenpreise, weil er den
    // tatsaechlichen Verbrauch gewichtet.
    $dyn = ($kwh > 0.01)
         ? round($kosten / $kwh * 100 + (float) $cfg['aufschlag'], 3) : null;
    $diff = ($dyn === null) ? null : round($dyn - (float) $cfg['festpreis'], 3);
    $euro = ($diff === null) ? null : round($diff * $kwh / 100, 2);
    return array($kwh, $kosten, $n, $dyn, $diff, $euro);
}

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
    list($kwhM, $kostenM, $nM, $dyn, $diff, $euro)
        = tb_kosten_kennzahlen($tage, $cfg, date('Y-m'));

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
    /* --- Der guenstige Anteil des gestrigen Verbrauchs ---
     *
     * Die Frage, fuer die es dieses Plugin eigentlich gibt: wie viel des
     * eigenen Verbrauchs lag in den guenstigen Stunden? Sie laesst sich
     * nur mit der STUENDLICHEN Aufloesung beantworten. tb_abfrage_verbrauch()
     * kann sie seit jeher, aufgerufen wurde bis 0.9.6 ausschliesslich DAILY.
     *
     * Eine zweite Abfrage je Verbrauchstakt, nicht je Minute. Und beide
     * Werte entstehen NUR, wenn beide Zaehlreihen vorliegen - fehlt eine,
     * gibt es keinen Wert und keine 0. Eine 0 stuende in Loxone und saehe
     * richtig aus. */
    $vb['guenstiganteil'] = null;
    $vb['ersparnis_gestern'] = null;
    $sd = tb_gql(tb_abfrage_verbrauch((string) $cfg['home_id'], 'HOURLY', 48));
    if (!isset($sd['_fehler'])) {
        $sh = tb_erstes_home($sd);
        $sk = isset($sh['consumption']['nodes']) ? $sh['consumption']['nodes'] : array();
        /* Derselbe Tag wie oben, nicht ein zweites Mal berechnet. Zwischen
         * beiden Stellen liegt ein Netzabruf mit bis zu 60 s Zeitschranke -
         * laeuft der Cron ueber Mitternacht, bezoegen sich Tageswerte und
         * Stundenwerte in DERSELBEN verbrauch.json auf verschiedene Tage. */
        $gestern_tag = $gestern;
        $kwh_g = 0.0; $kwh_guenstig = 0.0; $kosten_g = 0.0; $stunden = 0;
        foreach ((array) $sk as $n) {
            if (!isset($n['from'], $n['consumption']) || $n['consumption'] === null) { continue; }
            $ts = strtotime((string) $n['from']);
            if ($ts === false || date('Y-m-d', $ts) !== $gestern_tag) { continue; }
            $kwh = (float) $n['consumption'];
            $kwh_g += $kwh;
            $stunden++;
            if (isset($n['cost']) && $n['cost'] !== null) { $kosten_g += (float) $n['cost']; }
            /* Der Preis DIESER Stunde. unitPrice ist der Endpreis in Euro
             * je kWh - dieselbe Groesse wie priceInfo.total, also wird sie
             * mit denselben Schwellen beurteilt. */
            if (isset($n['unitPrice']) && $n['unitPrice'] !== null) {
                $ct = (float) $n['unitPrice'] * 100 + (float) $cfg['aufschlag'];
                if (tb_niveau($ct, $cfg) === 0) { $kwh_guenstig += $kwh; }
            }
        }
        /* Ein Tag hat 24 Stunden. Weniger als 20 sind kein Tag, sondern
         * ein Bruchstueck - daraus einen Prozentsatz zu bilden hiesse,
         * eine Zahl auszugeben, die aussieht wie eine Tagesbilanz. */
        if ($stunden >= 20 && $kwh_g > 0.01) {
            $vb['guenstiganteil'] = (int) round($kwh_guenstig * 100.0 / $kwh_g);
            $fest = $kwh_g * (float) $cfg['festpreis'] / 100.0;
            /* Auch hier der Aufschlag - sonst vergleicht die Zeile einen
             * Festpreis mit Tibbers Rohkosten. */
            $dyn_g = $kosten_g + $kwh_g * (float) $cfg['aufschlag'] / 100.0;
            $vb['ersparnis_gestern'] = round($fest - $dyn_g, 2);
        }
        $vb['stunden_gestern'] = $stunden;
    } else {
        /* Ohne diesen Zweig scheiterte der stuendliche Zweitabruf STUMM:
         * keine Zeile im Protokoll, kein Merkmal. GUENSTIGANTEIL und
         * ERSPARNIS_GESTERN blieben leer, und in Loxone stand der letzte
         * Wert - ohne dass irgendwo stand, warum. */
        tb_log_gebremst('verbrauch_std', 'Der stuendliche Verbrauchsabruf misslang: '
            . $sd['_fehler'] . ' Guenstiger Anteil und Ersparnis von gestern bleiben '
            . 'deshalb leer.');
    }

    if (!tb_json_schreiben($p['datadir'] . '/verbrauch.json', $vb)) {
        $meldung = 'Der Zwischenspeicher ' . $p['datadir'] . '/verbrauch.json liess '
                 . 'sich nicht schreiben.';
        tb_log_gebremst('verbrauch_schreiben', $meldung);
        return array(0, $meldung);
    }
    return array(1, count($tage) . ' Tage Historie, ' . $nM . ' davon im laufenden Monat, '
                 . (isset($vb['stunden_gestern']) ? (int) $vb['stunden_gestern'] : 0)
                 . ' Stundenwerte fuer gestern.');
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
    $dyn = $kwh > 0.01 ? round($kosten / $kwh * 100 + (float) $cfg['aufschlag'], 3) : null;
    if ($dyn === null) {
        /* $n > 0 heisst nur: es gab Tageszeilen. Stehen darin lauter Nullen,
         * ist $dyn null - und bis 0.9.9 setzte sprintf() dafuer eine 0 ein
         * und waehlte den Zweig 'draufgezahlt'. Herausgekommen ist ein Satz,
         * der eine Messung behauptet, die es nicht gibt: "0.0 kWh an 31
         * Tagen ... draufgezahlt 0.00 Euro." Lieber kein Bericht als einer,
         * der richtig aussieht. */
        return array(0, 'Fuer ' . $vormonat . ' steht kein Verbrauch in der Historie - '
                      . 'es wird kein Bericht erzeugt.');
    }
    $fix = (float) $cfg['festpreis'];
    $grund = (float) $cfg['grundpreis'];
    $diff = round($dyn - $fix, 3);
    $euro = round($diff * $kwh / 100, 2);

    $text = sprintf(
        'Monatsbericht %s: %.1f kWh an %d Tagen, %.2f Euro Arbeitspreis. '
        . 'Dynamisch %.3f ct/kWh gegen fest %.3f ct/kWh - %s %.2f Euro. '
        . 'Grundpreis %.2f Euro ist dabei nicht eingerechnet.',
        $vormonat, $kwh, $n, $kosten,
        $dyn, $fix,
        ($euro < 0) ? 'gespart' : 'draufgezahlt',
        abs($euro), $grund);

    if (!tb_json_schreiben($p['datadir'] . '/bericht.json', array(
        'ts' => time(), 'monat' => $vormonat, 'kwh' => round($kwh, 2),
        'kosten' => round($kosten, 2), 'tage' => $n, 'dyn' => $dyn,
        'fix' => $fix, 'diff' => $diff, 'euro' => $euro, 'text' => $text,
    ))) {
        tb_log_gebremst('bericht_datei', 'Der Monatsbericht liess sich nicht nach '
            . $p['datadir'] . '/bericht.json schreiben.');
    }
    /* Der Erledigt-Marker MUSS stehen, sonst wiederholt sich der Bericht
     * jede Minute. Bis 0.9.9 wurde sein Rueckgabewert verworfen und die
     * Protokollzeile lief ungebremst: am Ersten des Monats von 8 bis 24 Uhr
     * rund 960 gleiche Zeilen - genug, um die Kappung ausloesen und das
     * Protokoll des Tages wegwerfen zu lassen. */
    if (@file_put_contents($marker, (string) time()) === false) {
        tb_log_gebremst('bericht_marker', 'Der Erledigt-Marker ' . $marker . ' liess '
            . 'sich nicht schreiben - der Monatsbericht wiederholt sich deshalb, bis '
            . 'das Verzeichnis beschreibbar ist.');
        tb_log_gebremst('bericht_text', $text);
        return array(1, $text);
    }
    tb_log($text);
    return array(1, $text);
}

/* ==================================================================
 * MQTT
 * ================================================================== */

/**
 * Ueber MQTT veroeffentlichen - nur bei AENDERUNG.
 *
 * Bis 0.9.6 lief das hier bei JEDEM Minutenlauf und schickte ALLES: bis
 * zu 48 Statusfelder plus 24 Stundenpreise, also rund 72 Datagramme je
 * Minute und gegen 100 000 am Tag - auch wenn sich am Preis eine Stunde
 * lang nichts ruehrte.
 *
 * Drei Themen gehen dabei AN DER SIGNATUR VORBEI und bei jedem Durchgang
 * hinaus: status/ok, status/ts und status/zaehler. Sie sind das
 * Lebenszeichen. Wer nur bei Aenderungen sendet, hoert bei einer Stoerung
 * einfach auf - die zuletzt gesendeten Werte bleiben im Broker stehen,
 * und in Loxone sieht ein toter Dienst genauso aus wie ein ruhiges Haus.
 *
 * Und sie duerfen NICHT in die Signatur: der Zeitstempel aendert sich
 * jede Minute, und eine Signatur, die sich jede Minute aendert, macht den
 * Filter wirkungslos - dann geht wieder alles hinaus.
 */
function tb_veroeffentlichen()
{
    $cfg = tb_config();
    if (empty($cfg['mqtt_ein'])) { return array(0, 0, 'aus'); }
    $p = tb_paths();
    $topic = trim((string) $cfg['mqtt_topic'], '/');
    if ($topic === '') { $topic = 'tibber'; }

    $w = tb_werte();
    $paare = array();
    foreach ($w as $k => $v) {
        // ALTER, PULSE_ALTER und ZAEHLER gehen nicht als Wert hinaus: ueber
        // MQTT gibt es kein Alter (beim Senden ist es immer null oder es
        // tickt jede Sekunde weiter), und der Zaehler ist ein Lebenszeichen.
        // Alle stehen unten unter status/ - als Zeitstempel.
        // Die Liste steht in tb_mqtt_ausgeschlossen(), damit sie sich nicht
        // von der Themenliste im Reiter MQTT wegbewegen kann.
        if (in_array($k, tb_mqtt_ausgeschlossen(), true)) { continue; }
        $paare[strtolower($k)] = $v;
    }
    /* Die Stundenpreise von heute einzeln, damit Loxone einen Tagesverlauf
     * zeichnen kann, ohne den JSON-Endpunkt zu bemuehen.
     *
     * Gemittelt je Stunde, nicht ueberschrieben. Bei Stundenpreisen ist das
     * genau der bisherige Wert - der Mittelwert ueber einen Eintrag ist
     * dieser Eintrag; eine bestehende Anlage merkt also nichts. Kaemen
     * einmal Viertelstundenpreise, stuende sonst der Preis der LETZTEN
     * Viertelstunde unter dem Namen der ganzen Stunde. */
    $st = tb_stand();
    $summe = array(); $anzahl = array();
    foreach ((array) (isset($st['liste_heute']) ? $st['liste_heute'] : array()) as $e) {
        $h = (int) date('G', $e['ts']);
        if (!isset($summe[$h])) { $summe[$h] = 0.0; $anzahl[$h] = 0; }
        $summe[$h] += (float) $e['ct'];
        $anzahl[$h]++;
    }
    foreach ($summe as $h => $s) {
        $paare['stunde/' . $h . '/ct'] = round($s / max(1, $anzahl[$h]), 3);
    }

    $signatur = tb_mqtt_signatur($paare);
    $merker = $p['datadir'] . '/.mqtt_signatur';
    $alt = is_file($merker) ? trim((string) @file_get_contents($merker)) : '';
    $geaendert = ($alt !== $signatur);

    // Das Lebenszeichen IMMER, die Werte nur bei Aenderung.
    $senden = tb_mqtt_lebenszeichen();
    if ($geaendert) {
        foreach ($paare as $k => $v) { $senden[$k] = $v; }
    }
    list($versucht, $fehler) = tb_mqtt_senden($senden, $topic);
    if ($geaendert && $fehler === 0) {
        // Der Merker wird nur fortgeschrieben, wenn wirklich alles hinaus
        // ist. Sonst gilt ein halb gesendeter Stand als gesendet, und die
        // fehlenden Werte liegen bis zur naechsten Aenderung.
        @file_put_contents($merker, $signatur);
    }
    return array($versucht, $fehler, $geaendert ? 'geaendert' : 'lebenszeichen');
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
    $zeilen[] = ($ok1 ? 'Rechenkern: [OK]   ' : 'Rechenkern: [FEHL] ') . 'Preisliste und Kennzahlen: 24 Stunden, '
              . 'Minimum ' . $kz['minp'] . ' ct um ' . $kz['minh'] . ' Uhr, Maximum '
              . $kz['maxp'] . ' ct um ' . $kz['maxh'] . ' Uhr (erwartet 10/2 und 40/18)';
    if (!$ok1) { $fehler++; }

    // Guenstigstes 3-Stunden-Fenster ab Mitternacht: 3 bis 5 Uhr, Schnitt 10 ct.
    $f = tb_fenster($liste, 3, $t0);
    $ok2 = ($f['h'] === 2) && (abs($f['ct'] - 10.0) < 0.001);
    $zeilen[] = ($ok2 ? 'Rechenkern: [OK]   ' : 'Rechenkern: [FEHL] ') . 'Guenstigstes 3-Stunden-Fenster ab '
              . 'Mitternacht: ab ' . var_export($f['h'], true) . ' Uhr zu '
              . var_export($f['ct'], true) . ' ct (erwartet 2 Uhr, 10 ct)';
    if (!$ok2) { $fehler++; }

    // Der Aufschlag muss durchschlagen.
    $probe2 = array_merge(tb_vorgaben(), array('aufschlag' => 1.5));
    $liste2 = tb_preisliste($roh, $probe2);
    $ok3 = abs($liste2[0]['ct'] - 21.5) < 0.001;
    $zeilen[] = ($ok3 ? 'Rechenkern: [OK]   ' : 'Rechenkern: [FEHL] ') . 'Aufschlag 1,5 ct auf 20 ct ergibt '
              . $liste2[0]['ct'] . ' ct (erwartet 21,5)';
    if (!$ok3) { $fehler++; }

    // Rang: um 3 Uhr muss die laufende Stunde die guenstigste sein.
    list($rg, $rgd) = tb_rang($liste, $t0 + 3 * 3600 + 60);
    $ok4 = ($rg === 1);
    $zeilen[] = ($ok4 ? 'Rechenkern: [OK]   ' : 'Rechenkern: [FEHL] ') . 'Rang um 03:01 Uhr: ' . var_export($rg, true)
              . ' von ' . $rgd . ' (erwartet 1)';
    if (!$ok4) { $fehler++; }

    // Niveau gegen die eigenen Schwellen.
    $ok5 = (tb_niveau(10.0, $probe) === 0) && (tb_niveau(30.0, $probe) === 1)
           && (tb_niveau(40.0, $probe) === 2);
    $zeilen[] = ($ok5 ? 'Rechenkern: [OK]   ' : 'Rechenkern: [FEHL] ') . 'Preisniveau bei 10/30/40 ct: '
              . tb_niveau(10.0, $probe) . '/' . tb_niveau(30.0, $probe) . '/'
              . tb_niveau(40.0, $probe) . ' (erwartet 0/1/2)';
    if (!$ok5) { $fehler++; }

    // Tibbers eigenes Niveau.
    $ok6 = (tb_tibber_level('VERY_CHEAP') === 0) && (tb_tibber_level('VERY_EXPENSIVE') === 4)
           && (tb_tibber_level('quatsch') === -1);
    $zeilen[] = ($ok6 ? 'Rechenkern: [OK]   ' : 'Rechenkern: [FEHL] ') . 'Tibber-Preisniveau VERY_CHEAP/'
              . 'VERY_EXPENSIVE/unbekannt ergibt 0/4/-1';
    if (!$ok6) { $fehler++; }

    /* --- Viertelstundenpreise: dieselbe Rechnung, andere Schrittweite ---
     *
     * Seit dem 01.10.2025 werden die Boersenpreise viertelstuendlich
     * gestellt. Das Plugin fragt priceInfo ohne resolution und bekommt
     * deshalb weiterhin Stundenwerte - Tibber hat aber angekuendigt,
     * QUARTER_HOURLY zur Vorgabe zu machen.
     *
     * Geprueft wird gegen DENSELBEN Tagesverlauf wie oben, nur in 96
     * Vierteln statt 24 Stunden: das guenstigste 3-Stunden-Fenster muss
     * weiterhin um 2 Uhr beginnen und 10 ct kosten - nicht 45 Minuten
     * lang sein. Und die Schrittweite muss als 900 s erkannt werden. */
    $roh4 = array();
    for ($q = 0; $q < 96; $q++) {
        $h = intdiv($q, 4);
        $eur = 0.20;
        if ($h >= 2 && $h <= 5) { $eur = 0.10; }
        if ($h >= 18 && $h <= 20) { $eur = 0.40; }
        $roh4[] = array('startsAt' => date('c', $t0 + $q * 900),
                        'total' => $eur, 'energy' => $eur * 0.6, 'tax' => $eur * 0.4,
                        'level' => 'NORMAL');
    }
    $liste4 = tb_preisliste($roh4, $probe);
    $s4 = tb_schrittweite($liste4);
    $f4 = tb_fenster($liste4, 3, $t0);
    $p4 = tb_preis_zur_zeit($liste4, $t0 + 3 * 3600 + 60);
    $ok11 = ($s4 === 900) && ($f4['h'] === 2) && (abs($f4['ct'] - 10.0) < 0.001)
            && ($p4 !== null) && (abs($p4 - 10.0) < 0.001);
    $zeilen[] = ($ok11 ? 'Rechenkern: [OK]   ' : 'Rechenkern: [FEHL] ')
              . 'Viertelstundenpreise: Schrittweite ' . $s4 . ' s, Fenster ab '
              . var_export($f4['h'], true) . ' Uhr zu ' . var_export($f4['ct'], true)
              . ' ct, Preis um 03:01 ' . var_export($p4, true)
              . ' ct (erwartet 900, 2 Uhr, 10 ct, 10 ct)';
    if (!$ok11) { $fehler++; }

    /* Und die Gegenrichtung: bei Stundenpreisen muss dieselbe Funktion 3600
     * melden. Eine Schrittweitenerkennung, die immer 900 sagt, waere
     * genauso falsch wie eine feste 3600. */
    $ok12 = (tb_schrittweite($liste) === 3600) && (tb_schrittweite(array()) === 3600);
    $zeilen[] = ($ok12 ? 'Rechenkern: [OK]   ' : 'Rechenkern: [FEHL] ')
              . 'Schrittweite bei Stundenpreisen: ' . tb_schrittweite($liste)
              . ' s, bei leerer Liste ' . tb_schrittweite(array())
              . ' s (erwartet 3600 und 3600)';
    if (!$ok12) { $fehler++; }

    /* --- Suchtext: das Trennzeichen, und dass es wirklich traegt ---
     *
     * Bis 0.9.6 fehlte dem Suchtext das fuehrende Semikolon. Loxone sucht
     * woertlich und nimmt den ersten Treffer: 'ALTER=' steckt in
     * 'PULSE_ALTER=', 'OK=' in 'MORGEN_OK='. Beide stehen frueher in der
     * Zeile - der Miniserver las an diesen zwei Feldern den falschen Wert,
     * ausgerechnet an denen, an denen die Ausfallerkennung haengt.
     *
     * Geprueft wird an der GEBAUTEN Zeile, nicht an einer Namensliste. */
    list($sn, $sfalsch) = tb_suchtext_pruefen();
    $ok7 = ($sfalsch === array()) && ($sn > 0);
    $zeilen[] = ($ok7 ? 'Rechenkern: [OK]   ' : 'Rechenkern: [FEHL] ')
              . 'Suchtexte eindeutig: ' . $sn . ' Felder, '
              . ($sfalsch ? count($sfalsch) . ' treffen falsch ('
                            . implode(', ', $sfalsch) . ')' : 'keiner trifft falsch');
    if (!$ok7) { $fehler++; }

    $ok8 = (tb_check('ALTER') === '\\i;ALTER=\\i\\v');
    $zeilen[] = ($ok8 ? 'Rechenkern: [OK]   ' : 'Rechenkern: [FEHL] ')
              . 'Gestalt des Suchtexts: ' . tb_check('ALTER')
              . ' (erwartet \\i;ALTER=\\i\\v)';
    if (!$ok8) { $fehler++; }

    /* --- Das zweite Fenster ueberschneidet sich nicht mit dem ersten ---
     * Am selben Pruefstueck wie oben: nachts 10 ct von 2 bis 5 Uhr, abends
     * 40 ct. Das erste 3-Stunden-Fenster ab Mitternacht beginnt um 2 Uhr;
     * das zweite darf fruehestens um 5 Uhr beginnen. */
    $f1 = tb_fenster($liste, 3, $t0);
    $f2 = tb_fenster($liste, 3, $f1['ts'] + 3 * 3600);
    $ok9 = ($f1['h'] === 2) && ($f2['h'] !== null) && ($f2['h'] >= 5);
    $zeilen[] = ($ok9 ? 'Rechenkern: [OK]   ' : 'Rechenkern: [FEHL] ')
              . 'Zweites Fenster ohne Ueberschneidung: erstes ab '
              . var_export($f1['h'], true) . ' Uhr, zweites ab '
              . var_export($f2['h'], true) . ' Uhr (erwartet 2 und mindestens 5)';
    if (!$ok9) { $fehler++; }

    /* --- Eine zu kurze Reihe ergibt KEINEN Durchschnitt ---
     * Lieber kein Wert als eine Zahl, die richtig aussieht: ein Mittel aus
     * drei Punkten saehe aus wie ein Monatsdurchschnitt. Geprueft wird der
     * Rueckgabewert der Kennzahlenfunktion an einer leeren Reihe - auf
     * einer frischen Anlage ist genau das der Fall. */
    /* Die Mindestzahl kommt aus der Bibliothek, nicht aus dieser Datei.
     * Bis 0.9.9 stand die 48 hier zweimal - ein Selbsttest, der seine
     * Vergleichszahl selbst mitbringt, bleibt gruen, wenn sich die geprueft
     * Funktion aendert, und die Aussage ist dann falsch. */
    $mind = tb_verlauf_mindestpunkte();
    list($pavg, $prang, $pn) = tb_verlauf_kennzahlen(20.0, 30);
    $ok10 = ($pn >= $mind) ? ($pavg !== null) : ($pavg === null && $prang === null);
    $zeilen[] = ($ok10 ? 'Rechenkern: [OK]   ' : 'Rechenkern: [FEHL] ')
              . 'Verlaufskennzahlen: ' . $pn . ' Punkte, Durchschnitt '
              . ($pavg === null ? 'nicht gebildet' : $pavg . ' ct')
              . ' (unter ' . $mind . ' Punkten wird keiner gebildet)';
    if (!$ok10) { $fehler++; }

    /* --- Der eigene Aufschlag steckt in den Kostenkennzahlen ---
     *
     * Bis 0.9.9 nicht: CUR trug ihn, DYN_MONAT nicht. Geprueft an einer
     * Historie mit bekanntem Ergebnis - 300 kWh fuer 84 Euro sind 28 ct/kWh,
     * mit 3 ct Aufschlag 31. Der Vergleich mit dem Festpreis 34 ergibt
     * dann -3 ct und -9 Euro, nicht -6 ct und -18 Euro. */
    $ktage = array('2026-08-10' => array('kwh' => 150.0, 'kosten' => 42.0),
                   '2026-08-11' => array('kwh' => 150.0, 'kosten' => 42.0));
    $kcfg = array_merge(tb_vorgaben(), array('aufschlag' => 3.0, 'festpreis' => 34.0));
    list($kkwh, $kkos, $kn, $kdyn, $kdiff, $keuro)
        = tb_kosten_kennzahlen($ktage, $kcfg, '2026-08');
    $ok11 = (abs($kdyn - 31.0) < 0.001) && (abs($kdiff + 3.0) < 0.001)
            && (abs($keuro + 9.0) < 0.01);
    $zeilen[] = ($ok11 ? 'Rechenkern: [OK]   ' : 'Rechenkern: [FEHL] ')
              . 'Aufschlag in den Kostenkennzahlen: 300 kWh fuer 84 Euro plus 3 ct '
              . 'ergibt ' . var_export($kdyn, true) . ' ct/kWh, Unterschied '
              . var_export($kdiff, true) . ' ct und ' . var_export($keuro, true)
              . ' Euro (erwartet 31, -3 und -9)';
    if (!$ok11) { $fehler++; }

    /* --- Ein Tag mit Kosten, aber ohne Verbrauch verzerrt nichts ---
     *
     * Bis 0.9.9 wuchsen beide Zaehlreihen getrennt: 10 kWh/3 EUR plus
     * 0 kWh/3 EUR ergab 60 ct/kWh statt 30. */
    $ktage2 = array('2026-08-10' => array('kwh' => 10.0,  'kosten' => 3.0),
                    '2026-08-11' => array('kwh' => null,  'kosten' => 3.0));
    $kcfg2 = array_merge(tb_vorgaben(), array('aufschlag' => 0.0, 'festpreis' => 34.0));
    list($k2kwh, $k2kos, $k2n, $k2dyn) = tb_kosten_kennzahlen($ktage2, $kcfg2, '2026-08');
    $ok12 = (abs($k2dyn - 30.0) < 0.001) && ($k2n === 1) && (abs($k2kos - 3.0) < 0.001);
    $zeilen[] = ($ok12 ? 'Rechenkern: [OK]   ' : 'Rechenkern: [FEHL] ')
              . 'Tag mit Kosten ohne Verbrauch: ' . $k2n . ' Tag gezaehlt, '
              . var_export($k2kos, true) . ' Euro, ' . var_export($k2dyn, true)
              . ' ct/kWh (erwartet 1, 3 und 30)';
    if (!$ok12) { $fehler++; }

    /* --- FENSTER2_IN zaehlt ab JETZT, nicht ab dem Ende des ersten ---
     *
     * Bis 0.9.9 rechnete tb_fenster() den Abstand gegen seinen eigenen
     * Startpunkt - hier also gegen das Ende des ersten Fensters. Gemessen:
     * um 11:01 stand dort 0, waehrend das zweite Fenster wirklich in 17
     * Stunden begann. Eine Loxone-Regel "laden, wenn FENSTER2_IN = 0" loeste
     * damit mittags aus. Geprueft wird an demselben Pruefstueck wie oben. */
    /* Die Erwartung ist eine FESTE Zahl aus dem Pruefstueck, nicht dieselbe
     * Formel noch einmal. Der erste Anlauf dieses Satzes hat den Abstand mit
     * derselben Rechnung nachgebaut, die er pruefen sollte - er waere gruen
     * geblieben, wenn man die Korrektur herausnimmt. Dieselbe Falle steht in
     * dieser Linie schon einmal, bei tb_suchtext_pruefen().
     *
     * Der Pruefling: 24 Stunden, 20 ct, nachts 10, abends 40. Um 11:01 Uhr
     * beginnt das erste 3-Stunden-Fenster um 11 Uhr (in 0 h), das zweite
     * fruehestens um 14 Uhr - also in 3 Stunden. Vor der Korrektur stand
     * dort 0, weil gegen das Ende des ersten gerechnet wurde. */
    $jetzt2 = $t0 + 11 * 3600 + 60;              // 11:01 Uhr
    $g1 = tb_fenster($liste, 3, $jetzt2);
    $g2 = tb_fenster_zweites($liste, 3, $g1, $jetzt2);
    $ok13 = ($g1['h'] === 11) && ($g2['h'] === 14) && ($g2['in'] === 3);
    $zeilen[] = ($ok13 ? 'Rechenkern: [OK]   ' : 'Rechenkern: [FEHL] ')
              . 'FENSTER2_IN zaehlt ab jetzt: um 11:01 Uhr beginnt das erste Fenster um '
              . var_export($g1['h'], true) . ' Uhr, das zweite um '
              . var_export($g2['h'], true) . ' Uhr in ' . var_export($g2['in'], true)
              . ' h (erwartet 11, 14 und 3)';
    if (!$ok13) { $fehler++; }

    // Die Loxone-Vorlage muss wohlgeformt sein.
    list($vname, $vinhalt) = tb_vorlage();
    $vorher = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($vinhalt);
    libxml_clear_errors();
    libxml_use_internal_errors($vorher);
    $zeilen[] = ($xml !== false ? 'Rechenkern: [OK]   ' : 'Rechenkern: [FEHL] ') . 'Loxone-Vorlage ' . $vname
              . ($xml !== false ? ' ist wohlgeformt, ' . count($xml->VirtualInHttpCmd)
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

    /* Die Schlusszeile, die freigabe_pruefen.py auswertet. Ohne sie meldet
     * das Werkzeug 'keine auswertbare Ausgabe' - der Selbsttest lief, und
     * die Freigabepruefung konnte ihn nicht bewerten.
     *
     * Gezaehlt wird der RECHENKERN: die Pruefwerte, die auf jeder Maschine
     * gleich ausgehen muessen. Die Zeilen ueber DIESE Anlage (kein Token,
     * kein Gateway) stehen weiter oben und werden getrennt genannt - ein
     * Tor, das auf einer Baumaschine immer rot ist, sagt nichts mehr.
     *
     * Und die Zahl wird aus den ausgegebenen Zeilen GEZAEHLT, nicht aus
     * einem mitlaufenden Zaehler genommen: steht die Zahl im Quelltext des
     * Pruefskripts, ist es keine Pruefung, sondern eine Absichtserklaerung. */
    $kern = 0; $kern_fehl = 0;
    foreach ($zeilen as $zl) {
        if (strpos($zl, 'Rechenkern:') !== 0) { continue; }
        $kern++;
        if (strpos($zl, '[FEHL]') !== false) { $kern_fehl++; }
    }
    /* $fehler zaehlt beides mit; gemeldet werden die Zahlen getrennt, damit
     * eine Beanstandung zu DIESER Anlage nicht wie ein Fehler des Plugins
     * aussieht. Bis 0.9.9 wurde $fehler an 20 Stellen erhoeht und nirgends
     * gelesen - ein Zaehler, den niemand liest, ist eine falsche Faehrte. */
    $anlage = 0;
    foreach ($zeilen as $zl) {
        if (strpos($zl, '[FEHL]') === 0) { $anlage++; }
    }
    $zeilen[] = '';
    $zeilen[] = sprintf('Rechenkern: %d Faelle geprueft, %d Fehlschlaege',
                        $kern, $kern_fehl);
    $zeilen[] = sprintf('Gezaehlt insgesamt: %d Beanstandung(en) in dieser Ausgabe.',
                        $fehler);
    if ($anlage > 0) {
        $zeilen[] = sprintf('Dazu %d Beanstandung(en) zu DIESER Anlage - die stehen '
            . 'oben und sind kein Urteil ueber das Plugin.', $anlage);
    }
    echo implode("\n", $zeilen) . "\n";
    return ($kern_fehl > 0) ? 1 : 0;
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

/* Ein unbekannter Schalter faellt NICHT durch.
 *
 * Wer von Hand 'php tb_cron.php --selftest' tippt (statt --selbsttest),
 * soll eine Antwort sehen und keinen stillen Normallauf, der Preise holt
 * und MQTT-Nachrichten verschickt. Gemessen am Zendure-Plugin: dort
 * landete derselbe Tippfehler in der Dienstschleife. */
$tb_bekannt = array('--selbsttest', '--preise', '--verbrauch');
foreach ($tb_argv as $tb_i => $tb_a) {
    if ($tb_i === 0) { continue; }
    /* Bis 0.9.9 wurde nur geprueft, was mit -- beginnt. 'php tb_cron.php
     * preise' - ohne Striche - lief damit als stiller Normallauf durch,
     * genau das, was der Kommentar darueber verhindern will. */
    if (!in_array($tb_a, $tb_bekannt, true)) {
        fwrite(STDERR, 'Unbekannter Schalter: ' . $tb_a . "\n"
            . 'Bekannt sind: ' . implode(', ', $tb_bekannt) . "\n");
        exit(2);
    }
}

/* Der Selbsttest rechnet nur - er braucht die Sperre nicht und darf deshalb
 * auch nicht an ihr scheitern. Er steht VOR dem Ziehen der Sperre. */
if (in_array('--selbsttest', $tb_argv, true)) { exit(tb_selbsttest()); }

$tb_sperre = tb_sperre_ziehen();
if ($tb_sperre === null) {
    /* Ein anderer Lauf ist noch dran. Der Minutenlauf geht kommentarlos -
     * der naechste Takt kommt gleich. Ein HANDaufruf bekommt eine Antwort
     * und einen eigenen Rueckgabewert: sonst haelt die Oberflaeche das
     * Nichtstun fuer einen gelungenen Abruf. */
    if (count($tb_argv) > 1) {
        fwrite(STDERR, "Ein anderer Lauf haelt gerade die Sperre - es wurde nichts "
            . "geholt. Bitte in einer Minute noch einmal.\n");
        exit(3);
    }
    exit(0);
}

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
    $vergangen = time() - $letzte;
    if ($vergangen < 0) {
        /* Im Marker steht eine Zukunft. Das passiert nach einem Neustart
         * ohne Echtzeituhr, wenn die Uhr erst spaeter ueber das Netz
         * gestellt wird: die Differenz ist dann negativ und damit IMMER
         * kleiner als der Takt - der Abruf waere bis zur Markerzeit
         * blockiert, ohne dass irgendwo etwas stuende. */
        tb_log_gebremst('uhrsprung', 'Der Marker ' . basename($f) . ' liegt in der '
            . 'Zukunft (' . date('d.m.Y H:i', $letzte) . '). Die Uhr des LoxBerry ist '
            . 'vermutlich zurueckgestellt worden; der Abruf wird trotzdem faellig.');
        $vergangen = PHP_INT_MAX;
    }
    if ($vergangen < max(1, (int) $minuten) * 60) { return false; }
    @file_put_contents($f, (string) time());
    return true;
}

/** Den Takt-Marker fortschreiben, ohne zu fragen, ob er faellig war. */
function tb_takt_setzen($name)
{
    $f = tb_paths()['datadir'] . '/.letzter_' . preg_replace('/[^a-z]/', '', $name);
    @file_put_contents($f, (string) time());
}

if ($tb_preise || ($tb_auto && tb_faellig('preise', (int) $tb_cfg['preistakt']))) {
    list($ok, $meldung) = tb_preise_holen();
    if ($ok) { tb_log('Preise geholt: ' . $meldung); }
    /* Ein Handabruf schreibt den Takt-Marker mit fort. Sonst kann der
     * Minutenlauf unmittelbar danach ein zweites Mal abrufen - und Tibber
     * bremst haeufige Abrufe. Bei $tb_preise wird tb_faellig() wegen des
     * Kurzschlusses gar nicht gerufen, der Marker blieb also stehen. */
    if ($tb_preise && $ok) { tb_takt_setzen('preise'); }
}
if ($tb_verbrauch || ($tb_auto && tb_faellig('verbrauch', (int) $tb_cfg['verbrauchstakt']))) {
    list($ok, $meldung) = tb_verbrauch_holen();
    if ($ok === 1) { tb_log('Verbrauch geholt: ' . $meldung); }
    if ($tb_verbrauch && $ok === 1) { tb_takt_setzen('verbrauch'); }
}
if ($tb_auto) {
    list($ok, $meldung) = tb_monatsbericht();
    // Nur ein wirklich erstellter Bericht wird protokolliert; 'nicht faellig'
    // waere jede Minute eine Zeile.
    if ($ok === 0) { tb_log_gebremst('bericht', 'Monatsbericht: ' . $meldung); }
    if ($ok === 1) { tb_notify('bericht', 'hinweis', $meldung); }
}

/* Das Lebenszeichen: der Zaehler wird bei JEDEM Durchgang weitergestellt,
 * auch wenn kein Abruf faellig war. Nur so laesst sich "der Cron laeuft
 * noch" von "der Cron ist tot" unterscheiden - eine Prozessnummer gibt es
 * bei einem Cron-Lauf nicht, und das Abbild allein sagt es nicht, weil es
 * nur bei einem faelligen Abruf neu geschrieben wird. */
tb_zaehler_weiter();

/* Meldungen an das Benachrichtigungszentrum - beim WECHSEL des Befundes,
 * nicht bei jedem Durchlauf. Eine Meldung je Minute ist keine Meldung,
 * sondern Rauschen, und wer sie abstellt, stellt auch die echte ab. */
$tb_st_jetzt = tb_stand();
$tb_alter_jetzt = tb_alter();
/* Die Schranke kommt aus tb_altersschranke() - dieselbe Funktion, die auch
 * die Statuskachel der Oberflaeche einfaerbt. Zwei Stellen mit derselben
 * Zahl waeren zwei Stellen, die auseinanderlaufen koennen. */
$tb_altersschranke = tb_altersschranke($tb_cfg);
if (tb_token_lesen() === '') {
    tb_notify('token', 'fehler', 'Spotpreis Tibber: es ist kein Zugangstoken '
        . 'hinterlegt - es werden keine Preise geholt.');
} elseif (empty($tb_st_jetzt['ok']) && isset($tb_st_jetzt['fehler'])
          && $tb_st_jetzt['fehler'] !== '') {
    tb_notify('abruf', 'fehler', 'Spotpreis Tibber: der Preisabruf misslingt. '
        . $tb_st_jetzt['fehler']);
} elseif ($tb_alter_jetzt >= 0 && $tb_alter_jetzt > $tb_altersschranke) {
    tb_notify('abruf', 'hinweis', 'Spotpreis Tibber: der letzte gelungene '
        . 'Preisabruf ist ' . (int) round($tb_alter_jetzt / 60) . ' Minuten her '
        . '(gemeldet wird ab ' . (int) round($tb_altersschranke / 60) . ' Minuten).');
} else {
    tb_notify('abruf', 'ok', 'Der Preisabruf laeuft wieder.');
    tb_notify('token', 'ok', 'Es ist ein Zugangstoken hinterlegt.');
}

list($tb_v, $tb_f, $tb_lage) = tb_veroeffentlichen();
/* Beide Zahlen getrennt. Ein 'n Werte versendet' ohne die Zahl der
 * Fehlschlaege ist eine Zusammenfassung, die besser aussieht als ihr
 * schlechtester Punkt - und LOGOK setzt voraus, dass nichts gescheitert
 * ist. Der Normalfall (nur Lebenszeichen) wird nicht protokolliert:
 * das waeren 1440 Zeilen am Tag. */
if ($tb_f > 0) {
    tb_log_gebremst('mqtt_fehler', 'MQTT: ' . $tb_v . ' Themen versucht, '
        . $tb_f . ' gescheitert.');
} elseif ($tb_lage === 'geaendert') {
    tb_log_gebremst('mqtt_ok', 'MQTT: ' . $tb_v . ' Themen veroeffentlicht.', 3600);
}
exit(0);
