<?php
/**
 * Spotpreis Tibber - Bedienoberflaeche
 *
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 *
 * Diese Datei ist NUR Oberflaeche. Der Abruf laeuft im Cron (bin/tb_cron.php)
 * und im Pulse-Dienst (bin/tb_pulse.php), der Miniserver spricht mit
 * webfrontend/html/index.php. Ein Plugin, das den Abruf hier erledigt, ist
 * falsch gebaut - auch wenn es funktioniert.
 *
 * Praefix 'tb_', weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Die Bibliothek liegt unter webfrontend/html/, weil Endpunkt und Dienste sie
 * ebenfalls brauchen. Der Pfad dorthin ist im installierten Zustand ein
 * anderer als im entpackten Archiv - deshalb eine Kandidatenliste und kein
 * fester Pfad. Ein fester Pfad war die Ursache des HTTP 500, das am
 * 10.08.2026 fuer Docker NG gemeldet wurde. */
$tb_gefunden = false;
foreach (array(
    dirname(dirname(__DIR__)) . '/html/plugins/' . basename(__DIR__) . '/tb_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/tb_lib.php',
    dirname(__DIR__) . '/html/tb_lib.php',
) as $tb_kandidat) {
    if (is_file($tb_kandidat)) {
        require_once $tb_kandidat;
        $tb_gefunden = true;
        break;
    }
}
if (!$tb_gefunden) {
    echo '<p><b>Fehler:</b> tb_lib.php wurde nicht gefunden. Bitte das Plugin neu installieren.</p>';
    exit;
}
require_once __DIR__ . '/tb_test.php';

$tb_p = tb_paths();
if ($tb_p['home'] !== '' && is_file($tb_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $tb_p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $tb_p['home'] . '/libs/phplib/loxberry_web.php';
}

/* Aktiver Reiter. Wer einen Reiter hinzufuegt, muss diese Positivliste
 * mitziehen - sonst springt die Seite nach jedem Absenden zurueck auf
 * Einstellungen, obwohl der Reiter sichtbar und anklickbar ist. */
$tb_muster = '/^tab-(settings|mqtt|loxone|test|log)$/';
$tb_tab = 'tab-settings';
if (isset($_POST['activetab']) && preg_match($tb_muster, (string) $_POST['activetab'])) {
    $tb_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && preg_match($tb_muster, 'tab-' . (string) $_GET['form'])) {
    $tb_tab = 'tab-' . (string) $_GET['form'];
}

$tb_meldungen = array();
$tb_fehler = array();      // gesammelt, nicht ueberschrieben
$tb_testausgabe = '';
$tb_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';

/** Nur Steuerzeichen, Anfuehrungszeichen und Leerraum entfernen. Ein hartes
 *  preg_replace auf eine Positivliste zerstoert eingefuegte Werte. */
function tb_saeubern($wert)
{
    return trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $wert));
}

/* ---------------- Loxone-Vorlage herunterladen ---------------- */
if ($tb_post && isset($_POST['vorlage'])) {
    list($tb_name, $tb_inhalt) = tb_vorlage();
    header('Content-Type: application/xml; charset=utf-8');
    // Die Anfuehrungszeichen um den Dateinamen sind Pflicht - ohne sie bricht
    // jeder Name, der ein Leerzeichen enthaelt.
    header('Content-Disposition: attachment; filename="' . $tb_name . '"');
    echo $tb_inhalt;
    exit;
}

