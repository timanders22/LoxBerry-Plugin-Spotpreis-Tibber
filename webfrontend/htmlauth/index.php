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

/* ==================================================================
 * DER WACHPOSTEN - EINE Stelle, VOR allen Handlern
 * ==================================================================
 *
 * htmlauth schuetzt gegen den unangemeldeten Aufruf. Es schuetzt NICHT
 * dagegen, dass der Browser eines angemeldeten Bedieners ein Formular
 * abschickt, das auf einer fremden Seite steht - die hinterlegten
 * Zugangsdaten gehen dabei mit.
 *
 * Der teuerste Knopf dieses Plugins ist "Neues Merkwort erzeugen": danach
 * beantwortet der Endpunkt jeden virtuellen Eingang mit 403, und ein
 * virtueller Eingang wertet die Antwort nicht aus - der Ausfall bliebe still.
 *
 * Geprueft wird an EINER Stelle, und faellt die Pruefung durch, wird $_POST
 * bis auf den aktiven Reiter GELEERT. Das ist mit Absicht gruendlicher als
 * eine Abfrage vor jedem Handler: der naechste Handler, den jemand ergaenzt,
 * ist damit von selbst mitgeschuetzt. Ein Schutz, den man beim Erweitern
 * vergessen kann, ist keiner.
 *
 * Abgewiesen heisst GEMELDET: ein Formular, das wortlos nichts tut, schickt
 * den Anwender auf die Suche nach einem Fehler, den es nicht gibt.
 * ================================================================== */
$tb_fmt = tb_formtoken();
if ($tb_post) {
    if ($tb_fmt === '') {
        $tb_fehler[] = tb_t('EINST.WACHE_KEIN_TOKEN');
    } elseif (!tb_formtoken_ok()) {
        $tb_fehler[] = tb_t('EINST.WACHE_ABGEWIESEN');
        tb_log('Ein POST ohne gueltiges Formularmerkmal wurde abgewiesen.');
    }
    if ($tb_fehler) {
        $tb_behalten = isset($_POST['activetab']) && is_string($_POST['activetab'])
                     ? (string) $_POST['activetab'] : null;
        $_POST = array();
        if ($tb_behalten !== null) { $_POST['activetab'] = $tb_behalten; }
        $tb_post = false;
    }
}

/* Aktiver Reiter - NACH dem Wachposten.
 *
 * Stuende die Reiterwahl davor, uebernaehme sie das activetab eines
 * abgewiesenen POST, und ein fremdes Formular koennte wenigstens noch den
 * Reiter umschalten.
 *
 * Wer einen Reiter hinzufuegt, muss diese Positivliste mitziehen - sonst
 * springt die Seite nach jedem Absenden zurueck auf Einstellungen, obwohl der
 * Reiter sichtbar und anklickbar ist. Der Reiter Test prueft die drei Stellen
 * (Liste, Leiste, Bereiche) seit 0.9.7 gegeneinander. */
$tb_muster = '/^tab-(settings|fahrplan|mqtt|loxone|test|log)$/';
$tb_tab = 'tab-settings';
if (isset($_POST['activetab']) && is_string($_POST['activetab'])
    && preg_match($tb_muster, (string) $_POST['activetab'])) {
    $tb_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && is_string($_GET['form'])
          && preg_match($tb_muster, 'tab-' . (string) $_GET['form'])) {
    $tb_tab = 'tab-' . (string) $_GET['form'];
}

/* ==================================================================
 * DIE HANDLER STEHEN VOR lbheader() - DAS IST BAUVORSCHRIFT
 * ==================================================================
 *
 * Stand der Kopf davor, war er beim Aufruf von header() schon
 * geschrieben - "Cannot modify header information", und der Knopf
 * "Einstellungen sichern" lieferte eine Seite mit angehaengtem JSON
 * statt einer Datei.
 *
 * Am PHP-CLI ist das unsichtbar: header() ist dort wirkungslos und
 * headers_sent() immer falsch. Und wer OHNE gueltiges Formularmerkmal
 * misst, wird vom Wachposten abgewiesen, bevor der Handler anlaeuft.
 * Beides hat den Fehler lange verdeckt.
 *
 * Reihenfolge: Bibliothek, Konfiguration, Wachposten, Reiterwahl,
 * ALLE Handler samt Downloads, dann erst lbheader(), dann HTML.
 * ================================================================== */
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

/* ---------------- Verlauf herunterladen ----------------
 *
 * Die CSV-Dateien unter data/plugins/<ordner>/verlauf/ wurden bis 0.9.6
 * geschrieben und von NIEMANDEM gelesen - gemessen ueber den ganzen
 * Plugin-Ordner. Dazu gab es ein Eingabefeld "Verlauf aufbewahren (Tage)",
 * dessen einzige Wirkung war, wie lange ungelesene Dateien liegen bleiben.
 * Eine Datei, die niemand liest, wird nicht optimiert - sie wird entweder
 * wirksam gemacht oder gestrichen. Hier ist sie wirksam. */
if ($tb_post && isset($_POST['verlauf_holen'])) {
    /* Die Konfiguration wird HIER geholt. $tb_cfg wird erst im Laden-Block
     * weiter unten gesetzt - eine undefinierte Variable ist in PHP lautlos
     * null, und max(1, (int) null) waere 1 Tag statt der eingestellten 90
     * gewesen. Genau diese Klasse hat am 26.08.2026 zwoelf Linien einen
     * Knopf gekostet, der nichts tat. */
    $tb_vcfg = tb_config();
    $tb_reihe = tb_verlauf_lesen(max(1, (int) $tb_vcfg['verlauf_tage']));
    if (!$tb_reihe) {
        /* Bis 0.9.9 kam hier eine Datei mit 27 Byte heraus - nur die
         * Kopfzeile. Der Anwender hielt den Knopf fuer funktionierend und
         * suchte den Fehler in seinem Tabellenprogramm. */
        $tb_fehler[] = tb_t('EINST.VERLAUF_LEER');
        $tb_tab = 'tab-settings';
    } else {
    $tb_csv = "zeitpunkt;unix;ct_pro_kwh\r\n";
    foreach ($tb_reihe as $tb_e) {
        $tb_csv .= date('Y-m-d H:i:s', $tb_e['ts']) . ';' . $tb_e['ts'] . ';'
                 . number_format($tb_e['ct'], 3, ',', '') . "\r\n";
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="spotpreis_tibber_verlauf_'
           . date('Ymd_His') . '.csv"');
    echo $tb_csv;
    exit;
    }
}

