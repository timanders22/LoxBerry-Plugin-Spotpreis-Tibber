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
$tb_soll = (string) $tb_cfg['aktionstoken'];
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
    if ($v === null || $v === '' || !is_numeric($v)) { return '-'; }
    return (string) (0 + $v);
}

$tb_st = tb_stand();
$tb_werte = tb_werte();

if ($tb_aktion === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'werte'     => $tb_werte,
        'stand'     => $tb_st,
        'verbrauch' => tb_verbrauch(),
        'pulse'     => tb_live(),
        'bericht'   => tb_json_lesen(tb_paths()['datadir'] . '/bericht.json'),
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($tb_aktion === 'stunden') {
    $heute  = isset($tb_st['liste_heute']) ? $tb_st['liste_heute'] : array();
    $morgen = isset($tb_st['liste_morgen']) ? $tb_st['liste_morgen'] : array();
    printf("STUNDEN;OK=%d;HEUTE=%d;MORGEN=%d;ALTER=%s\n",
        (int) $tb_werte['OK'], count($heute), count($morgen), tb_w($tb_werte['ALTER']));
    foreach (array('HEUTE' => $heute, 'MORGEN' => $morgen) as $tag => $liste) {
        foreach ($liste as $e) {
            printf("%s;H=%d;CT=%s;ENERGIE=%s;STEUER=%s;TLEVEL=%s\n",
                $tag, (int) date('G', $e['ts']), tb_w($e['ct']),
                tb_w($e['energie']), tb_w($e['steuer']), tb_w($e['level']));
        }
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
        printf("TAG=%s;KWH=%s;EUR=%s\n", $tag, tb_w($w['kwh']), tb_w($w['kosten']));
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