/* ---------------- Einstellungen speichern ---------------- */
if ($tb_post && isset($_POST['speichern'])) {
    $tb_cfg = tb_config();

    /* Das Tibber-Token. Ein LEERES Feld loescht nichts - sonst stuende
     * irgendwann ein leeres Token in der Datei, ohne dass es jemand merkt.
     * Zum Loeschen gibt es einen eigenen Haken. */
    $tb_neu = isset($_POST['tibber_token']) ? trim((string) $_POST['tibber_token']) : '';
    if (isset($_POST['token_loeschen'])) {
        tb_token_speichern('');
        $tb_meldungen[] = tb_t('EINST.TOKEN_GELOESCHT');
    } elseif ($tb_neu !== '') {
        $tb_grund = tb_token_form($tb_neu);
        if ($tb_grund !== '') {
            // Die FORM wird beurteilt, damit niemand in eine Fehlermeldung von
            // Tibber laeuft. Der WERT wird nie angezeigt.
            $tb_fehler[] = tb_t($tb_grund);
        } elseif (!tb_token_speichern($tb_neu)) {
            $tb_fehler[] = sprintf(tb_t('EINST.FEHLER_SPEICHERN'), $tb_p['token']);
        } else {
            $tb_meldungen[] = tb_t('EINST.TOKEN_GESPEICHERT');
        }
    }

    $tb_home = tb_saeubern($_POST['home_id'] ?? '');
    if ($tb_home !== '' && !preg_match('/^[0-9a-fA-F\-]{8,64}$/', $tb_home)) {
        $tb_fehler[] = tb_t('EINST.FEHLER_HOME');
    } else {
        $tb_cfg['home_id'] = $tb_home;
    }

    foreach (array(
        'fensterstunden'      => array(1, 12),
        'preistakt'           => array(5, 1440),
        'verbrauchstakt'      => array(30, 1440),
        'verlauf_tage'        => array(1, 3650),
        'zeitueberschreitung' => array(5, 60),
    ) as $tb_feld => $tb_grenzen) {
        $tb_wert = isset($_POST[$tb_feld]) ? trim((string) $_POST[$tb_feld]) : '';
        if (!preg_match('/^[0-9]+$/', $tb_wert)) {
            $tb_fehler[] = sprintf(tb_t('EINST.FEHLER_ZAHL'), tb_t('EINST.L_' . strtoupper($tb_feld)));
            continue;
        }
        $tb_zahl = (int) $tb_wert;
        if ($tb_zahl < $tb_grenzen[0] || $tb_zahl > $tb_grenzen[1]) {
            $tb_fehler[] = sprintf(tb_t('EINST.FEHLER_BEREICH'),
                tb_t('EINST.L_' . strtoupper($tb_feld)), $tb_grenzen[0], $tb_grenzen[1]);
            continue;
        }
        $tb_cfg[$tb_feld] = $tb_zahl;
    }

    // Kommazahlen. Komma und Punkt sind beide erlaubt - wer 25,5 eintippt,
    // meint 25,5 und nicht einen Fehler.
    foreach (array(
        'aufschlag'  => array(-50, 50),
        'guenstig'   => array(-50, 200),
        'teuer'      => array(-50, 200),
        'festpreis'  => array(0, 200),
        'grundpreis' => array(0, 500),
    ) as $tb_feld => $tb_grenzen) {
        $tb_wert = str_replace(',', '.', trim((string) ($_POST[$tb_feld] ?? '')));
        if (!preg_match('/^-?[0-9]{1,4}(\.[0-9]{1,3})?$/', $tb_wert)) {
            $tb_fehler[] = sprintf(tb_t('EINST.FEHLER_KOMMA'), tb_t('EINST.L_' . strtoupper($tb_feld)));
            continue;
        }
        $tb_zahl = (float) $tb_wert;
        if ($tb_zahl < $tb_grenzen[0] || $tb_zahl > $tb_grenzen[1]) {
            $tb_fehler[] = sprintf(tb_t('EINST.FEHLER_BEREICH'),
                tb_t('EINST.L_' . strtoupper($tb_feld)), $tb_grenzen[0], $tb_grenzen[1]);
            continue;
        }
        $tb_cfg[$tb_feld] = $tb_zahl;
    }
    if ((float) $tb_cfg['guenstig'] >= (float) $tb_cfg['teuer']) {
        $tb_fehler[] = tb_t('EINST.FEHLER_SCHWELLEN');
    }

    $tb_cfg['verbrauch_ein'] = isset($_POST['verbrauch_ein']) ? 1 : 0;
    $tb_cfg['pulse_ein']     = isset($_POST['pulse_ein']) ? 1 : 0;
    $tb_cfg['mqtt_ein']      = isset($_POST['mqtt_ein']) ? 1 : 0;
    $tb_cfg['monatsbericht'] = isset($_POST['monatsbericht']) ? 1 : 0;

    $tb_topic = tb_saeubern($_POST['mqtt_topic'] ?? '');
    if ($tb_topic === '' || !preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $tb_topic)) {
        $tb_fehler[] = tb_t('EINST.FEHLER_TOPIC');
    } else {
        $tb_cfg['mqtt_topic'] = trim($tb_topic, '/');
    }

    if (!$tb_fehler) {
        if (tb_config_speichern($tb_cfg)) {
            $tb_meldungen[] = tb_t('EINST.GESPEICHERT');
            tb_log('Einstellungen gespeichert.');
        } else {
            $tb_fehler[] = sprintf(tb_t('EINST.FEHLER_SPEICHERN'), $tb_p['config']);
        }
    }
    $tb_tab = 'tab-settings';
}

/* ---------------- Pulse-Dienst ---------------- */
if ($tb_post && isset($_POST['dienst'])) {
    list($tb_ok, $tb_ausgabe) = tb_dienst((string) $_POST['dienst']);
    if ($tb_ok) {
        $tb_meldungen[] = tb_t('EINST.DIENST_' . strtoupper((string) $_POST['dienst']))
                        . ' ' . tb_e($tb_ausgabe);
    } else {
        $tb_fehler[] = tb_e($tb_ausgabe);
    }
    $tb_tab = 'tab-settings';
}

/* ---------------- Neues Aktionstoken ---------------- */
if ($tb_post && isset($_POST['token_neu'])) {
    $tb_cfg = tb_config();
    $tb_cfg['aktionstoken'] = tb_aktionstoken_erzeugen();
    if (tb_config_speichern($tb_cfg)) {
        $tb_meldungen[] = tb_t('LOX.TOKEN_NEU');
        tb_log('Neues Merkwort fuer den Endpunkt gewuerfelt.');
    } else {
        $tb_fehler[] = sprintf(tb_t('EINST.FEHLER_SPEICHERN'), $tb_p['config']);
    }
    $tb_tab = 'tab-loxone';
}

/* ---------------- Log leeren ---------------- */
if ($tb_post && isset($_POST['log_leeren'])) {
    @mkdir(dirname($tb_p['log']), 0775, true);
    @file_put_contents($tb_p['log'], '[' . date('Y-m-d H:i:s') . '] ' . tb_t('LOG.GELEERT') . "\n");
    $tb_meldungen[] = tb_t('LOG.GELEERT');
    $tb_tab = 'tab-log';
}

/* ---------------- Reiter Test ---------------- */
if ($tb_post && isset($_POST['test'])) {
    list($tb_stand_erg, $tb_text) = tb_test_aktion((string) $_POST['test']);
    if ($tb_stand_erg === 1) {
        $tb_meldungen[] = tb_e($tb_text);
    } else {
        $tb_fehler[] = tb_e($tb_text);
    }
    $tb_tab = 'tab-test';
}
if ($tb_post && isset($_POST['selbsttest'])) {
    $tb_testausgabe = tb_selbsttest_ausgabe();
    $tb_tab = 'tab-test';
}

