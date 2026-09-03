<?php
/**
 * Spotpreis Tibber - Endpunkt fuer den Miniserver
 *
 * Liegt im unangemeldeten Bereich, damit Loxone ihn ohne Zugangsdaten
 * erreicht, und ist deshalb durch ein Token geschuetzt. Verglichen wird mit
 * hash_equals, also in gleichbleibender Zeit - ein einfaches == liesse sich
 * ueber die Antwortzeit Zeichen fuer Zeichen erraten.
 *
 *   /plugins/<ordner>/index.php?token=<TOKEN>&aktion=<Befehl>
 *
 * Rein LESEND. Es gibt hier nichts zu schalten: das Plugin holt Preise, es
 * steuert nichts. Ein Endpunkt im unangemeldeten Bereich, der mehr koennte
 * als lesen, waere eine Angriffsflaeche ohne Gegenwert.
 *
 *   status     eine Zeile TIBBER;SCHLUESSEL=WERT;... (Vorgabe)
 *   stunden    die Stundenpreise von heute und morgen, eine Zeile je Stunde
 *   verbrauch  Tagesverbrauch und Kosten aus der Historie
 *   pulse      die Momentanwerte der Tibber Pulse
 *   json       alles als JSON
 *
 * Dazu, ohne jede Wirkung:
 *
 *   ?selftest=1&token=<TOKEN>   beantwortet NUR, ob das Token stimmt
 *
 *       richtiges Token   HTTP 200  SELFTEST;OK=1;TOKEN=OK;FASSUNG=…
 *       falsches Token    HTTP 403  SELFTEST;OK=0;ERR=TOKEN
 *       keines gesetzt    HTTP 403  SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET
 *
 * Ein Strich als Wert bedeutet: dieser Wert liegt nicht vor. Es wird bewusst
 * keine 0 gesendet - eine 0 waere eine stille Falschaussage, und Loxone
 * behaelt bei einem Strich den letzten Wert.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/tb_lib.php';
header('Content-Type: text/plain; charset=utf-8');
/* Der Miniserver fragt diese Adresse im Abfragetakt ab, und die Antwort
 * aendert sich jede Minute. Ohne diese Zeile darf ein zwischengeschalteter
 * Rechner sie halten - und Loxone laese dann einen alten Zustand, ohne dass
 * es irgendwo auffiele. */
header('Cache-Control: no-store, max-age=0');

/* tb_config(FALSE) - hier wird nicht geschrieben.
 *
 * Die Lesefunktion holt eine fehlende Konfiguration aus der Zweitschrift
 * zurueck. Das ist in der Oberflaeche richtig und hier falsch: ein einziger
 * Aufruf OHNE Token legte damit den Konfigurationsordner samt Datei an, und
 * zwar bevor sich jemand ausgewiesen hatte. Wer sich nicht ausweisen kann,
 * legt nichts an - auch nichts Harmloses. */
$tb_cfg = tb_config(false);

/* Ein Parameter, der kein Skalar ist. ?token[]=x macht daraus ein Feld, und
 * (string) auf ein Feld ist unter PHP 8 ein TypeError: die Anfrage endet mit
 * HTTP 500 und LEEREM Rumpf, der Miniserver bekommt also gar nichts zu lesen.
 * Unter 7.4 liefe dieselbe Anfrage mit einer Warnung mitten in der Antwort
 * weiter. Deshalb wird EINMAL zentral eingesammelt. */
function tb_get($name, $vorgabe = '')
{
    if (!isset($_GET[$name])) { return $vorgabe; }
    $w = $_GET[$name];
    if (!is_scalar($w)) { return null; }
    $w = (string) $w;
    return strlen($w) > 256 ? null : $w;
}

$tb_ist = tb_get('token');
$tb_aktion = tb_get('aktion', 'status');
if ($tb_ist === null || $tb_aktion === null) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=PARAMETERFORM\n";
    exit;
}

/* ---------------- Token ---------------- */
/* trim wie in tb_formtoken() und tb_aktionstoken(). Ohne ihn haelt der
 * Endpunkt ein Merkwort aus lauter Leerzeichen fuer gesetzt und vergleicht
 * damit, waehrend die Oberflaeche es als leer behandelt. */
$tb_soll = trim((string) $tb_cfg['aktionstoken']);
$tb_selbsttest = tb_get('selftest') === '1';

if ($tb_soll === '') {
    http_response_code(403);
    if ($tb_selbsttest) {
        echo "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n";
        exit;
    }
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Token.\n";
    exit;
}
if (!hash_equals($tb_soll, $tb_ist)) {
    http_response_code(403);
    echo $tb_selbsttest ? "SELFTEST;OK=0;ERR=TOKEN\n" : "FEHLER;OK=0;GRUND=TOKEN\n";
    exit;
}