/* ---------------- Einstellungen speichern ---------------- */
if ($tb_post && isset($_POST['speichern'])) {
    $tb_cfg = tb_config();
    /* Der Ausgangsstand, um einzelne Felder zurueckzunehmen. Siehe unten:
     * beanstandet wird die ZEILE, gespeichert wird alles Uebrige. */
    $tb_alt = $tb_cfg;

    /* Das Tibber-Token. Ein LEERES Feld loescht nichts - sonst stuende
     * irgendwann ein leeres Token in der Datei, ohne dass es jemand merkt.
     * Zum Loeschen gibt es einen eigenen Haken. */
    $tb_neu = isset($_POST['tibber_token']) ? trim((string) $_POST['tibber_token']) : '';
    if (isset($_POST['token_loeschen'])) {
        /* Haken UND Eingabe zusammen: das ist ein Widerspruch, kein Vorrang.
         * Bis 0.9.9 gewann der Haken stillschweigend, und das eingetippte
         * Token war weg, ohne dass irgendwo etwas stand. */
        if ($tb_neu !== '') {
            $tb_fehler[] = tb_t('EINST.TOKEN_KONFLIKT');
        } else {
            tb_token_speichern('');
            $tb_meldungen[] = tb_t('EINST.TOKEN_GELOESCHT');
        }
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

    /* Die Zuhause-Kennung wird gegen DIESELBE Funktion geprueft, die sie
     * spaeter in die GraphQL-Abfrage einsetzt. Bis 0.9.6 gab es zwei Regeln
     * fuer dieselbe Angabe - hier /^[0-9a-fA-F\-]{8,64}$/, in der Bibliothek
     * /^[A-Za-z0-9-]{1,64}$/. Zwei Wahrheiten ueber denselben Wert sind eine
     * zu viel; welche galt, entschied der Zufall des Aufrufwegs. */
    /* is_string vor jedem (string)-Cast. Ein POST mit home_id[]=x erzeugte
     * sonst "Array to string conversion" - fachlich harmlos (die Weisslisten
     * greifen), aber die Warnung steht bei display_errors=On im Seitenkopf
     * und kann ein spaeteres header() verhindern. */
    $tb_home = tb_saeubern(isset($_POST['home_id']) && is_string($_POST['home_id'])
                           ? $_POST['home_id'] : '');
    if ($tb_home !== '' && tb_gql_id($tb_home) === '') {
        $tb_fehler[] = tb_t('EINST.FEHLER_HOME');
    } else {
        $tb_cfg['home_id'] = $tb_home;
    }

    /* Die Grenzen kommen aus tb_feldregeln() - derselben Liste, gegen die
     * auch eine zurueckgespielte Sicherung geprueft wird. Standen sie hier
     * ein zweites Mal, liefe die eine Stelle irgendwann von der anderen weg,
     * und ein Wert waere im Formular abgewiesen und aus einer Datei erlaubt. */
    $tb_regeln = tb_feldregeln();
    foreach ($tb_regeln as $tb_feld => $tb_r) {
        if ($tb_r['art'] !== 'zahl') { continue; }
        $tb_wert = isset($_POST[$tb_feld]) && is_string($_POST[$tb_feld])
                 ? trim((string) $_POST[$tb_feld]) : '';
        if (!preg_match('/^[0-9]+$/', $tb_wert)) {
            $tb_fehler[] = sprintf(tb_t('EINST.FEHLER_ZAHL'), tb_t('EINST.L_' . strtoupper($tb_feld)));
            continue;
        }
        $tb_zahl = (int) $tb_wert;
        if ($tb_zahl < $tb_r['min'] || $tb_zahl > $tb_r['max']) {
            $tb_fehler[] = sprintf(tb_t('EINST.FEHLER_BEREICH'),
                tb_t('EINST.L_' . strtoupper($tb_feld)), $tb_r['min'], $tb_r['max']);
            continue;
        }
        $tb_cfg[$tb_feld] = $tb_zahl;
    }

    // Kommazahlen. Komma und Punkt sind beide erlaubt - wer 25,5 eintippt,
    // meint 25,5 und nicht einen Fehler.
    foreach ($tb_regeln as $tb_feld => $tb_r) {
        if ($tb_r['art'] !== 'komma') { continue; }
        $tb_roh = isset($_POST[$tb_feld]) && is_string($_POST[$tb_feld]) ? $_POST[$tb_feld] : '';
        $tb_wert = str_replace(',', '.', trim((string) $tb_roh));
        if (!preg_match('/^-?[0-9]{1,4}(\.[0-9]{1,3})?$/', $tb_wert)) {
            $tb_fehler[] = sprintf(tb_t('EINST.FEHLER_KOMMA'), tb_t('EINST.L_' . strtoupper($tb_feld)));
            continue;
        }
        $tb_zahl = (float) $tb_wert;
        if ($tb_zahl < $tb_r['min'] || $tb_zahl > $tb_r['max']) {
            $tb_fehler[] = sprintf(tb_t('EINST.FEHLER_BEREICH'),
                tb_t('EINST.L_' . strtoupper($tb_feld)), $tb_r['min'], $tb_r['max']);
            continue;
        }
        $tb_cfg[$tb_feld] = $tb_zahl;
    }
    if ((float) $tb_cfg['guenstig'] >= (float) $tb_cfg['teuer']) {
        $tb_fehler[] = tb_t('EINST.FEHLER_SCHWELLEN');
        /* Nur die BEIDEN Schwellen bleiben stehen, wie sie waren. Alles
         * Uebrige wird trotzdem gespeichert - sonst muesste der Anwender
         * wegen einer Zeile acht Felder neu eintippen. */
        $tb_cfg['guenstig'] = $tb_alt['guenstig'];
        $tb_cfg['teuer']    = $tb_alt['teuer'];
    }

    $tb_cfg['verbrauch_ein'] = isset($_POST['verbrauch_ein']) ? 1 : 0;
    $tb_cfg['pulse_ein']     = isset($_POST['pulse_ein']) ? 1 : 0;
    $tb_cfg['monatsbericht'] = isset($_POST['monatsbericht']) ? 1 : 0;


    /* Gespeichert wird IMMER - nicht nur, wenn nichts beanstandet wurde.
     *
     * REGELN_2, "Beanstandungen melden, nicht das ganze Speichern verhindern":
     * was sich zurechtruecken laesst, wird zurechtgerueckt, die betroffene
     * Zeile uebergangen, und alles Uebrige gespeichert. Bis 0.9.9 verwarf ein
     * einziger Tippfehler ALLE uebrigen Eingaben: der Anwender aenderte acht
     * Felder, vertippte sich in einem und musste alle acht neu eintippen.
     * Die beanstandeten Felder sind oben per continue uebersprungen worden,
     * stehen also noch auf ihrem alten Wert. */
    if (tb_config_speichern($tb_cfg)) {
        $tb_meldungen[] = $tb_fehler ? tb_t('EINST.GESPEICHERT_TEILWEISE')
                                     : tb_t('EINST.GESPEICHERT');
        tb_log($tb_fehler
            ? 'Einstellungen gespeichert; ' . count($tb_fehler) . ' Feld(er) beanstandet '
              . 'und unveraendert gelassen.'
            : 'Einstellungen gespeichert.');
    } else {
        $tb_fehler[] = sprintf(tb_t('EINST.FEHLER_SPEICHERN'), $tb_p['config']);
    }
    $tb_tab = 'tab-settings';

    /* mqtt_ein und mqtt_topic werden hier bewusst NICHT angefasst: sie wohnen im
     * Reiter MQTT und haben dort ein eigenes Formular. Die Konfiguration
     * kommt aus tb_config(), die Werte ueberleben also unveraendert. Stuende
     * hier weiter "isset($_POST['mqtt_ein']) ? 1 : 0", wuerde jedes Speichern
     * der Einstellungen MQTT stillschweigend abschalten. */
}

/* ---------------- MQTT (eigener Reiter, eigenes Formular) ----------------
 *
 * Eigenes Formular UND eigener Handler gehoeren zusammen. Loesten beide
 * Formulare denselben Handler aus, setzte dieser die Haken des jeweils
 * nicht abgeschickten Formulars per isset() auf 0 - der Benutzer verloere
 * Werte, die er nie gesehen hat. Der Handler laedt darum den Bestand und
 * ruehrt ausschliesslich die MQTT-Werte an. */
/* ---------------- Fahrplaner speichern ----------------
 *
 * Ein eigenes Formular und ein eigener Handler, so wie MQTT einen hat. Der
 * Einstellungs-Handler fasst diese Schluessel nicht an und dieser keine
 * anderen - sonst loeschte ein Druck im einen Reiter die Felder des anderen
 * und meldete "gespeichert".
 *
 * Geprueft wird gegen tb_wert_pruefen(), also gegen DIESELBE Positivliste,
 * die auch eine zurueckgespielte Sicherung durchlaufen muss. Alle
 * Beanstandungen werden gesammelt; ist auch nur eine dabei, bleibt der
 * vorherige Stand vollstaendig stehen.
 */
if ($tb_post && isset($_POST['save_fahrplan'])) {
    $tb_cfg = tb_config();
    $tb_fpneucfg = $tb_cfg;
    $tb_fpmangel = array();

    /* Die Haken zuerst: eine nicht angehakte Checkbox sendet gar nichts,
     * isset() ist hier also die Frage und nicht der Wert. */
    $tb_fpneucfg['hysterese'] = isset($_POST['hysterese']) ? 1 : 0;

    /* Die uebrigen globalen Felder ueber eine Liste, damit Feldname,
     * Pruefung und Zuweisung nicht auseinanderlaufen koennen. */
    foreach (array('budget_kw', 'budget2_kw', 'budget2_von', 'budget2_bis',
                   'pv_bonus', 'pv_schwelle', 'pv_quelle', 'pv_url', 'pv_pfad',
                   'pv_zeitfeld', 'pv_wertfeld', 'pv_einheit',
                   'soc_url', 'soc_pfad') as $tb_fpk) {
        $tb_fpv = isset($_POST[$tb_fpk]) && is_scalar($_POST[$tb_fpk])
              ? trim((string) $_POST[$tb_fpk]) : '';
        if (!tb_wert_taugt($tb_fpv)) {
            $tb_fpmangel[] = sprintf(tb_t('EINST.SICH_WERT_UNTAUGLICH'), tb_e($tb_fpk));
            continue;
        }
        $tb_fpgrund = tb_wert_pruefen($tb_fpk, $tb_fpv);
        if ($tb_fpgrund !== '') { $tb_fpmangel[] = $tb_fpgrund; continue; }
        $tb_fpneucfg[$tb_fpk] = $tb_fpv;
    }

    /* Ein Eingabefeld, das nur fuer eine Quellenart Sinn hat, wird bei den
     * anderen ABGEWIESEN und nicht uebergangen: wer von "Liste von Saetzen"
     * auf forecast.solar umstellt und Zeit- und Wertfeld stehen laesst,
     * bekaeme sonst eine Einstellung, die nirgends ankommt und trotzdem
     * dasteht. */
    if (isset($tb_fpneucfg['pv_quelle']) && $tb_fpneucfg['pv_quelle'] !== 'liste') {
        $tb_fpneucfg['pv_zeitfeld'] = '';
        $tb_fpneucfg['pv_wertfeld'] = '';
    }
    if (isset($tb_fpneucfg['pv_quelle']) && $tb_fpneucfg['pv_quelle'] === 'forecast_solar') {
        // Bei forecast.solar steht der Pfad fest - ein eigener waere eine
        // Einstellung ohne Wirkung.
        $tb_fpneucfg['pv_pfad'] = '';
    }
    if (isset($tb_fpneucfg['pv_quelle']) && $tb_fpneucfg['pv_quelle'] === ''
        && trim((string) $tb_fpneucfg['pv_url']) !== '') {
        $tb_fpmangel[] = tb_t('FP.M_URL_OHNE_QUELLE');
    }
    if (isset($tb_fpneucfg['pv_quelle']) && $tb_fpneucfg['pv_quelle'] !== ''
        && trim((string) $tb_fpneucfg['pv_url']) === '') {
        $tb_fpmangel[] = tb_t('FP.M_QUELLE_OHNE_URL');
    }

    /* Die vier Schaltregeln. Der Index ist hier ausgeschrieben und fest -
     * es gibt kein Anlegen und kein Loeschen, also kann keine Zeile
     * verrutschen. Waeren die Regeln eine wachsende Liste, muesste der
     * urspruengliche Schluessel als verstecktes Feld mitreisen. */
    $tb_fpregeln = isset($_POST['regel']) && is_array($_POST['regel'])
               ? $_POST['regel'] : array();
    $tb_fpneuregeln = array();
    for ($tb_fpi = 0; $tb_fpi < TB_REGELN; $tb_fpi++) {
        $tb_fpr = isset($tb_cfg['regeln'][$tb_fpi]) && is_array($tb_cfg['regeln'][$tb_fpi])
              ? $tb_cfg['regeln'][$tb_fpi] : tb_regel_vorgabe();
        $tb_fpe0 = isset($tb_fpregeln[$tb_fpi]) && is_array($tb_fpregeln[$tb_fpi])
               ? $tb_fpregeln[$tb_fpi] : array();
        $tb_fpr['aktiv'] = !empty($tb_fpe0['aktiv']) ? 1 : 0;
        $tb_fpr['neg']   = !empty($tb_fpe0['neg']) ? 1 : 0;
        foreach (array('name', 'art', 'n', 'von', 'bis', 'horizont', 'schwelle',
                       'prozent', 'rang', 'leistung', 'energie', 'frist',
                       'pv_sperre', 'soc_min', 'soc_max', 'min_lauf',
                       'min_pause') as $tb_fpf) {
            if (!isset($tb_fpe0[$tb_fpf]) || !is_scalar($tb_fpe0[$tb_fpf])) { continue; }
            $tb_fpr[$tb_fpf] = trim((string) $tb_fpe0[$tb_fpf]);
        }
        $tb_fpneuregeln[$tb_fpi] = $tb_fpr;
    }
    /* Beurteilt wird mit derselben Funktion, die auch die Sicherung
     * durchlaeuft - eine zweite Wahrheit ueber zulaessige Werte gibt es
     * nicht. */
    list($tb_fpgeprueft, $tb_fprmangel) = tb_regeln_pruefen($tb_fpneuregeln);
    foreach ($tb_fprmangel as $tb_fpm) { $tb_fpmangel[] = $tb_fpm; }
    if (!$tb_fprmangel) { $tb_fpneucfg['regeln'] = $tb_fpgeprueft; }

    if ($tb_fpmangel) {
        /* GAR NICHTS speichern - anders als der Einstellungs-Handler daneben,
         * der die beanstandete Zeile uebergeht und den Rest sichert.
         *
         * Der Unterschied hat einen Grund: dort sind die Felder voneinander
         * unabhaengig, hier bilden sie einen Fahrplan. Eine Regel mit
         * uebernommener Leistung und abgewiesener Frist waere kein
         * halbrichtiger Eintrag, sondern ein anderer Fahrplan - und man sieht
         * es ihm nicht an. */
        foreach ($tb_fpmangel as $tb_fpm) { $tb_fehler[] = $tb_fpm; }
    } elseif (!tb_config_lesbar()) {
        $tb_fehler[] = sprintf(tb_t('EINST.FEHLER_SPEICHERN'), $tb_p['config']);
    } elseif (tb_config_speichern($tb_fpneucfg)) {
        $tb_cfg = tb_config();
        $tb_fpan = 0;
        foreach ((array) $tb_cfg['regeln'] as $tb_fpr2) {
            if (!empty($tb_fpr2['aktiv'])) { $tb_fpan++; }
        }
        $tb_meldungen[] = tb_t('FP.GESPEICHERT');
        /* Das Protokoll bleibt einsprachig - es ist ein technisches
         * Nachschlagewerk, kein Text fuer den Bediener. */
        tb_log(sprintf('Fahrplaner gespeichert: %d von %d Regeln aktiv, Budget %s kW, '
                       . 'PV-Quelle %s, Hysterese %s.', $tb_fpan, TB_REGELN,
                       $tb_cfg['budget_kw'],
                       $tb_cfg['pv_quelle'] === '' ? 'aus' : $tb_cfg['pv_quelle'],
                       empty($tb_cfg['hysterese']) ? 'aus' : 'an'));
    } else {
        $tb_fehler[] = sprintf(tb_t('EINST.FEHLER_SPEICHERN'), $tb_p['config']);
    }
    $tb_tab = 'tab-fahrplan';
}

if ($tb_post && isset($_POST['save_mqtt'])) {
    $tb_mcfg = tb_config();
    $tb_mcfg['mqtt_ein'] = isset($_POST['mqtt_ein']) ? 1 : 0;
    $tb_mtopic = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
        (string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : '')));
    if ($tb_mtopic === '' || !preg_match('#^[A-Za-z0-9_/\-]{1,64}$#', $tb_mtopic)) {
        /* Nur das THEMA wird beanstandet. Der Haken ist fuer sich genommen
         * gueltig und bleibt erhalten - bis 0.9.9 warf ein falsch getipptes
         * Thema den frisch gesetzten Haken mit weg. */
        $tb_fehler[] = tb_t('EINST.FEHLER_TOPIC');
    } else {
        $tb_mcfg['mqtt_topic'] = trim($tb_mtopic, '/');
    }
    /* Und der Fehlschlag wird GEMELDET. Bis 0.9.9 gab es hier keinen
     * else-Zweig: die Seite kam wortlos zurueck, das Feld zeigte wieder den
     * alten Wert, und der Anwender hielt es fuer einen Bedienfehler. */
    if (tb_config_speichern($tb_mcfg)) {
        $tb_meldungen[] = $tb_fehler ? tb_t('EINST.GESPEICHERT_TEILWEISE')
                                     : tb_t('EINST.GESPEICHERT');
    } else {
        $tb_fehler[] = sprintf(tb_t('EINST.FEHLER_SPEICHERN'), $tb_p['config']);
    }
    $tb_tab = 'tab-mqtt';
}