/* ---------------- Laden ---------------- */
$tb_cfg     = tb_config();
$tb_token   = tb_aktionstoken();
$tb_st      = tb_stand();
$tb_vb      = tb_verbrauch();
$tb_werte   = tb_werte();
$tb_mqtt    = tb_mqtt_zustand();
$tb_pid     = tb_dienst_pid();
$tb_alter   = tb_alter();
$tb_host    = tb_hostname();
$tb_basis   = 'http://' . $tb_host . '/plugins/' . $tb_p['plugin'] . '/index.php';
$tb_hat_token = tb_token_lesen() !== '';
$tb_bericht = tb_json_lesen($tb_p['datadir'] . '/bericht.json');
$tb_logzeilen = array();
if (is_file($tb_p['log'])) {
    $tb_logzeilen = array_slice(
        array_reverse(file($tb_p['log'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()),
        0, 400);
}

$tb_rahmen = class_exists('LBWeb', false);
if ($tb_rahmen) {
    LBWeb::lbheader('Spotpreis Tibber', 'https://wiki.loxberry.de/', 'help.html');
}
?>
<style>
/* Hausstandard, wortgetreu aus VORLAGE_hausstandard.css.html uebernommen.
   Nicht neu erfinden: der Knopf-Fehler vom 30.07.2026 steckte in sieben
   Plugins gleichzeitig, weil jedes seine eigene Kopie hatte. */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; white-space: pre-wrap; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-fehler { border: 1px solid #ef9a9a; background: #ffebee; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, "Courier New", monospace;
    font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto;
    white-space: pre-wrap; }
</style>
<div class="sm-wrap">

<?php foreach ($tb_meldungen as $tb_m) { ?>
<div class="sm-hinweis"><?= $tb_m ?></div>
<?php } ?>
<?php if ($tb_fehler) { ?>
<div class="sm-fehler"><b><?= tb_e(tb_t('ALLG.BEANSTANDUNG')) ?></b>
<ul style="margin:6px 0 0 18px;padding:0;">
<?php foreach ($tb_fehler as $tb_f) { ?><li><?= $tb_f ?></li><?php } ?>
</ul></div>
<?php } ?>

<?php if (!$tb_hat_token) { ?>
<div class="sm-warnung"><?= tb_t('EINST.KEIN_TOKEN_HINWEIS') ?></div>
<?php } ?>

<!-- ================= Statuskacheln ================= -->
<div class="sm-kacheln">
  <div class="sm-kachel"><?= tb_e(tb_t('ALLG.JETZT')) ?>
    <b><?= $tb_werte['CUR'] === null ? '&ndash;' : tb_e(number_format((float) $tb_werte['CUR'], 2, ',', '.')) . ' ct' ?></b>
    <span class="sm-hilfe"><?php
      $tb_niv = $tb_werte['LEVEL'];
      echo $tb_niv === null ? '&ndash;' : tb_e(tb_t('ALLG.NIVEAU_' . (int) $tb_niv));
    ?></span>
  </div>
  <div class="sm-kachel"><?= tb_e(tb_t('ALLG.RANG')) ?>
    <b><?= $tb_werte['RANK'] === null ? '&ndash;' : (int) $tb_werte['RANK'] ?></b>
    <span class="sm-hilfe"><?= sprintf(tb_e(tb_t('ALLG.VON_N')), (int) $tb_werte['RANKD']) ?></span>
  </div>
  <div class="sm-kachel"><?= sprintf(tb_e(tb_t('ALLG.FENSTER')), (int) $tb_cfg['fensterstunden']) ?>
    <b><?= $tb_werte['FENSTER_H'] === null ? '&ndash;' : sprintf('%02d', (int) $tb_werte['FENSTER_H']) . ':00' ?></b>
    <span class="sm-hilfe"><?= $tb_werte['FENSTER_CT'] === null ? '&ndash;'
        : tb_e(number_format((float) $tb_werte['FENSTER_CT'], 2, ',', '.')) . ' ct' ?></span>
  </div>
  <div class="sm-kachel"><?= tb_e(tb_t('ALLG.LETZTER_ABRUF')) ?>
    <b class="<?= ($tb_alter >= 0 && $tb_alter < 7200) ? 'sm-an' : 'sm-aus' ?>"><?= $tb_alter < 0 ? '&ndash;' : (int) round($tb_alter / 60) . ' min' ?></b>
    <span class="sm-hilfe"><?= $tb_alter < 0 ? tb_e(tb_t('ALLG.NIE')) : tb_e(date('d.m.Y H:i', time() - $tb_alter)) ?></span>
  </div>
  <div class="sm-kachel">Pulse
    <b class="<?= ($tb_werte['PULSE'] !== null) ? 'sm-an' : 'sm-aus' ?>"><?= $tb_werte['PULSE'] === null ? '&ndash;' : (int) $tb_werte['PULSE'] . ' W' ?></b>
    <span class="sm-hilfe"><?= empty($tb_cfg['pulse_ein']) ? tb_e(tb_t('ALLG.AUS'))
        : ($tb_pid ? 'PID ' . (int) $tb_pid : tb_e(tb_t('ALLG.GESTOPPT'))) ?></span>
  </div>
  <div class="sm-kachel">MQTT
    <b class="<?= $tb_mqtt['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= $tb_mqtt['autostart'] ? tb_e(tb_t('ALLG.EIN')) : tb_e(tb_t('ALLG.AUS')) ?></b>
    <span class="sm-hilfe"><?= tb_e(tb_t('ALLG.GATEWAY')) ?></span>
  </div>
</div>

<?php if (!empty($tb_st['fehler'])) { ?>
<div class="sm-warnung"><b><?= tb_e(tb_t('ALLG.LETZTE_STOERUNG')) ?></b> <?= tb_e($tb_st['fehler']) ?></div>
<?php } ?>

<?php
$tb_lh = isset($tb_st['liste_heute']) ? $tb_st['liste_heute'] : array();
$tb_lm = isset($tb_st['liste_morgen']) ? $tb_st['liste_morgen'] : array();
if ($tb_lh || $tb_lm) { ?>
<div class="sm-hinweis">
<?= tb_preis_svg($tb_lh, $tb_lm) ?>
<div class="sm-hilfe"><?= tb_t('ALLG.DIAGRAMM_HINWEIS') ?></div>
</div>
<?php } ?>

<!-- Reiterleiste: echte Links, JavaScript faengt den Klick ab. So bleibt jeder
     Reiter verlinkbar, Eingaben in anderen Reitern gehen nicht verloren, und
     faellt das Skript aus, ist die Seite weiterhin bedienbar. -->
<div class="sm-tabs">
	<a class="sm-tab" data-ziel="tab-settings" href="index.php?form=settings"><?= tb_e(tb_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab" data-ziel="tab-mqtt"     href="index.php?form=mqtt">MQTT</a>
	<a class="sm-tab" data-ziel="tab-loxone"   href="index.php?form=loxone"><?= tb_e(tb_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab" data-ziel="tab-test"     href="index.php?form=test"><?= tb_e(tb_t('REITER.TEST')) ?></a>
	<a class="sm-tab" data-ziel="tab-log"      href="index.php?form=log"><?= tb_e(tb_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $tb_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">

<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="speichern" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?= tb_e(tb_t('EINST.H_ZUGANG')) ?></h2>
<div class="sm-hinweis"><?= tb_t('EINST.ZUGANG_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label for="tibber_token"><?= tb_e(tb_t('EINST.L_TOKEN')) ?></label>
  <input data-role="none" type="password" id="tibber_token" name="tibber_token" value=""
         placeholder="<?= $tb_hat_token ? tb_e(sprintf(tb_t('EINST.TOKEN_GESETZT'), strlen(tb_token_lesen()))) : tb_e(tb_t('EINST.TOKEN_LEER')) ?>">
  <div class="sm-hilfe"><?= tb_t('EINST.H_TOKEN') ?></div>
</div>
<?php if ($tb_hat_token) { ?>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="token_loeschen" value="1">
    <?= tb_e(tb_t('EINST.L_TOKEN_LOESCHEN')) ?>
  </label>
</div>
<?php } ?>
<div class="sm-feld">
  <label for="home_id"><?= tb_e(tb_t('EINST.L_HOME_ID')) ?></label>
  <input data-role="none" type="text" id="home_id" name="home_id" value="<?= tb_e($tb_cfg['home_id']) ?>">
  <div class="sm-hilfe"><?= tb_t('EINST.H_HOME_ID') ?></div>
</div>

<h2><?= tb_e(tb_t('EINST.H_PREIS')) ?></h2>
<div class="sm-hinweis"><?= tb_t('EINST.PREIS_ERKLAERUNG') ?></div>
<?php
$tb_zahlfelder = array(
    'aufschlag'      => array('number', '-50', '50', '0.001'),
    'guenstig'       => array('number', '-50', '200', '0.01'),
    'teuer'          => array('number', '-50', '200', '0.01'),
    'fensterstunden' => array('number', '1', '12', '1'),
);
foreach ($tb_zahlfelder as $tb_f => $tb_a) { ?>
<div class="sm-feld">
  <label for="<?= $tb_f ?>"><?= tb_e(tb_t('EINST.L_' . strtoupper($tb_f))) ?></label>
  <input data-role="none" type="<?= $tb_a[0] ?>" id="<?= $tb_f ?>" name="<?= $tb_f ?>"
         value="<?= tb_e($tb_cfg[$tb_f]) ?>" min="<?= $tb_a[1] ?>" max="<?= $tb_a[2] ?>" step="<?= $tb_a[3] ?>">
  <div class="sm-hilfe"><?= tb_t('EINST.H_' . strtoupper($tb_f)) ?></div>
</div>
<?php } ?>

<h2><?= tb_e(tb_t('EINST.H_VERBRAUCH')) ?></h2>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="verbrauch_ein" value="1" <?= !empty($tb_cfg['verbrauch_ein']) ? 'checked' : '' ?>>
    <?= tb_e(tb_t('EINST.L_VERBRAUCH_EIN')) ?>
  </label>
  <div class="sm-hilfe"><?= tb_t('EINST.H_VERBRAUCH_EIN') ?></div>
</div>
<div class="sm-feld">
  <label for="festpreis"><?= tb_e(tb_t('EINST.L_FESTPREIS')) ?></label>
  <input data-role="none" type="number" id="festpreis" name="festpreis" value="<?= tb_e($tb_cfg['festpreis']) ?>" min="0" max="200" step="0.01">
  <div class="sm-hilfe"><?= tb_t('EINST.H_FESTPREIS') ?></div>
</div>
<div class="sm-feld">
  <label for="grundpreis"><?= tb_e(tb_t('EINST.L_GRUNDPREIS')) ?></label>
  <input data-role="none" type="number" id="grundpreis" name="grundpreis" value="<?= tb_e($tb_cfg['grundpreis']) ?>" min="0" max="500" step="0.01">
  <div class="sm-hilfe"><?= tb_t('EINST.H_GRUNDPREIS') ?></div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="monatsbericht" value="1" <?= !empty($tb_cfg['monatsbericht']) ? 'checked' : '' ?>>
    <?= tb_e(tb_t('EINST.L_MONATSBERICHT')) ?>
  </label>
  <div class="sm-hilfe"><?= tb_t('EINST.H_MONATSBERICHT') ?></div>
</div>

<h2><?= tb_e(tb_t('EINST.H_PULSE')) ?></h2>
<div class="sm-hinweis"><?= tb_t('EINST.PULSE_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="pulse_ein" value="1" <?= !empty($tb_cfg['pulse_ein']) ? 'checked' : '' ?>>
    <?= tb_e(tb_t('EINST.L_PULSE_EIN')) ?>
  </label>
</div>

<h2><?= tb_e(tb_t('EINST.H_TAKT')) ?></h2>
<?php foreach (array('preistakt' => array(5, 1440), 'verbrauchstakt' => array(30, 1440),
                     'zeitueberschreitung' => array(5, 60), 'verlauf_tage' => array(1, 3650)) as $tb_f => $tb_g) { ?>
<div class="sm-feld">
  <label for="<?= $tb_f ?>"><?= tb_e(tb_t('EINST.L_' . strtoupper($tb_f))) ?></label>
  <input data-role="none" type="number" id="<?= $tb_f ?>" name="<?= $tb_f ?>"
         value="<?= (int) $tb_cfg[$tb_f] ?>" min="<?= $tb_g[0] ?>" max="<?= $tb_g[1] ?>">
  <div class="sm-hilfe"><?= tb_t('EINST.H_' . strtoupper($tb_f)) ?></div>
</div>
<?php } ?>

<h2>MQTT</h2>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="mqtt_ein" value="1" <?= !empty($tb_cfg['mqtt_ein']) ? 'checked' : '' ?>>
    <?= tb_e(tb_t('EINST.L_MQTT_EIN')) ?>
  </label>
</div>
<div class="sm-feld">
  <label for="mqtt_topic"><?= tb_e(tb_t('EINST.L_MQTT_TOPIC')) ?></label>
  <input data-role="none" type="text" id="mqtt_topic" name="mqtt_topic" value="<?= tb_e($tb_cfg['mqtt_topic']) ?>" placeholder="tibber">
</div>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= tb_e(tb_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= tb_e(tb_t('EINST.H_DIENST')) ?></h2>
<p class="sm-hilfe"><?= tb_t('EINST.DIENST_ERKLAERUNG') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= tb_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= tb_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="dienst" value="start"><?= tb_e(tb_t('EINST.K_START')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="restart"><?= tb_e(tb_t('EINST.K_NEUSTART')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="stop"><?= tb_e(tb_t('EINST.K_STOPP')) ?></button>
  </form>
</div>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $tb_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<h2><?= tb_e(tb_t('MQTT.H_ZUSTAND')) ?></h2>
<p class="sm-hilfe"><?= tb_t('MQTT.GATEWAY_ERKLAERUNG') ?></p>
<?php if (!$tb_mqtt['gefunden']) { ?>
<div class="sm-fehler"><?= tb_t('MQTT.NICHT_GEFUNDEN') ?></div>
<?php } elseif (!$tb_mqtt['autostart']) { ?>
<div class="sm-fehler"><?= tb_t('MQTT.AUTOSTART_AUS') ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= tb_t('MQTT.AUTOSTART_EIN') ?></div>
<?php } ?>
<table class="sm-tbl">
<tr><th><?= tb_e(tb_t('ALLG.EIGENSCHAFT')) ?></th><th><?= tb_e(tb_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= tb_e(tb_t('MQTT.T_AUTOSTART')) ?></td><td class="<?= $tb_mqtt['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= $tb_mqtt['autostart'] ? tb_e(tb_t('ALLG.EIN')) : tb_e(tb_t('ALLG.AUS')) ?></td></tr>
<tr><td><?= tb_e(tb_t('MQTT.T_BROKER')) ?></td><td><span class="sm-mono"><?= tb_e($tb_mqtt['broker']) ?>:<?= tb_e($tb_mqtt['brokerport']) ?></span></td></tr>
<tr><td><?= tb_e(tb_t('MQTT.T_UDP')) ?></td><td><span class="sm-mono"><?= (int) $tb_mqtt['udpport'] ?></span></td></tr>
<tr><td><?= tb_e(tb_t('MQTT.T_PLUGIN')) ?></td><td class="<?= !empty($tb_cfg['mqtt_ein']) ? 'sm-an' : 'sm-aus' ?>"><?= !empty($tb_cfg['mqtt_ein']) ? tb_e(tb_t('ALLG.EIN')) : tb_e(tb_t('ALLG.AUS')) ?></td></tr>
<tr><td><?= tb_e(tb_t('MQTT.T_SOCKETS')) ?></td><td class="<?= extension_loaded('sockets') ? 'sm-an' : 'sm-aus' ?>"><?= extension_loaded('sockets') ? tb_e(tb_t('ALLG.JA')) : tb_e(tb_t('ALLG.NEIN')) ?></td></tr>
</table>

<h2><?= tb_e(tb_t('MQTT.H_ABO')) ?></h2>
<div class="sm-warnung"><?= tb_t('MQTT.ABO_WARNUNG') ?></div>
<div class="sm-step"><?= tb_t('MQTT.ABO_SCHRITTE') ?>
<p><span class="sm-mono"><?= tb_e($tb_cfg['mqtt_topic']) ?>/#</span></p>
</div>

<h2><?= tb_e(tb_t('MQTT.H_THEMEN')) ?></h2>
<p class="sm-hilfe"><?= tb_t('MQTT.THEMEN_ERKLAERUNG') ?></p>
<table class="sm-tbl">
<tr><th><?= tb_e(tb_t('MQTT.T_THEMA')) ?></th><th><?= tb_e(tb_t('MQTT.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (tb_mqtt_themen() as $tb_thema => $tb_schluessel) { ?>
<tr><td><span class="sm-mono"><?= tb_e($tb_cfg['mqtt_topic'] . '/' . $tb_thema) ?></span></td>
    <td><?= tb_t($tb_schluessel) ?></td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= tb_t('MQTT.PLATZHALTER') ?></p>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $tb_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= tb_e(tb_t('LOX.H_TITEL')) ?></h2>
<p><?= tb_t('LOX.EINLEITUNG') ?></p>

<div class="sm-step"><b><?= tb_e(tb_t('LOX.S1_TITEL')) ?></b><br><?= tb_t('LOX.S1_TEXT') ?></div>

<div class="sm-step"><b><?= tb_e(tb_t('LOX.S2_TITEL')) ?></b><br>
<?= tb_t('LOX.S2_TEXT') ?>
<p><span class="sm-mono"><?= tb_e($tb_cfg['mqtt_topic']) ?>/#</span></p>
<div class="sm-warnung"><?= tb_t('LOX.S2_WARNUNG') ?></div>
</div>

<div class="sm-step"><b><?= tb_e(tb_t('LOX.S3_TITEL')) ?></b><br>
<?= tb_t('LOX.S3_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= tb_e(tb_t('ALLG.EIGENSCHAFT')) ?></th><th><?= tb_e(tb_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= tb_e(tb_t('LOX.T_ADRESSE')) ?></td>
    <td><span class="sm-mono"><?= tb_e($tb_basis) ?>?token=<?= tb_e($tb_token) ?>&amp;aktion=status</span></td></tr>
<tr><td><?= tb_e(tb_t('LOX.T_ZYKLUS')) ?></td><td>300 <?= tb_e(tb_t('ALLG.SEKUNDEN')) ?></td></tr>
</table>
<div class="sm-warnung"><?= tb_t('LOX.ADRESSE_VORSCHLAG') ?></div>
<?= tb_t('LOX.S3_BEFEHLE') ?>
<table class="sm-tbl">
<tr><th><?= tb_e(tb_t('LOX.T_TITEL')) ?></th><th><?= tb_e(tb_t('LOX.T_BEFEHL')) ?></th>
    <th><?= tb_e(tb_t('LOX.T_EINHEIT')) ?></th><th><?= tb_e(tb_t('LOX.T_GRENZEN')) ?></th>
    <th><?= tb_e(tb_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (tb_status_felder() as $tb_feld => $tb_info) { ?>
<tr><td><span class="sm-mono">TIBBER_<?= tb_e($tb_feld) ?></span></td>
    <td><span class="sm-mono">\i<?= tb_e($tb_feld) ?>=\i\v</span></td>
    <td><?= tb_e($tb_info[0]) ?></td>
    <td><span class="sm-mono"><?= (int) $tb_info[2] ?> &hellip; <?= (int) $tb_info[3] ?></span></td>
    <td><?= tb_t($tb_info[1]) ?></td></tr>
<?php } ?>
</table>
<div class="sm-warnung"><?= tb_t('LOX.S3_STRICH') ?></div>
<div class="sm-warnung"><?= tb_t('LOX.IMPORT_WARNUNG') ?></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="1"><?= tb_e(tb_t('LOX.K_VORLAGE')) ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= tb_t('LEGENDE.TECHNIK') ?></span>
</div>
</div>

<div class="sm-step"><b><?= tb_e(tb_t('LOX.S4_TITEL')) ?></b><br>
<?= tb_t('LOX.S4_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= tb_e(tb_t('LOX.T_ADRESSE')) ?></th><th><?= tb_e(tb_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (array('status' => 'LOX.EP_STATUS', 'stunden' => 'LOX.EP_STUNDEN',
                     'verbrauch' => 'LOX.EP_VERBRAUCH', 'pulse' => 'LOX.EP_PULSE',
                     'json' => 'LOX.EP_JSON') as $tb_a => $tb_s) { ?>
<tr><td><span class="sm-mono"><?= tb_e($tb_basis) ?>?token=<?= tb_e($tb_token) ?>&amp;aktion=<?= $tb_a ?></span></td>
    <td><?= tb_t($tb_s) ?></td></tr>
<?php } ?>
</table>
<table class="sm-tbl">
<tr><td><?= tb_e(tb_t('LOX.T_TOKEN')) ?></td><td><span class="sm-mono"><?= tb_e($tb_token) ?></span></td></tr>
</table>
<?= tb_t('LOX.S4_TOKEN') ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= tb_e(tb_t('LOX.K_TOKEN_NEU')) ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= tb_t('LEGENDE.AKTION_TOKEN') ?></span>
</div>
</div>

<div class="sm-step"><b><?= tb_e(tb_t('LOX.S5_TITEL')) ?></b><br><?= tb_t('LOX.S5_TEXT') ?></div>

<?php
/**
 * Die komplette Baustein-Liste. Pflicht im Hausstandard.
 *
 * Anspruch: Wer die Tabelle von oben nach unten abarbeitet, hat die Funktion
 * nachgebaut, ohne nachzudenken. Loxone Config fuehrt alle Bausteine in der
 * Baustein-Suche (F5).
 */
function tb_bausteine()
{
    return array(
        array(1,  'BAUSTEIN.T_VE',       'BAUSTEIN.N01', 'BAUSTEIN.P01', '&mdash;'),
        array(2,  'BAUSTEIN.T_VE',       'BAUSTEIN.N02', 'BAUSTEIN.P02', '&mdash;'),
        array(3,  'BAUSTEIN.T_VE',       'BAUSTEIN.N03', 'BAUSTEIN.P03', '&mdash;'),
        array(4,  'BAUSTEIN.T_VE',       'BAUSTEIN.N04', 'BAUSTEIN.P04', '&mdash;'),
        array(5,  'BAUSTEIN.T_VE',       'BAUSTEIN.N05', 'BAUSTEIN.P05', '&mdash;'),
        array(6,  'BAUSTEIN.T_VE',       'BAUSTEIN.N06', 'BAUSTEIN.P06', '&mdash;'),
        array(7,  'BAUSTEIN.T_VE',       'BAUSTEIN.N07', 'BAUSTEIN.P07', '&mdash;'),
        array(8,  'BAUSTEIN.T_VE',       'BAUSTEIN.N08', 'BAUSTEIN.P08', '&mdash;'),
        array(9,  'BAUSTEIN.T_SWS',      'BAUSTEIN.N09', 'BAUSTEIN.P09', 'I &larr; #7'),
        array(10, 'BAUSTEIN.T_NICHT',    'BAUSTEIN.N10', '',             'I &larr; #8'),
        array(11, 'BAUSTEIN.T_ODER',     'BAUSTEIN.N11', '',             'I1 &larr; #9, I2 &larr; #10'),
        array(12, 'BAUSTEIN.T_EVZ',      'BAUSTEIN.N12', 'BAUSTEIN.P12', 'I &larr; #11'),
        array(13, 'BAUSTEIN.T_BENACHR',  'BAUSTEIN.N13', 'BAUSTEIN.P13', 'I &larr; #12'),
        array(14, 'BAUSTEIN.T_VEZ',      'BAUSTEIN.N14', 'BAUSTEIN.P14', '&mdash;'),
        array(15, 'BAUSTEIN.T_VERGL',    'BAUSTEIN.N15', 'BAUSTEIN.P15', 'I1 &larr; #1, I2 &larr; #14'),
        array(16, 'BAUSTEIN.T_VEZ',      'BAUSTEIN.N16', 'BAUSTEIN.P16', '&mdash;'),
        array(17, 'BAUSTEIN.T_VERGL',    'BAUSTEIN.N17', 'BAUSTEIN.P17', 'I1 &larr; #3, I2 &larr; #16'),
        array(18, 'BAUSTEIN.T_ODER',     'BAUSTEIN.N18', '',             'I1 &larr; #15, I2 &larr; #17'),
        array(19, 'BAUSTEIN.T_TASTER',   'BAUSTEIN.N19', 'BAUSTEIN.P19', '&mdash;'),
        array(20, 'BAUSTEIN.T_UND',      'BAUSTEIN.N20', '',             'I1 &larr; #18, I2 &larr; #19, I3 &larr; #10 (negiert)'),
        array(21, 'BAUSTEIN.T_EVZ',      'BAUSTEIN.N21', 'BAUSTEIN.P21', 'I &larr; #20'),
        array(22, 'BAUSTEIN.T_MERKER',   'BAUSTEIN.N22', 'BAUSTEIN.P22', 'I &larr; #21'),
        array(23, 'BAUSTEIN.T_VERGL',    'BAUSTEIN.N23', 'BAUSTEIN.P23', 'I1 &larr; #4, I2 &larr; ' . tb_t('BAUSTEIN.KONST0')),
        array(24, 'BAUSTEIN.T_IMPULS',   'BAUSTEIN.N24', 'BAUSTEIN.P24', 'I &larr; #23'),
        array(25, 'BAUSTEIN.T_BENACHR',  'BAUSTEIN.N25', 'BAUSTEIN.P25', 'I &larr; #24'),
        array(26, 'BAUSTEIN.T_SWS',      'BAUSTEIN.N26', 'BAUSTEIN.P26', 'I &larr; #2'),
        array(27, 'BAUSTEIN.T_STATUS',   'BAUSTEIN.N27', 'BAUSTEIN.P27', 'I1 &larr; #1, I2 &larr; #2'),
        array(28, 'BAUSTEIN.T_VE',       'BAUSTEIN.N28', 'BAUSTEIN.P28', '&mdash;'),
        array(29, 'BAUSTEIN.T_FORMEL',   'BAUSTEIN.N29', 'BAUSTEIN.P29', 'I1 &larr; #28, I2 &larr; #1'),
        array(30, 'BAUSTEIN.T_STAT',     'BAUSTEIN.N30', 'BAUSTEIN.P30', 'I &larr; #29'),
    );
}
?>

<div class="sm-step"><b><?= tb_e(tb_t('LOX.S6_TITEL')) ?></b><br>
<?= tb_t('LOX.S6_TEXT') ?>
<table class="sm-tbl">
<tr><th>#</th><th><?= tb_e(tb_t('LOX.T_BAUSTEIN')) ?></th><th><?= tb_e(tb_t('LOX.T_NAMENSVORSCHLAG')) ?></th>
    <th><?= tb_e(tb_t('LOX.T_PARAMETER')) ?></th><th><?= tb_e(tb_t('LOX.T_EINGAENGE')) ?></th></tr>
<?php foreach (tb_bausteine() as $tb_b) { ?>
<tr><td><?= (int) $tb_b[0] ?></td><td><?= tb_t($tb_b[1]) ?></td><td><?= tb_t($tb_b[2]) ?></td>
    <td><?= $tb_b[3] !== '' ? tb_t($tb_b[3]) : '&mdash;' ?></td><td><?= $tb_b[4] ?></td></tr>
<?php } ?>
</table>
<?= tb_t('LOX.S6_ERLAEUTERUNG') ?>
</div>

<div class="sm-step"><b><?= tb_e(tb_t('LOX.S7_TITEL')) ?></b><br>
<?= tb_t('LOX.S7_TEXT') ?>
<table class="sm-tbl">
<tr><th><?= tb_e(tb_t('LOX.T_PRUEFUNG')) ?></th><th><?= tb_e(tb_t('LOX.T_ERWARTUNG')) ?></th></tr>
<tr><td><span class="sm-mono"><?= tb_e($tb_basis) ?>?token=<?= tb_e($tb_token) ?>&amp;aktion=status</span></td>
    <td><span class="sm-mono">TIBBER;CUR=...;OK=1</span></td></tr>
<tr><td><span class="sm-mono"><?= tb_e($tb_basis) ?>?aktion=status</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=TOKEN</span> (HTTP 403)</td></tr>
<tr><td><span class="sm-mono"><?= tb_e($tb_basis) ?>?token=<?= tb_e($tb_token) ?>&amp;aktion=quatsch</span></td>
    <td><span class="sm-mono">FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION</span> (HTTP 400)</td></tr>
</table>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $tb_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= tb_e(tb_t('TEST.H_SELBSTPRUEFUNG')) ?></h2>
<p class="sm-hilfe"><?= tb_t('TEST.EINLEITUNG') ?></p>
<table class="sm-tbl">
<tr><th style="width:36px;">&nbsp;</th><th><?= tb_e(tb_t('TEST.T_FRAGE')) ?></th><th><?= tb_e(tb_t('TEST.T_BEFUND')) ?></th></tr>
<?php foreach (tb_pruefungen() as $tb_z) { ?>
<tr><td style="text-align:center;"><?php
    if ($tb_z['stand'] === 1) { echo '<span class="sm-an">&#10004;</span>'; }
    elseif ($tb_z['stand'] === 0) { echo '<span class="sm-aus">&#10008;</span>'; }
    else { echo '<span style="color:#888;">&#9679;</span>'; }
?></td><td><?= $tb_z['frage'] ?></td><td><?= $tb_z['antwort'] ?></td></tr>
<?php } ?>
</table>

<?php if ($tb_bericht && isset($tb_bericht['text'])) { ?>
<h3><?= tb_e(tb_t('TEST.H_BERICHT')) ?></h3>
<div class="sm-hinweis"><?= tb_e($tb_bericht['text']) ?></div>
<?php } ?>

<?php if (isset($tb_vb['tage']) && $tb_vb['tage']) { ?>
<h3><?= tb_e(tb_t('TEST.H_VERBRAUCH')) ?></h3>
<table class="sm-tbl">
<tr><th><?= tb_e(tb_t('TEST.T_TAG')) ?></th><th><?= tb_e(tb_t('TEST.T_KWH')) ?></th><th><?= tb_e(tb_t('TEST.T_EUR')) ?></th><th><?= tb_e(tb_t('TEST.T_SCHNITT')) ?></th></tr>
<?php
$tb_liste = array_reverse($tb_vb['tage'], true);
$tb_i = 0;
foreach ($tb_liste as $tb_tag => $tb_w) {
    if ($tb_i++ >= 14) { break; }
    $tb_ct = ($tb_w['kwh'] > 0.01 && $tb_w['kosten'] !== null)
           ? round($tb_w['kosten'] / $tb_w['kwh'] * 100, 2) : null; ?>
<tr><td><?= tb_e($tb_tag) ?></td>
    <td><?= $tb_w['kwh'] === null ? '&ndash;' : tb_e(number_format((float) $tb_w['kwh'], 2, ',', '.')) ?></td>
    <td><?= $tb_w['kosten'] === null ? '&ndash;' : tb_e(number_format((float) $tb_w['kosten'], 2, ',', '.')) ?></td>
    <td><?= $tb_ct === null ? '&ndash;' : tb_e(number_format($tb_ct, 2, ',', '.')) . ' ct' ?></td></tr>
<?php } ?>
</table>
<div class="sm-hilfe"><?= tb_t('TEST.VERBRAUCH_HILFE') ?></div>
<?php } ?>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= tb_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= tb_t('LEGENDE.TECHNIK') ?></span>
</div>

<h3><?= tb_e(tb_t('TEST.H_LESEN')) ?></h3>
<div class="sm-knopfreihe">
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= tb_e($tb_basis) ?>?token=<?= tb_e($tb_token) ?>&amp;aktion=status" target="_blank"><?= tb_e(tb_t('TEST.K_STATUS')) ?></a>
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= tb_e($tb_basis) ?>?token=<?= tb_e($tb_token) ?>&amp;aktion=stunden" target="_blank"><?= tb_e(tb_t('TEST.K_STUNDEN')) ?></a>
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= tb_e($tb_basis) ?>?token=<?= tb_e($tb_token) ?>&amp;aktion=verbrauch" target="_blank"><?= tb_e(tb_t('TEST.K_VERBRAUCH')) ?></a>
  <a data-role="none" class="sm-btn sm-b-lesen" href="<?= tb_e($tb_basis) ?>?token=<?= tb_e($tb_token) ?>&amp;aktion=pulse" target="_blank"><?= tb_e(tb_t('TEST.K_PULSE')) ?></a>
</div>

<h3><?= tb_e(tb_t('TEST.H_TECHNIK')) ?></h3>
<p class="sm-hilfe"><?= tb_t('TEST.TECHNIK_ERKLAERUNG') ?></p>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="selbsttest" value="1"><?= tb_e(tb_t('TEST.K_SELBSTTEST')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="konto"><?= tb_e(tb_t('TEST.K_KONTO')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="preise"><?= tb_e(tb_t('TEST.K_PREISE')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="verbrauch"><?= tb_e(tb_t('TEST.K_VERBRAUCH_HOLEN')) ?></button>
  </form>
  <a data-role="none" class="sm-btn sm-b-technik" href="<?= tb_e($tb_basis) ?>?token=<?= tb_e($tb_token) ?>&amp;aktion=json" target="_blank"><?= tb_e(tb_t('TEST.K_JSON')) ?></a>
</div>
<?php if ($tb_testausgabe !== '') { ?>
<div class="sm-pre"><?= tb_e($tb_testausgabe) ?></div>
<?php } ?>

<div class="sm-warnung"><b><?= tb_e(tb_t('TEST.H_UNGEPRUEFT')) ?></b><br><?= tb_t('TEST.UNGEPRUEFT') ?></div>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $tb_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= tb_e(tb_t('LOG.H_TITEL')) ?></h2>
<div class="sm-warnung"><?= tb_t('LOG.RAMDISK') ?></div>
<p class="sm-hilfe"><?= tb_t('LOG.ERKLAERUNG') ?><br>
<span class="sm-mono"><?= tb_e($tb_p['log']) ?></span></p>
<?php if ($tb_logzeilen) { ?>
<div class="sm-log"><?= tb_e(implode("\n", $tb_logzeilen)) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= tb_t('LOG.LEER') ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= tb_t('LEGENDE.AKTION_LOG') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="log_leeren" value="1"><?= tb_e(tb_t('LOG.K_LEEREN')) ?></button>
  </form>
</div>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	zeige(<?= json_encode($tb_tab) ?>);
})();
</script>
<?php
if ($tb_rahmen) {
    LBWeb::lbfooter();
}