/* ---------------- Selbsttest ----------------
 *
 * Ein Token muss sich pruefen lassen, OHNE dass etwas passiert. Hier ist das
 * billig, weil der Endpunkt ohnehin nur liest - der Gewinn liegt woanders:
 * erst damit kann der Reiter Test den eigenen Endpunkt WIRKLICH abrufen,
 * statt einen Link anzubieten. Und genau dieser Aufruf findet die Klasse, die
 * keine Leseprüfung sieht: html/ und htmlauth/ liegen installiert in
 * getrennten Baeumen, und ein Endpunkt, der seine Bibliothek nicht findet,
 * antwortet mit HTTP 500 und leerem Rumpf - in Loxone sieht das aus wie
 * "kein Wert".
 *
 * Er steht hinter der Tokenpruefung und vor jeder Wirkung: kein
 * Geraetekontakt, kein Schreibzugriff, keine Aktion. */
if ($tb_selbsttest) {
    echo "SELFTEST;OK=1;TOKEN=OK;FASSUNG=" . tb_fassung() . "\n";
    exit;
}

/* ---------------- Aktion (Weissliste) ---------------- */
$tb_erlaubt = array('status', 'stunden', 'verbrauch', 'pulse', 'json');
if ($tb_aktion === '') { $tb_aktion = 'status'; }
if (!in_array($tb_aktion, $tb_erlaubt, true)) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION\n";
    echo 'Erlaubt sind: ' . implode(', ', $tb_erlaubt) . "\n";
    exit;
}

/** Ein Strich statt einer erfundenen 0. */
function tb_w($v)
{
    /* is_numeric(INF) ist wahr, und '1e999' wird beim Rechnen zu INF. Ohne
     * die Endlichkeitsprobe stuende in der Statuszeile CUR=INF - der Suchtext
     * findet dort keine Zahl, und der Miniserver haelt anders als beim Strich
     * auch nicht den letzten Wert. */
    if ($v === null || $v === '' || !is_numeric($v) || !is_finite((float) $v)) {
        return '-';
    }
    return (string) (0 + $v);
}

$tb_st = tb_stand();
$tb_werte = tb_werte();