/* ---------------- Pulse-Dienst ---------------- */
if ($tb_post && isset($_POST['dienst'])) {
    list($tb_ok, $tb_ausgabe) = tb_dienst(is_string($_POST['dienst'])
                                          ? (string) $_POST['dienst'] : '');
    if ($tb_ok) {
        $tb_meldungen[] = tb_t('EINST.DIENST_' . strtoupper(is_string($_POST['dienst'])
                                                            ? (string) $_POST['dienst'] : ''))
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
        /* Das Formularmerkmal ist aus dem Aktionstoken abgeleitet und damit
         * ebenfalls neu. Ohne diese Zeile truegen die Formulare der frisch
         * gerenderten Seite noch das alte - der naechste Klick des Anwenders
         * liefe in den Wachposten, und er saehe eine Abweisung fuer etwas,
         * das er richtig gemacht hat. */
        $tb_fmt = tb_formtoken($tb_cfg);
    } else {
        $tb_fehler[] = sprintf(tb_t('EINST.FEHLER_SPEICHERN'), $tb_p['config']);
    }
    $tb_tab = 'tab-loxone';
}

/* ---------------- Log leeren ---------------- */
if ($tb_post && isset($_POST['log_leeren'])) {
    if (!is_dir(dirname($tb_p['log']))) { @mkdir(dirname($tb_p['log']), 0775, true); }
    /* Der Rueckgabewert wird geprueft. Auf einer vollen Ramdisk oder bei
     * falschen Rechten meldete die Oberflaeche bis 0.9.9 Erfolg, waehrend
     * das Protokoll unveraendert darunter stand - eine stille Falschaussage. */
    if (@file_put_contents($tb_p['log'], '[' . date('Y-m-d H:i:s') . '] '
        . tb_t('LOG.GELEERT') . "\n") === false) {
        $tb_fehler[] = sprintf(tb_t('EINST.FEHLER_SPEICHERN'), $tb_p['log']);
    } else {
        $tb_meldungen[] = tb_t('LOG.GELEERT');
    }
    $tb_tab = 'tab-log';
}