if ($tb_aktion === 'json') {
    $tb_json = json_encode(array(
        'werte'     => $tb_werte,
        'stand'     => $tb_st,
        'verbrauch' => tb_verbrauch(),
        'pulse'     => tb_live(),
        'bericht'   => tb_json_lesen(tb_paths()['datadir'] . '/bericht.json'),
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($tb_json === false) {
        /* Bis 0.9.9 stand hier ein blankes echo. Scheitert json_encode - eine
         * ueberlaufende Zahl in einem Zwischenspeicher genuegt -, ging HTTP
         * 200 mit Content-Type application/json und NULL Byte Rumpf hinaus.
         * Wer das auswertet, bekommt keinen Fehler, sondern Stille. */
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "FEHLER;OK=0;GRUND=JSON;TEXT=" . json_last_error_msg() . "\n";
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo $tb_json;
    exit;
}

if ($tb_aktion === 'stunden') {
    $heute  = isset($tb_st['liste_heute']) ? $tb_st['liste_heute'] : array();
    $morgen = isset($tb_st['liste_morgen']) ? $tb_st['liste_morgen'] : array();
    /* Erst zaehlen, was wirklich ausgegeben wird, dann die Kopfzeile schreiben.
     *
     * Bis 0.9.9 wurden die Eintraege ungeprueft indiziert. Steht in
     * stand.json ein Eintrag, der kein Feld ist - von Hand bearbeitet, halb
     * geschrieben -, ist $e['ts'] unter PHP 8 ein TypeError: die Antwort
     * bricht NACH der Kopfzeile ab, HTTP 500 mit halbem Rumpf. Der Miniserver
     * liest dann eine Ankuendigung ueber 24 Stunden und keine einzige Zeile. */
    $tb_zeilen = array();
    foreach (array('HEUTE' => $heute, 'MORGEN' => $morgen) as $tag => $liste) {
        foreach ((array) $liste as $e) {
            if (!is_array($e) || !isset($e['ts'])) { continue; }
            $tb_zeilen[$tag][] = sprintf("%s;H=%d;CT=%s;ENERGIE=%s;STEUER=%s;TLEVEL=%s\n",
                $tag, (int) date('G', (int) $e['ts']),
                tb_w(isset($e['ct']) ? $e['ct'] : null),
                tb_w(isset($e['energie']) ? $e['energie'] : null),
                tb_w(isset($e['steuer']) ? $e['steuer'] : null),
                tb_w(isset($e['level']) ? $e['level'] : null));
        }
    }
    $tb_nh = isset($tb_zeilen['HEUTE']) ? count($tb_zeilen['HEUTE']) : 0;
    $tb_nm = isset($tb_zeilen['MORGEN']) ? count($tb_zeilen['MORGEN']) : 0;
    printf("STUNDEN;OK=%d;HEUTE=%d;MORGEN=%d;ALTER=%s\n",
        (int) $tb_werte['OK'], $tb_nh, $tb_nm, tb_w($tb_werte['ALTER']));
    foreach (array('HEUTE', 'MORGEN') as $tag) {
        foreach (isset($tb_zeilen[$tag]) ? $tb_zeilen[$tag] : array() as $z) { echo $z; }
    }
    exit;
}

if ($tb_aktion === 'verbrauch') {
    $vb = tb_verbrauch();
    $tage = isset($vb['tage']) && is_array($vb['tage']) ? $vb['tage'] : array();
    printf("VERBRAUCH;OK=%d;TAGE=%d;MONAT_KWH=%s;MONAT_EUR=%s;DYN=%s;FIX=%s;DIFF=%s;EURO=%s\n",
        $tage ? 1 : 0, count($tage),
        tb_w(isset($vb['verbr_monat']) ? $vb['verbr_monat'] : null),
        tb_w(isset($vb['kosten_monat']) ? $vb['kosten_monat'] : null),
        tb_w(isset($vb['dyn_monat']) ? $vb['dyn_monat'] : null),
        tb_w((float) $tb_cfg['festpreis']),
        tb_w(isset($vb['diff_monat']) ? $vb['diff_monat'] : null),
        tb_w(isset($vb['euro_monat']) ? $vb['euro_monat'] : null));
    foreach ($tage as $tag => $w) {
        if (!is_array($w)) { continue; }
        printf("TAG=%s;KWH=%s;EUR=%s\n", $tag,
            tb_w(isset($w['kwh']) ? $w['kwh'] : null),
            tb_w(isset($w['kosten']) ? $w['kosten'] : null));
    }
    exit;
}

if ($tb_aktion === 'pulse') {
    $l = tb_live();
    $alter = tb_live_alter();
    if ($alter < 0) {
        echo "PULSE;OK=0;GRUND=NOCH_KEIN_WERT\n";
        echo "Der Pulse-Dienst hat noch nichts geliefert. Reiter Einstellungen, "
           . "Echtzeitwerte einschalten und den Dienst starten.\n";
        exit;
    }
    // Aelter als zwei Minuten heisst: es gibt keinen gueltigen Momentanwert
    // mehr. Das wird gesagt, statt einen alten Wert als aktuell auszugeben.
    printf("PULSE;OK=%d;ALTER=%d;POWER=%s;ERZEUGUNG=%s;TAG_KWH=%s;TAG_EUR=%s;"
         . "L1=%s;L2=%s;L3=%s;U1=%s;U2=%s;U3=%s;MIN=%s;MAX=%s;SCHNITT=%s\n",
        $alter <= 120 ? 1 : 0, $alter,
        tb_w(isset($l['power']) ? $l['power'] : null),
        tb_w(isset($l['powerProduction']) ? $l['powerProduction'] : null),
        tb_w(isset($l['accumulatedConsumption']) ? $l['accumulatedConsumption'] : null),
        tb_w(isset($l['accumulatedCost']) ? $l['accumulatedCost'] : null),
        tb_w(isset($l['currentL1']) ? $l['currentL1'] : null),
        tb_w(isset($l['currentL2']) ? $l['currentL2'] : null),
        tb_w(isset($l['currentL3']) ? $l['currentL3'] : null),
        tb_w(isset($l['voltagePhase1']) ? $l['voltagePhase1'] : null),
        tb_w(isset($l['voltagePhase2']) ? $l['voltagePhase2'] : null),
        tb_w(isset($l['voltagePhase3']) ? $l['voltagePhase3'] : null),
        tb_w(isset($l['minPower']) ? $l['minPower'] : null),
        tb_w(isset($l['maxPower']) ? $l['maxPower'] : null),
        tb_w(isset($l['averagePower']) ? $l['averagePower'] : null));
    exit;
}

/* ---------------- status ---------------- */
$tb_teile = array('TIBBER');
foreach (tb_status_felder() as $feld => $unbenutzt) {
    $tb_teile[] = $feld . '=' . tb_w($tb_werte[$feld]);
}
echo implode(';', $tb_teile) . "\n";