/* ---------------- Reiter Test ---------------- */
if ($tb_post && isset($_POST['test'])) {
    list($tb_stand_erg, $tb_text) = tb_test_aktion(is_string($_POST['test'])
                                                   ? (string) $_POST['test'] : '');
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

/* ---------------- Laden ----------------
 *
 * Die Konfiguration wird VERVOLLSTAENDIGT, nicht nur beim Lesen ergaenzt:
 * fehlt ein Schluessel, wird er einmal mit seiner Vorgabe in die Datei
 * geschrieben. Danach ist "fehlt" nie mehr von "steht auf dem Vorgabewert"
 * zu unterscheiden - und eine Sicherung traegt wirklich alles. Geschrieben
 * wird nur, wenn etwas fehlte; sonst aendert sich die Datei bei jedem
 * Seitenaufruf ohne Anlass. */
list($tb_soll_n, $tb_fehlend, $tb_fremd) = tb_config_vervollstaendigen(true);

/* Erst das Merkwort, DANN die Konfiguration lesen.
 *
 * tb_aktionstoken() erzeugt ein fehlendes Merkwort und SCHREIBT es - aber in
 * seine eigene frische Kopie. Bis 0.9.9 stand die Zeile hinter
 * $tb_cfg = tb_config(), und tb_formtoken($tb_cfg) rechnete deshalb aus dem
 * VERALTETEN Stand: das Formularmerkmal war leer.
 *
 * Gemessen am 02.09.2026: eine tibber.json mit Schluesseln, aber ohne
 * aktionstoken - der Zustand jeder Anlage, die aus einer aelteren Sicherung
 * kommt oder deren Merkwort geloescht wurde - lieferte alle 15 Formulare mit
 * value="". Der erste Klick lief danach in den Wachposten ("kein gueltiges
 * Merkmal"), obwohl der Anwender alles richtig gemacht hatte; erst ein
 * Neuladen half. Bei ganz FEHLENDER Datei fiel es nicht auf, weil die
 * Selbstheilung dort ohnehin greift - deshalb hat es niemand gesehen. */
$tb_token   = tb_aktionstoken();
$tb_cfg     = tb_config();
$tb_fmt     = tb_formtoken($tb_cfg);
$tb_st      = tb_stand();
$tb_vb      = tb_verbrauch();
$tb_werte   = tb_werte();
$tb_mqtt    = tb_mqtt_zustand();
$tb_pid     = tb_dienst_pid();
$tb_alter   = tb_alter();
$tb_host    = tb_hostname();
/* Dasselbe Bauteil, das auch tb_vorlage() benutzt - zwei Stellen, die
 * dieselbe Adresse zusammensetzen, laufen auseinander. */
$tb_basis   = tb_endpunkt_basis($tb_host);
$tb_hat_token = tb_token_lesen() !== '';
$tb_bericht = tb_json_lesen($tb_p['datadir'] . '/bericht.json');
$tb_logzeilen = array();
if (is_file($tb_p['log'])) {
    $tb_logzeilen = array_slice(
        array_reverse(file($tb_p['log'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()),
        0, 400);
}

$tb_rahmen = class_exists('LBWeb', false);

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration samt BEIDEN Geheimnissen: dem
 * Aktionstoken fuer den Loxone-Endpunkt UND dem persoenlichen
 * Tibber-Zugangstoken. Ohne das zweite stuenden nach dem Zurueckspielen alle
 * Felder richtig, und das Plugin kaeme trotzdem nicht an die Anlage - die
 * Datei waere fuer ihren eigentlichen Zweck, den Umzug auf einen zweiten
 * LoxBerry, unbrauchbar. Bis 0.9.6 war genau das der Fall.
 *
 * Damit traegt sie ein Geheimnis, und der Warnhinweis am Knopf sagt das. Das
 * FORMULARMERKMAL gehoert ausdruecklich nicht hinein - es lebt eine Sitzung. */
if ($tb_post && isset($_POST['tb_sichern'])) {
    $tb_js = json_encode(tb_sicherung_bauen(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($tb_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="spotpreis_tibber_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $tb_js;
        exit;
    }
    $tb_fehler[] = tb_t('EINST.SICH_SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei des
 * Servers unterschieben. Dann die Groessengrenze - eine Sicherung dieses
 * Plugins ist wenige Kilobyte gross; alles darueber wird gar nicht gelesen. */
if ($tb_post && isset($_POST['tb_zurueck'])) {
    if (!isset($_FILES['tb_sicherung']) || !is_array($_FILES['tb_sicherung'])
        || !isset($_FILES['tb_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['tb_sicherung']['tmp_name'])) {
        $tb_fehler[] = tb_t('EINST.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['tb_sicherung']['size'] > 262144) {
        $tb_fehler[] = tb_t('EINST.SICH_ZU_GROSS');
    } else {
        list($tb_neu, $tb_mangel, $tb_n, $tb_tok) = tb_sicherung_lesen(
            (string) @file_get_contents($_FILES['tb_sicherung']['tmp_name']));
        if ($tb_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird
             * nichts. Wer nur die erste zeigt, schickt den Anwender in eine
             * Schleife aus je einem Fund pro Anlauf. */
            $tb_fehler[] = tb_t('EINST.SICH_ABGELEHNT') . ' '
                            . implode(' ', $tb_mangel);
        } elseif (tb_config_speichern($tb_neu)) {
            $tb_meldungen[] = sprintf(tb_t('EINST.SICH_UEBERNOMMEN'), $tb_n);
            if ($tb_tok !== null && $tb_tok !== '' && tb_token_speichern($tb_tok)) {
                $tb_meldungen[] = tb_t('EINST.SICH_TOKEN_UEBERNOMMEN');
            }
            tb_log('Einstellungen aus einer Sicherung zurueckgespielt: '
                   . $tb_n . ' Werte.');
            /* Nach dem Zurueckspielen wird alles NEU GELESEN.
             *
             * Die Anzeige weiter unten baut auf $tb_cfg, $tb_token und
             * $tb_werte, und die standen zu diesem Zeitpunkt schon im
             * Speicher. Ohne diese Zeilen zeigte das Formular danach die
             * ALTEN Werte - der Anwender haelt das Zurueckspielen fuer
             * wirkungslos und drueckt noch einmal. */
            /* Dieselbe Reihenfolge wie im Laden-Block: erst das Merkwort,
             * dann die Konfiguration. Eine zurueckgespielte Sicherung kann
             * ohne Merkwort kommen - dann entsteht es hier, und $tb_cfg muss
             * es schon tragen. */
            $tb_token = tb_aktionstoken();
            $tb_cfg   = tb_config();
            $tb_fmt   = tb_formtoken($tb_cfg);
            $tb_werte = tb_werte();
            $tb_hat_token = tb_token_lesen() !== '';
        } else {
            $tb_fehler[] = tb_t('EINST.SICH_SCHREIBFEHLER');
        }
    }
    $tb_tab = 'tab-settings';
}


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
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen. Wortgetreu aus
   VORLAGE_hausstandard.css.html uebernommen - bis 0.9.10 kam diese Linie ohne
   ein einziges <select> aus, deshalb fehlte der Block hier. Ein <select> ueber
   die volle Breite mit data-role="none" sieht sonst aus wie ein Textfeld: der
   eingebaute Pfeil sitzt am rechten Rand und faellt dort nicht auf.

   Die Raute im SVG wird als %23 geschrieben - eine rohe Raute beendet in einer
   CSS-Adresse den Wert. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
/* Eine breite Tabelle scrollt in ihrem eigenen Kasten, nicht die Seite.
   Wortgetreu aus VORLAGE_hausstandard.css.html; diese Linie kam bis 0.9.10
   ohne breite Tabelle aus, deshalb fehlte die Klasse. Der Fahrplan mit
   seinen vier Spalten und bis zu 48 Zeilen braucht sie. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
/* Die laufende Stunde in der Fahrplan-Vorschau. Kein neuer Farbwert: dasselbe
   Gruen wie sm-hinweis, nur als Hintergrund einer Zeile. */
.sm-tbl tr.sm-jetzt td { background: #f2f8ea; font-weight: 700; }
/* Ein Block aus Feldern, die zusammengehoeren - eine Schaltregel. Nur Rahmen
   und Abstand; alles darin sind gewoehnliche sm-feld. */
.sm-gruppe { border: 1px solid #dcdcdc; border-radius: 8px; padding: 10px 14px;
    margin: 14px 0; }
.sm-gruppe > h3 { margin: 4px 0 10px; font-size: 1.0em; }
/* Zwei bis vier Felder nebeneinander, wo sie zusammen eine Angabe bilden
   (von/bis, Mindestlauf/Mindestpause). Bricht auf schmalen Anzeigen um. */
.sm-reihe { display: flex; flex-wrap: wrap; gap: 14px; }
.sm-reihe > .sm-feld { flex: 1 1 180px; min-width: 160px; }
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
    <b class="<?= ($tb_alter >= 0 && $tb_alter < tb_altersschranke($tb_cfg)) ? 'sm-an' : 'sm-aus' ?>"><?= $tb_alter < 0 ? '&ndash;' : (int) round($tb_alter / 60) . ' min' ?></b>
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
     Reiter verlinkbar, und Eingaben in anderen Reitern gehen nicht verloren.

     Welcher Reiter offen ist, entscheidet der SERVER: sm-active steht schon im
     ausgelieferten HTML, an der Leiste UND am Bereich. Bis 0.9.6 setzte es an
     der Leiste ausschliesslich das Skript - ohne JavaScript war zwar der
     richtige Bereich offen, aber keiner der fuenf Reiter als offen markiert.
     Das Skript richtet danach nur noch die activetab-Felder aus.

     Die Leiste steht ausgeschrieben da und nicht in einer Schleife: das
     Hauswerkzeug sucht die Reiter woertlich und meldet sonst "nicht gemessen",
     was sich beim Ueberfliegen wie ein Haken einsammelt. Damit sie trotzdem
     nicht auseinanderlaufen kann, misst der Reiter Test Liste, Leiste und
     Bereiche gegeneinander. -->
<div class="sm-tabs">
	<a class="sm-tab<?= $tb_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings" href="index.php?form=settings"><?= tb_e(tb_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= $tb_tab === 'tab-fahrplan' ? ' sm-active' : '' ?>" data-ziel="tab-fahrplan" href="index.php?form=fahrplan"><?= tb_e(tb_t('REITER.FAHRPLAN')) ?></a>
	<a class="sm-tab<?= $tb_tab === 'tab-mqtt' ? ' sm-active' : '' ?>"     data-ziel="tab-mqtt"     href="index.php?form=mqtt">MQTT</a>
	<a class="sm-tab<?= $tb_tab === 'tab-loxone' ? ' sm-active' : '' ?>"   data-ziel="tab-loxone"   href="index.php?form=loxone"><?= tb_e(tb_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $tb_tab === 'tab-test' ? ' sm-active' : '' ?>"     data-ziel="tab-test"     href="index.php?form=test"><?= tb_e(tb_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $tb_tab === 'tab-log' ? ' sm-active' : '' ?>"      data-ziel="tab-log"      href="index.php?form=log"><?= tb_e(tb_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $tb_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">

<?php /* Die Legende steht OBEN im Reiter, nicht in der Mitte.
         REGELN_2: "Eine gesammelte Legende oben im Reiter, darunter folgen
         die Knopfreihen. Keine Knopfreihe ohne erklaerende Legende ueber
         sich." Bis 0.9.9 stand der orange Speichern-Knopf UEBER seiner
         Legende - der erste Knopf des Reiters war damit der einzige ohne
         Erklaerung. */ ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= tb_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= tb_t('LEGENDE.AKTION') ?></span>
</div>

<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="fmt" value="<?= tb_e($tb_fmt) ?>">
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

<?php /* MQTT stand hier bis zu dieser Fassung. Es wohnt jetzt
         vollstaendig im Reiter MQTT - eine Sache, eine Stelle. */ ?>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= tb_e(tb_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= tb_e(tb_t('EINST.H_DIENST')) ?></h2>
<p class="sm-hilfe"><?= tb_t('EINST.DIENST_ERKLAERUNG') ?></p>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= tb_e($tb_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="dienst" value="start"><?= tb_e(tb_t('EINST.K_START')) ?></button>
  </form>
  <form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= tb_e($tb_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="restart"><?= tb_e(tb_t('EINST.K_NEUSTART')) ?></button>
  </form>
  <form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= tb_e($tb_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="stop"><?= tb_e(tb_t('EINST.K_STOPP')) ?></button>
  </form>
</div>

<h2><?= tb_e(tb_t('EINST.H_SICHERUNG')) ?></h2>
<div class="sm-hinweis"><?= tb_t('EINST.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= tb_t('EINST.SICH_WARNUNG') ?></div>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= tb_e($tb_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="tb_sichern" value="1"><?= tb_e(tb_t('EINST.K_SICHERN')) ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
<input data-role="none" type="hidden" name="fmt" value="<?= tb_e($tb_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="file" name="tb_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="tb_zurueck" value="1"><?= tb_e(tb_t('EINST.K_ZURUECK')) ?></button>
  </form>
</div>
</div>

<!-- ================= Reiter: Fahrplaner ================= -->
<div class="sm-seite<?= $tb_tab === 'tab-fahrplan' ? ' sm-active' : '' ?>" id="tab-fahrplan">

<h2><?= tb_e(tb_t('FP.H_TITEL')) ?></h2>
<p><?= tb_t('FP.ERKLAERUNG') ?></p>
<p class="sm-hilfe"><?= tb_t('FP.VERFAHREN') ?></p>

<div class="sm-hinweis"><?= tb_t('FP.LOXONE_HINWEIS') ?></div>

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= tb_e($tb_fmt) ?>">
<input data-role="none" type="hidden" name="save_fahrplan" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-fahrplan">

<h3><?= tb_e(tb_t('FP.H_GLOBAL')) ?></h3>
<div class="sm-reihe">
  <div class="sm-feld">
    <label for="budget_kw"><?= tb_e(tb_t('FP.L_BUDGET')) ?></label>
    <input data-role="none" type="number" id="budget_kw" name="budget_kw" value="<?= tb_e($tb_cfg['budget_kw']) ?>" min="0" max="200" step="0.1">
    <div class="sm-hilfe"><?= tb_t('FP.H_BUDGET') ?></div>
  </div>
  <div class="sm-feld">
    <label for="pv_bonus"><?= tb_e(tb_t('FP.L_PV_BONUS')) ?></label>
    <input data-role="none" type="number" id="pv_bonus" name="pv_bonus" value="<?= tb_e($tb_cfg['pv_bonus']) ?>" min="0" max="100" step="0.1">
    <div class="sm-hilfe"><?= tb_t('FP.H_PV_BONUS') ?></div>
  </div>
  <div class="sm-feld">
    <label for="pv_schwelle"><?= tb_e(tb_t('FP.L_PV_SCHWELLE')) ?></label>
    <input data-role="none" type="number" id="pv_schwelle" name="pv_schwelle" value="<?= tb_e($tb_cfg['pv_schwelle']) ?>" min="1" max="100000" step="1">
    <div class="sm-hilfe"><?= tb_t('FP.H_PV_SCHWELLE') ?></div>
  </div>
</div>
<div class="sm-reihe">
  <div class="sm-feld">
    <label for="budget2_kw"><?= tb_e(tb_t('FP.L_BUDGET2')) ?></label>
    <input data-role="none" type="number" id="budget2_kw" name="budget2_kw" value="<?= tb_e($tb_cfg['budget2_kw']) ?>" min="0" max="200" step="0.1">
    <div class="sm-hilfe"><?= tb_t('FP.H_BUDGET2') ?></div>
  </div>
  <div class="sm-feld">
    <label for="budget2_von"><?= tb_e(tb_t('FP.L_BUDGET2_VON')) ?></label>
    <input data-role="none" type="number" id="budget2_von" name="budget2_von" value="<?= tb_e($tb_cfg['budget2_von']) ?>" min="0" max="23" step="1">
  </div>
  <div class="sm-feld">
    <label for="budget2_bis"><?= tb_e(tb_t('FP.L_BUDGET2_BIS')) ?></label>
    <input data-role="none" type="number" id="budget2_bis" name="budget2_bis" value="<?= tb_e($tb_cfg['budget2_bis']) ?>" min="0" max="23" step="1">
    <div class="sm-hilfe"><?= tb_t('FP.H_BUDGET2_ZEIT') ?></div>
  </div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="hysterese" value="1" <?= !empty($tb_cfg['hysterese']) ? 'checked' : '' ?>>
    <?= tb_e(tb_t('FP.L_HYSTERESE')) ?>
  </label>
  <div class="sm-hilfe"><?= tb_t('FP.H_HYSTERESE') ?></div>
</div>

<h3><?= tb_e(tb_t('FP.H_QUELLEN')) ?></h3>
<p class="sm-hilfe"><?= tb_t('FP.QUELLEN_ERKLAERUNG') ?></p>
<?php
/* Der zuletzt geholte Stand. tb_umwelt() OHNE Argument liest nur den
 * Zwischenspeicher - die Oberflaeche loest keinen Netzabruf aus, das tut
 * allein der Minutentakt. */
$tb_fpumw = tb_umwelt();
if (empty($tb_fpumw['ts'])) { ?>
<div class="sm-hilfe"><?= tb_t('FP.STAND_LEER') ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= sprintf(tb_t('FP.STAND'),
    $tb_fpumw['pv_summe'] === null ? '&ndash;' : tb_e(round((float) $tb_fpumw['pv_summe'], 1)),
    $tb_fpumw['soc'] === null ? '&ndash;' : tb_e((int) round((float) $tb_fpumw['soc']))) ?></div>
<?php }
if (($tb_fpumw['pv_meldung'] === 'NICHT_ERREICHBAR')
    || ($tb_fpumw['soc_meldung'] === 'NICHT_ERREICHBAR')) { ?>
<div class="sm-warnung"><?= tb_t('FP.NICHT_ERREICHBAR') ?></div>
<?php } ?>
<div class="sm-feld">
  <label for="pv_quelle"><?= tb_e(tb_t('FP.L_PV_QUELLE')) ?></label>
  <select data-role="none" id="pv_quelle" name="pv_quelle">
<?php foreach (array('' => 'FP.QUELLE_AUS', 'forecast_solar' => 'FP.QUELLE_FORECAST_SOLAR',
                     'objekt' => 'FP.QUELLE_OBJEKT', 'liste' => 'FP.QUELLE_LISTE')
               as $tb_fpw => $tb_fps) { ?>
    <option value="<?= tb_e($tb_fpw) ?>"<?= (string) $tb_cfg['pv_quelle'] === (string) $tb_fpw ? ' selected' : '' ?>><?= tb_e(tb_t($tb_fps)) ?></option>
<?php } ?>
  </select>
  <div class="sm-hilfe"><?= tb_t('FP.H_PV_QUELLE') ?></div>
</div>
<div class="sm-feld">
  <label for="pv_url"><?= tb_e(tb_t('FP.L_PV_URL')) ?></label>
  <input data-role="none" type="text" id="pv_url" name="pv_url" value="<?= tb_e($tb_cfg['pv_url']) ?>" placeholder="https://api.forecast.solar/estimate/48.1/11.6/30/0/9.9">
  <div class="sm-hilfe"><?= tb_t('FP.H_PV_URL') ?></div>
</div>
<div class="sm-reihe">
  <div class="sm-feld">
    <label for="pv_pfad"><?= tb_e(tb_t('FP.L_PV_PFAD')) ?></label>
    <input data-role="none" type="text" id="pv_pfad" name="pv_pfad" value="<?= tb_e($tb_cfg['pv_pfad']) ?>">
    <div class="sm-hilfe"><?= tb_t('FP.H_PV_PFAD') ?></div>
  </div>
  <div class="sm-feld">
    <label for="pv_zeitfeld"><?= tb_e(tb_t('FP.L_PV_ZEITFELD')) ?></label>
    <input data-role="none" type="text" id="pv_zeitfeld" name="pv_zeitfeld" value="<?= tb_e($tb_cfg['pv_zeitfeld']) ?>" placeholder="period_end">
  </div>
  <div class="sm-feld">
    <label for="pv_wertfeld"><?= tb_e(tb_t('FP.L_PV_WERTFELD')) ?></label>
    <input data-role="none" type="text" id="pv_wertfeld" name="pv_wertfeld" value="<?= tb_e($tb_cfg['pv_wertfeld']) ?>" placeholder="pv_estimate">
    <div class="sm-hilfe"><?= tb_t('FP.H_PV_FELDER') ?></div>
  </div>
</div>
<div class="sm-feld">
  <label for="pv_einheit"><?= tb_e(tb_t('FP.L_PV_EINHEIT')) ?></label>
  <select data-role="none" id="pv_einheit" name="pv_einheit">
<?php foreach (array('wh' => 'FP.EINHEIT_WH', 'w' => 'FP.EINHEIT_W',
                     'kw' => 'FP.EINHEIT_KW') as $tb_fpw => $tb_fps) { ?>
    <option value="<?= tb_e($tb_fpw) ?>"<?= (string) $tb_cfg['pv_einheit'] === (string) $tb_fpw ? ' selected' : '' ?>><?= tb_e(tb_t($tb_fps)) ?></option>
<?php } ?>
  </select>
  <div class="sm-hilfe"><?= tb_t('FP.H_PV_EINHEIT') ?></div>
</div>
<div class="sm-reihe">
  <div class="sm-feld">
    <label for="soc_url"><?= tb_e(tb_t('FP.L_SOC_URL')) ?></label>
    <input data-role="none" type="text" id="soc_url" name="soc_url" value="<?= tb_e($tb_cfg['soc_url']) ?>">
    <div class="sm-hilfe"><?= tb_t('FP.H_SOC_URL') ?></div>
  </div>
  <div class="sm-feld">
    <label for="soc_pfad"><?= tb_e(tb_t('FP.L_SOC_PFAD')) ?></label>
    <input data-role="none" type="text" id="soc_pfad" name="soc_pfad" value="<?= tb_e($tb_cfg['soc_pfad']) ?>">
    <div class="sm-hilfe"><?= tb_t('FP.H_SOC_PFAD') ?></div>
  </div>
</div>

<h3><?= tb_e(tb_t('FP.H_REGELN')) ?></h3>
<p class="sm-hilfe"><?= tb_t('FP.REGELN_ERKLAERUNG') ?></p>
<?php for ($tb_fpi = 0; $tb_fpi < TB_REGELN; $tb_fpi++) {
    $tb_fpr = isset($tb_cfg['regeln'][$tb_fpi]) && is_array($tb_cfg['regeln'][$tb_fpi])
          ? $tb_cfg['regeln'][$tb_fpi] : tb_regel_vorgabe();
    $tb_fppfx = 'regel[' . $tb_fpi . ']';
    $tb_fpid = 'r' . $tb_fpi . '_';
?>
<div class="sm-gruppe">
<h3><?= tb_e(sprintf(tb_t('FP.REGEL_N'), $tb_fpi + 1)) ?><?= $tb_fpr['name'] !== '' ? tb_e(' - ' . $tb_fpr['name']) : '' ?></h3>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="<?= $tb_fppfx ?>[aktiv]" value="1" <?= !empty($tb_fpr['aktiv']) ? 'checked' : '' ?>>
    <?= tb_e(tb_t('FP.L_AKTIV')) ?>
  </label>
  <div class="sm-hilfe"><?= tb_t('FP.H_AKTIV') ?></div>
</div>
<div class="sm-reihe">
  <div class="sm-feld">
    <label for="<?= $tb_fpid ?>name"><?= tb_e(tb_t('FP.L_NAME')) ?></label>
    <input data-role="none" type="text" id="<?= $tb_fpid ?>name" name="<?= $tb_fppfx ?>[name]" value="<?= tb_e($tb_fpr['name']) ?>" maxlength="40">
    <div class="sm-hilfe"><?= tb_t('FP.H_NAME') ?></div>
  </div>
  <div class="sm-feld">
    <label for="<?= $tb_fpid ?>art"><?= tb_e(tb_t('FP.L_ART')) ?></label>
    <select data-role="none" id="<?= $tb_fpid ?>art" name="<?= $tb_fppfx ?>[art]">
<?php   foreach (array('fenster' => 'FP.ART_FENSTER', 'stunden' => 'FP.ART_STUNDEN',
                       'schwelle' => 'FP.ART_SCHWELLE', 'mittel' => 'FP.ART_MITTEL')
                 as $tb_fpw => $tb_fps) { ?>
      <option value="<?= tb_e($tb_fpw) ?>"<?= (string) $tb_fpr['art'] === (string) $tb_fpw ? ' selected' : '' ?>><?= tb_e(tb_t($tb_fps)) ?></option>
<?php   } ?>
    </select>
  </div>
  <div class="sm-feld">
    <label for="<?= $tb_fpid ?>n"><?= tb_e(tb_t('FP.L_N')) ?></label>
    <input data-role="none" type="number" id="<?= $tb_fpid ?>n" name="<?= $tb_fppfx ?>[n]" value="<?= tb_e($tb_fpr['n']) ?>" min="1" max="12" step="1">
    <div class="sm-hilfe"><?= tb_t('FP.H_N') ?></div>
  </div>
</div>
<div class="sm-reihe">
  <div class="sm-feld">
    <label for="<?= $tb_fpid ?>von"><?= tb_e(tb_t('FP.L_VON')) ?></label>
    <input data-role="none" type="number" id="<?= $tb_fpid ?>von" name="<?= $tb_fppfx ?>[von]" value="<?= tb_e($tb_fpr['von']) ?>" min="0" max="23" step="1">
  </div>
  <div class="sm-feld">
    <label for="<?= $tb_fpid ?>bis"><?= tb_e(tb_t('FP.L_BIS')) ?></label>
    <input data-role="none" type="number" id="<?= $tb_fpid ?>bis" name="<?= $tb_fppfx ?>[bis]" value="<?= tb_e($tb_fpr['bis']) ?>" min="0" max="23" step="1">
    <div class="sm-hilfe"><?= tb_t('FP.H_ZEITFENSTER') ?></div>
  </div>
  <div class="sm-feld">
    <label for="<?= $tb_fpid ?>horizont"><?= tb_e(tb_t('FP.L_HORIZONT')) ?></label>
    <input data-role="none" type="number" id="<?= $tb_fpid ?>horizont" name="<?= $tb_fppfx ?>[horizont]" value="<?= tb_e($tb_fpr['horizont']) ?>" min="1" max="48" step="1">
    <div class="sm-hilfe"><?= tb_t('FP.H_HORIZONT') ?></div>
  </div>
</div>
<div class="sm-reihe">
  <div class="sm-feld">
    <label for="<?= $tb_fpid ?>schwelle"><?= tb_e(tb_t('FP.L_SCHWELLE')) ?></label>
    <input data-role="none" type="number" id="<?= $tb_fpid ?>schwelle" name="<?= $tb_fppfx ?>[schwelle]" value="<?= tb_e($tb_fpr['schwelle']) ?>" min="-100" max="200" step="0.1">
    <div class="sm-hilfe"><?= tb_t('FP.H_SCHWELLE') ?></div>
  </div>
  <div class="sm-feld">
    <label for="<?= $tb_fpid ?>prozent"><?= tb_e(tb_t('FP.L_PROZENT')) ?></label>
    <input data-role="none" type="number" id="<?= $tb_fpid ?>prozent" name="<?= $tb_fppfx ?>[prozent]" value="<?= tb_e($tb_fpr['prozent']) ?>" min="0" max="90" step="1">
    <div class="sm-hilfe"><?= tb_t('FP.H_PROZENT') ?></div>
  </div>
</div>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="<?= $tb_fppfx ?>[neg]" value="1" <?= !empty($tb_fpr['neg']) ? 'checked' : '' ?>>
    <?= tb_e(tb_t('FP.L_NEG')) ?>
  </label>
  <div class="sm-hilfe"><?= tb_t('FP.H_NEG') ?></div>
</div>

<h4><?= tb_e(tb_t('FP.H_PLANFELDER')) ?></h4>
<div class="sm-reihe">
  <div class="sm-feld">
    <label for="<?= $tb_fpid ?>rang"><?= tb_e(tb_t('FP.L_RANG')) ?></label>
    <input data-role="none" type="number" id="<?= $tb_fpid ?>rang" name="<?= $tb_fppfx ?>[rang]" value="<?= tb_e($tb_fpr['rang']) ?>" min="1" max="99" step="1">
    <div class="sm-hilfe"><?= tb_t('FP.H_RANG') ?></div>
  </div>
  <div class="sm-feld">
    <label for="<?= $tb_fpid ?>leistung"><?= tb_e(tb_t('FP.L_LEISTUNG')) ?></label>
    <input data-role="none" type="number" id="<?= $tb_fpid ?>leistung" name="<?= $tb_fppfx ?>[leistung]" value="<?= tb_e($tb_fpr['leistung']) ?>" min="0" max="100" step="0.1">
    <div class="sm-hilfe"><?= tb_t('FP.H_LEISTUNG') ?></div>
  </div>
  <div class="sm-feld">
    <label for="<?= $tb_fpid ?>energie"><?= tb_e(tb_t('FP.L_ENERGIE')) ?></label>
    <input data-role="none" type="number" id="<?= $tb_fpid ?>energie" name="<?= $tb_fppfx ?>[energie]" value="<?= tb_e($tb_fpr['energie']) ?>" min="0" max="500" step="0.1">
    <div class="sm-hilfe"><?= tb_t('FP.H_ENERGIE') ?></div>
  </div>
  <div class="sm-feld">
    <label for="<?= $tb_fpid ?>frist"><?= tb_e(tb_t('FP.L_FRIST')) ?></label>
    <input data-role="none" type="number" id="<?= $tb_fpid ?>frist" name="<?= $tb_fppfx ?>[frist]" value="<?= tb_e($tb_fpr['frist']) ?>" min="-1" max="23" step="1">
    <div class="sm-hilfe"><?= tb_t('FP.H_FRIST') ?></div>
  </div>
</div>
<div class="sm-reihe">
  <div class="sm-feld">
    <label for="<?= $tb_fpid ?>pv_sperre"><?= tb_e(tb_t('FP.L_PV_SPERRE')) ?></label>
    <input data-role="none" type="number" id="<?= $tb_fpid ?>pv_sperre" name="<?= $tb_fppfx ?>[pv_sperre]" value="<?= tb_e($tb_fpr['pv_sperre']) ?>" min="0" max="500" step="0.1">
    <div class="sm-hilfe"><?= tb_t('FP.H_PV_SPERRE') ?></div>
  </div>
  <div class="sm-feld">
    <label for="<?= $tb_fpid ?>soc_min"><?= tb_e(tb_t('FP.L_SOC_MIN')) ?></label>
    <input data-role="none" type="number" id="<?= $tb_fpid ?>soc_min" name="<?= $tb_fppfx ?>[soc_min]" value="<?= tb_e($tb_fpr['soc_min']) ?>" min="0" max="100" step="1">
  </div>
  <div class="sm-feld">
    <label for="<?= $tb_fpid ?>soc_max"><?= tb_e(tb_t('FP.L_SOC_MAX')) ?></label>
    <input data-role="none" type="number" id="<?= $tb_fpid ?>soc_max" name="<?= $tb_fppfx ?>[soc_max]" value="<?= tb_e($tb_fpr['soc_max']) ?>" min="0" max="100" step="1">
    <div class="sm-hilfe"><?= tb_t('FP.H_SOC_GRENZEN') ?></div>
  </div>
</div>
<div class="sm-reihe">
  <div class="sm-feld">
    <label for="<?= $tb_fpid ?>min_lauf"><?= tb_e(tb_t('FP.L_MIN_LAUF')) ?></label>
    <input data-role="none" type="number" id="<?= $tb_fpid ?>min_lauf" name="<?= $tb_fppfx ?>[min_lauf]" value="<?= tb_e($tb_fpr['min_lauf']) ?>" min="0" max="720" step="1">
  </div>
  <div class="sm-feld">
    <label for="<?= $tb_fpid ?>min_pause"><?= tb_e(tb_t('FP.L_MIN_PAUSE')) ?></label>
    <input data-role="none" type="number" id="<?= $tb_fpid ?>min_pause" name="<?= $tb_fppfx ?>[min_pause]" value="<?= tb_e($tb_fpr['min_pause']) ?>" min="0" max="720" step="1">
    <div class="sm-hilfe"><?= tb_t('FP.H_TAKTSCHUTZ') ?></div>
  </div>
</div>
</div>
<?php } ?>

<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= tb_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= tb_e(tb_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= tb_e(tb_t('FP.H_VORSCHAU')) ?></h2>
<p class="sm-hilfe"><?= tb_t('FP.VORSCHAU_ERKLAERUNG') ?></p>
<?php
$tb_fpfp = tb_fahrplan();
if (!$tb_fpfp['preise']) { ?>
<div class="sm-hilfe"><?= tb_t('FP.VORSCHAU_LEER') ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th><?= tb_e(tb_t('FP.T_REGEL')) ?></th><th><?= tb_e(tb_t('FP.T_ZUSTAND')) ?></th>
    <th><?= tb_e(tb_t('FP.T_WANN')) ?></th><th><?= tb_e(tb_t('FP.T_PREIS_SCHNITT')) ?></th></tr>
<?php foreach ($tb_fpfp['regeln'] as $tb_fpr) {
    if (empty($tb_fpr['ein'])) {
        $tb_fpzust = tb_t('FP.ZUSTAND_AUS');
    } elseif (!empty($tb_fpr['aktiv'])) {
        $tb_fpzust = tb_t('FP.ZUSTAND_LAEUFT');
    } elseif ($tb_fpr['gesperrt'] === 'pv') {
        $tb_fpzust = tb_t('FP.SPERRE_PV');
    } elseif ($tb_fpr['gesperrt'] === 'soc_min') {
        $tb_fpzust = tb_t('FP.SPERRE_SOC_MIN');
    } elseif ($tb_fpr['gesperrt'] === 'soc_max') {
        $tb_fpzust = tb_t('FP.SPERRE_SOC_MAX');
    } elseif ((int) $tb_fpr['in'] < 0) {
        $tb_fpzust = tb_t('FP.ZUSTAND_KEIN_BLOCK');
    } else {
        $tb_fpzust = tb_t('FP.ZUSTAND_WARTET');
    }
    $tb_fpwann = !empty($tb_fpr['aktiv']) ? tb_t('FP.JETZT')
             : ((int) $tb_fpr['in'] < 0 ? '&ndash;'
                : sprintf(tb_t('FP.IN_STUNDEN'), (int) $tb_fpr['in']));
?>
<tr><td><?= tb_e($tb_fpr['name']) ?></td>
    <td class="<?= !empty($tb_fpr['aktiv']) ? 'sm-an' : '' ?>"><?= tb_e($tb_fpzust) ?><?php
      if (!empty($tb_fpr['verdraengt'])) {
          echo ' <span class="sm-hilfe">' . tb_e(sprintf(tb_t('FP.VERDRAENGT'),
               (int) $tb_fpr['verdraengt'])) . '</span>';
      } ?></td>
    <td><?= $tb_fpwann ?></td>
    <td><?= $tb_fpr['ct'] === null ? '&ndash;' : tb_e($tb_fpr['ct']) ?></td></tr>
<?php } ?>
</table>

<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= tb_e(tb_t('FP.T_ZEIT')) ?></th><th><?= tb_e(tb_t('FP.T_PREIS')) ?></th>
    <th><?= tb_e(tb_t('FP.T_BELEGUNG')) ?></th><th><?= tb_e(tb_t('FP.T_REGEL')) ?></th></tr>
<?php
$tb_fpjetzt = time() - (time() % (int) $tb_fpfp['slotlen']);
foreach ($tb_fpfp['preise'] as $tb_fpts => $tb_fpct) {
    $tb_fpnamen = array();
    foreach ($tb_fpfp['plan'] as $tb_fpp2) {
        if (!empty($tb_fpp2['slots']) && in_array($tb_fpts, $tb_fpp2['slots'], true)) {
            $tb_fpnamen[] = $tb_fpp2['name'];
        }
    }
    $tb_fpkw = isset($tb_fpfp['belegung'][$tb_fpts]) ? (float) $tb_fpfp['belegung'][$tb_fpts] : 0.0;
?>
<tr<?= $tb_fpts === $tb_fpjetzt ? ' class="sm-jetzt"' : '' ?>>
    <td><span class="sm-mono"><?= tb_e(date('d.m. H:i', (int) $tb_fpts)) ?></span></td>
    <td><?= tb_e(round((float) $tb_fpct, 2)) ?></td>
    <td><?= $tb_fpkw > 0 ? tb_e($tb_fpkw) : '&ndash;' ?></td>
    <td><?= $tb_fpnamen ? tb_e(implode(', ', $tb_fpnamen)) : '&ndash;' ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $tb_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">

<h2>MQTT</h2>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= tb_e($tb_fmt) ?>">
<input data-role="none" type="hidden" name="save_mqtt" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
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
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= tb_t('LEGENDE.AKTION') ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= tb_e(tb_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
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
</table>

<h2><?= tb_e(tb_t('MQTT.H_ABO')) ?></h2>
<div class="sm-warnung"><?= tb_abo_text() ?></div>
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

<!-- EINE gesammelte Legende oben im Reiter, und sie nennt genau die Farben,
     die hier als Knopf vorkommen: grau fuer die Vorlage, orange fuer das neue
     Merkwort. Bis 0.9.6 standen zwei Legenden in diesem Reiter, jede an ihrer
     Knopfreihe - dieselbe Zeile zweimal untereinander stiftet mehr Unruhe als
     Nutzen. Eine Legende, die eine Farbe erklaert, die hier nicht vorkommt,
     waere genauso irrefuehrend wie eine fehlende. -->
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= tb_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= tb_t('LEGENDE.AKTION_TOKEN') ?></span>
</div>

<div class="sm-step"><b><?= tb_e(tb_t('LOX.S1_TITEL')) ?></b><br><?= tb_t('LOX.S1_TEXT') ?></div>

<div class="sm-step"><b><?= tb_e(tb_t('LOX.S2_TITEL')) ?></b><br>
<?= tb_t('LOX.S2_TEXT') ?>
<p><span class="sm-mono"><?= tb_e($tb_cfg['mqtt_topic']) ?>/#</span></p>
<div class="sm-warnung"><?= tb_abo_text() ?></div>
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
    <td><span class="sm-mono"><?= tb_e(tb_check($tb_feld)) ?></span></td>
    <td><?= tb_e($tb_info['einheit']) ?></td>
    <td><span class="sm-mono"><?= (int) $tb_info['min'] ?> &hellip; <?= (int) $tb_info['max'] ?></span></td>
    <td><?= tb_t($tb_info['text']) ?></td></tr>
<?php } ?>
</table>
<div class="sm-warnung"><?= tb_t('LOX.S3_STRICH') ?></div>
<div class="sm-warnung"><?= tb_t('LOX.IMPORT_WARNUNG') ?></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= tb_e($tb_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="1"><?= tb_e(tb_t('LOX.K_VORLAGE')) ?></button>
  </form>
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
<input data-role="none" type="hidden" name="fmt" value="<?= tb_e($tb_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= tb_e(tb_t('LOX.K_TOKEN_NEU')) ?></button>
  </form>
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
/**
 * Eine Zelle mit dem Suchtext eines Feldes.
 *
 * Der Suchtext stand bis 0.9.6 als Fliesstext in den Sprachdateien - achtzehn
 * Abschriften eines Musters, das der Quelltext nebenan selbst baut. Sie waren
 * damit auch die einzige Stelle, an der das fehlende Trennzeichen NICHT
 * mitkorrigiert worden waere: der Anwender schreibt die Tabelle ab, nicht den
 * Quelltext. Eine berichtigte Abschrift ist immer noch eine Abschrift.
 *
 * Jetzt steht in der Sprachdatei nur noch der Rahmen mit einem %s, und das
 * Muster kommt aus tb_check() - derselben Funktion, aus der auch die
 * Importdatei und die Feldtabelle es holen.
 *
 * Die Rueckgabe als array('text' => …) ist Absicht: die Ausgabe erkennt
 * daran, dass der Wert fertig ist, und schickt ihn NICHT noch einmal durch
 * tb_t().
 */
function tb_muster_zelle($schluessel, $feld)
{
    return array('text' => sprintf(tb_t($schluessel),
        '<span class="sm-mono">' . tb_e(tb_check($feld)) . '</span>'));
}

function tb_bausteine()
{
    return array(
        array(1,  'BAUSTEIN.T_VE',       'BAUSTEIN.N01', tb_muster_zelle('BAUSTEIN.P01', 'CUR'), '&mdash;'),
        array(2,  'BAUSTEIN.T_VE',       'BAUSTEIN.N02', tb_muster_zelle('BAUSTEIN.P02', 'LEVEL'), '&mdash;'),
        array(3,  'BAUSTEIN.T_VE',       'BAUSTEIN.N03', tb_muster_zelle('BAUSTEIN.P03', 'RANK'), '&mdash;'),
        array(4,  'BAUSTEIN.T_VE',       'BAUSTEIN.N04', tb_muster_zelle('BAUSTEIN.P04', 'FENSTER_IN'), '&mdash;'),
        array(5,  'BAUSTEIN.T_VE',       'BAUSTEIN.N05', tb_muster_zelle('BAUSTEIN.P05', 'FENSTER_CT'), '&mdash;'),
        array(6,  'BAUSTEIN.T_VE',       'BAUSTEIN.N06', tb_muster_zelle('BAUSTEIN.P06', 'MIN_HEUTE'), '&mdash;'),
        array(7,  'BAUSTEIN.T_VE',       'BAUSTEIN.N07', tb_muster_zelle('BAUSTEIN.P07', 'ALTER'), '&mdash;'),
        array(8,  'BAUSTEIN.T_VE',       'BAUSTEIN.N08', tb_muster_zelle('BAUSTEIN.P08', 'OK'), '&mdash;'),
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
        array(28, 'BAUSTEIN.T_VE',       'BAUSTEIN.N28', tb_muster_zelle('BAUSTEIN.P28', 'PULSE'), '&mdash;'),
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
    <td><?php
        /* Ein fertiger Text (aus tb_muster_zelle) wird NICHT noch einmal durch
         * tb_t() geschickt - sonst suchte die Uebersetzung einen Schluessel,
         * der ein ganzer Satz ist, und gaebe ihn unveraendert zurueck. */
        if (is_array($tb_b[3])) { echo $tb_b[3]['text']; }
        elseif ($tb_b[3] !== '') { echo tb_t($tb_b[3]); }
        else { echo '&mdash;'; }
    ?></td><td><?= $tb_b[4] ?></td></tr>
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
<?php /* Der Endpunkt beherrscht ?selftest=1 seit jeher, und der Reiter Test
         ruft ihn intern auf - in der Oberflaeche stand die Adresse aber
         nirgends. Wer nachsehen will, ob das Merkwort im Miniserver noch
         stimmt, brauchte dafuer bis 0.9.9 die README. */ ?>
<tr><td><span class="sm-mono"><?= tb_e($tb_basis) ?>?selftest=1&amp;token=<?= tb_e($tb_token) ?></span></td>
    <td><span class="sm-mono">SELFTEST;OK=1;TOKEN=OK;FASSUNG=&hellip;</span></td></tr>
</table>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $tb_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= tb_e(tb_t('TEST.H_SELBSTPRUEFUNG')) ?></h2>
<p class="sm-hilfe"><?= tb_t('TEST.EINLEITUNG') ?></p>
<table class="sm-tbl">
<tr><th style="width:36px;">&nbsp;</th><th><?= tb_e(tb_t('TEST.T_FRAGE')) ?></th><th><?= tb_e(tb_t('TEST.T_BEFUND')) ?></th></tr>
<?php
/* Die Zeilen, die etwas kosten, laufen nur, wenn dieser Reiter serverseitig
 * der offene ist - alle fuenf werden bei jedem Seitenaufbau mitgerendert. */
$tb_zeilen = tb_pruefungen($tb_tab === 'tab-test');
foreach ($tb_zeilen as $tb_z) { ?>
<tr><td style="text-align:center;"><?php
    if ($tb_z['stand'] === 1) { echo '<span class="sm-an">&#10004;</span>'; }
    elseif ($tb_z['stand'] === 0) { echo '<span class="sm-aus">&#10008;</span>'; }
    else { echo '<span style="color:#888;">&#9679;</span>'; }
?></td><td><?= $tb_z['frage'] ?></td><td><?= $tb_z['antwort'] ?></td></tr>
<?php } ?>
</table>
<?php
/* Die Bilanz nennt die Striche AUSDRUECKLICH. Ein Strich heisst "hier konnte
 * nichts gemessen werden" und sammelt sich beim Ueberfliegen wie ein Haken
 * ein - eine Zusammenfassung, die ihn verschweigt, sieht besser aus als ihr
 * schlechtester Punkt. */
$tb_bil = tb_pruef_bilanz($tb_zeilen);
?>
<p class="sm-hilfe"><?= sprintf(tb_e(tb_t('TEST.BILANZ')),
    (int) $tb_bil['haken'], count($tb_zeilen), (int) $tb_bil['kreuz'], (int) $tb_bil['strich']) ?></p>
<?php if ($tb_tab !== 'tab-test') { ?>
<div class="sm-hinweis"><?= tb_t('TEST.NUR_IM_REITER') ?></div>
<?php } ?>

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
<input data-role="none" type="hidden" name="fmt" value="<?= tb_e($tb_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="selbsttest" value="1"><?= tb_e(tb_t('TEST.K_SELBSTTEST')) ?></button>
  </form>
  <form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= tb_e($tb_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="konto"><?= tb_e(tb_t('TEST.K_KONTO')) ?></button>
  </form>
  <form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= tb_e($tb_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="preise"><?= tb_e(tb_t('TEST.K_PREISE')) ?></button>
  </form>
  <form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= tb_e($tb_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="verbrauch"><?= tb_e(tb_t('TEST.K_VERBRAUCH_HOLEN')) ?></button>
  </form>
  <a data-role="none" class="sm-btn sm-b-technik" href="<?= tb_e($tb_basis) ?>?token=<?= tb_e($tb_token) ?>&amp;aktion=json" target="_blank"><?= tb_e(tb_t('TEST.K_JSON')) ?></a>
</div>
<?php if ($tb_testausgabe !== '') { ?>
<div class="sm-pre"><?= tb_e($tb_testausgabe) ?></div>
<?php } ?>

<h3><?= tb_e(tb_t('TEST.H_VERLAUF')) ?></h3>
<?php
/* Der Verlauf. Bis 0.9.6 schrieb der Cron diese Dateien stuendlich, und
 * NIEMAND las sie - dazu gab es ein Eingabefeld fuer die Aufbewahrungsdauer,
 * dessen einzige Wirkung war, wie lange ungelesene Dateien liegen bleiben.
 * Jetzt beantworten sie die Frage, die eine feste Schwelle nicht beantworten
 * kann: ist der Preis von jetzt gemessen an den letzten Wochen guenstig? */
$tb_reihe = tb_verlauf_lesen(max(1, (int) $tb_cfg['verlauf_tage']));
list($tb_avg30, $tb_rang30, $tb_n30) = tb_verlauf_kennzahlen($tb_werte['CUR']);
?>
<p class="sm-hilfe"><?= tb_t('TEST.VERLAUF_HILFE') ?></p>
<?php if ($tb_avg30 === null) { ?>
<div class="sm-hinweis"><?= sprintf(tb_t('TEST.VERLAUF_ZU_KURZ'), (int) $tb_n30) ?></div>
<?php } else { ?>
<div class="sm-kacheln">
  <div class="sm-kachel"><?= tb_e(tb_t('TEST.K_AVG30')) ?>
    <b><?= tb_e(number_format((float) $tb_avg30, 2, ',', '.')) ?> ct</b>
    <span class="sm-hilfe"><?= sprintf(tb_e(tb_t('TEST.K_AVG30_N')), (int) $tb_n30) ?></span>
  </div>
  <div class="sm-kachel"><?= tb_e(tb_t('TEST.K_RANG30')) ?>
    <b><?= $tb_rang30 === null ? '&ndash;' : (int) $tb_rang30 . ' %' ?></b>
    <span class="sm-hilfe"><?= tb_e(tb_t('TEST.K_RANG30_H')) ?></span>
  </div>
</div>
<?php
$tb_svg = tb_verlauf_svg($tb_reihe);
if ($tb_svg !== '') { echo '<div class="sm-hinweis">' . $tb_svg . '</div>'; }
} ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= tb_e($tb_fmt) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="verlauf_holen" value="1"><?= tb_e(tb_t('TEST.K_VERLAUF')) ?></button>
  </form>
</div>

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
<input data-role="none" type="hidden" name="fmt" value="<?= tb_e($tb_fmt) ?>">
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
		/* Der Reiter Test laedt die Seite WIRKLICH neu, statt nur umzuschalten.
		   Seine teuren Zeilen - der HTTP-Aufruf des eigenen Endpunkts - laufen
		   nur, wenn er serverseitig der offene ist. Wuerde das Skript den Klick
		   auch hier abfangen, bekaeme man die Selbstpruefung nie zu sehen, ohne
		   die Seite von Hand neu zu laden. */
		if (r.dataset.ziel === 'tab-test') { return; }
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	zeige('<?= tb_e($tb_tab) ?>');
})();
</script>
<?php
if ($tb_rahmen) {
    LBWeb::lbfooter();
}
