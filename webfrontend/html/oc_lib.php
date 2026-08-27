<?php
/**
 * Octopus Dynamic - gemeinsame Bibliothek
 *
 * Holt die Viertelstundenpreise des Tarifs dynamicOctopus ueber die
 * Kraken-Schnittstelle (GraphQL) und liefert:
 *   - Endpreis der laufenden Viertelstunde und der laufenden Stunde
 *   - guenstigste/teuerste Zeit heute und morgen, Rang der laufenden
 *     Viertelstunde, Preisniveau, guenstigstes zusammenhaengendes Fenster
 *   - Zustand als JSON, Werte ueber das MQTT-Gateway von LoxBerry
 *   - Ansage (TTS) und Push-Freigabe, je Stunde einzeln schaltbar
 *   - CO2-Intensitaet des Strommixes, Vergleich fester/dynamischer Tarif
 *
 * WICHTIG - die Preisrechnung:
 * Octopus liefert mit "latestGrossUnitRateCentsPerKwh" bereits den fertigen
 * BRUTTO-Arbeitspreis in ct/kWh. Es wird deshalb NICHTS aufgeschlagen: keine
 * Netzentgelte, keine Umlagen, keine Umsatzsteuer. Ein eigener Aufschlagsrechner
 * waere hier eine Fehlerquelle ohne Nutzen.
 *
 * Zugangsdaten stehen in einer eigenen Datei mit Rechten 0600, nicht in der
 * Konfiguration, die die Oberflaeche anzeigt.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set('Europe/Berlin');

/** Schnittstelle laut Octopus-Anleitung (Stand 05.08.2026). */
define('OC_API', 'https://api.oeg-kraken.energy/v1/graphql/');

/* ==================================================================
 * Pfade, Protokoll
 * ================================================================== */

/**
 * Alle Pfade. Der Pluginordner wird aus dem Ablageort DIESER Datei
 * abgeleitet - nicht aus der Plugindatenbank. Deren MD5-Schluessel haengt
 * an Autor, E-Mail und Plugin-Name und aendert sich bei jedem Fork.
 */
/* Der Fahrplaner. Eigene Datei daneben, byteweise gleich mit der im
 * Spotpreis-aWATTar-Plugin - Frist, Rangfolge, Leistungsbudget und
 * PV-Gutschrift stecken dort. Naeheres im Kopf von planer.php. */
require_once __DIR__ . '/planer.php';


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

function oc_paths()
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
    }
    $ordner = basename(dirname(__FILE__));   // installiert: .../html/plugins/<ordner>
    if ($ordner === 'html' || $ordner === '') {
        $ordner = 'octopus';                 // Archiv: .../webfrontend/html
    }
    if ($home && is_dir($home)) {
        $p = array(
            'home'    => $home,
            'plugin'  => $ordner,
            'config'  => $home . '/config/plugins/' . $ordner . '/octopus.json',
            'backup'  => $home . '/config/plugins/' . $ordner . '.backup.json',
            'zugang'  => $home . '/config/plugins/' . $ordner . '/zugang.json',
            'data'    => $home . '/data/plugins/' . $ordner,
            'log'     => $home . '/log/plugins/' . $ordner . '/octopus.log',
            'general' => $home . '/config/system/general.json',
            'tmp'     => '/tmp/' . $ordner,
        );
        return $p;
    }
    // Kein LoxBerry gefunden (Entwicklung, Pruefstand)
    $wurzel = dirname(dirname(__DIR__));
    $tmp = sys_get_temp_dir() . '/octopus';
    $p = array(
        'home'    => '',
        'plugin'  => $ordner,
        'config'  => $tmp . '/octopus.json',
        'backup'  => $tmp . '/octopus.backup.json',
        'zugang'  => $tmp . '/zugang.json',
        'data'    => $tmp . '/data',
        'log'     => $tmp . '/octopus.log',
        'general' => $wurzel . '/general.json',
        'tmp'     => $tmp,
    );
    return $p;
}

function oc_tmpdir()
{
    $d = oc_paths()['tmp'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    return $d;
}

function oc_datadir()
{
    $d = oc_paths()['data'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    return $d;
}

function oc_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * Eine Datei wegraeumen - aber nur, wenn es sie gibt.
 *
 * DAS @ GENUEGT NICHT. Ist ein eigener Fehlerbehandler gesetzt - der
 * Hauspruefstand tut das -, wird er unabhaengig von error_reporting
 * gerufen, und "unlink(...): No such file or directory" steht als Befund
 * im Protokoll, obwohl gar nichts fehlt. Im Haus zweimal hineingelaufen,
 * einmal bei mkdir() und einmal hier.
 */
function oc_weg($f)
{
    if ($f !== '' && is_file($f)) { @unlink($f); }
}


/**
 * Protokollzeile. Bewusst ohne Umlaute: die Datei wird auch ueber die
 * Konsole gelesen, und dort ist die Zeichensatzlage unklar.
 */
/**
 * Das Protokoll auf einen Inhalt setzen - Leeren und Kuerzen laufen beide
 * hier durch.
 *
 * WARUM MIT SPERRE
 * Das Anhaengen in oc_log() geht mit FILE_APPEND, also mit O_APPEND: der
 * Kern setzt vor jedem Schreiben ans tatsaechliche Dateiende. Ein
 * gleichzeitiges Kuerzen kann deshalb keine Zeile ZERREISSEN - nachgemessen
 * mit vier Sekunden gleichzeitigem Anhaengen und Leeren: 0 unbrauchbare
 * Zeilen, in beiden Varianten. Die Sorge um eine "kaputte" Logdatei ist
 * unbegruendet.
 *
 * Verlieren kann man eine Zeile trotzdem: Wer zwischen dem Lesen des
 * Endstuecks und dem Zurueckschreiben anhaengt, schreibt in eine Datei, die
 * gleich ueberschrieben wird. Beim Kuerzen einer 512-kB-Datei ist dieses
 * Fenster nicht winzig. flock() schliesst es - und kostet nichts.
 *
 * ftruncate statt file_put_contents: So bleibt es dieselbe Datei mit
 * derselben Inode. Wer sie gerade offen hat, schreibt weiter hinein statt
 * in eine geloeschte Leiche.
 */
function oc_log_setzen($f, $inhalt)
{
    $fp = @fopen($f, 'c+');
    if (!$fp) {
        return false;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $inhalt);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function oc_log($msg)
{
    $f = oc_paths()['log'];
    $dir = dirname($f);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > 512000) {
        $tail = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: array(), -200);
        oc_log_setzen($f, implode("\n", $tail) . "\n");
    }
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

/**
 * Schreibt nur, wenn sich die Zeile geaendert hat. Ohne diese Bremse
 * schreibt der minuetliche Cron dieselbe Meldung 1440 mal am Tag.
 */
function oc_log_if_changed($key, $line)
{
    $f = oc_tmpdir() . '/last_' . preg_replace('/[^a-z0-9_]/', '', $key) . '.txt';
    $prev = is_file($f) ? (string) file_get_contents($f) : '';
    if ($line !== $prev) {
        oc_log($key . ': ' . $line);
        @file_put_contents($f, $line);
    }
}

/* ==================================================================
 * Konfiguration
 * ================================================================== */

function oc_vorgaben()
{
    return array(
        // Betrieb
        'enabled'       => 1,
        'demo'          => 0,      // 1 = ohne Octopus-Vertrag mit Boersendaten rechnen
        'demo_aufschlag' => 15.0,  // ct/kWh netto, NUR fuer den Demo-Modus
        'demo_vat'      => 19.0,   // %, NUR fuer den Demo-Modus
        'aktionstoken'  => '',
        // Bewertung
        'cheap'         => 20.0,   // Schwelle "guenstig" in ct/kWh brutto
        'expensive'     => 35.0,   // Schwelle "teuer" in ct/kWh brutto
        'window'        => 3,      // Laenge des gesuchten guenstigsten Fensters in Stunden
        // Schaltregeln (ab 0.9.1): je Regel EIN fertiges 0/1-Signal. Bis 0.9.0
        // lieferte das Plugin nur Zahlen - Startzeit, Minuten bis dahin,
        // Durchschnittspreis. Siehe oc_regel_werte().
        'regeln'        => array(),
        // Stundenprofil fuer den Spot Price Optimizer von Loxone:
        //   aus | absolut (PH00-PH23) | relativ (PR00-PR23) | beides
        // Der Baustein hat nur 24 Preiseingaenge - die Viertelstunden werden
        // dafuer stundenweise gemittelt. Das ist kein Verlust an Genauigkeit
        // fuer den Baustein, sondern die einzige Form, die er annimmt.
        'profil_ein'    => 'aus',
        // CO2
        'co2_enabled'   => 1,
        'co2_clean'     => 200,    // Schwelle "sauber" in g CO2/kWh
        // Vergleich fester Tarif gegen dynamischen Tarif
        'fixed_price'   => 30.90,  // eigener fester Arbeitspreis ct/kWh brutto
        'fix_grund'     => 12.90,  // Grundpreis des festen Tarifs EUR/Monat
        'dyn_grund'     => 0.0,    // Grundpreis des Octopus-Tarifs EUR/Monat
        'fix_sofortbonus' => 0.0,
        'fix_neubonus'  => 0.0,
        'fix_neubonus_pct' => 0.0,
        'fix_rabatt'    => 0.0,
        'consumption'   => 3500,   // Jahresverbrauch kWh
        'months'        => array(),// Netzbezug je Monat in kWh (12 Werte, 0 = nicht gepflegt)
        'shift_kwh'     => 3.0,    // taeglich verschiebbare Menge in kWh
        // MQTT
        'mqtt_enabled'  => 1,
        'mqtt_topic'    => 'octopus',
        // Meldungen
        'notify'        => array(),
        'tts'           => array(),
        // Fahrplaner (ab 1.0.0): Leistungsbudget und PV-Gutschrift.
        // Vorgabe 0 heisst jeweils "aus".
        'pv_quelle'     => '',     // '' | forecast_solar | objekt | liste
        'pv_url'        => '',
        'pv_pfad'       => '',
        'pv_zeitfeld'   => '',
        'pv_wertfeld'   => '',
        'pv_einheit'    => 'wh',   // wh | w | kw
        'soc_url'       => '',
        'soc_pfad'      => '',
        /* Ab 1.1.0: der ECHTE Verbrauch statt des erfundenen Haushaltsprofils.
         *
         * Ohne diese Angabe gewichtet der Kostenvergleich mit einem
         * vereinfachten H0-Profil - das ist eine Abschaetzung und wird auch
         * so genannt. Wer eine Adresse hinterlegt, die den Verbrauch je
         * Stunde liefert, bekommt statt der Abschaetzung eine Messung.
         * Dieselben drei Formen wie bei der PV-Prognose, damit niemand eine
         * zweite Schreibweise lernen muss. */
        'verbrauch_quelle'   => '',    // '' | objekt | liste
        'verbrauch_url'      => '',
        'verbrauch_pfad'     => '',
        'verbrauch_zeitfeld' => '',
        'verbrauch_wertfeld' => '',
        'verbrauch_einheit'  => 'wh',  // wh | w | kw
        /* Ab 1.1.0: Hysterese. Ein begonnener Block laeuft bis zu seinem
         * Ende, auch wenn die neue Preisreihe inzwischen etwas Billigeres
         * kennt. Ab Werk AN - ohne sie schaltet die Wallbox bei jedem
         * Abruf um, und das ist kein Zustand, den jemand absichtlich will.
         * Wer den alten Stand braucht, schaltet sie ab. */
        'hysterese'     => 1,
    ) + plan_global_vorgabe();
}

/* ------------------------------------------------------------------
 * Drei Umformer, die JEDEN Wert derselben Behandlung unterziehen
 *
 * Sie stehen hier, weil oc_config() sie braucht - und oc_config() braucht
 * sie, seit die Konfiguration auch aus einer zurueckgespielten
 * Sicherungsdatei kommen kann. Bis 1.0.9 kappte nur das Formular; wer eine
 * Datei von Hand baute, schrieb ungeprueft in die Konfiguration.
 *
 * Ein Feld statt einer Zahl ergibt hier den Vorgabewert und keine Meldung -
 * die Meldung macht oc_sicherung_lesen() beim EINLESEN. Hier geht es nur
 * darum, dass die Rechnung danach mit Zahlen rechnet.
 * ------------------------------------------------------------------ */

/** Kommazahl in Schranken; alles Unbrauchbare wird zum Vorgabewert. */
function oc_zahl($v, $vorgabe, $min, $max)
{
    if (is_array($v) || is_bool($v) || $v === null) { return (float) $vorgabe; }
    $s = str_replace(',', '.', trim((string) $v));
    if (!is_numeric($s)) { return (float) $vorgabe; }
    return max((float) $min, min((float) $max, (float) $s));
}

/** Ganze Zahl in Schranken. */
function oc_ganz($v, $vorgabe, $min, $max)
{
    if (is_array($v) || is_bool($v) || $v === null) { return (int) $vorgabe; }
    $s = trim((string) $v);
    if (!preg_match('/^-?[0-9]+$/', $s)) { return (int) $vorgabe; }
    return (int) max((int) $min, min((int) $max, (int) $s));
}

/**
 * Text ohne Steuerzeichen, gekappt.
 *
 * NICHT hart gefiltert: eine Positivliste zerstoert gueltige Eingaben -
 * Adressen, Vorlagen, Namen. Entfernt werden Steuerzeichen (die zerlegen
 * die UDP-Zeile an den MQTT-Gateway) und die Anfuehrungszeichen, die den
 * Sprachdateien und dem XML-Export zu schaffen machen.
 */
function oc_text($v, $max = 500)
{
    if (is_array($v) || is_bool($v) || $v === null) { return ''; }
    $s = preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $v);
    $s = trim(preg_replace('/ {2,}/', ' ', (string) $s));
    return function_exists('mb_substr')
        ? mb_substr($s, 0, (int) $max, 'UTF-8') : substr($s, 0, (int) $max);
}

/**
 * Ein MQTT-Thema oder -Praefix unschaedlich machen.
 *
 * DAS GEGENSTUECK ZU oc_mqtt_wert_saeubern(), UND ES HAT GEFEHLT.
 *
 * Der Wert wurde seit jeher gesaeubert, das Thema nie - das Formular
 * filterte es, der Rueckspielweg nicht. Gemessen mit einer Sicherungsdatei,
 * die als Praefix "octopus/x publish fremd/thema 1\nboese" trug:
 *
 *     Zeile 1: publish octopus/x publish fremd/thema 1
 *     Zeile 2: boese/cur 12.3
 *
 * Der Gateway liest zeilenweise und hat daraus ein erfundenes Thema
 * gebildet. Ein Praefix darf deshalb nur enthalten, was ein Thema sein
 * darf: Buchstaben, Ziffern, Unterstrich, Bindestrich und Schraegstrich.
 */
function oc_mqtt_thema_saeubern($v, $vorgabe = 'octopus')
{
    $s = preg_replace('#[^A-Za-z0-9_/-]#', '', (string) (is_array($v) ? '' : $v));
    $s = trim((string) $s, '/ ');
    return $s === '' ? $vorgabe : substr($s, 0, 64);
}

function oc_config()
{
    $p = oc_paths();
    // Selbstheilung: fehlende oder leere Konfiguration aus der Sicherung holen
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    if (($roh === '' || $roh === '{}') && is_file($p['backup'])) {
        /* is_dir() VOR mkdir(). Das @ genuegt nicht: ist ein eigener
         * Fehlerbehandler gesetzt - der Hauspruefstand tut das -, wird er
         * unabhaengig von error_reporting gerufen, und "mkdir(): File
         * exists" steht als Befund im Protokoll, obwohl nichts fehlt. */
        if (!is_dir(dirname($p['config']))) {
            @mkdir(dirname($p['config']), 0775, true);
        }
        @copy($p['backup'], $p['config']);
        $roh = trim((string) @file_get_contents($p['config']));
    }
    $cfg = $roh !== '' ? json_decode($roh, true) : array();
    if (!is_array($cfg)) { $cfg = array(); }
    $cfg += oc_vorgaben();

    if (!is_array($cfg['notify'])) { $cfg['notify'] = array(); }
    $cfg['notify'] += array(
        'audio'      => 0,
        'push'       => 0,
        'hours'      => array(),   // Stunden 0-23 mit Ansage/Push
        'only_cheap' => 0,         // nur melden, wenn unter der Schwelle "guenstig"
        'negative'   => 1,         // zusaetzlich immer bei negativem Nettopreis
        'tomorrow'   => 0,         // Meldung, sobald die Preise fuer morgen da sind
        /* Ab 1.1.0: die Benachrichtigung des LoxBerry selbst (die Glocke in
         * der Kopfzeile). Bis dahin gab es nur MQTT-Themen, die erst in
         * Loxone verdrahtet werden mussten - wer das nicht getan hat,
         * merkte einen Ausfall gar nicht. */
        'lb'         => 1,         // Glocke bei Ausfall und "Preise fuer morgen"
        'lb_stunden' => 6,         // ab wie vielen Stunden ohne Abruf gemeldet wird
    );
    if (!is_array($cfg['notify']['hours'])) { $cfg['notify']['hours'] = array(); }
    foreach (array('audio', 'push', 'only_cheap', 'negative', 'tomorrow', 'lb') as $nk) {
        $cfg['notify'][$nk] = empty($cfg['notify'][$nk]) ? 0 : 1;
    }
    $cfg['notify']['lb_stunden'] = oc_ganz($cfg['notify']['lb_stunden'], 6, 1, 72);
    $std = array();
    foreach ((array) $cfg['notify']['hours'] as $h) {
        if (is_array($h)) { continue; }
        $h = (int) $h;
        if ($h >= 0 && $h <= 23 && !in_array($h, $std, true)) { $std[] = $h; }
    }
    sort($std);
    $cfg['notify']['hours'] = $std;

    if (!is_array($cfg['tts'])) { $cfg['tts'] = array(); }
    $cfg['tts'] += array('mode' => 'musicserver', 'ip' => '', 'port' => 7091,
                         'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => '');
    /* Auch die Ansage-Angaben kommen aus der Sicherungsdatei, wenn eine
     * zurueckgespielt wurde. Die Vorlage traegt eine Adresse - eine
     * ungeprueft uebernommene schickte den Ansagetext an einen fremden
     * Rechner. */
    if (!in_array($cfg['tts']['mode'], array('musicserver', 'ms4h', 'audioserver', 'custom'), true)) {
        $cfg['tts']['mode'] = 'musicserver';
    }
    $cfg['tts']['ip'] = oc_text($cfg['tts']['ip'], 100);
    if ($cfg['tts']['ip'] !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $cfg['tts']['ip'])) {
        $cfg['tts']['ip'] = '';
    }
    $cfg['tts']['port']   = oc_ganz($cfg['tts']['port'], 7091, 1, 65535);
    $cfg['tts']['volume'] = oc_ganz($cfg['tts']['volume'], 8, 1, 100);
    $cfg['tts']['zones']  = trim(preg_replace('/[^0-9,~ ]/', '',
        is_array($cfg['tts']['zones']) ? '' : (string) $cfg['tts']['zones']));
    if ($cfg['tts']['zones'] === '') { $cfg['tts']['zones'] = '1'; }
    $cfg['tts']['lang'] = preg_replace('/[^a-z]/', '',
        strtolower(is_array($cfg['tts']['lang']) ? '' : (string) $cfg['tts']['lang']));
    if ($cfg['tts']['lang'] === '') { $cfg['tts']['lang'] = 'de'; }
    $cfg['tts']['template'] = oc_text($cfg['tts']['template'], 400);
    if ($cfg['tts']['template'] !== ''
        && !preg_match('#^https?://#i', $cfg['tts']['template'])) {
        $cfg['tts']['template'] = '';
    }

    if (!is_array($cfg['months'])) { $cfg['months'] = array(); }
    for ($i = 0; $i < 12; $i++) {
        $cfg['months'][$i] = isset($cfg['months'][$i]) ? max(0, (float) $cfg['months'][$i]) : 0.0;
    }
    // ---- Schaltregeln und Profil (ab 0.9.1) ----
    if (!is_array($cfg['regeln'])) { $cfg['regeln'] = array(); }
    for ($i = 0; $i < OC_REGELN; $i++) {
        $r = isset($cfg['regeln'][$i]) && is_array($cfg['regeln'][$i]) ? $cfg['regeln'][$i] : array();
        $r += oc_regel_vorgabe();
        $r['aktiv'] = empty($r['aktiv']) ? 0 : 1;
        $r['neg'] = empty($r['neg']) ? 0 : 1;
        $r['name'] = oc_text($r['name'], 40);
        $r['art'] = in_array($r['art'], oc_regel_arten(), true) ? $r['art'] : 'fenster';
        $r['n'] = oc_ganz($r['n'], 3, 1, 12);
        $r['von'] = oc_ganz($r['von'], 0, 0, 23);
        $r['bis'] = oc_ganz($r['bis'], 0, 0, 23);
        $r['horizont'] = oc_ganz($r['horizont'], 24, 1, 48);
        $r['schwelle'] = oc_zahl($r['schwelle'], 20.0, -100, 200);
        $r['prozent'] = oc_ganz($r['prozent'], 20, 0, 90);
        // Felder des Fahrplaners. Hier wird gekappt, nicht abgewiesen - das
        // Abweisen macht die Oberflaeche beim Speichern.
        $r['rang'] = max(1, min(99, (int) $r['rang']));
        $r['leistung'] = max(0.0, min(100.0, (float) $r['leistung']));
        $r['energie'] = max(0.0, min(500.0, (float) $r['energie']));
        $r['frist'] = (int) $r['frist'];
        if ($r['frist'] < 0 || $r['frist'] > 23) { $r['frist'] = -1; }
        $r['pv_sperre'] = oc_zahl($r['pv_sperre'], 0.0, 0, 500);
        $r['soc_min'] = oc_ganz($r['soc_min'], 0, 0, 100);
        $r['soc_max'] = oc_ganz($r['soc_max'], 0, 0, 100);
        /* Taktschutz ab 1.1.0. Beide in Minuten, 0 = aus. Die Obergrenze
         * von 720 Minuten ist keine Willkuer: laenger als zwoelf Stunden am
         * Stueck ist keine Mindestlaufzeit mehr, sondern ein Dauerlauf. */
        $r['min_lauf'] = oc_ganz($r['min_lauf'], 0, 0, 720);
        $r['min_pause'] = oc_ganz($r['min_pause'], 0, 0, 720);
        $cfg['regeln'][$i] = $r;
    }
    /* ---- Alle uebrigen Werte in ihre Schranken ----
     *
     * DIESE LISTE IST DIE ZWEITE HAELFTE DER SICHERUNGSPRUEFUNG. Sie muss
     * dieselben Grenzen tragen wie der Speichern-Handler in der Oberflaeche;
     * steht dort eine andere Zahl, hat man zwei Wahrheiten. Wer eine Grenze
     * aendert, aendert sie an beiden Stellen - der Selbsttest im Reiter Test
     * vergleicht sie und meldet, wenn sie auseinanderlaufen. */
    $cfg['enabled']     = empty($cfg['enabled']) ? 0 : 1;
    $cfg['demo']        = empty($cfg['demo']) ? 0 : 1;
    $cfg['co2_enabled'] = empty($cfg['co2_enabled']) ? 0 : 1;
    $cfg['mqtt_enabled'] = empty($cfg['mqtt_enabled']) ? 0 : 1;
    $cfg['hysterese']   = empty($cfg['hysterese']) ? 0 : 1;

    $cfg['demo_aufschlag'] = oc_zahl($cfg['demo_aufschlag'], 15.0, 0, 100);
    $cfg['demo_vat']       = oc_zahl($cfg['demo_vat'], 19.0, 0, 30);
    $cfg['cheap']          = oc_zahl($cfg['cheap'], 20.0, 0, 200);
    $cfg['expensive']      = oc_zahl($cfg['expensive'], 35.0, 0, 400);
    /* Eine Schwelle "guenstig" oberhalb von "teuer" ergibt ein Preisniveau,
     * das nie 2 wird. Das Formular weist es ab und meldet es; hier, wo die
     * Werte aus einer Datei kommen koennen, wird auf die Vorgaben
     * zurueckgestellt - lieber ein bekannter Stand als ein unmoeglicher. */
    if ($cfg['cheap'] >= $cfg['expensive']) {
        $cfg['cheap'] = 20.0;
        $cfg['expensive'] = 35.0;
    }
    $cfg['window']       = oc_ganz($cfg['window'], 3, 1, 12);
    $cfg['co2_clean']    = oc_zahl($cfg['co2_clean'], 200, 0, 1000);
    $cfg['fixed_price']  = oc_zahl($cfg['fixed_price'], 30.90, 0, 200);
    $cfg['fix_grund']    = oc_zahl($cfg['fix_grund'], 12.90, 0, 500);
    $cfg['dyn_grund']    = oc_zahl($cfg['dyn_grund'], 0.0, 0, 500);
    $cfg['fix_sofortbonus']  = oc_zahl($cfg['fix_sofortbonus'], 0.0, 0, 5000);
    $cfg['fix_neubonus']     = oc_zahl($cfg['fix_neubonus'], 0.0, 0, 5000);
    $cfg['fix_neubonus_pct'] = oc_zahl($cfg['fix_neubonus_pct'], 0.0, 0, 100);
    $cfg['fix_rabatt']   = oc_zahl($cfg['fix_rabatt'], 0.0, 0, 100);
    $cfg['consumption']  = oc_ganz($cfg['consumption'], 3500, 100, 100000);
    $cfg['shift_kwh']    = oc_zahl($cfg['shift_kwh'], 3.0, 0, 100);

    /* Das Praefix geht in die UDP-Zeile an den MQTT-Gateway. Siehe
     * oc_mqtt_thema_saeubern() - dort steht, was ohne diese Zeile passiert. */
    $cfg['mqtt_topic'] = oc_mqtt_thema_saeubern($cfg['mqtt_topic'], 'octopus');

    /* Das Aktionstoken steht in den Adressen im Miniserver. Was nicht seine
     * Form hat, ist keines - dann lieber leer, denn der Endpunkt weist ein
     * leeres Token ausdruecklich ab (fail closed) und sagt auch, warum. */
    $tok = is_array($cfg['aktionstoken']) ? '' : trim((string) $cfg['aktionstoken']);
    $cfg['aktionstoken'] = preg_match('/^[A-Za-z0-9]{8,64}$/', $tok) ? $tok : '';

    foreach (array('pv_url', 'soc_url', 'verbrauch_url') as $f) {
        $cfg[$f] = oc_text($cfg[$f], 500);
        if ($cfg[$f] !== '' && !preg_match('#^https?://#i', $cfg[$f])) { $cfg[$f] = ''; }
    }
    foreach (array('pv_pfad', 'pv_zeitfeld', 'pv_wertfeld', 'soc_pfad',
                   'verbrauch_pfad', 'verbrauch_zeitfeld', 'verbrauch_wertfeld') as $f) {
        $cfg[$f] = oc_text($cfg[$f], 200);
    }

    // Fahrplaner, global
    $cfg['budget_kw']   = oc_zahl($cfg['budget_kw'], 0.0, 0, 200);
    $cfg['pv_bonus']    = oc_zahl($cfg['pv_bonus'], 0.0, 0, 100);
    $cfg['pv_schwelle'] = oc_ganz($cfg['pv_schwelle'], 500, 1, 100000);
    $cfg['budget2_kw']  = oc_zahl($cfg['budget2_kw'], 0.0, 0, 200);
    $cfg['budget2_von'] = oc_ganz($cfg['budget2_von'], 0, 0, 23);
    $cfg['budget2_bis'] = oc_ganz($cfg['budget2_bis'], 0, 0, 23);

    if (!in_array($cfg['pv_quelle'], array('', 'forecast_solar', 'objekt', 'liste'), true)) {
        $cfg['pv_quelle'] = '';
    }
    if (!in_array($cfg['pv_einheit'], array('wh', 'w', 'kw'), true)) {
        $cfg['pv_einheit'] = 'wh';
    }
    if (!in_array($cfg['verbrauch_quelle'], array('', 'objekt', 'liste'), true)) {
        $cfg['verbrauch_quelle'] = '';
    }
    if (!in_array($cfg['verbrauch_einheit'], array('wh', 'w', 'kw'), true)) {
        $cfg['verbrauch_einheit'] = 'wh';
    }
    if (!in_array($cfg['profil_ein'], array('aus', 'absolut', 'relativ', 'beides'), true)) {
        $cfg['profil_ein'] = 'aus';
    }
    return $cfg;
}

/**
 * Die Schranken an EINER Stelle - damit Formular, Konfiguration und
 * Sicherungspruefung nicht auseinanderlaufen koennen.
 *
 * array(Schluessel => array(Art, Vorgabe, Min, Max)); Art ist 'z' fuer
 * Kommazahl, 'g' fuer ganze Zahl, 'b' fuer Haken, 't' fuer Text mit
 * Hoechstlaenge und 'w' fuer eine Auswahl aus einer festen Liste.
 *
 * Der Selbsttest im Reiter Test prueft, dass jeder Schluessel aus
 * oc_vorgaben() hier vorkommt - sonst rutscht ein neues Feld ungeprueft
 * durch die Sicherung.
 */
function oc_schranken()
{
    return array(
        'enabled'        => array('b'),
        'demo'           => array('b'),
        'co2_enabled'    => array('b'),
        'mqtt_enabled'   => array('b'),
        'hysterese'      => array('b'),
        'demo_aufschlag' => array('z', 15.0, 0, 100),
        'demo_vat'       => array('z', 19.0, 0, 30),
        'cheap'          => array('z', 20.0, 0, 200),
        'expensive'      => array('z', 35.0, 0, 400),
        'window'         => array('g', 3, 1, 12),
        'co2_clean'      => array('z', 200, 0, 1000),
        'fixed_price'    => array('z', 30.90, 0, 200),
        'fix_grund'      => array('z', 12.90, 0, 500),
        'dyn_grund'      => array('z', 0.0, 0, 500),
        'fix_sofortbonus'  => array('z', 0.0, 0, 5000),
        'fix_neubonus'     => array('z', 0.0, 0, 5000),
        'fix_neubonus_pct' => array('z', 0.0, 0, 100),
        'fix_rabatt'     => array('z', 0.0, 0, 100),
        'consumption'    => array('g', 3500, 100, 100000),
        'shift_kwh'      => array('z', 3.0, 0, 100),
        'budget_kw'      => array('z', 0.0, 0, 200),
        'pv_bonus'       => array('z', 0.0, 0, 100),
        'pv_schwelle'    => array('g', 500, 1, 100000),
        'budget2_kw'     => array('z', 0.0, 0, 200),
        'budget2_von'    => array('g', 0, 0, 23),
        'budget2_bis'    => array('g', 0, 0, 23),
        'mqtt_topic'     => array('t', 64),
        'aktionstoken'   => array('t', 64),
        'pv_url'         => array('t', 500),
        'soc_url'        => array('t', 500),
        'verbrauch_url'  => array('t', 500),
        'pv_pfad'        => array('t', 200),
        'pv_zeitfeld'    => array('t', 200),
        'pv_wertfeld'    => array('t', 200),
        'soc_pfad'       => array('t', 200),
        'verbrauch_pfad' => array('t', 200),
        'verbrauch_zeitfeld' => array('t', 200),
        'verbrauch_wertfeld' => array('t', 200),
        'pv_quelle'      => array('w', '', 'forecast_solar', 'objekt', 'liste'),
        'pv_einheit'     => array('w', 'wh', 'w', 'kw'),
        'verbrauch_quelle'  => array('w', '', 'objekt', 'liste'),
        'verbrauch_einheit' => array('w', 'wh', 'w', 'kw'),
        'profil_ein'     => array('w', 'aus', 'absolut', 'relativ', 'beides'),
        // Diese vier sind Felder und werden eigens geprueft.
        'regeln'         => array('f'),
        'months'         => array('f'),
        'notify'         => array('f'),
        'tts'            => array('f'),
    );
}

function oc_config_write($cfg)
{
    $p = oc_paths();
    if (!is_dir(dirname($p['config']))) {
        @mkdir(dirname($p['config']), 0775, true);
    }
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $vor = $p['config'] . '.neu';
    if (@file_put_contents($vor, $json) === false) { return false; }
    @chmod($vor, 0640);
    if (!@rename($vor, $p['config'])) { return false; }
    @copy($p['config'], $p['backup']);
    oc_weg(oc_tmpdir() . '/state.json');   // Zustand mit neuen Schwellen neu rechnen
    return true;
}

/** Zufallstoken fuer den Aktionsendpunkt. */
function oc_token_erzeugen($laenge = 24)
{
    $zeichen = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $t;
}

/* ==================================================================
 * Zugangsdaten - eigene Datei, Rechte 0600
 * ================================================================== */

function oc_zugang()
{
    $f = oc_paths()['zugang'];
    $z = is_file($f) ? json_decode((string) @file_get_contents($f), true) : array();
    if (!is_array($z)) { $z = array(); }
    $z += array('email' => '', 'passwort' => '', 'konto' => '');
    return $z;
}

function oc_zugang_write($email, $passwort, $konto)
{
    $f = oc_paths()['zugang'];
    if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
    $z = array('email' => (string) $email, 'passwort' => (string) $passwort,
               'konto' => (string) $konto, 'ts' => time());
    $vor = $f . '.neu';
    if (@file_put_contents($vor, json_encode($z, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
        return false;
    }
    @chmod($vor, 0600);
    if (!@rename($vor, $f)) { return false; }
    @chmod($f, 0600);
    oc_weg(oc_datadir() . '/token.json');   // neue Zugangsdaten, altes Token verwerfen
    oc_weg(oc_tmpdir() . '/state.json');
    oc_log('Zugangsdaten gespeichert (Konto ' . oc_maske_konto($konto) . ', Passwortlaenge '
        . strlen((string) $passwort) . ' Zeichen)');
    return true;
}

/** Kundennummer fuer Protokoll und Anzeige verkuerzen: A-1234ABCD -> A-12****CD */
function oc_maske_konto($k)
{
    $k = (string) $k;
    if (strlen($k) < 6) { return $k === '' ? '(leer)' : str_repeat('*', strlen($k)); }
    return substr($k, 0, 4) . str_repeat('*', max(1, strlen($k) - 6)) . substr($k, -2);
}

/**
 * Form der Kundennummer pruefen. Laut Octopus beginnt sie immer mit "A-",
 * gefolgt von Ziffern und/oder Buchstaben. Was nicht passt, wird abgewiesen
 * und gemeldet - nicht stillschweigend zurechtgebogen.
 */
function oc_konto_gueltig($k)
{
    return (bool) preg_match('/^A-[A-Za-z0-9]{4,20}$/', (string) $k);
}

function oc_email_gueltig($m)
{
    return (bool) filter_var((string) $m, FILTER_VALIDATE_EMAIL);
}

/* ==================================================================
 * HTTP - eine Stelle fuer alle Abrufe
 *
 * Kopfzeilen nach Hausregel: vor mancher Schnittstelle sitzt ein Waechter,
 * der Vorgabewerte abweist. Deshalb User-Agent, Accept, Accept-Language und
 * Accept-Encoding an JEDER Anfrage.
 * ================================================================== */

function oc_http($url, $post = null, $extra = array(), $timeout = 20)
{
    $kopf = array(
        'User-Agent: LoxBerry-Plugin-Octopus/1.0 (+https://wiki.loxberry.de)',
        'Accept: application/json, text/plain;q=0.8, */*;q=0.5',
        'Accept-Language: de-DE,de;q=0.9,en;q=0.6',
    );
    foreach ($extra as $z) { $kopf[] = $z; }
    $erg = array('ok' => false, 'code' => 0, 'body' => '', 'fehler' => '');

    if (function_exists('curl_init')) {
        $kopf[] = 'Accept-Encoding: gzip, deflate';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $timeout));
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_ENCODING, '');       // entpackt gzip selbst
        curl_setopt($ch, CURLOPT_HTTPHEADER, $kopf);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        $body = curl_exec($ch);
        $nr = curl_errno($ch);
        $erg['code'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            $erg['fehler'] = oc_curl_fehler($nr);
            return $erg;
        }
        $erg['body'] = (string) $body;
    } else {
        // Ohne cURL entpackt PHP nichts - also gar nicht erst packen lassen.
        $kopf[] = 'Accept-Encoding: identity';
        $opt = array('http' => array(
            'method'        => $post !== null ? 'POST' : 'GET',
            'header'        => implode("\r\n", $kopf),
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ));
        if ($post !== null) { $opt['http']['content'] = $post; }
        $body = @file_get_contents($url, false, stream_context_create($opt));
        if ($body === false) {
            $erg['fehler'] = 'FEHLER_VERBINDUNG';
            return $erg;
        }
        $erg['body'] = (string) $body;
        if (isset($http_response_header[0])
            && preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
            $erg['code'] = (int) $m[1];
        }
    }

    if ($erg['code'] >= 400) {
        $erg['fehler'] = 'FEHLER_HTTP:' . $erg['code'];
        return $erg;
    }
    $erg['ok'] = true;
    return $erg;
}

/**
 * Fehlernummern von cURL in etwas uebersetzen, das eine Ursache benennt.
 * Der nackte Errno-Text hilft niemandem.
 */
function oc_curl_fehler($nr)
{
    switch ((int) $nr) {
        case 6:  return 'FEHLER_DNS';         // Name nicht aufloesbar
        case 7:  return 'FEHLER_ABGELEHNT';   // erreichbar, aber niemand nimmt ab
        case 28: return 'FEHLER_ZEIT';        // nichts antwortet
        case 35:
        case 60: return 'FEHLER_TLS';
        default: return 'FEHLER_VERBINDUNG';
    }
}

/**
 * Antwort als JSON lesen. Kommt HTML zurueck, hat ein Gateway geantwortet
 * und nicht die Schnittstelle - das gehoert ausdruecklich in die Meldung,
 * sonst sucht man den Fehler bei der Anmeldung, die laengst funktioniert.
 */
function oc_json($body, &$fehler)
{
    $t = ltrim((string) $body);
    if ($t === '') { $fehler = 'FEHLER_LEER'; return null; }
    if ($t[0] === '<') { $fehler = 'FEHLER_HTML'; return null; }
    $d = json_decode($t, true);
    if (!is_array($d)) { $fehler = 'FEHLER_JSON'; return null; }
    return $d;
}

/* ==================================================================
 * Kraken: Token und Preise
 * ================================================================== */

/**
 * Token holen. Es ist eine Stunde gueltig; wir halten es 55 Minuten und
 * legen es mit Rechten 0600 ab. Die Zugangsdaten werden NIE ueber die
 * Kommandozeile uebergeben - Argumente stehen in der Prozessliste.
 */
function oc_kraken_token($force = false, &$fehler = null)
{
    $fehler = '';
    $f = oc_datadir() . '/token.json';
    if (!$force && is_file($f)) {
        $t = json_decode((string) @file_get_contents($f), true);
        if (is_array($t) && !empty($t['token']) && (int) $t['exp'] > time() + 60) {
            return (string) $t['token'];
        }
    }
    $z = oc_zugang();
    if ($z['email'] === '' || $z['passwort'] === '') {
        $fehler = 'FEHLER_KEIN_ZUGANG';
        return '';
    }
    $abfrage = 'mutation krakenTokenAuthentication($email: String!, $password: String!) {'
             . ' obtainKrakenToken(input: {email: $email, password: $password}) { token } }';
    $payload = json_encode(array(
        'query'     => $abfrage,
        'variables' => array('email' => $z['email'], 'password' => $z['passwort']),
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $r = oc_http(OC_API, $payload, array('Content-Type: application/json'), 20);
    if (!$r['ok'] && $r['body'] === '') {
        $fehler = $r['fehler'];
        oc_log_if_changed('anmeldung', 'fehlgeschlagen (' . $fehler . ')');
        return '';
    }
    $d = oc_json($r['body'], $jf);
    if ($d === null) {
        $fehler = $jf;
        oc_log_if_changed('anmeldung', 'unlesbare Antwort (' . $fehler . ', HTTP ' . $r['code'] . ')');
        return '';
    }
    if (!empty($d['errors'])) {
        $fehler = 'FEHLER_ANMELDUNG';
        $m = isset($d['errors'][0]['message']) ? (string) $d['errors'][0]['message'] : '';
        oc_log_if_changed('anmeldung', 'abgelehnt: ' . substr($m, 0, 200));
        return '';
    }
    $token = isset($d['data']['obtainKrakenToken']['token'])
        ? (string) $d['data']['obtainKrakenToken']['token'] : '';
    if ($token === '') {
        $fehler = 'FEHLER_KEIN_TOKEN';
        oc_log_if_changed('anmeldung', 'Antwort enthielt kein Token');
        return '';
    }
    $vor = $f . '.neu';
    @file_put_contents($vor, json_encode(array('token' => $token, 'exp' => time() + 3300)));
    @chmod($vor, 0600);
    @rename($vor, $f);
    @chmod($f, 0600);
    oc_log_if_changed('anmeldung', 'Token geholt, gueltig bis ' . date('H:i', time() + 3300));
    return $token;
}

/**
 * Preisliste bei Kraken abholen.
 *
 * Rueckgabe: array('slots' => [ts => array('ct','net','len')], 'fehler' => '',
 *                  'brutto_ok' => 0/1, 'roh' => Anzahl gefundener Eintraege)
 *
 * Die Struktur unterhalb von "unitRateInformation" kann sich laut Octopus
 * aendern. Deshalb wird die Antwort NICHT auf einem festen Pfad gelesen,
 * sondern nach Eintraegen mit validFrom/validTo durchsucht.
 */
function oc_kraken_preise($force = false)
{
    $out = array('slots' => array(), 'fehler' => '', 'brutto_ok' => 1, 'roh' => 0);
    $z = oc_zugang();
    if ($z['konto'] === '') { $out['fehler'] = 'FEHLER_KEIN_KONTO'; return $out; }
    if (!oc_konto_gueltig($z['konto'])) { $out['fehler'] = 'FEHLER_KONTOFORM'; return $out; }

    $token = oc_kraken_token($force, $tf);
    if ($token === '') { $out['fehler'] = $tf !== '' ? $tf : 'FEHLER_KEIN_TOKEN'; return $out; }

    $abfrage = 'query getDayAheadPrices($accountNumber: String!) {'
        . ' account(accountNumber: $accountNumber) { properties { electricityMalos { agreements {'
        . ' unitRateForecast { validFrom validTo unitRateInformation {'
        . ' ... on TimeOfUseProductUnitRateInformation { rates {'
        . ' netUnitRateCentsPerKwh latestGrossUnitRateCentsPerKwh } } } } } } } } }';
    $payload = json_encode(array(
        'query'     => $abfrage,
        'variables' => array('accountNumber' => $z['konto']),
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $r = oc_http(OC_API, $payload, array('Content-Type: application/json',
                                         'Authorization: ' . $token), 25);
    if (!$r['ok'] && $r['body'] === '') { $out['fehler'] = $r['fehler']; return $out; }

    $d = oc_json($r['body'], $jf);
    if ($d === null) { $out['fehler'] = $jf; return $out; }
    if (!empty($d['errors'])) {
        $m = isset($d['errors'][0]['message']) ? (string) $d['errors'][0]['message'] : '';
        // Ein abgelaufenes Token gibt es nur einmal: einmal neu anmelden und
        // die Abfrage wiederholen, statt eine Stunde lang nichts zu liefern.
        if (!$force && stripos($m, 'token') !== false) {
            oc_weg(oc_datadir() . '/token.json');
            return oc_kraken_preise(true);
        }
        $out['fehler'] = 'FEHLER_ABFRAGE';
        oc_log_if_changed('abfrage', 'abgelehnt: ' . substr($m, 0, 200));
        return $out;
    }

    $roh = array();
    oc_sammle_preise(isset($d['data']) ? $d['data'] : $d, $roh);
    $out['roh'] = count($roh);
    if (!$roh) {
        // Genau der Fall aus der Octopus-Anleitung: wer den Tarif
        // dynamicOctopus nicht hat, bekommt eine leere Liste zurueck.
        $out['fehler'] = 'FEHLER_LEERE_LISTE';
        oc_log_if_changed('abfrage', 'Antwort ohne Preiseintraege - dynamicOctopus-Tarif?');
        return $out;
    }
    ksort($roh);
    foreach ($roh as $ts => $s) {
        if ($s['ct'] === null) { continue; }
        if (empty($s['brutto'])) { $out['brutto_ok'] = 0; }
        $out['slots'][$ts] = array('ct' => $s['ct'], 'net' => $s['net'], 'len' => $s['len']);
    }
    if (!$out['slots']) { $out['fehler'] = 'FEHLER_KEINE_PREISE'; return $out; }
    oc_log_if_changed('abfrage', count($out['slots']) . ' Preiseintraege von '
        . date('d.m. H:i', array_key_first($out['slots'])) . ' bis '
        . date('d.m. H:i', array_key_last($out['slots'])));
    return $out;
}

/** Rekursiv nach Eintraegen mit validFrom/validTo suchen. */
function oc_sammle_preise($node, &$out)
{
    if (!is_array($node)) { return; }
    if (isset($node['validFrom']) && isset($node['validTo'])) {
        $von = strtotime((string) $node['validFrom']);
        $bis = strtotime((string) $node['validTo']);
        $brutto = null; $netto = null;
        oc_finde_satz($node, $brutto, $netto);
        if ($von && $bis && $bis > $von && ($brutto !== null || $netto !== null)) {
            $out[$von] = array(
                'ct'     => $brutto !== null ? round($brutto, 4) : round($netto, 4),
                'net'    => $netto !== null ? round($netto, 4) : null,
                'len'    => min(3600, max(300, $bis - $von)),
                'brutto' => $brutto !== null ? 1 : 0,
            );
        }
        return;
    }
    foreach ($node as $kind) {
        if (is_array($kind)) { oc_sammle_preise($kind, $out); }
    }
}

/** Innerhalb eines Eintrags die beiden Preisfelder suchen, egal wie tief. */
function oc_finde_satz($node, &$brutto, &$netto)
{
    if (!is_array($node)) { return; }
    foreach ($node as $k => $v) {
        if ($k === 'latestGrossUnitRateCentsPerKwh' && is_numeric($v)) {
            $brutto = (float) $v;
        } elseif ($k === 'netUnitRateCentsPerKwh' && is_numeric($v)) {
            $netto = (float) $v;
        } elseif (is_array($v)) {
            oc_finde_satz($v, $brutto, $netto);
        }
    }
}

/* ==================================================================
 * Demo-Modus
 *
 * Ohne Octopus-Vertrag gibt es keine Preise. Damit Oberflaeche, MQTT-Themen
 * und die Loxone-Bausteine trotzdem vollstaendig durchgetestet werden
 * koennen, rechnet der Demo-Modus aus den offenen Boersenpreisen von aWATTar
 * eine Preisliste derselben Form. Die Werte sind SIMULIERT und werden ueberall
 * als solche gekennzeichnet - der Aufschlag ist frei gewaehlt, nicht gemessen.
 * ================================================================== */

function oc_demo_preise($force = false)
{
    $cfg = oc_config();
    $out = array('slots' => array(), 'fehler' => '', 'brutto_ok' => 1, 'roh' => 0);
    $auf = max(0.0, (float) $cfg['demo_aufschlag']);
    $vat = 1 + max(0.0, (float) $cfg['demo_vat']) / 100.0;

    foreach (array(strtotime('today 00:00'), strtotime('tomorrow 00:00')) as $tag) {
        $cache = oc_datadir() . '/demo_' . date('Ymd', $tag) . '.json';
        $js = false;
        if (!$force && is_file($cache) && time() - filemtime($cache) < 900) {
            $js = (string) @file_get_contents($cache);
        } else {
            $r = oc_http('https://api.awattar.de/v1/marketdata?start=' . ($tag * 1000)
                       . '&end=' . (($tag + 86400) * 1000), null, array(), 15);
            if ($r['ok'] && strpos($r['body'], 'marketprice') !== false) {
                @file_put_contents($cache, $r['body']);
                $js = $r['body'];
            } elseif (is_file($cache)) {
                $js = (string) @file_get_contents($cache);
            } else {
                if ($out['fehler'] === '') { $out['fehler'] = $r['fehler'] !== '' ? $r['fehler'] : 'FEHLER_DEMO'; }
                continue;
            }
        }
        $d = json_decode((string) $js, true);
        if (!isset($d['data']) || !is_array($d['data'])) { continue; }
        foreach ($d['data'] as $row) {
            if (!isset($row['start_timestamp']) || !isset($row['marketprice'])) { continue; }
            $ts = (int) ($row['start_timestamp'] / 1000);
            $net = round(((float) $row['marketprice']) / 10.0, 4);   // EUR/MWh -> ct/kWh
            $ct = round(($net + $auf) * $vat, 4);
            // Stunde in vier Viertelstunden zerlegen, damit die Form dieselbe
            // ist wie bei Octopus. Innerhalb der Stunde bleibt der Wert gleich -
            // erfundene Schwankungen waeren eine Falschaussage.
            for ($v = 0; $v < 4; $v++) {
                $out['slots'][$ts + $v * 900] = array('ct' => $ct, 'net' => $net, 'len' => 900);
            }
            $out['roh']++;
        }
    }
    if (!$out['slots'] && $out['fehler'] === '') { $out['fehler'] = 'FEHLER_DEMO'; }
    ksort($out['slots']);
    if ($out['slots']) {
        oc_log_if_changed('demo', 'Demo-Modus: ' . count($out['slots']) . ' Viertelstunden aus Boersendaten, Aufschlag '
            . $auf . ' ct netto, ' . (float) $cfg['demo_vat'] . ' % USt');
    }
    return $out;
}

/* ==================================================================
 * Preisliste, Kennzahlen
 * ================================================================== */

/**
 * Viertelstundenpreise (Cache 15 Minuten).
 * Rueckgabe: array('slots','fehler','demo','brutto_ok','stand')
 */
function oc_preise($force = false)
{
    $cfg = oc_config();
    $cache = oc_datadir() . '/preise.json';
    if (!$force && is_file($cache) && time() - filemtime($cache) < 900) {
        $c = json_decode((string) @file_get_contents($cache), true);
        if (is_array($c) && !empty($c['slots'])) {
            // json_decode macht aus den Zeitstempeln Zeichenketten - zurueck
            // in ganze Zahlen, sonst greift kein Vergleich mit time().
            $neu = array();
            foreach ($c['slots'] as $ts => $s) { $neu[(int) $ts] = $s; }
            ksort($neu);
            $c['slots'] = $neu;
            return $c;
        }
    }
    $demo = !empty($cfg['demo']);
    $r = $demo ? oc_demo_preise($force) : oc_kraken_preise($force);
    // Ohne Zugangsdaten waere die Oberflaeche sonst dauerhaft leer. Statt
    // stillschweigend auf Demo auszuweichen, wird der Ersatzweg GEMELDET.
    if (!$demo && !$r['slots'] && in_array($r['fehler'], array('FEHLER_KEIN_ZUGANG', 'FEHLER_KEIN_KONTO'), true)) {
        return array('slots' => array(), 'fehler' => $r['fehler'], 'demo' => 0,
                     'brutto_ok' => 1, 'stand' => 0);
    }
    // 'stand' ist der Zeitpunkt des letzten ERFOLGREICHEN Abrufs. Ohne diese
    // Unterscheidung meldete 'alter' auch dann 0 Minuten, wenn gar nichts
    // geholt werden konnte - und in Loxone saehe alles frisch aus.
    $erg = array('slots' => $r['slots'], 'fehler' => $r['fehler'], 'demo' => $demo ? 1 : 0,
                 'brutto_ok' => $r['brutto_ok'], 'stand' => $r['slots'] ? time() : 0);
    if ($erg['slots']) {
        @file_put_contents($cache, json_encode($erg));
    } elseif (is_file($cache)) {
        // Abruf gescheitert: letzte bekannte Liste weiterverwenden, aber den
        // Fehler mitfuehren, damit die Oberflaeche ihn zeigen kann.
        $alt = json_decode((string) @file_get_contents($cache), true);
        if (is_array($alt) && !empty($alt['slots'])) {
            $neu = array();
            foreach ($alt['slots'] as $ts => $s) { $neu[(int) $ts] = $s; }
            ksort($neu);
            $alt['slots'] = $neu;
            $alt['fehler'] = $erg['fehler'];
            $alt['veraltet'] = 1;
            return $alt;
        }
    }
    return $erg;
}

/** Aus Viertelstunden Stundenmittel bilden: [ts_stunde => ct]. */
function oc_stunden($slots)
{
    $b = array();
    foreach ($slots as $ts => $s) {
        $h = $ts - ($ts % 3600);
        if (!isset($b[$h])) { $b[$h] = array(0.0, 0); }
        $b[$h][0] += (float) $s['ct'];
        $b[$h][1]++;
    }
    $out = array();
    foreach ($b as $h => $v) { $out[$h] = round($v[0] / max(1, $v[1]), 3); }
    ksort($out);
    return $out;
}

/* ==================================================================
 * Schaltregeln - fertige 0/1-Signale statt Zahlen
 *
 * Bis 0.9.0 lieferte das Plugin Startzeit, Minuten bis dahin und
 * Durchschnittspreis des guenstigsten Fensters. Alles Zahlen. Wer daraus
 * "jetzt laden" machen wollte, baute im Miniserver eine Kaskade aus
 * Vergleichern und Zeitbausteinen. Eine Schaltregel beantwortet die Frage
 * hier und gibt eine Eins oder eine Null aus.
 *
 * BESONDERHEIT GEGENUEBER DEM AWATTAR-PLUGIN: Octopus rechnet in
 * VIERTELSTUNDEN. Die Regeln arbeiten deshalb auf Slots zu 900 s. Die
 * Angabe "Anzahl Stunden" wird intern mit vier multipliziert, und 'in'
 * und 'rest' zaehlen in MINUTEN - wie das bereits vorhandene fenster_in.
 *
 * Vier Arten:
 *   fenster   die N guenstigsten Stunden AM STUECK   (Wallbox, Waschmaschine)
 *   stunden   die N guenstigsten VOLLEN Stunden      (Speicher, Warmwasser)
 *   schwelle  Preis unter einem festen Wert          (Heizstab)
 *   mittel    Preis X % unter dem Tagesmittel        (mitlaufend)
 *
 * Bei 'stunden' wird bewusst auf volle Stunden gemittelt statt die
 * guenstigsten Viertelstunden zu picken: sonst schaltet die Wallbox im
 * Viertelstundentakt an und aus.
 * ================================================================== */

define('OC_REGELN', 4);

/**
 * Die zulaessigen Regelarten - an EINER Stelle.
 *
 * Oberflaeche, Konfigurationspruefung und Sicherungspruefung lesen alle
 * hier. Bis 1.0.9 stand die Liste dreimal im Quelltext; 'scheiben' waere
 * beim Ergaenzen an einer der drei Stellen vergessen worden, und dann
 * haette das Formular eine Art angeboten, die die Konfiguration wieder
 * verwirft - ohne eine Meldung.
 */
function oc_regel_arten()
{
    return array('fenster', 'stunden', 'scheiben', 'schwelle', 'mittel');
}

/** Vorgabe einer Schaltregel. */
function oc_regel_vorgabe()
{
    return array_merge(array(
        'aktiv' => 0,
        'name' => '',
        'art' => 'fenster',   // fenster | stunden | scheiben | schwelle | mittel
        'n' => 3,             // Anzahl Stunden
        'von' => 0,           // Zeitfenster von (Stunde, einschliesslich)
        'bis' => 0,           // bis (Stunde, ausschliesslich); von == bis = ganzer Tag
        'horizont' => 24,     // nur die naechsten X Stunden betrachten
        'schwelle' => 20.0,   // ct/kWh brutto (art = schwelle)
        'prozent' => 20,      // % unter dem Tagesmittel (art = mittel)
        'neg' => 1,           // bei negativem Preis immer einschalten
    ), plan_regel_vorgabe());
}

/** Liegt die Stunde $h im Zeitfenster? von == bis bedeutet: ganzer Tag. */
function oc_in_zeitfenster($h, $von, $bis)
{
    $h = (int) $h; $von = (int) $von; $bis = (int) $bis;
    if ($von === $bis) { return true; }
    if ($von < $bis) { return $h >= $von && $h < $bis; }
    return $h >= $von || $h < $bis;   // ueber Mitternacht, z. B. 22 bis 6
}

/** Viertelstunden, die fuer eine Regel in Frage kommen. ts => ct. */
function oc_regel_kandidaten($r, $slots, $start)
{
    $ende = $start + max(1, (int) $r['horizont']) * 3600;
    $out = array();
    foreach ($slots as $ts => $s) {
        if ($ts < $start || $ts >= $ende) { continue; }
        if (!oc_in_zeitfenster((int) date('G', $ts), $r['von'], $r['bis'])) { continue; }
        $out[$ts] = (float) $s['ct'];
    }
    ksort($out);
    return $out;
}

/**
 * Eine Regel auswerten.
 * Rueckgabe: aktiv (0/1), in (Minuten bis zum naechsten Treffer, -1 = keiner),
 * rest (verbleibende Minuten am Stueck), ct (Schnitt der Treffer),
 * start (Startstunde), startmin (Startminute), grund.
 */
function oc_regel_werte($r, $slots, $st)
{
    $leer = array('aktiv' => 0, 'in' => -1, 'rest' => 0, 'ct' => 0.0,
                  'start' => -1, 'startmin' => 0, 'grund' => 'aus');
    if (empty($r['aktiv'])) { return $leer; }
    /* Die Regelart 'scheiben' gibt es erst seit 1.1.0 und nur im Planer.
     * Diese Funktion ist die Rechnung von 0.9.1, die der Reiter Test zum
     * Vergleich daneben stellt - sie KANN dazu nichts sagen. Ein stiller
     * Rueckfall auf 'mittel' waere eine Falschaussage; also sagt sie es. */
    if ((string) $r['art'] === 'scheiben') {
        return array_merge($leer, array('grund' => 'nicht_vergleichbar'));
    }
    $jetzt = (int) $st['slotstart'];
    $kand = oc_regel_kandidaten($r, $slots, $jetzt);
    $treffer = array();

    if ($r['art'] === 'fenster') {
        // N Stunden am Stueck = N*4 luekenlose Viertelstunden.
        $ks = array_keys($kand);
        $len = min(max(1, (int) $r['n']) * 4, count($ks));
        $best = null;
        for ($i = 0; $len > 0 && $i + $len <= count($ks); $i++) {
            if ($ks[$i + $len - 1] - $ks[$i] !== ($len - 1) * 900) { continue; }
            $s = 0.0;
            for ($j = 0; $j < $len; $j++) { $s += $kand[$ks[$i + $j]]; }
            if ($best === null || $s / $len < $best[1]) { $best = array($i, $s / $len); }
        }
        if ($best !== null) {
            for ($j = 0; $j < $len; $j++) { $treffer[] = $ks[$best[0] + $j]; }
        }
    } elseif ($r['art'] === 'stunden') {
        // Volle Stunden mitteln, die N guenstigsten nehmen, dann alle
        // Viertelstunden dieser Stunden als Treffer melden.
        $std = array();
        foreach ($kand as $ts => $ct) {
            $h = $ts - ($ts % 3600);
            if (!isset($std[$h])) { $std[$h] = array(0.0, 0); }
            $std[$h][0] += $ct;
            $std[$h][1]++;
        }
        $mittel = array();
        foreach ($std as $h => $v) {
            // Angebrochene Stunden nicht bewerten - sie waeren kuenstlich
            // guenstig oder teuer, je nachdem welche Viertel fehlen.
            if ($v[1] === 4) { $mittel[$h] = $v[0] / 4; }
        }
        asort($mittel);
        $gewaehlt = array_slice(array_keys($mittel), 0, max(1, (int) $r['n']));
        foreach ($kand as $ts => $ct) {
            if (in_array($ts - ($ts % 3600), $gewaehlt, true)) { $treffer[] = $ts; }
        }
        sort($treffer);
    } else {
        if ($r['art'] === 'schwelle') {
            $grenze = (float) $r['schwelle'];
        } else {
            $m = (float) $st['heute']['avg'];
            if ($m <= 0 && $kand) { $m = array_sum($kand) / count($kand); }
            $grenze = round($m * (1 - max(0, min(90, (int) $r['prozent'])) / 100), 3);
        }
        foreach ($kand as $ts => $ct) {
            if ($ct <= $grenze) { $treffer[] = $ts; }
        }
    }

    $erg = $leer;
    if ($treffer) {
        $erg['ct'] = round(array_sum(array_intersect_key($kand, array_flip($treffer))) / count($treffer), 3);
        $erg['aktiv'] = in_array($jetzt, $treffer, true) ? 1 : 0;
        foreach ($treffer as $ts) {
            if ($ts >= $jetzt) {
                $erg['start'] = (int) date('G', $ts);
                $erg['startmin'] = (int) date('i', $ts);
                $erg['in'] = (int) round(($ts - $jetzt) / 60);
                break;
            }
        }
        if ($erg['aktiv']) {
            $rest = 0;
            for ($ts = $jetzt; in_array($ts, $treffer, true); $ts += 900) { $rest += 15; }
            $erg['rest'] = $rest;
        }
        $erg['grund'] = $erg['aktiv'] ? $r['art'] : 'wartet';
    }

    // Negativer Preis sticht - wer dann nicht laedt, verschenkt Geld.
    if (!empty($r['neg']) && !empty($st['neg'])) {
        $erg['aktiv'] = 1;
        $erg['in'] = 0;
        $erg['rest'] = max(15, (int) $erg['rest']);
        $erg['grund'] = 'negativ';
    }
    return $erg;
}

/* ==================================================================
 * Fremde Auskuenfte fuer den Fahrplaner
 *
 * PV-Prognose und Speicherstand. Das AUSWERTEN steckt in planer.php und
 * ist dort ohne Netz durchgeprueft; hier steht nur das Holen. Gecacht wird
 * 15 Minuten - eine Prognose aendert sich nicht schneller, und ein
 * Fremddienst, den jedes Plugin im Minutentakt fragt, sperrt irgendwann aus.
 * ================================================================== */

function oc_umwelt($force = false)
{
    $cfg = oc_config();
    $leer = array('pv' => null, 'pv_summe' => null, 'soc' => null,
                  'pv_meldung' => '', 'soc_meldung' => '', 'ts' => 0);
    $cache = oc_tmpdir() . '/umwelt.json';
    if (!$force && is_file($cache) && time() - filemtime($cache) < 900) {
        $c = json_decode((string) @file_get_contents($cache), true);
        if (is_array($c)) { return $c + $leer; }
    }
    $erg = $leer;
    $erg['ts'] = time();
    $jetzt = time() - (time() % 900);

    if ($cfg['pv_quelle'] !== '' && trim((string) $cfg['pv_url']) !== '') {
        $roh = oc_holen($cfg['pv_url']);
        if ($roh === null) {
            $erg['pv_meldung'] = 'NICHT_ERREICHBAR';
        } else {
            list($pv, $m) = plan_pv_lesen($roh, $cfg['pv_quelle'], $cfg['pv_pfad'],
                $cfg['pv_zeitfeld'], $cfg['pv_wertfeld'], $cfg['pv_einheit'], 900);
            $erg['pv_meldung'] = $m;
            if ($pv) {
                $erg['pv'] = $pv;
                $erg['pv_summe'] = plan_pv_summe($pv, $jetzt, 24);
            }
        }
    }

    if (trim((string) $cfg['soc_url']) !== '') {
        $roh = oc_holen($cfg['soc_url']);
        if ($roh === null) {
            $erg['soc_meldung'] = 'NICHT_ERREICHBAR';
        } else {
            list($soc, $m) = plan_soc_lesen($roh, $cfg['soc_pfad']);
            $erg['soc_meldung'] = $m;
            $erg['soc'] = $soc;
        }
    }

    @file_put_contents($cache, json_encode($erg));
    return $erg;
}

/** Eine JSON-Adresse holen. Rueckgabe: Feld oder null. */
function oc_holen($url)
{
    $url = trim((string) $url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) { return null; }
    $ctx = stream_context_create(array('http' => array(
        'timeout' => 12, 'user_agent' => 'LoxBerry Octopus', 'ignore_errors' => true)));
    $r = @file_get_contents($url, false, $ctx);
    if ($r === false) { return null; }
    $d = json_decode($r, true);
    return is_array($d) ? $d : null;
}

/**
 * Den Sperrgrund als Zahl - Loxone rechnet mit Zahlen, nicht mit Woertern.
 * 0 frei, 1 PV-Prognose, 2 Speicher zu leer, 3 Speicher zu voll.
 */
function oc_sperre_zahl($grund)
{
    if ($grund === 'pv') { return 1; }
    if ($grund === 'soc_min') { return 2; }
    if ($grund === 'soc_max') { return 3; }
    return 0;
}

/**
 * Alle Regeln auswerten - seit 1.0.0 ueber den gemeinsamen Fahrplaner.
 *
 * oc_regel_werte() darueber bleibt unveraendert stehen: der Reiter Test
 * zeigt damit die alte und die neue Rechnung nebeneinander. Der Planer
 * bringt drei Dinge dazu, die eine einzelne Regel nicht wissen kann - die
 * Frist, das gemeinsame Leistungsbudget und die PV-Prognose.
 *
 * 'in' und 'rest' zaehlen hier wie bisher in MINUTEN; der Planer rechnet
 * ohnehin in Minuten, es ist also nichts umzurechnen.
 */
function oc_regeln($slots, $st)
{
    $cfg = oc_config();
    $umwelt = oc_umwelt();
    // Der Planer will ts => Preis, die Slots tragen ein ganzes Feld.
    $preise = array();
    foreach ($slots as $ts => $sl) { $preise[$ts] = (float) $sl['ct']; }

    $fp = plan_rechnen($preise, 900, (int) $st['slotstart'], $cfg['regeln'], array(
        'pv'       => isset($umwelt['pv']) ? $umwelt['pv'] : null,
        'pv_summe' => isset($umwelt['pv_summe']) ? $umwelt['pv_summe'] : null,
        'soc'      => isset($umwelt['soc']) ? $umwelt['soc'] : null,
        'neg'      => !empty($st['neg']) ? 1 : 0,
        /* Das Tagesmittel nur uebergeben, wenn es eines gibt. 0.0 waere ein
         * Wert und kein Nichtwissen - und bei negativen Preisen ist der
         * Unterschied entscheidend. */
        'mittel'   => (!empty($st['ok']) && !empty($st['heute']['n']))
                      ? (float) $st['heute']['avg'] : null,
        'laufend'  => oc_laufend_lesen(),
    ), array(
        'budget_kw'   => $cfg['budget_kw'],
        'pv_bonus'    => $cfg['pv_bonus'],
        'pv_schwelle' => $cfg['pv_schwelle'],
        'budget2_kw'  => $cfg['budget2_kw'],
        'budget2_von' => $cfg['budget2_von'],
        'budget2_bis' => $cfg['budget2_bis'],
    ));

    $out = array();
    foreach ($fp as $i => $w) {
        $r = isset($cfg['regeln'][$i]) ? $cfg['regeln'][$i] : array();
        $w['name'] = (isset($r['name']) && $r['name'] !== '') ? $r['name'] : ('Regel ' . ($i + 1));
        $w['art'] = isset($r['art']) ? $r['art'] : 'fenster';
        $w['ein'] = empty($r['aktiv']) ? 0 : 1;
        unset($w['slots']);
        $out[] = $w;
    }
    return $out;
}

/**
 * Der Fahrplan MIT den Zeitscheiben - nur fuer die Anzeige.
 *
 * oc_regeln() wirft die Scheibenliste weg, weil Loxone sie nicht braucht.
 * Die Oberflaeche braucht sie sehr wohl: erst daran sieht man, wann welche
 * Regel laeuft und wie viel Leistung gleichzeitig verplant ist.
 *
 * Rueckgabe: array('plan'=>..., 'belegung'=>ts=>kW, 'slotlen'=>Sekunden,
 *                  'preise'=>ts=>ct)
 */
function oc_fahrplan($st = null)
{
    $cfg = oc_config();
    if ($st === null) { $st = oc_state(); }
    /* oc_preise() liefert eine HUELLE mit den Schluesseln slots, fehler,
     * demo und stand - nicht die Slots selbst. Wer das verwechselt,
     * iteriert ueber 'FEHLER_KEIN_ZUGANG' und greift auf ein Zeichen einer
     * Zeichenkette zu; unter PHP 8 ist das ein TypeError und die ganze
     * Seite bleibt weiss. Deshalb hier ausdruecklich ['slots'] und je
     * Eintrag eine Pruefung. */
    $roh = oc_preise();
    $slots = (is_array($roh) && isset($roh['slots']) && is_array($roh['slots']))
        ? $roh['slots'] : array();
    $preise = array();
    foreach ($slots as $ts => $sl) {
        if (is_array($sl) && isset($sl['ct'])) { $preise[(int) $ts] = (float) $sl['ct']; }
    }
    ksort($preise);
    $umwelt = oc_umwelt();
    $plan = plan_rechnen($preise, 900, (int) $st['slotstart'], $cfg['regeln'], array(
        'pv'       => isset($umwelt['pv']) ? $umwelt['pv'] : null,
        'pv_summe' => isset($umwelt['pv_summe']) ? $umwelt['pv_summe'] : null,
        'soc'      => isset($umwelt['soc']) ? $umwelt['soc'] : null,
        'neg'      => !empty($st['neg']) ? 1 : 0,
        /* Das Tagesmittel nur uebergeben, wenn es eines gibt. 0.0 waere ein
         * Wert und kein Nichtwissen - und bei negativen Preisen ist der
         * Unterschied entscheidend. */
        'mittel'   => (!empty($st['ok']) && !empty($st['heute']['n']))
                      ? (float) $st['heute']['avg'] : null,
        'laufend'  => oc_laufend_lesen(),
    ), array(
        'budget_kw'   => $cfg['budget_kw'],
        'pv_bonus'    => $cfg['pv_bonus'],
        'pv_schwelle' => $cfg['pv_schwelle'],
        'budget2_kw'  => $cfg['budget2_kw'],
        'budget2_von' => $cfg['budget2_von'],
        'budget2_bis' => $cfg['budget2_bis'],
    ));
    foreach ($plan as $i => $p) {
        $plan[$i]['name'] = (isset($cfg['regeln'][$i]['name']) && $cfg['regeln'][$i]['name'] !== '')
            ? $cfg['regeln'][$i]['name'] : ('Regel ' . ($i + 1));
    }
    return array('plan' => $plan, 'belegung' => plan_belegung($plan),
                 'slotlen' => 900, 'preise' => $preise);
}

/** Kennzahlen eines Tages aus den Viertelstunden zwischen $von und $bis. */
function oc_tagstats($slots, $von, $bis)
{
    $teil = array();
    foreach ($slots as $ts => $s) {
        if ($ts >= $von && $ts < $bis) { $teil[$ts] = $s; }
    }
    if (!$teil) { return null; }
    ksort($teil);
    $min = null; $max = null; $sum = 0.0; $n = 0;
    $liste = array();
    foreach ($teil as $ts => $s) {
        $ct = (float) $s['ct'];
        $liste[$ts] = round($ct, 3);
        $sum += $ct; $n++;
        if ($min === null || $ct < $min[1]) { $min = array($ts, $ct); }
        if ($max === null || $ct > $max[1]) { $max = array($ts, $ct); }
    }
    $std = oc_stunden($teil);
    return array(
        'n'      => $n,
        'avg'    => round($sum / max(1, $n), 3),
        'minp'   => round($min[1], 3), 'mints' => $min[0],
        'minh'   => (int) date('G', $min[0]), 'minm' => (int) date('i', $min[0]),
        'maxp'   => round($max[1], 3), 'maxts' => $max[0],
        'maxh'   => (int) date('G', $max[0]), 'maxm' => (int) date('i', $max[0]),
        'slots'  => $liste,
        'hours'  => $std,
    );
}

function oc_tagstats_leer()
{
    return array('n' => 0, 'avg' => 0, 'minp' => 0, 'mints' => 0, 'minh' => 0, 'minm' => 0,
                 'maxp' => 0, 'maxts' => 0, 'maxh' => 0, 'maxm' => 0,
                 'slots' => array(), 'hours' => array());
}

/**
 * Guenstigstes zusammenhaengendes Fenster ab jetzt, gesucht im
 * Viertelstundenraster. $stunden ist die gewuenschte Laenge in Stunden.
 */
function oc_fenster($slots, $stunden)
{
    $stunden = max(1, min(12, (int) $stunden));
    $len = $stunden * 4;                       // Anzahl Viertelstunden
    $jetzt = time();
    $start = $jetzt - ($jetzt % 900);
    $liste = array();
    foreach ($slots as $ts => $s) {
        if ($ts >= $start) { $liste[$ts] = (float) $s['ct']; }
    }
    ksort($liste);
    $ks = array_keys($liste);
    $best = null;
    for ($i = 0; $i + $len <= count($ks); $i++) {
        if ($ks[$i + $len - 1] - $ks[$i] !== ($len - 1) * 900) { continue; }  // Luecke
        $s = 0.0;
        for ($j = 0; $j < $len; $j++) { $s += $liste[$ks[$i + $j]]; }
        $avg = $s / $len;
        if ($best === null || $avg < $best[1]) { $best = array($ks[$i], $avg); }
    }
    if ($best === null) {
        return array('ts' => 0, 'h' => -1, 'm' => 0, 'in' => -1, 'ct' => 0);
    }
    return array(
        'ts' => $best[0],
        'h'  => (int) date('G', $best[0]),
        'm'  => (int) date('i', $best[0]),
        'in' => (int) round(($best[0] - $start) / 60),   // in wie vielen Minuten
        'ct' => round($best[1], 3),
    );
}

/** Kompletter Zustand (Cache 4 Minuten). */
function oc_state($force = false)
{
    $cfg = oc_config();
    $jetzt = time();
    $slotstart = $jetzt - ($jetzt % 900);
    $hstart = $jetzt - ($jetzt % 3600);
    $cache = oc_tmpdir() . '/state.json';
    if (!$force && is_file($cache) && time() - filemtime($cache) < 240) {
        $c = json_decode((string) @file_get_contents($cache), true);
        if (is_array($c) && isset($c['slotstart']) && (int) $c['slotstart'] === $slotstart) {
            return $c;
        }
    }

    $pr = oc_preise($force);
    $slots = $pr['slots'];
    $heute = oc_tagstats($slots, strtotime('today 00:00'), strtotime('tomorrow 00:00'));
    $morgen = oc_tagstats($slots, strtotime('tomorrow 00:00'), strtotime('tomorrow 00:00') + 86400);
    $std = oc_stunden($slots);

    $cur = isset($slots[$slotstart]) ? round((float) $slots[$slotstart]['ct'], 3) : 0.0;
    $curn = (isset($slots[$slotstart]) && $slots[$slotstart]['net'] !== null)
        ? round((float) $slots[$slotstart]['net'], 3) : 0.0;
    $next = isset($slots[$slotstart + 900]) ? round((float) $slots[$slotstart + 900]['ct'], 3) : 0.0;
    $curh = isset($std[$hstart]) ? $std[$hstart] : 0.0;
    $nexth = isset($std[$hstart + 3600]) ? $std[$hstart + 3600] : 0.0;

    // Rang der laufenden Viertelstunde in den naechsten 24 Stunden
    $fenster24 = array();
    foreach ($slots as $ts => $s) {
        if ($ts >= $slotstart && $ts < $slotstart + 86400) { $fenster24[$ts] = (float) $s['ct']; }
    }
    $werte = array_values($fenster24);
    sort($werte);
    $rang = 1;
    foreach ($werte as $v) { if ($v < $cur) { $rang++; } }

    // Rang der laufenden Stunde in den naechsten 24 Stunden
    $std24 = array();
    foreach ($std as $ts => $v) {
        if ($ts >= $hstart && $ts < $hstart + 86400) { $std24[$ts] = $v; }
    }
    $wh = array_values($std24);
    sort($wh);
    $rangh = 1;
    foreach ($wh as $v) { if ($v < $curh) { $rangh++; } }

    $ok = ($heute !== null && $heute['n'] > 0);
    $level = 2;
    if ($ok && $cur <= (float) $cfg['cheap']) { $level = 1; }
    if ($ok && $cur >= (float) $cfg['expensive']) { $level = 3; }

    $st = array(
        'ok'          => $ok ? 1 : 0,
        'demo'        => (int) $pr['demo'],
        'veraltet'    => !empty($pr['veraltet']) ? 1 : 0,
        'brutto_ok'   => (int) $pr['brutto_ok'],
        'fehler'      => (string) $pr['fehler'],
        'stand'       => (int) $pr['stand'],
        'ts'          => $jetzt,
        'slotstart'   => $slotstart,
        'hstart'      => $hstart,
        'stunde'      => (int) date('G'),
        'minute'      => (int) date('i'),
        'cur'         => $cur,
        'cur_netto'   => $curn,
        'cur_h'       => $curh,
        'next'        => $next,
        'next_h'      => $nexth,
        'neg'         => $curn < 0 ? 1 : 0,
        'rank'        => $rang,
        'rankd'       => count($werte) ? count($werte) + 1 - $rang : 99,
        'n'           => count($werte),
        'rank_h'      => $rangh,
        'n_h'         => count($wh),
        'level'       => $level,
        'heute'       => $heute !== null ? $heute : oc_tagstats_leer(),
        'morgen'      => $morgen !== null ? $morgen : oc_tagstats_leer(),
        'tomorrow_ok' => ($morgen !== null && $morgen['n'] > 0) ? 1 : 0,
        'fenster'     => oc_fenster($slots, $cfg['window']),
        'fenster_len' => (int) $cfg['window'],
        'slots_n'     => count($slots),
    );

    $co2 = oc_co2($force);
    $st['co2']       = $co2['now'];
    $st['co2_ok']    = $co2['ok'];
    $st['co2_min']   = $co2['min'];
    $st['co2_minh']  = $co2['minh'];
    $st['co2_avg']   = $co2['avg'];
    $st['co2_clean'] = ($co2['ok'] && $co2['now'] > 0 && $co2['now'] <= (float) $cfg['co2_clean']) ? 1 : 0;

    $mc = oc_month_compare(1);
    $lauf = $mc ? reset($mc) : null;
    $st['fix']        = (float) $cfg['fixed_price'];
    $st['dyn_monat']  = $lauf ? $lauf['dynp'] : 0;
    $st['diff_monat'] = $lauf ? $lauf['diff'] : 0;
    $st['euro_monat'] = $lauf ? $lauf['euro'] : 0;

    $sh = oc_shift_saving(7);
    $st['shift_ct']   = $sh['ct'];
    $st['shift_euro'] = $sh['euro'];
    $st['shift_jahr'] = $sh['euro_jahr'];

    /* HIER STAND DER SCHREIBBEFEHL FUER DEN ZWISCHENSPEICHER - MITTEN IN
     * DER FUNKTION. Er steht jetzt am Ende, vor dem return.
     *
     * Was das angerichtet hat, ist am Endpunkt gemessen worden: alles, was
     * unterhalb dieser Zeile noch in $st gelegt wird - die Stundenprofile
     * ph/pm/pr, pv_summe, soc, die REGELN und planlast -, fehlte im
     * Zwischenspeicher. Der zweite und jeder weitere Aufruf innerhalb von
     * 240 Sekunden bekam ihn und damit einen verstuemmelten Zustand:
     *
     *     1. Aufruf (frisch)          146 Felder
     *     2. Aufruf (Zwischenspeicher) 118 Felder
     *     es fehlten REGEL1_AKTIV bis REGEL4_RANG und PH/PM/PR00-23
     *
     * Und weil der minuetliche Cron den Zwischenspeicher selbst fuellt,
     * war das der Regelfall und nicht die Ausnahme: der Miniserver bekam
     * die Schaltausgaenge praktisch nie. Dazu sprang das Zahlenformat
     * (12.000 frisch, 12 aus dem Speicher), weil json_encode aus 12.0 eine
     * ganze Zahl macht - und die MQTT-Bremse "nur bei Aenderung senden"
     * war wirkungslos, weil ihre Signatur im Minutentakt zwischen 145 und
     * 117 Schluesseln sprang.
     *
     * Merksatz fuer den naechsten, der hier etwas anhaengt: ein
     * Zwischenspeicher wird geschrieben, wenn der Wert FERTIG ist. */

    // Stundenmittel fuer den Spot Price Optimizer: der Baustein hat nur
    // 24 Preiseingaenge, Viertelstunden nimmt er nicht an.
    $stdh = oc_stunden($slots);
    $st['profil_heute'] = array();
    $st['profil_morgen'] = array();
    $st['profil_relativ'] = array();
    $t0 = strtotime('today 00:00');
    $m0 = strtotime('tomorrow 00:00');
    for ($h = 0; $h < 24; $h++) {
        $st['profil_heute'][$h] = isset($stdh[$t0 + $h * 3600]) ? $stdh[$t0 + $h * 3600] : 0.0;
        $st['profil_morgen'][$h] = isset($stdh[$m0 + $h * 3600]) ? $stdh[$m0 + $h * 3600] : 0.0;
        $st['profil_relativ'][$h] = isset($stdh[$hstart + $h * 3600]) ? $stdh[$hstart + $h * 3600] : 0.0;
    }
    /* Fremde Auskuenfte vor den Regeln - der Planer braucht sie. Ein
     * Fehlschlag macht den Zustand nicht ungueltig: ohne Prognose plant der
     * Planer wie vorher, nur ohne Gutschrift. */
    $umwelt = oc_umwelt();
    $st['pv_summe'] = isset($umwelt['pv_summe']) ? $umwelt['pv_summe'] : null;
    $st['soc'] = isset($umwelt['soc']) ? $umwelt['soc'] : null;
    $st['pv_meldung'] = isset($umwelt['pv_meldung']) ? $umwelt['pv_meldung'] : '';
    $st['soc_meldung'] = isset($umwelt['soc_meldung']) ? $umwelt['soc_meldung'] : '';

    // Zuletzt: die Regeln brauchen neg, slotstart und das Tagesmittel.
    $st['regeln'] = oc_regeln($slots, $st);

    // Verplante Leistung in der laufenden Viertelstunde.
    $st['planlast'] = 0.0;
    $st['spart_eur'] = 0.0;
    foreach ($st['regeln'] as $r) {
        if (!empty($r['aktiv'])) { $st['planlast'] += (float) $r['leistung']; }
        $st['spart_eur'] += isset($r['spart_eur']) ? (float) $r['spart_eur'] : 0.0;
    }
    $st['planlast'] = round($st['planlast'], 2);
    $st['spart_eur'] = round($st['spart_eur'], 2);

    /* Die Hysterese fortschreiben: welcher Block laeuft gerade, und bis
     * wann? Erst NACH der Rechnung, damit der naechste Lauf ihn vorfindet.
     * Siehe oc_laufend_fortschreiben(). */
    oc_laufend_fortschreiben($st['regeln'], $slotstart);

    /* ERST JETZT den Zwischenspeicher schreiben - $st ist vollstaendig.
     * Die Begruendung steht oben an der Stelle, an der der Befehl bis
     * 1.0.9 stand. */
    @file_put_contents($cache, json_encode($st));

    oc_log_if_changed('zustand', 'jetzt=' . $st['cur'] . ' ct rang=' . $st['rank'] . '/' . $st['n']
        . ' niveau=' . $st['level'] . ' morgen=' . $st['tomorrow_ok'] . ' demo=' . $st['demo']);
    return $st;
}

/* ==================================================================
 * CO2-Intensitaet (Fraunhofer ISE, Energy-Charts - frei, ohne Konto)
 * ================================================================== */

function oc_co2($force = false)
{
    $cfg = oc_config();
    $aus = array('ok' => 0, 'now' => 0, 'min' => 0, 'minh' => -1, 'max' => 0,
                 'maxh' => -1, 'avg' => 0, 'hours' => array());
    if (empty($cfg['co2_enabled'])) { return $aus; }

    $cache = oc_tmpdir() . '/co2.json';
    if (!$force && is_file($cache) && time() - filemtime($cache) < 1800) {
        $c = json_decode((string) @file_get_contents($cache), true);
        if (is_array($c) && isset($c['ok'])) { return $c; }
    }
    $r = oc_http('https://api.energy-charts.info/co2eq?country=de', null, array(), 15);
    $d = $r['ok'] ? json_decode($r['body'], true) : null;
    if (!isset($d['unix_seconds']) || !is_array($d['unix_seconds'])) {
        if (is_file($cache)) {
            $c = json_decode((string) @file_get_contents($cache), true);
            if (is_array($c)) { return $c; }
        }
        oc_log_if_changed('co2', 'Abruf fehlgeschlagen (api.energy-charts.info, '
            . ($r['fehler'] !== '' ? $r['fehler'] : 'unlesbare Antwort') . ')');
        return $aus;
    }
    $eimer = array();
    foreach ($d['unix_seconds'] as $i => $ts) {
        $v = isset($d['co2eq'][$i]) ? $d['co2eq'][$i] : null;
        if ($v === null && isset($d['co2eq_forecast'][$i])) { $v = $d['co2eq_forecast'][$i]; }
        if ($v === null) { continue; }
        $h = ((int) $ts) - (((int) $ts) % 3600);
        if (!isset($eimer[$h])) { $eimer[$h] = array(0.0, 0); }
        $eimer[$h][0] += (float) $v;
        $eimer[$h][1]++;
    }
    ksort($eimer);
    $jetzt = time(); $hstart = $jetzt - ($jetzt % 3600);
    $hours = array(); $min = null; $max = null; $sum = 0; $n = 0; $cur = 0;
    foreach ($eimer as $h => $b) {
        $g = (int) round($b[0] / max(1, $b[1]));
        if ($h === $hstart) { $cur = $g; }
        if ($h < $hstart || $h >= $hstart + 86400) { continue; }
        $st = (int) date('G', $h);
        $hours[$st] = $g;
        $sum += $g; $n++;
        if ($min === null || $g < $min[1]) { $min = array($st, $g); }
        if ($max === null || $g > $max[1]) { $max = array($st, $g); }
    }
    if (!$n) { return $aus; }
    $out = array('ok' => 1, 'now' => $cur, 'min' => $min[1], 'minh' => $min[0],
                 'max' => $max[1], 'maxh' => $max[0], 'avg' => (int) round($sum / $n),
                 'hours' => $hours, 'ts' => time());
    @file_put_contents($cache, json_encode($out));
    oc_log_if_changed('co2', 'jetzt ' . $out['now'] . ' g/kWh, sauberste Stunde '
        . $out['minh'] . ' Uhr mit ' . $out['min'] . ' g');
    return $out;
}

/* ==================================================================
 * Historie und Tarifvergleich
 * ================================================================== */

/**
 * Vereinfachtes Haushalts-Lastprofil (H0-aehnlich), Summe rund 24.
 * Dient NUR der Gewichtung beim Vergleich fester/dynamischer Tarif - ohne
 * echte Verbrauchsdaten waere ein glatter Mittelwert zu optimistisch.
 */
function oc_profil()
{
    return array(0.55, 0.50, 0.45, 0.45, 0.50, 0.60, 0.85, 1.15, 1.25, 1.20, 1.15, 1.20,
                 1.30, 1.20, 1.05, 1.00, 1.05, 1.25, 1.45, 1.50, 1.40, 1.20, 0.95, 0.70);
}

/** Tageswerte fortschreiben: Ymd;Schnitt;Min;Max;gewichtet;CO2;Demo */
function oc_history_add($st = null)
{
    if ($st === null) { $st = oc_state(); }
    if (!$st['ok'] || empty($st['heute']['hours'])) { return; }
    $f = oc_datadir() . '/history.csv';
    $tag = date('Ymd');
    $zeilen = is_file($f) ? (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()) : array();
    foreach ($zeilen as $l) {
        if (strpos($l, $tag . ';') === 0) { return; }   // heute schon erfasst
    }
    /* Gewichtet wird mit dem ECHTEN Verbrauchsprofil, wenn eine Quelle
     * hinterlegt ist - sonst mit dem vereinfachten H0-Profil wie bisher.
     * Welches es war, steht in der Spalte 'quelle' der Historie. */
    list($prof, $herkunft) = oc_profil_aktiv();
    $ws = 0.0; $w = 0.0;
    foreach ($st['heute']['hours'] as $ts => $ct) {
        $h = (int) date('G', (int) $ts);
        $g = isset($prof[$h]) ? $prof[$h] : 1.0;
        $ws += ((float) $ct) * $g;
        $w += $g;
    }
    $avgw = $w > 0 ? round($ws / $w, 3) : $st['heute']['avg'];
    $zeile = $tag . ';' . $st['heute']['avg'] . ';' . $st['heute']['minp'] . ';'
           . $st['heute']['maxp'] . ';' . $avgw . ';' . (int) $st['co2_avg'] . ';' . (int) $st['demo'];
    $zeilen[] = $zeile;
    if (count($zeilen) > 400) { $zeilen = array_slice($zeilen, -400); }
    @file_put_contents($f, implode("\n", $zeilen) . "\n");
    oc_log('Tageswerte gesichert: Schnitt ' . $st['heute']['avg'] . ' ct (gewichtet ' . $avgw
        . ', Profil ' . $herkunft . '), Min ' . $st['heute']['minp']
        . ', Max ' . $st['heute']['maxp'] . ($st['demo'] ? ' [DEMO]' : ''));
}

/**
 * Den fertigen Tageswert beiseitelegen, damit er sich nachtragen laesst.
 *
 * Wird ab 23:40 bei jedem Lauf gerufen und ueberschreibt sich selbst. Faellt
 * der Lauf um 23:50 aus - Neustart, Update, Stromausfall -, findet der
 * naechste Tag hier den fertigen Wert vor und traegt ihn nach. Die Datei
 * liegt im DATENORDNER und nicht in /tmp: /tmp ist auf dem LoxBerry eine
 * Ramdisk und waere nach einem Neustart genau dann leer, wenn man sie
 * braucht. (Dieselbe Ueberlegung wie beim Marker des Monatsberichts.)
 */
function oc_tagesstand_merken($st = null)
{
    if ($st === null) { $st = oc_state(); }
    if (empty($st['ok']) || empty($st['heute']['hours'])) { return false; }
    list($prof, $herkunft) = oc_profil_aktiv();
    $ws = 0.0; $w = 0.0;
    foreach ($st['heute']['hours'] as $ts => $ct) {
        $h = (int) date('G', (int) $ts);
        $g = isset($prof[$h]) ? $prof[$h] : 1.0;
        $ws += ((float) $ct) * $g;
        $w += $g;
    }
    $avgw = $w > 0 ? round($ws / $w, 3) : $st['heute']['avg'];
    $tag = date('Ymd');
    $zeile = $tag . ';' . $st['heute']['avg'] . ';' . $st['heute']['minp'] . ';'
           . $st['heute']['maxp'] . ';' . $avgw . ';' . (int) $st['co2_avg'] . ';' . (int) $st['demo'];
    @file_put_contents(oc_datadir() . '/tagesstand.json',
        json_encode(array('tag' => $tag, 'zeile' => $zeile, 'profil' => $herkunft)));
    return true;
}

/** [[Ymd, avg, min, max, avg_gewichtet, co2, demo], ...] */
function oc_history_read($tage = 30)
{
    $f = oc_datadir() . '/history.csv';
    $out = array();
    if (is_file($f)) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $l) {
            $c = explode(';', $l);
            if (count($c) >= 4) {
                $out[] = array($c[0], (float) $c[1], (float) $c[2], (float) $c[3],
                               isset($c[4]) ? (float) $c[4] : 0.0,
                               isset($c[5]) ? (int) $c[5] : 0,
                               isset($c[6]) ? (int) $c[6] : 0);
            }
        }
    }
    return array_slice($out, -max(1, (int) $tage));
}

/** Monatsverbraeuche: array('use'=>0/1,'kwh'=>[12],'summe'=>kWh) */
function oc_months()
{
    $cfg = oc_config();
    $kwh = array(); $sum = 0.0;
    for ($i = 0; $i < 12; $i++) {
        $v = isset($cfg['months'][$i]) ? max(0, (float) $cfg['months'][$i]) : 0.0;
        $kwh[$i] = $v;
        $sum += $v;
    }
    return array('use' => $sum > 0 ? 1 : 0, 'kwh' => $kwh, 'summe' => round($sum, 1));
}

/** Monatsvergleich aus der Historie. */
function oc_month_compare($monate = 12)
{
    $cfg = oc_config();
    $fix = (float) $cfg['fixed_price'];
    $mon = oc_months();
    $agg = array();
    foreach (oc_history_read(400) as $r) {
        $m = substr($r[0], 0, 6);
        if (!isset($agg[$m])) { $agg[$m] = array('n' => 0, 'sum' => 0.0, 'sump' => 0.0); }
        $agg[$m]['n']++;
        $agg[$m]['sum'] += $r[1];
        $agg[$m]['sump'] += ($r[4] > 0) ? $r[4] : $r[1];
    }
    $out = array();
    foreach ($agg as $m => $a) {
        $dyn = round($a['sum'] / max(1, $a['n']), 3);
        $dynp = round($a['sump'] / max(1, $a['n']), 3);
        $diff = round($fix - $dynp, 3);      // positiv = dynamisch waere guenstiger
        $mi = ((int) substr($m, 4, 2)) - 1;
        $tage_mon = (int) date('t', strtotime(substr($m, 0, 4) . '-' . substr($m, 4, 2) . '-01'));
        $kwh_tag = ($mon['use'] && !empty($mon['kwh'][$mi]))
            ? $mon['kwh'][$mi] / max(1, $tage_mon)
            : max(0.1, (float) $cfg['consumption']) / 365.0;
        $out[$m] = array('monat' => $m, 'tage' => $a['n'], 'dyn' => $dyn, 'dynp' => $dynp,
                         'fix' => $fix, 'diff' => $diff,
                         'euro' => round($diff / 100 * $kwh_tag * $a['n'], 2),
                         'kwh' => round($kwh_tag * $a['n'], 1),
                         'quelle' => ($mon['use'] && !empty($mon['kwh'][$mi])) ? 'monat' : 'jahr');
    }
    krsort($out);
    return array_slice($out, 0, max(1, (int) $monate), true);
}

/** Vollkostenvergleich auf ein Jahr hochgerechnet. */
function oc_cost_compare()
{
    $cfg = oc_config();
    $mon = oc_months();
    $kwh_jahr = $mon['use'] ? $mon['summe'] : max(0, (float) $cfg['consumption']);
    $mpreis = array(); $alle = array();
    foreach (oc_history_read(400) as $r) {
        $mi = ((int) substr($r[0], 4, 2)) - 1;
        $p = ($r[4] > 0) ? $r[4] : $r[1];
        if (!isset($mpreis[$mi])) { $mpreis[$mi] = array(0.0, 0); }
        $mpreis[$mi][0] += $p;
        $mpreis[$mi][1]++;
        $alle[] = $p;
    }
    $schnitt = $alle ? array_sum($alle) / count($alle) : 0.0;
    if ($schnitt <= 0) {
        $st = oc_state();
        $schnitt = $st['ok'] ? (float) $st['heute']['avg'] : 0.0;
    }
    $tage = array(31, 28.25, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
    $dyn_arbeit = 0.0; $gemessen = 0;
    for ($i = 0; $i < 12; $i++) {
        $kwh_m = $mon['use'] ? $mon['kwh'][$i] : $kwh_jahr * $tage[$i] / 365.25;
        $p = (isset($mpreis[$i]) && $mpreis[$i][1] > 0) ? $mpreis[$i][0] / $mpreis[$i][1] : $schnitt;
        if (isset($mpreis[$i])) { $gemessen++; }
        $dyn_arbeit += $kwh_m * $p / 100;
    }
    $dyn_grund = max(0, (float) $cfg['dyn_grund']) * 12;
    $dyn_jahr = $dyn_arbeit + $dyn_grund;

    $fix_arbeit = $kwh_jahr * max(0, (float) $cfg['fixed_price']) / 100;
    $fix_grund = max(0, (float) $cfg['fix_grund']) * 12;
    $fix_zwischen = $fix_arbeit + $fix_grund;
    $rabatt_pct = max(0, min(100, (float) $cfg['fix_rabatt']));
    $rabatt = $fix_zwischen * $rabatt_pct / 100;
    $fix_nach = $fix_zwischen - $rabatt;
    $boni = max(0, (float) $cfg['fix_sofortbonus']) + max(0, (float) $cfg['fix_neubonus'])
          + $fix_zwischen * max(0, min(100, (float) $cfg['fix_neubonus_pct'])) / 100;
    $fix_jahr1 = $fix_nach - $boni;

    return array(
        'kwh' => round($kwh_jahr, 1), 'monate_gemessen' => $gemessen, 'schnitt' => round($schnitt, 3),
        'dyn_arbeit' => round($dyn_arbeit, 2), 'dyn_grund' => round($dyn_grund, 2),
        'dyn_jahr' => round($dyn_jahr, 2), 'dyn_monat' => round($dyn_jahr / 12, 2),
        'fix_arbeit' => round($fix_arbeit, 2), 'fix_grund' => round($fix_grund, 2),
        'fix_zwischen' => round($fix_zwischen, 2), 'rabatt_pct' => $rabatt_pct,
        'rabatt' => round($rabatt, 2), 'boni' => round($boni, 2),
        'fix_jahr1' => round($fix_jahr1, 2), 'fix_folge' => round($fix_nach, 2),
        'fix_monat1' => round($fix_jahr1 / 12, 2), 'fix_monatf' => round($fix_nach / 12, 2),
        'vorteil1' => round($fix_jahr1 - $dyn_jahr, 2),
        'vorteilf' => round($fix_nach - $dyn_jahr, 2),
    );
}

/** Ersparnis durch verschobenen Verbrauch (Abschaetzung aus der Historie). */
function oc_shift_saving($tage = 7)
{
    $cfg = oc_config();
    $kwh = max(0, (float) $cfg['shift_kwh']);
    $rows = oc_history_read(max(1, (int) $tage));
    $sum = 0.0; $n = 0;
    foreach ($rows as $r) {
        $sum += max(0, $r[1] - $r[2]);   // Tagesschnitt minus Tagesminimum
        $n++;
    }
    if (!$n) { return array('tage' => 0, 'ct' => 0, 'euro' => 0, 'euro_jahr' => 0, 'kwh' => $kwh); }
    $ct = round($sum / $n, 3);
    return array('tage' => $n, 'ct' => $ct, 'euro' => round($ct * $kwh * $n / 100, 2),
                 'euro_jahr' => round($ct * $kwh * 365 / 100, 2), 'kwh' => $kwh);
}

/* ==================================================================
 * MQTT - ueber das MQTT-Gateway von LoxBerry
 *
 * Der Gateway ist seit LoxBerry 3 BESTANDTEIL DES SYSTEMS, kein Plugin.
 * Ob Nachrichten ankommen koennen, sagt NICHT "Brokerhost ist gesetzt"
 * (der Wert ist ab Werk gesetzt), sondern Gatewayautostart.
 * ================================================================== */

function oc_gateway()
{
    $g = array('vorhanden' => 0, 'autostart' => 0, 'broker' => '', 'port' => 0,
               'udpport' => 0, 'lokal' => 0);
    $f = oc_paths()['general'];
    if (!is_file($f)) { return $g; }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!is_array($d)) { return $g; }
    $m = isset($d['Mqtt']) ? $d['Mqtt'] : (isset($d['mqtt']) ? $d['mqtt'] : array());
    if (!is_array($m)) { return $g; }
    $hol = function ($m, $a, $b) {
        if (isset($m[$a])) { return $m[$a]; }
        if (isset($m[$b])) { return $m[$b]; }
        return null;
    };
    $g['vorhanden'] = 1;
    $g['broker']    = (string) $hol($m, 'Brokerhost', 'brokerhost');
    $g['port']      = (int) $hol($m, 'Brokerport', 'brokerport');
    $g['udpport']   = (int) $hol($m, 'Udpinport', 'udpinport');
    $g['lokal']     = (int) $hol($m, 'Uselocalbroker', 'uselocalbroker');
    $as = $hol($m, 'Gatewayautostart', 'gatewayautostart');
    $g['autostart'] = in_array((string) $as, array('1', 'true', 'yes', 'on'), true) ? 1 : 0;
    return $g;
}

/**
 * Alle veroeffentlichten Themen: Schluessel => array(Sprachschluessel, Einheit).
 * Diese Liste ist die einzige Quelle - Reiter MQTT, Reiter Loxone und die
 * Loxone-Vorlage lesen alle hier.
 */
function oc_themen()
{
    $t = array(
        'ok'            => array('THEMA.OK', ''),
        'demo'          => array('THEMA.DEMO', ''),
        'cur'           => array('THEMA.CUR', 'ct/kWh'),
        'cur_netto'     => array('THEMA.CUR_NETTO', 'ct/kWh'),
        'cur_h'         => array('THEMA.CUR_H', 'ct/kWh'),
        'next'          => array('THEMA.NEXT', 'ct/kWh'),
        'next_h'        => array('THEMA.NEXT_H', 'ct/kWh'),
        'neg'           => array('THEMA.NEG', ''),
        'rank'          => array('THEMA.RANK', ''),
        'rankd'         => array('THEMA.RANKD', ''),
        'rank_h'        => array('THEMA.RANK_H', ''),
        'level'         => array('THEMA.LEVEL', ''),
        'avg_heute'     => array('THEMA.AVG_HEUTE', 'ct/kWh'),
        'min_heute'     => array('THEMA.MIN_HEUTE', 'ct/kWh'),
        'minh_heute'    => array('THEMA.MINH_HEUTE', 'h'),
        'max_heute'     => array('THEMA.MAX_HEUTE', 'ct/kWh'),
        'maxh_heute'    => array('THEMA.MAXH_HEUTE', 'h'),
        'morgen_ok'     => array('THEMA.MORGEN_OK', ''),
        'avg_morgen'    => array('THEMA.AVG_MORGEN', 'ct/kWh'),
        'min_morgen'    => array('THEMA.MIN_MORGEN', 'ct/kWh'),
        'minh_morgen'   => array('THEMA.MINH_MORGEN', 'h'),
        'max_morgen'    => array('THEMA.MAX_MORGEN', 'ct/kWh'),
        'maxh_morgen'   => array('THEMA.MAXH_MORGEN', 'h'),
        'fenster_start' => array('THEMA.FENSTER_START', 'h'),
        'fenster_min'   => array('THEMA.FENSTER_MIN', 'min'),
        'fenster_in'    => array('THEMA.FENSTER_IN', 'min'),
        'fenster_ct'    => array('THEMA.FENSTER_CT', 'ct/kWh'),
        'co2'           => array('THEMA.CO2', 'g/kWh'),
        'co2_min'       => array('THEMA.CO2_MIN', 'g/kWh'),
        'co2_minh'      => array('THEMA.CO2_MINH', 'h'),
        'co2_clean'     => array('THEMA.CO2_CLEAN', ''),
        'fix'           => array('THEMA.FIX', 'ct/kWh'),
        'dyn_monat'     => array('THEMA.DYN_MONAT', 'ct/kWh'),
        'diff_monat'    => array('THEMA.DIFF_MONAT', 'ct/kWh'),
        'euro_monat'    => array('THEMA.EURO_MONAT', 'EUR'),
        'shift_jahr'    => array('THEMA.SHIFT_JAHR', 'EUR'),
        'ann'           => array('THEMA.ANN', ''),
        'audio'         => array('THEMA.AUDIO', ''),
        'push'          => array('THEMA.PUSH', ''),
        'ptest'         => array('THEMA.PTEST', ''),
        'alter'         => array('THEMA.ALTER', 'min'),
    );
    // Schaltregeln: je Regel vier Themen. 'aktiv' ist das digitale Signal,
    // an dem in Loxone ein Eingang haengt - der Rest ist Beiwerk.
    $cfg = oc_config();
    for ($i = 1; $i <= OC_REGELN; $i++) {
        $name = trim((string) $cfg['regeln'][$i - 1]['name']);
        $zusatz = $name !== '' ? ' (' . $name . ')' : '';
        $t['regel' . $i . '_aktiv'] = array('THEMA.REGEL_AKTIV', '', $i, $zusatz);
        $t['regel' . $i . '_in']    = array('THEMA.REGEL_IN', 'min', $i, $zusatz);
        $t['regel' . $i . '_rest']  = array('THEMA.REGEL_REST', 'min', $i, $zusatz);
        $t['regel' . $i . '_ct']    = array('THEMA.REGEL_CT', 'ct/kWh', $i, $zusatz);
        $t['regel' . $i . '_verdraengt'] = array('THEMA.REGEL_VERDRAENGT', '', $i, $zusatz);
        $t['regel' . $i . '_sperre'] = array('THEMA.REGEL_SPERRE', '', $i, $zusatz);
        $t['regel' . $i . '_rang']   = array('THEMA.REGEL_RANG', '', $i, $zusatz);
        // ---- ab 1.1.0 ----
        $t['regel' . $i . '_fehlt'] = array('THEMA.REGEL_FEHLT', '', $i, $zusatz);
        $t['regel' . $i . '_spart'] = array('THEMA.REGEL_SPART', 'ct/kWh', $i, $zusatz);
        $t['regel' . $i . '_spart_eur'] = array('THEMA.REGEL_SPART_EUR', 'EUR', $i, $zusatz);
    }
    // Fahrplaner, global
    $t['plan_budget'] = array('THEMA.PLAN_BUDGET', 'kW');
    $t['plan_budget2'] = array('THEMA.PLAN_BUDGET2', 'kW');
    $t['plan_last']   = array('THEMA.PLAN_LAST', 'kW');
    $t['plan_pv']     = array('THEMA.PLAN_PV', 'kWh');
    $t['plan_soc']    = array('THEMA.PLAN_SOC', '%');
    $t['plan_spart']  = array('THEMA.PLAN_SPART', 'EUR');
    /* ---- Lebenszeichen (Hausstandard) ----
     * Diese drei gehen bei JEDEM Durchgang hinaus, auch wenn sich sonst
     * nichts geaendert hat - sonst faellt bei einer Anlage, die tagelang
     * dieselben Werte liefert, genau das Zeichen aus, das sagen soll, dass
     * das Plugin noch lebt. Der Gateway macht aus dem Schraegstrich einen
     * Unterstrich: octopus/status/ok wird zu octopus_status_ok. */
    $t['status/ok']      = array('THEMA.STATUS_OK', '');
    $t['status/ts']      = array('THEMA.STATUS_TS', 's');
    $t['status/zaehler'] = array('THEMA.STATUS_ZAEHLER', '');
    // Stundenprofil fuer den Spot Price Optimizer.
    $modus = (string) $cfg['profil_ein'];
    if ($modus === 'absolut' || $modus === 'beides') {
        for ($h = 0; $h < 24; $h++) {
            $t[sprintf('ph%02d', $h)] = array('THEMA.PH', 'ct/kWh', $h, '');
            $t[sprintf('pm%02d', $h)] = array('THEMA.PM', 'ct/kWh', $h, '');
        }
    }
    if ($modus === 'relativ' || $modus === 'beides') {
        for ($h = 0; $h < 24; $h++) {
            $t[sprintf('pr%02d', $h)] = array('THEMA.PR', 'ct/kWh', $h, '');
        }
    }
    return $t;
}

/**
 * Klartext zu einem Thema. Regel- und Profilthemen tragen zusaetzlich eine
 * Nummer (Regel 1-4, Stunde 0-23) und den vom Anwender vergebenen Namen -
 * ein Eingang "Wallbox" ist beim Verdrahten mehr wert als "Regel 1".
 */
/**
 * Der Name, unter dem ein Thema als virtueller Eingang ankommt.
 *
 * Der MQTT-Gateway ersetzt in Themen den Schraegstrich durch einen
 * Unterstrich: aus octopus/status/ok wird octopus_status_ok. Wer den
 * Eingang in Loxone unter dem Themennamen sucht, findet ihn sonst nicht -
 * und die Vorlage erzeugte einen Titel, den es nie gibt.
 *
 * Dieselbe Umformung macht die HTTP-Zeile des Endpunkts, damit derselbe
 * Wert auf beiden Wegen gleich heisst.
 */
function oc_thema_flach($k)
{
    return str_replace('/', '_', (string) $k);
}

/**
 * Einen Wert fuer die HTTP-Zeile formatieren.
 *
 * ENTSCHIEDEN WIRD AN DER EINHEIT, NICHT AM PHP-TYP.
 *
 * Vorher stand hier "is_float($v) ? sprintf('%.3f', $v) : $v". Das sieht
 * richtig aus und ist es nicht: der Zustand geht durch json_encode in den
 * Zwischenspeicher, und json_decode macht aus 12.0 wieder eine GANZE Zahl.
 * Derselbe Preis kam deshalb einmal als 12.000 und einmal als 12 heraus -
 * je nachdem, ob frisch gerechnet oder aus dem Speicher gelesen wurde.
 * Gemessen an zwei Aufrufen hintereinander.
 *
 * Fuer die Befehlserkennung in Loxone ist das gleichgueltig. Fuer den
 * Menschen, der zwei Aufrufe nebeneinander legt, ist es das nicht - und
 * fuer ein Pruefwerkzeug, das beide Wege vergleicht, erst recht nicht.
 */
function oc_wert_formatieren($k, $v, $info = null)
{
    if (is_array($v) || is_bool($v) || $v === null) { return ''; }
    if (!is_numeric($v)) { return (string) $v; }
    $einheit = (is_array($info) && isset($info[1])) ? (string) $info[1] : '';
    $mit_komma = in_array($einheit, array('ct/kWh', 'kWh', 'kW', 'EUR', '%'), true);
    return $mit_komma ? sprintf('%.3f', (float) $v) : (string) $v;
}

function oc_thema_text($info)
{
    $t = strip_tags(html_entity_decode(oc_t($info[0]), ENT_QUOTES, 'UTF-8'));
    if (isset($info[2])) { $t = sprintf($t, (int) $info[2]); }
    if (isset($info[3])) { $t .= (string) $info[3]; }
    return $t;
}

/** Werte zu den Themen. */
function oc_werte($st = null)
{
    $cfg = oc_config();
    if ($st === null) { $st = oc_state(); }
    $w = array(
        'ok'            => $st['ok'],
        'demo'          => $st['demo'],
        'cur'           => $st['cur'],
        'cur_netto'     => $st['cur_netto'],
        'cur_h'         => $st['cur_h'],
        'next'          => $st['next'],
        'next_h'        => $st['next_h'],
        'neg'           => $st['neg'],
        'rank'          => $st['rank'],
        'rankd'         => $st['rankd'],
        'rank_h'        => $st['rank_h'],
        'level'         => $st['level'],
        'avg_heute'     => $st['heute']['avg'],
        'min_heute'     => $st['heute']['minp'],
        'minh_heute'    => $st['heute']['minh'],
        'max_heute'     => $st['heute']['maxp'],
        'maxh_heute'    => $st['heute']['maxh'],
        'morgen_ok'     => $st['tomorrow_ok'],
        'avg_morgen'    => $st['morgen']['avg'],
        'min_morgen'    => $st['morgen']['minp'],
        'minh_morgen'   => $st['morgen']['minh'],
        'max_morgen'    => $st['morgen']['maxp'],
        'maxh_morgen'   => $st['morgen']['maxh'],
        'fenster_start' => $st['fenster']['h'],
        'fenster_min'   => $st['fenster']['m'],
        'fenster_in'    => $st['fenster']['in'],
        'fenster_ct'    => $st['fenster']['ct'],
        'co2'           => $st['co2'],
        'co2_min'       => $st['co2_min'],
        'co2_minh'      => $st['co2_minh'],
        'co2_clean'     => $st['co2_clean'],
        'fix'           => $st['fix'],
        'dyn_monat'     => $st['dyn_monat'],
        'diff_monat'    => $st['diff_monat'],
        'euro_monat'    => $st['euro_monat'],
        'shift_jahr'    => $st['shift_jahr'],
        'ann'           => oc_ann_active($st),
        'audio'         => empty($cfg['notify']['audio']) ? 0 : 1,
        'push'          => empty($cfg['notify']['push']) ? 0 : 1,
        'ptest'         => oc_ptest_active(),
        'alter'         => $st['stand'] > 0 ? (int) round((time() - $st['stand']) / 60) : 9999,
    );
    foreach ((array) (isset($st['regeln']) ? $st['regeln'] : array()) as $r) {
        $n = (int) $r['nr'];
        $w['regel' . $n . '_aktiv'] = (int) $r['aktiv'];
        $w['regel' . $n . '_in']    = (int) $r['in'];
        $w['regel' . $n . '_rest']  = (int) $r['rest'];
        $w['regel' . $n . '_ct']    = $r['ct'];
        // Fahrplaner. VERD und SPERRE beantworten die Frage, die sonst im
        // Dunkeln bleibt: warum laeuft es gerade NICHT?
        $w['regel' . $n . '_verdraengt'] = isset($r['verdraengt']) ? (int) $r['verdraengt'] : 0;
        $w['regel' . $n . '_sperre'] = oc_sperre_zahl(isset($r['gesperrt']) ? $r['gesperrt'] : '');
        $w['regel' . $n . '_rang'] = isset($r['rang']) ? (int) $r['rang'] : 50;
        // ---- ab 1.1.0 ----
        $w['regel' . $n . '_fehlt'] = isset($r['fehlt']) ? (int) $r['fehlt'] : 0;
        $w['regel' . $n . '_spart'] = isset($r['spart_ct']) ? (float) $r['spart_ct'] : 0.0;
        $w['regel' . $n . '_spart_eur'] = isset($r['spart_eur']) ? (float) $r['spart_eur'] : 0.0;
    }
    // Fahrplaner, global
    $w['plan_budget'] = (float) $cfg['budget_kw'];
    $w['plan_budget2'] = (float) $cfg['budget2_kw'];
    $w['plan_last'] = isset($st['planlast']) ? (float) $st['planlast'] : 0.0;
    $w['plan_pv'] = (isset($st['pv_summe']) && $st['pv_summe'] !== null) ? (float) $st['pv_summe'] : 0.0;
    $w['plan_soc'] = (isset($st['soc']) && $st['soc'] !== null) ? (float) $st['soc'] : -1;
    $w['plan_spart'] = isset($st['spart_eur']) ? (float) $st['spart_eur'] : 0.0;
    /* ---- Lebenszeichen ----
     * 'ok' ist NICHT dasselbe wie das Thema 'ok' weiter oben: dort heisst
     * es "es liegen gueltige Preise vor", hier "das Plugin hat gerade
     * gearbeitet". Beides kann auseinanderfallen, und genau dann will man
     * es unterscheiden koennen. */
    $w['status/ok'] = 1;
    $w['status/ts'] = time();
    $w['status/zaehler'] = oc_zaehler_stand();
    $modus = (string) $cfg['profil_ein'];
    if ($modus === 'absolut' || $modus === 'beides') {
        for ($h = 0; $h < 24; $h++) {
            $w[sprintf('ph%02d', $h)] = isset($st['profil_heute'][$h]) ? $st['profil_heute'][$h] : 0;
            $w[sprintf('pm%02d', $h)] = isset($st['profil_morgen'][$h]) ? $st['profil_morgen'][$h] : 0;
        }
    }
    if ($modus === 'relativ' || $modus === 'beides') {
        for ($h = 0; $h < 24; $h++) {
            $w[sprintf('pr%02d', $h)] = isset($st['profil_relativ'][$h]) ? $st['profil_relativ'][$h] : 0;
        }
    }
    return $w;
}

/**
 * Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.
 *
 * Das Gateway liest ZEILENWEISE. Ein Zeilenumbruch im Wert - aus einer
 * Fehlermeldung des Betriebssystems, einem Geraetenamen oder der Ausgabe
 * eines Systembefehls - zerlegt die Uebertragung, und aus den Bruchstuecken
 * bildet das Gateway erfundene Themen. Ein Tabulator schadet ebenso, weil
 * Leerzeichen Thema und Wert trennt.
 */
function oc_mqtt_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

/**
 * Die Werte an den UDP-Eingang des MQTT-Gateways geben.
 *
 * $nur_lebenszeichen = true schickt AUSSCHLIESSLICH status/ok, status/ts
 * und status/zaehler. Das ist der Fall "es hat sich nichts geaendert": die
 * Sendebremse haelt die 140 Werte zurueck, aber das Lebenszeichen geht
 * trotzdem hinaus. Sonst schwiege das Plugin an einem Tag mit gleichen
 * Preisen stundenlang, und niemand koennte "laeuft noch" von "haengt" oder
 * "ist tot" unterscheiden.
 */
function oc_mqtt_publish($st = null, $nur_lebenszeichen = false)
{
    $cfg = oc_config();
    if (empty($cfg['mqtt_enabled'])) { return false; }
    $g = oc_gateway();
    if (!$g['udpport']) {
        oc_log_if_changed('mqtt', 'kein UDP-Eingang des MQTT-Gateways gefunden');
        return false;
    }
    // Ohne die Socket-Erweiterung waere der Aufruf unten ein Fatal Error,
    // den auch das @ nicht abfaengt. Also vorher fragen und es sagen.
    if (!function_exists('socket_create')) {
        oc_log_if_changed('mqtt', 'PHP-Erweiterung sockets fehlt - es kann nichts an den Gateway gesendet werden');
        return false;
    }
    if ($st === null && !$nur_lebenszeichen) { $st = oc_state(); }
    /* Das Praefix geht als THEMA in die Zeile. oc_mqtt_thema_saeubern()
     * sorgt dafuer, dass darin kein Zeilenumbruch und kein Leerzeichen
     * steckt - der Gateway liest zeilenweise, und aus den Bruchstuecken
     * bildet er erfundene Themen. Der WERT wird weiter unten gesaeubert;
     * bis 1.0.9 wurde nur der Wert behandelt und das Thema nie. */
    $praefix = oc_mqtt_thema_saeubern($cfg['mqtt_topic'], 'octopus');

    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) {
        oc_log_if_changed('mqtt', 'UDP-Socket konnte nicht angelegt werden');
        return false;
    }
    if ($nur_lebenszeichen) {
        $werte = array(
            'status/ok'      => 1,
            'status/ts'      => time(),
            'status/zaehler' => oc_zaehler_stand(),
        );
    } else {
        $werte = oc_werte($st);
    }
    foreach ($werte as $k => $v) {
        $msg = 'publish ' . $praefix . '/' . $k . ' ' . oc_mqtt_wert_saeubern($v);
        @socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $g['udpport']);
    }
    socket_close($s);
    return true;
}

/* ==================================================================
 * Ansage (TTS) und Meldungen
 * ================================================================== */

/** TTS-Adresse bauen. Bei mode=audioserver gibt es keine - dann null. */
function oc_tts_url($text)
{
    $cfg = oc_config();
    $t = $cfg['tts'];
    if ($t['mode'] === 'audioserver') {
        return null;    // Original Loxone Audioserver: Ansage nur ueber Loxone Config
    }
    if ($t['mode'] === 'musicserver' && (string) $t['ip'] === '') {
        return '';   // ohne IP laesst sich die Music-Server-Adresse nicht bauen
    }

    /* Zonenliste EINMAL fuer alle Modi normalisieren. Vorher wurde nur im
     * Modus musicserver je Zone getrimmt; in den Vorlagen-Modi ging die
     * Eingabe roh in {zones} - aus "2, 4, 6" wurde eine Adresse mit
     * Leerzeichen. */
    $zl = array();
    foreach (explode(',', (string) $t['zones']) as $z) {
        $z = trim($z);
        if ($z !== '') { $zl[] = $z; }
    }
    $t['zones'] = implode(',', $zl);
    if ($t['mode'] === 'musicserver') {
        $vol = max(1, min(100, (int) $t['volume']));
        $zonen = array();
        foreach (explode(',', (string) $t['zones']) as $z) {
            $z = trim($z);
            if ($z === '') { continue; }
            $zonen[] = (strpos($z, '~') === false) ? $z . '~' . $vol : $z;
        }
        $zs = $zonen ? implode(',', $zonen) : '1~' . $vol;
        return 'http://' . $t['ip'] . ':' . (int) $t['port'] . '/audio/grouped/tts/'
             . $zs . '/' . rawurlencode($t['lang'] . '|' . $text);
    }
    $tpl = trim((string) $t['template']);
    if ($tpl === '') { $tpl = 'http://{ip}:{port}/tts?text={text}&zone={zones}&vol={vol}'; }
    /* Die IP wird nur verlangt, wenn die Vorlage sie auch verwendet.
     * Vorher stand die Pruefung unbedingt am Anfang - eine eigene Vorlage
     * ohne {ip} war damit unbenutzbar (AWM-1.2.0-Fund, hier nachgezogen). */
    if ((string) $t['ip'] === '' && strpos($tpl, '{ip}') !== false) {
        return '';
    }
    return str_replace(
        array('{ip}', '{port}', '{zones}', '{vol}', '{lang}', '{text}'),
        array($t['ip'], (int) $t['port'], $t['zones'], (int) $t['volume'], $t['lang'], rawurlencode($text)),
        $tpl);
}

function oc_say($text)
{
    $url = oc_tts_url($text);
    if ($url === null) {
        oc_log('Ansage: Modus "Original Loxone Audioserver" - die Sprachausgabe erfolgt in Loxone Config');
        return false;
    }
    if ($url === '') {
        oc_log('Ansage uebersprungen: keine Adresse fuer die Sprachausgabe hinterlegt');
        return false;
    }
    $r = oc_http($url, null, array(), 10);
    oc_log('Ansage gesendet: "' . $text . '" -> ' . ($r['ok'] ? 'OK' : $r['fehler']));
    return $r['ok'];
}

/** Zahl deutsch aussprechen: 24.3 -> "24,3" */
function oc_num($v, $dec = 1)
{
    return str_replace('.', ',', number_format((float) $v, $dec, '.', ''));
}

function oc_announce_text($st = null)
{
    if ($st === null) { $st = oc_state(); }
    if (!$st['ok']) { return ''; }
    $t = str_replace('%P%', oc_num($st['cur'], 1), oc_t('ANSAGE.PREIS'));
    if ($st['demo']) { $t = oc_t('ANSAGE.DEMO') . ' ' . $t; }
    if ($st['neg']) {
        $t = str_replace('%P%', oc_num($st['cur'], 1), oc_t('ANSAGE.NEGATIV'));
        if ($st['demo']) { $t = oc_t('ANSAGE.DEMO') . ' ' . $t; }
    } elseif ($st['level'] === 1) {
        $t .= ' ' . oc_t('ANSAGE.GUENSTIG');
    } elseif ($st['level'] === 3) {
        $t .= ' ' . oc_t('ANSAGE.TEUER');
    }
    if ($st['fenster']['in'] === 0) {
        $t .= ' ' . str_replace('%N%', (int) $st['fenster_len'], oc_t('ANSAGE.FENSTER_JETZT'));
    } elseif ($st['fenster']['in'] > 0) {
        $t .= ' ' . str_replace(array('%H%', '%M%'),
              array((int) $st['fenster']['h'], sprintf('%02d', (int) $st['fenster']['m'])),
              oc_t('ANSAGE.FENSTER_AB'));
    }
    if (!empty($st['co2_ok']) && !empty($st['co2_clean'])) {
        $t .= ' ' . str_replace('%G%', (int) $st['co2'], oc_t('ANSAGE.SAUBER'));
    }
    return $t;
}

function oc_tomorrow_text($st = null)
{
    if ($st === null) { $st = oc_state(); }
    if (!$st['tomorrow_ok']) { return ''; }
    $t = str_replace(
        array('%MINH%', '%MINM%', '%MINP%', '%MAXH%', '%MAXM%', '%MAXP%', '%AVG%'),
        array((int) $st['morgen']['minh'], sprintf('%02d', (int) $st['morgen']['minm']),
              oc_num($st['morgen']['minp'], 1),
              (int) $st['morgen']['maxh'], sprintf('%02d', (int) $st['morgen']['maxm']),
              oc_num($st['morgen']['maxp'], 1), oc_num($st['morgen']['avg'], 1)),
        oc_t('ANSAGE.MORGEN'));
    return $st['demo'] ? oc_t('ANSAGE.DEMO') . ' ' . $t : $t;
}

function oc_hour_selected($h = null)
{
    $cfg = oc_config();
    if ($h === null) { $h = (int) date('G'); }
    return in_array((int) $h, array_map('intval', (array) $cfg['notify']['hours']), true);
}

/** Meldefenster fuer Loxone: 1 in den ersten 10 Minuten einer aktivierten Stunde. */
function oc_ann_active($st = null)
{
    $cfg = oc_config();
    if ($st === null) { $st = oc_state(); }
    if (!$st['ok'] || (int) date('i') >= 10) { return 0; }
    $sel = oc_hour_selected();
    $neg = !empty($cfg['notify']['negative']) && $st['neg'];
    if (!$sel && !$neg) { return 0; }
    if ($sel && !empty($cfg['notify']['only_cheap'])
        && $st['cur'] > (float) $cfg['cheap'] && !$st['neg']) {
        return 0;
    }
    return 1;
}

/** Merker der Test-Pushnachricht, 5 Minuten gueltig. */
function oc_ptest_active()
{
    $f = oc_tmpdir() . '/ptest';
    return (is_file($f) && time() - filemtime($f) < 300) ? 1 : 0;
}

/** Cron: stuendliche Ansage und Meldung "Preise fuer morgen sind da". */
function oc_announce_check($st = null)
{
    $cfg = oc_config();
    if ($st === null) { $st = oc_state(); }

    if (!empty($cfg['notify']['audio']) && $st['ok'] && (int) date('i') === 0) {
        $flag = oc_tmpdir() . '/said_' . date('YmdH');
        if (!is_file($flag)) {
            $sel = oc_hour_selected();
            $neg = !empty($cfg['notify']['negative']) && $st['neg'];
            $skip = $sel && !empty($cfg['notify']['only_cheap'])
                 && $st['cur'] > (float) $cfg['cheap'] && !$st['neg'];
            if (($sel || $neg) && !$skip) {
                @file_put_contents($flag, '1');
                $txt = oc_announce_text($st);
                if ($txt !== '') { oc_say($txt); }
            }
        }
    }
    if (!empty($cfg['notify']['tomorrow']) && $st['tomorrow_ok']) {
        $flag = oc_tmpdir() . '/tomorrow_' . date('Ymd');
        if (!is_file($flag)) {
            @file_put_contents($flag, '1');
            if (!empty($cfg['notify']['audio'])) {
                $txt = oc_tomorrow_text($st);
                if ($txt !== '') { oc_say($txt); }
            }
            oc_log('Preise fuer morgen da: min ' . $st['morgen']['minp'] . ' ct um '
                . $st['morgen']['minh'] . ':' . sprintf('%02d', $st['morgen']['minm'])
                . ', max ' . $st['morgen']['maxp'] . ' ct um '
                . $st['morgen']['maxh'] . ':' . sprintf('%02d', $st['morgen']['maxm']));
        }
    }
    foreach (glob(oc_tmpdir() . '/said_*') ?: array() as $f) {
        if (time() - (int) filemtime($f) > 7200) { @unlink($f); }
    }
    foreach (glob(oc_tmpdir() . '/tomorrow_*') ?: array() as $f) {
        if (basename($f) !== 'tomorrow_' . date('Ymd')) { @unlink($f); }
    }
}

/* ==================================================================
 * Loxone-Vorlage
 *
 * LoxBerry::LoxoneTemplateBuilder gibt es nur in Perl. Der geprüfte
 * PHP-Nachbau wird uebernommen, nicht neu geschrieben: Attributreihenfolge,
 * CRLF als Zeilenende und der Tabulator vor den Kindelementen entsprechen
 * dem Original (Vorlage: ap_xml_virtual_in_http aus APC-UPS 1.0.0).
 * ================================================================== */

function oc_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function oc_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . oc_x($kopf['title']) . '" ';
    $o .= 'Comment="' . oc_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . oc_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . oc_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . oc_x($c['title']) . '" ';
        $o .= 'Comment="' . oc_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . oc_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="-2147483647" ';
        $o .= 'MaxVal="2147483647"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Vorlage erzeugen. $art ist 'mqtt_in' oder 'http_in'.
 * Rueckgabe: array(dateiname, inhalt)
 */
function oc_vorlage($art = 'mqtt_in')
{
    $cfg = oc_config();
    $praefix = trim((string) $cfg['mqtt_topic']);
    if ($praefix === '') { $praefix = 'octopus'; }
    $fuss = 'Erzeugt vom LoxBerry-Plugin Octopus Dynamic (' . date('d.m.Y') . ')';

    if ($art === 'http_in') {
        // Rueckfallebene: Loxone holt die Textzeile selbst und zieht die Werte
        // per Befehlserkennung heraus. MQTT ist der Regelweg.
        $host = oc_eigene_ip();
        $cmds = array();
        foreach (oc_themen() as $k => $info) {
            $flach = strtoupper(oc_thema_flach($k));
            $cmds[] = array('title' => 'OCTOPUS_' . $flach,
                            'comment' => oc_thema_text($info),
                            'check' => $flach . '=\v;');
        }
        return array('octopus_http.xml', oc_xml_virtual_in_http(array(
            'title'   => 'Octopus Dynamic (HTTP)',
            'address' => 'http://' . $host . '/plugins/' . oc_paths()['plugin']
                       . '/index.php?token=' . (string) $cfg['aktionstoken'] . '&aktion=status',
            'polling' => '300',
            'comment' => $fuss,
        ), $cmds));
    }

    $cmds = array();
    foreach (oc_themen() as $k => $info) {
        $cmds[] = array(
            // Der Gateway bildet den Titel aus dem Thema und ersetzt dabei
            // den Schraegstrich - siehe oc_thema_flach().
            'title'   => $praefix . '_' . oc_thema_flach($k),
            'comment' => oc_thema_text($info) . ($info[1] !== '' ? ' [' . $info[1] . ']' : ''),
            'check'   => ' ',
        );
    }
    return array('octopus_eingaenge.xml', oc_xml_virtual_in_http(array(
        'title'   => 'Octopus Dynamic',
        'address' => 'http://localhost',
        'polling' => '604800',
        'comment' => $fuss,
    ), $cmds));
}

/** Eigene Netzadresse bestimmen (fuer Adressen in der Anleitung). */
function oc_eigene_ip()
{
    $kand = array();
    if (!empty($_SERVER['SERVER_ADDR'])) { $kand[] = $_SERVER['SERVER_ADDR']; }
    if (!empty($_SERVER['HTTP_HOST'])) {
        $h = preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']);
        if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $h)) { $kand[] = $h; }
    }
    if (function_exists('socket_create')) {
        $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($s) {
            if (@socket_connect($s, '8.8.8.8', 53)) {
                $addr = ''; $port = 0;
                if (@socket_getsockname($s, $addr, $port) && $addr !== '') { $kand[] = $addr; }
            }
            socket_close($s);
        }
    }
    $hn = @gethostbyname(@gethostname());
    if ($hn && preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $hn)) { $kand[] = $hn; }
    foreach ($kand as $ip) {
        if ($ip !== '' && strpos($ip, '127.') !== 0) { return $ip; }
    }
    return '127.0.0.1';
}

/**
 * Fassungsnummer aus der Plugindatenbank. Der MD5-Schluessel dort haengt an
 * Autor, E-Mail und Plugin-Name - deshalb wird ueber den ORDNERNAMEN gesucht,
 * nicht ueber einen fest verdrahteten Schluessel.
 */
function oc_version()
{
    $f = oc_paths()['home'] . '/data/system/plugindatabase.json';
    if (!is_file($f)) { return ''; }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!isset($d['plugins']) || !is_array($d['plugins'])) { return ''; }
    foreach ($d['plugins'] as $e) {
        if (isset($e['folder']) && $e['folder'] === oc_paths()['plugin']) {
            return isset($e['version']) ? (string) $e['version'] : '';
        }
    }
    return '';
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. language_en.ini muss deshalb
 * immer vollstaendig sein.
 * ================================================================== */

function oc_sprache()
{
    $s = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $s = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $s = getenv('LBLANG');
    }
    $s = strtolower(substr((string) $s, 0, 2));
    return in_array($s, array('de', 'en'), true) ? $s : 'en';
}

/**
 * Text zu einem Schluessel "ABSCHNITT.SCHLUESSEL". Ist der Schluessel
 * unbekannt, wird er selbst zurueckgegeben - so faellt beim Durchsehen
 * sofort auf, was fehlt, statt dass die Seite leer bleibt.
 */
function oc_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $p = oc_paths();
        // Installiert: <home>/templates/plugins/<ordner>/lang
        $pfad = $p['home'] . '/templates/plugins/' . $p['plugin'] . '/lang';
        if (!is_dir($pfad)) {
            // Archiv/Entwicklung: drei Ebenen ueber dieser Bibliothek
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . oc_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        // INI_SCANNER_RAW liefert die Anfuehrungszeichen mit zurueck, in die
        // jeder Wert gesetzt werden muss. Die gehoeren nicht in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) { $texte[$ab][$s] = trim((string) $w, '"'); }
        }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}

/** Fehlerschluessel in einen lesbaren Satz uebersetzen. */
function oc_fehlertext($schluessel)
{
    if ((string) $schluessel === '') { return ''; }
    if (strpos($schluessel, 'FEHLER_HTTP:') === 0) {
        return str_replace('%C%', substr($schluessel, 12), oc_t('FEHLER.HTTP'));
    }
    $t = oc_t('FEHLER.' . substr($schluessel, 7));
    return $t === 'FEHLER.' . substr($schluessel, 7) ? oc_t('FEHLER.UNBEKANNT') : $t;
}


/**
 * Die Fassung des LoxBerry-MQTT-Gateways - 0 heisst "nicht feststellbar".
 *
 * Sie steht als Mqtt.Gatewayversion in config/system/general.json (ab Werk
 * 1) und entscheidet, was der Anwender eintragen muss: unter V1 jedes Thema
 * von Hand auf der Abo-Seite, ab V2 erscheint die Themengruppe von selbst in
 * den Subscriptions.
 *
 * Die Datei wird hier eigens gelesen, obwohl andere Stellen sie auch lesen.
 * Das ist Absicht: dieser Baustein passt damit in jedes Plugin, unabhaengig
 * davon, wie es seinen MQTT-Zustand ermittelt - und er geht nicht kaputt,
 * wenn jemand jene Funktion umbaut.
 */
function oc_gateway_fassung()
{
    $home = getenv('LBHOMEDIR');
    if (!$home && defined('LBHOMEDIR')) {
        $home = LBHOMEDIR;
    }
    if (!$home || !is_dir($home)) {
        return 0;
    }
    $d = @json_decode((string) @file_get_contents(
        $home . '/config/system/general.json'), true);
    if (!is_array($d)) {
        return 0;
    }
    foreach (array('Mqtt', 'mqtt') as $ab) {
        if (!isset($d[$ab]) || !is_array($d[$ab])) {
            continue;
        }
        foreach (array('Gatewayversion', 'gatewayversion') as $sl) {
            if (isset($d[$ab][$sl]) && (string) $d[$ab][$sl] !== '') {
                return (int) $d[$ab][$sl];
            }
        }
    }
    return 0;
}

/**
 * Der Hinweis zum MQTT-Abo - in der Fassung, die zum GATEWAY passt.
 *
 * Bis hierher stand an der Ausgabestelle unbedingt "Ohne diesen Eintrag
 * kommt am Miniserver nichts an". Das gilt fuer Gateway V1; ab V2 schickte
 * der Satz jeden Anwender zu einem Eingabeplatz, den es nicht mehr gibt.
 *
 * Drei Ausgaenge: ist die Fassung nicht feststellbar, werden BEIDE Faelle
 * genannt statt einer behauptet.
 */
function oc_abo_text()
{
    $f = oc_gateway_fassung();
    if ($f <= 0) {
        return oc_t('MQTT.ABO_UNBEKANNT');
    }
    $gemessen = ' <span class="sm-mono">'
              . sprintf(oc_t('MQTT.ABO_GEMESSEN'), $f) . '</span>';
    return oc_t($f >= 2 ? 'MQTT.ABO_V2' : 'MQTT.ABO_WARNUNG') . $gemessen;
}


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Die sieben Punkte aus REGELN_2, und der wichtigste ist der dritte: eine
 * halb gueltige Datei ueberschreibt GAR NICHTS. Wer eine Sicherung
 * zurueckspielt, will entweder den ganzen Stand oder gar keinen - eine zur
 * Haelfte uebernommene Konfiguration ist schlimmer als die alte, und man
 * sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function oc_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(oc_t('EINST.SICH_KEIN_JSON')), 0);
    }
    $neu = oc_vorgaben();
    $bekannt = array_keys($neu);
    $schranken = oc_schranken();
    $anzahl = 0;
    $zugang = null;

    foreach ($daten as $k => $w) {
        /* ---- 1. Der eigene Kopf ----
         * Schluessel mit fuehrendem Unterstrich sind Beschriftung, keine
         * Einstellung: _hinweis, _plugin, _fassung, _stand. Sie werden
         * uebersprungen und NICHT als fremd beanstandet - sonst wiese das
         * Plugin seine eigene Datei ab. Genau dieser Fall ist anderswo im
         * Haus schon einmal aufgetreten. */
        if ((string) $k !== '' && $k[0] === '_') { continue; }

        /* ---- 2. Die Zugangsdaten ----
         * Sie stehen nur in der Datei, wenn beim Sichern der Haken gesetzt
         * war. Sie gehoeren NICHT in die Konfiguration, sondern in ihre
         * eigene Datei mit Rechten 0600 - deshalb hier herausgenommen und
         * dem Aufrufer gesondert zurueckgegeben. */
        if ((string) $k === 'zugang' && is_array($w)) {
            $zugang = array(
                'email'    => oc_text(isset($w['email']) ? $w['email'] : '', 200),
                'passwort' => (isset($w['passwort']) && !is_array($w['passwort']))
                              ? (string) $w['passwort'] : '',
                'konto'    => oc_text(isset($w['konto']) ? $w['konto'] : '', 40),
            );
            if ($zugang['email'] !== '' && !oc_email_gueltig($zugang['email'])) {
                $mangel[] = sprintf(oc_t('EINST.SICH_WERT'), 'zugang.email');
            }
            if ($zugang['konto'] !== '' && !oc_konto_gueltig($zugang['konto'])) {
                $mangel[] = sprintf(oc_t('EINST.SICH_WERT'), 'zugang.konto');
            }
            $anzahl++;
            continue;
        }

        /* ---- 3. Unbekannte Schluessel ----
         * Eine Beanstandung, kein stiller Verlust: sie stammen aus einer
         * anderen Fassung oder aus einem anderen Plugin. */
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(oc_t('EINST.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }

        /* ---- 4. Und jetzt der WERT ----
         *
         * DAS IST DIE HAELFTE, DIE GEFEHLT HAT. Bis 1.0.9 wurde nur der
         * Schluessel geprueft. Gemessen mit zehn von Hand gebauten Dateien:
         * NEUN wurden angenommen - ein MQTT-Praefix mit Zeilenumbruch, ein
         * leeres Aktionstoken, "cheap" als Feld und als Text, eine
         * PV-Adresse auf 127.0.0.1, eine Ansage-Vorlage auf einen fremden
         * Rechner, ein negativer Jahresverbrauch, eine Fensterlaenge 999.
         * Das Formular weist jeden dieser Werte ab; der Rueckspielweg tat
         * es nicht.
         *
         * ABGEWIESEN, NICHT GEKAPPT: beim Speichern ueber das Formular ist
         * Kappen richtig, denn der Bediener sieht das Ergebnis sofort. Bei
         * einer Datei saehe niemand, dass aus 99999 kW eine 200 wurde. */
        if (oc_wert_pruefen($k, $w, $schranken) !== '') {
            $mangel[] = sprintf(oc_t('EINST.SICH_WERT'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = oc_t('EINST.SICH_LEER');
    }

    /* ---- 5. Was nur im ZUSAMMENSPIEL falsch sein kann ----
     *
     * Einzeln sind 99 und 1 beide gueltige Schwellen. Zusammen ergeben sie
     * ein Preisniveau, das nie "normal" wird. Das Formular weist die
     * Kombination ab und meldet sie; oc_config() setzt sie stillschweigend
     * auf die Vorgaben zurueck. Beim Zurueckspielen ist beides falsch: der
     * Bediener saehe nicht, dass seine Schwellen verworfen wurden. */
    if (oc_zahl($neu['cheap'], 20.0, -1000, 1000) >= oc_zahl($neu['expensive'], 35.0, -1000, 1000)) {
        $mangel[] = oc_t('EINST.SICH_SCHWELLEN');
    }

    /* Die Regelliste ist eine LISTE von Regeln. Ein Feld mit Text darin
     * kaeme durch die Typpruefung, und oc_config() machte daraus eine
     * Regel aus lauter Vorgabewerten - ohne ein Wort. */
    if (is_array($neu['regeln'])) {
        foreach ($neu['regeln'] as $rk => $rv) {
            if (!is_array($rv)) {
                $mangel[] = sprintf(oc_t('EINST.SICH_WERT'),
                    'regeln.' . htmlspecialchars((string) $rk, ENT_QUOTES, 'UTF-8'));
                break;
            }
        }
    }

    /* Alles oder nichts: eine halb gueltige Datei ueberschreibt GAR NICHTS. */
    return array($mangel ? null : $neu, $mangel, $anzahl, $mangel ? null : $zugang);
}

/**
 * Taugt der Wert ueberhaupt als Wert?
 *
 * Die grobe Wache vor der feinen: kein Feld, wo eine Zahl stehen muss,
 * keine Steuerzeichen, keine unmaessige Laenge. Sie greift auch fuer
 * Schluessel, fuer die es keine eigene Schranke gibt.
 */
function oc_wert_taugt($w, $max = 4096)
{
    if (is_bool($w) || $w === null || is_array($w)) { return false; }
    if (is_int($w) || is_float($w)) { return true; }
    $s = (string) $w;
    if (strlen($s) > $max) { return false; }
    // Ein Zeilenumbruch in einem Wert zerlegt die UDP-Zeile an den
    // MQTT-Gateway - siehe oc_mqtt_wert_saeubern().
    return !preg_match('/[\x00-\x1F\x7F]/', $s);
}

/**
 * Einen einzelnen Wert gegen dieselbe Positivliste pruefen, die auch die
 * Konfiguration benutzt. Rueckgabe: '' wenn in Ordnung, sonst ein Kuerzel.
 */
function oc_wert_pruefen($k, $w, $schranken = null)
{
    if ($schranken === null) { $schranken = oc_schranken(); }
    if (!isset($schranken[$k])) {
        // Kein Eintrag in den Schranken: dann wenigstens die grobe Wache.
        return oc_wert_taugt($w) ? '' : 'unbrauchbar';
    }
    $s = $schranken[$k];
    $art = $s[0];

    if ($art === 'f') {
        /* Felder: regeln, months, notify, tts. Ihre Innereien normalisiert
         * oc_config() Wert fuer Wert; hier zaehlt, dass es ueberhaupt ein
         * Feld ist - ein Text an dieser Stelle liesse spaeter "+=" auf
         * eine Zeichenkette laufen, und das ist unter PHP 8 ein
         * TypeError mitten in der Seite. */
        return is_array($w) ? '' : 'kein_feld';
    }
    if (!oc_wert_taugt($w)) { return 'unbrauchbar'; }

    if ($art === 'b') {
        return in_array((string) $w, array('0', '1'), true) ? '' : 'kein_haken';
    }
    if ($art === 'z' || $art === 'g') {
        $roh = trim((string) $w);
        $t = str_replace(',', '.', $roh);
        if (!is_numeric($t)) { return 'keine_zahl'; }
        if ($art === 'g' && !preg_match('/^-?[0-9]+$/', $roh)) { return 'nicht_ganz'; }
        $v = (float) $t;
        if ($v < (float) $s[2] || $v > (float) $s[3]) { return 'ausserhalb'; }
        return '';
    }
    if ($art === 'w') {
        return in_array((string) $w, array_slice($s, 1), true) ? '' : 'unbekannt';
    }
    if ($art === 't') {
        $t = (string) $w;
        $laenge = function_exists('mb_strlen') ? mb_strlen($t, 'UTF-8') : strlen($t);
        if ($laenge > (int) $s[1]) { return 'zu_lang'; }
        /* Adressen muessen Adressen sein - eine zurueckgespielte Datei darf
         * den Abruf nicht auf einen fremden Rechner umlenken. */
        if (substr($k, -4) === '_url' && $t !== '' && !preg_match('#^https?://#i', $t)) {
            return 'keine_adresse';
        }
        if ($k === 'mqtt_topic' && $t !== '' && !preg_match('#^[A-Za-z0-9_/-]+$#', $t)) {
            return 'kein_thema';
        }
        if ($k === 'aktionstoken' && $t !== '' && !preg_match('/^[A-Za-z0-9]{8,64}$/', $t)) {
            return 'kein_token';
        }
        return '';
    }
    return '';
}

/**
 * Die Sicherungsdatei bauen.
 *
 * VIER DINGE, DIE SIE TRAGEN MUSS:
 *
 *  1. ALLE Schluessel aus oc_vorgaben(), nicht nur die abweichenden -
 *     sonst steht nach dem Zurueckspielen auf einer anderen Fassung ein
 *     Vorgabewert, den niemand gewaehlt hat.
 *
 *  2. DAS AKTIONSTOKEN. Ohne es stuenden nach dem Zurueckspielen alle
 *     Felder richtig, und das Plugin kaeme trotzdem nicht an die Anlage:
 *     die Adressen im Miniserver tragen es. Die Datei waere wertlos.
 *
 *  3. WAHLWEISE DIE ZUGANGSDATEN. Der erklaerte Zweck ist der UMZUG auf
 *     einen zweiten LoxBerry. Ohne E-Mail, Passwort und Kundennummer ist
 *     der Umzug NICHT fertig - dort stuenden alle Felder richtig, und es
 *     kaemen trotzdem keine Preise. Bis 1.0.9 behauptete der Warntext am
 *     Knopf, die Datei enthalte die Zugangsdaten; gemessen enthielt sie
 *     keine. Jetzt entscheidet ein Haken, und der Text sagt beides.
 *
 *  4. EINEN LESBAREN KOPF mit Datum, Plugin und Fassung. Seine Schluessel
 *     beginnen mit einem Unterstrich und werden beim Einlesen
 *     uebersprungen.
 *
 * Rueckgabe: der fertige JSON-Text, oder '' wenn er sich nicht bilden
 * liess (ungueltiges UTF-8 in einem Regelnamen zum Beispiel).
 */
function oc_sicherung_bauen($mit_zugang = false)
{
    $cfg = oc_config();
    $kopf = array(
        '_hinweis' => 'Sicherung der Einstellungen des LoxBerry-Plugins Octopus Dynamic.'
                    . ' Im Reiter Einstellungen unter "Einstellungen zurueckspielen"'
                    . ' wieder einlesen.',
        '_plugin'  => 'octopus',
        '_fassung' => oc_version(),
        '_stand'   => date('Y-m-d H:i:s'),
        '_zugang'  => $mit_zugang ? 'enthalten' : 'nicht enthalten',
    );
    $daten = $kopf + $cfg;
    if ($mit_zugang) {
        $z = oc_zugang();
        $daten['zugang'] = array(
            'email' => $z['email'], 'passwort' => $z['passwort'], 'konto' => $z['konto'],
        );
    }
    $js = json_encode($daten,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    /* json_encode kann false liefern. Ein blankes "echo json_encode(...);
     * exit;" haette dann eine 0-Byte-Datei geliefert, die wie eine
     * Sicherung aussieht. Der Aufrufer meldet stattdessen einen Fehler. */
    return $js === false ? '' : $js;
}

/* ==================================================================
 * Formulartoken - der Wachposten am Eingang
 *
 * Bis 1.0.9 hatte dieses Plugin GAR KEINEN: gemessen mit einer Suche ueber
 * den ganzen webfrontend-Zweig, kein 'formtoken', kein 'fmt', kein
 * 'hash_hmac'. Eine fremde Seite konnte im angemeldeten Browser einen
 * Preisabruf, ein MQTT-Senden oder eine Sprachansage ausloesen.
 *
 * Der Token wird aus dem Aktionstoken abgeleitet und nicht eigens
 * gespeichert - es gibt also kein zweites Geheimnis, das verlorengehen
 * kann, und die Sicherungsdatei traegt beides mit dem einen Wert.
 *
 * EIN Wachposten am Eingang, nicht eine Pruefung je Handler: einen
 * einzelnen Handler kann man beim Erweitern vergessen, den Eingang nicht.
 * ================================================================== */

function oc_formtoken($cfg = null)
{
    if ($cfg === null) { $cfg = oc_config(); }
    $t = isset($cfg['aktionstoken']) ? (string) $cfg['aktionstoken'] : '';
    if ($t === '') { return ''; }
    return hash_hmac('sha256', 'formular-v1', $t);
}

/**
 * Traegt dieser POST ein gueltiges Merkmal?
 *
 * FAIL CLOSED an zwei Stellen: ohne eingerichtetes Aktionstoken gibt es
 * kein Merkmal und damit kein Ja, und ein leeres Feld wird abgewiesen,
 * BEVOR hash_equals darankommt - hash_equals('', '') ist true.
 */
function oc_formtoken_ok($cfg = null)
{
    $soll = oc_formtoken($cfg);
    if ($soll === '') { return false; }
    $ist = (isset($_POST['fmt']) && !is_array($_POST['fmt'])) ? (string) $_POST['fmt'] : '';
    if ($ist === '') { return false; }
    return hash_equals($soll, $ist);
}

/* ==================================================================
 * Hysterese: was laeuft, laeuft zu Ende
 *
 * Der Planer bekommt bei jedem Lauf eine frische Preisreihe. Ohne
 * Gedaechtnis kann er deshalb bei jedem Abruf zu einem anderen Ergebnis
 * kommen - und die Wallbox schaltet mitten im Ladevorgang ab, weil in
 * drei Stunden eine Viertelstunde billiger geworden ist.
 *
 * Gemerkt wird nur EINE Zahl je Regel: bis wann der begonnene Block
 * laeuft. Sie wird gesetzt, wenn ein Block ANFAENGT, und nicht mehr
 * angefasst, bis er vorbei ist. Damit kann sie sich nicht selbst
 * verlaengern - das waere eine Regel, die nie wieder ausgeht.
 *
 * Die Ablage liegt in /tmp und uebersteht einen Neustart nicht. Das ist
 * richtig so: nach einem Neustart laeuft ohnehin nichts mehr, und ein
 * Gedaechtnis an einen Block, den niemand mehr faehrt, waere falsch.
 * ================================================================== */

/** array(Regelindex => bis_ts). Abgelaufene Eintraege fallen weg. */
function oc_laufend_lesen()
{
    $cfg = oc_config();
    if (empty($cfg['hysterese'])) { return array(); }
    $f = oc_tmpdir() . '/laufend.json';
    if (!is_file($f)) { return array(); }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!is_array($d)) { return array(); }
    $jetzt = time();
    $out = array();
    foreach ($d as $i => $bis) {
        if (is_array($bis)) { continue; }
        $bis = (int) $bis;
        // Harte Obergrenze: kein Block laeuft laenger als 24 Stunden.
        if ($bis > $jetzt && $bis <= $jetzt + 86400) { $out[(int) $i] = $bis; }
    }
    return $out;
}

/**
 * Nach der Rechnung fortschreiben.
 *
 * Drei Faelle je Regel:
 *   laeuft und war noch nicht vermerkt  -> Ende eintragen
 *   laeuft und war vermerkt             -> unveraendert stehen lassen
 *   laeuft nicht                        -> Eintrag entfernen
 */
function oc_laufend_fortschreiben($regeln, $jetzt)
{
    $cfg = oc_config();
    $f = oc_tmpdir() . '/laufend.json';
    if (empty($cfg['hysterese'])) {
        /* is_file() VOR unlink(). Das @ genuegt nicht, wenn ein eigener
         * Fehlerbehandler gesetzt ist - der wird unabhaengig von
         * error_reporting gerufen, und "No such file or directory" steht
         * dann als Befund im Protokoll, obwohl nichts fehlt. Dieselbe
         * Falle wie bei mkdir(); im Haus schon zweimal hineingelaufen. */
        if (is_file($f)) { @unlink($f); }
        return;
    }
    $alt = oc_laufend_lesen();
    $neu = array();
    foreach ((array) $regeln as $r) {
        if (!is_array($r) || empty($r['aktiv'])) { continue; }
        $i = (int) $r['nr'] - 1;
        if (isset($alt[$i])) {
            $neu[$i] = $alt[$i];
            continue;
        }
        $rest = isset($r['rest']) ? (int) $r['rest'] : 0;
        if ($rest > 0) { $neu[$i] = (int) $jetzt + $rest * 60; }
    }
    @file_put_contents($f, json_encode($neu));
}

/* ==================================================================
 * Benachrichtigung des LoxBerry (die Glocke in der Kopfzeile)
 *
 * Bis 1.0.9 gab es nur MQTT-Themen, die erst in Loxone verdrahtet werden
 * mussten. Wer das nicht getan hat, merkte einen Ausfall gar nicht: die
 * virtuellen Eingaenge behalten ihren letzten Wert, und in der App sieht
 * alles normal aus, obwohl die Preise von gestern sind.
 *
 * notify_ext() steckt in loxberry_log.php. Ein '@' hilft gegen "undefined
 * function" NICHT - das ist ein fataler Fehler, kein unterdrueckbarer.
 * Deshalb function_exists() davor. (Bauart uebernommen aus dem
 * Spotpreis-Tibber-Plugin, damit beide Linien dasselbe tun.)
 * ================================================================== */

function oc_notify($thema, $stufe, $text)
{
    $cfg = oc_config();
    if (empty($cfg['notify']['lb'])) { return false; }

    /* Nur bei AENDERUNG melden. Ohne diese Bremse stuenden nach einem Tag
     * Ausfall 1440 gleichlautende Meldungen in der Glocke. */
    $d = oc_datadir();
    $f = $d . '/.notify_' . preg_replace('/[^a-z0-9_]/i', '', (string) $thema);
    $neu = $stufe . '|' . md5((string) $text);
    $alt = is_file($f) ? trim((string) @file_get_contents($f)) : '';
    if ($alt === $neu) { return false; }
    @file_put_contents($f, $neu);
    if ($stufe === 'ok') { return true; }        // Entwarnung: nur merken

    // loxberry_log.php nachladen, wenn es da ist - der Cron laedt es nicht.
    $lib = oc_paths()['home'] . '/libs/phplib/loxberry_log.php';
    if (!function_exists('notify_ext') && is_file($lib)) { @require_once $lib; }
    if (!function_exists('notify_ext')) {
        oc_log_if_changed('kein_notify', 'Der Hinweis "' . $text . '" konnte nicht an das '
            . 'Benachrichtigungszentrum gehen: notify_ext() gibt es in dieser '
            . 'LoxBerry-Fassung nicht.');
        return false;
    }
    notify_ext(array(
        'PACKAGE'  => oc_paths()['plugin'],
        'NAME'     => 'octopus',
        'MESSAGE'  => (string) $text,
        'SEVERITY' => ($stufe === 'fehler') ? 3 : 4,
    ));
    return true;
}

/**
 * Die beiden Anlaesse, bei denen die Glocke laeuten soll.
 * Wird vom minuetlichen Lauf gerufen.
 */
function oc_notify_pruefen($st = null)
{
    $cfg = oc_config();
    if (empty($cfg['notify']['lb'])) { return; }
    if ($st === null) { $st = oc_state(); }

    $grenze = max(1, (int) $cfg['notify']['lb_stunden']);
    $alter = ((int) $st['stand'] > 0) ? (int) round((time() - (int) $st['stand']) / 3600) : 9999;
    if ($alter >= $grenze) {
        oc_notify('abruf', 'fehler', str_replace(
            array('%H%', '%F%'),
            array($alter === 9999 ? '?' : $alter, oc_fehlertext((string) $st['fehler'])),
            oc_t('NOTIFY.ABRUF')));
    } else {
        oc_notify('abruf', 'ok', 'ok');
    }

    if (!empty($st['tomorrow_ok'])) {
        oc_notify('morgen_' . date('Ymd'), 'hinweis', str_replace(
            array('%MINP%', '%MINH%', '%MAXP%', '%MAXH%'),
            array(oc_num($st['morgen']['minp'], 1), (int) $st['morgen']['minh'],
                  oc_num($st['morgen']['maxp'], 1), (int) $st['morgen']['maxh']),
            oc_t('NOTIFY.MORGEN')));
    }
    // Alte Merker aufraeumen: ein Tagesmerker von gestern hat ausgedient.
    foreach (glob(oc_datadir() . '/.notify_morgen_*') ?: array() as $alt) {
        if (basename($alt) !== '.notify_morgen_' . date('Ymd')) { @unlink($alt); }
    }
}

/* ==================================================================
 * Lebenszeichen
 *
 * Hausstandard: <praefix>/status/ok, /ts und /zaehler gehen bei JEDEM
 * Durchgang hinaus - auch dann, wenn sich sonst nichts geaendert hat.
 * Sonst faellt bei einer Anlage, die tagelang dieselben Werte liefert,
 * genau das Zeichen aus, das sagen soll, dass das Plugin noch lebt.
 * ================================================================== */

/** Laufende Nummer 0 bis 999, je Durchgang eins weiter. */
function oc_zaehler()
{
    $f = oc_tmpdir() . '/zaehler.txt';
    $n = is_file($f) ? (int) @file_get_contents($f) : 0;
    $n = ($n + 1) % 1000;
    @file_put_contents($f, (string) $n);
    return $n;
}

/** Der zuletzt vergebene Stand, ohne ihn weiterzudrehen. */
function oc_zaehler_stand()
{
    $f = oc_tmpdir() . '/zaehler.txt';
    return is_file($f) ? (int) @file_get_contents($f) : 0;
}

/* ==================================================================
 * Der echte Verbrauch statt des erfundenen Haushaltsprofils
 *
 * oc_profil() liefert ein vereinfachtes H0-Profil. Das ist eine ehrliche
 * Abschaetzung und wird auch so genannt - aber es ist geraten. Wer eine
 * Adresse hinterlegt, die den Verbrauch je Stunde liefert, bekommt statt
 * der Schaetzung eine Messung.
 *
 * Ausgewertet wird mit plan_pv_lesen() - dieselbe Rechnung, dieselben
 * Formen, dieselben Fehlermeldungen. Zwei Auswerter fuer dasselbe waeren
 * einer zu viel.
 * ================================================================== */

/**
 * Rueckgabe: array('profil' => array(0..23 => Gewicht)|null,
 *                  'meldung' => '', 'tage' => Anzahl ausgewerteter Tage)
 * Gecacht wie die uebrigen Fremdauskuenfte: hoechstens alle 15 Minuten.
 */
function oc_verbrauch($force = false)
{
    $cfg = oc_config();
    $leer = array('profil' => null, 'meldung' => '', 'tage' => 0, 'ts' => 0);
    if ((string) $cfg['verbrauch_quelle'] === '' || trim((string) $cfg['verbrauch_url']) === '') {
        return $leer;
    }
    $cache = oc_tmpdir() . '/verbrauch.json';
    if (!$force && is_file($cache) && time() - filemtime($cache) < 900) {
        $c = json_decode((string) @file_get_contents($cache), true);
        if (is_array($c)) { return $c + $leer; }
    }
    $erg = $leer;
    $erg['ts'] = time();
    $roh = oc_holen($cfg['verbrauch_url']);
    if ($roh === null) {
        $erg['meldung'] = 'NICHT_ERREICHBAR';
        @file_put_contents($cache, json_encode($erg));
        return $erg;
    }
    list($werte, $m) = plan_pv_lesen($roh, $cfg['verbrauch_quelle'], $cfg['verbrauch_pfad'],
        $cfg['verbrauch_zeitfeld'], $cfg['verbrauch_wertfeld'], $cfg['verbrauch_einheit'], 3600);
    $erg['meldung'] = $m;
    if ($werte) {
        /* Aus den Stundenwerten ein Gewichtsprofil bilden: je Stunde des
         * Tages der Mittelwert ueber alle gelieferten Tage, dann auf einen
         * Mittelwert von 1,0 normiert. Damit passt es an dieselbe Stelle
         * wie oc_profil(), und die Rechnung dahinter bleibt unveraendert. */
        $eimer = array_fill(0, 24, array(0.0, 0));
        $tage = array();
        foreach ($werte as $ts => $wh) {
            $h = (int) date('G', (int) $ts);
            $eimer[$h][0] += (float) $wh;
            $eimer[$h][1]++;
            $tage[date('Ymd', (int) $ts)] = 1;
        }
        $roh24 = array();
        $summe = 0.0; $n = 0;
        for ($h = 0; $h < 24; $h++) {
            $v = $eimer[$h][1] > 0 ? $eimer[$h][0] / $eimer[$h][1] : 0.0;
            $roh24[$h] = $v;
            if ($eimer[$h][1] > 0) { $summe += $v; $n++; }
        }
        /* Nur normieren, wenn ALLE 24 Stunden belegt sind. Ein Profil mit
         * Loechern gewichtete die fehlenden Stunden mit null und machte den
         * Vergleich schoener, als er ist. */
        if ($n === 24 && $summe > 0) {
            $mittel = $summe / 24.0;
            $profil = array();
            for ($h = 0; $h < 24; $h++) { $profil[$h] = round($roh24[$h] / $mittel, 4); }
            $erg['profil'] = $profil;
            $erg['tage'] = count($tage);
        } else {
            $erg['meldung'] = 'UNVOLLSTAENDIG';
        }
    }
    @file_put_contents($cache, json_encode($erg));
    return $erg;
}

/**
 * Das Gewichtsprofil, das der Kostenvergleich benutzt - und woher es kommt.
 * Rueckgabe: array(array(24 Gewichte), 'echt'|'geschaetzt')
 */
function oc_profil_aktiv()
{
    $v = oc_verbrauch();
    if (is_array($v['profil']) && count($v['profil']) === 24) {
        return array($v['profil'], 'echt');
    }
    return array(oc_profil(), 'geschaetzt');
}

/* ==================================================================
 * Historie: Nachtrag und Ausfuhr
 * ================================================================== */

/**
 * Die Tageswerte fortschreiben - mit Erledigt-Marker statt Zeitfenster.
 *
 * Bis 1.0.9 lief oc_history_add() nur zwischen 23:50 und 23:59. War der
 * LoxBerry in diesen zehn Minuten aus, im Neustart oder im Update, war der
 * Tag fuer immer verloren - und die Historie ist die Grundlage des ganzen
 * Kostenvergleichs. Es ist dieselbe Klasse Fehler, die fuer den
 * Monatsbericht schon einmal behoben wurde, nur eine Ebene tiefer.
 *
 * Jetzt: ab 23:50 wird der laufende Tag geschrieben, und ab 00:05 wird der
 * VORTAG nachgetragen, falls er fehlt. Der Nachtrag greift auf die
 * Historie zu, nicht auf die Preisliste - deshalb kann er nur aus dem
 * Zwischenspeicher rechnen, den der letzte Lauf des Vortags hinterlassen
 * hat. Liegt keiner vor, wird der Tag ausdruecklich als fehlend vermerkt
 * und nicht erfunden.
 */
function oc_history_nachtrag()
{
    $f = oc_datadir() . '/tagesstand.json';
    $gestern = date('Ymd', strtotime('yesterday'));
    $csv = oc_datadir() . '/history.csv';
    if (is_file($csv)) {
        foreach (file($csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $l) {
            if (strpos($l, $gestern . ';') === 0) { return false; }   // schon da
        }
    }
    if (!is_file($f)) { return false; }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!is_array($d) || !isset($d['tag']) || (string) $d['tag'] !== $gestern) { return false; }
    $zeilen = is_file($csv)
        ? (file($csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()) : array();
    $zeilen[] = $d['zeile'];
    sort($zeilen);
    if (count($zeilen) > 400) { $zeilen = array_slice($zeilen, -400); }
    @file_put_contents($csv, implode("\n", $zeilen) . "\n");
    oc_log('Tageswerte nachgetragen fuer den ' . $gestern . ' (der Lauf um 23:50 ist ausgefallen)');
    return true;
}

/**
 * Die Historie als CSV zum Herunterladen.
 *
 * Nach 400 Tagen faellt der aelteste Tag heraus. Wer den Vergleich ueber
 * Jahre fuehren will, braucht die Datei in der Hand - und wer einen
 * Fehlerbericht schreibt, kann sie anhaengen.
 *
 * Semikolon als Trenner und Komma als Dezimalzeichen: so oeffnet eine
 * deutsche Tabellenkalkulation die Datei ohne Rueckfrage.
 */
function oc_history_csv()
{
    $z = array('Datum;Schnitt ct/kWh;Minimum ct/kWh;Maximum ct/kWh;'
             . 'gewichtet ct/kWh;CO2 g/kWh;Quelle');
    foreach (oc_history_read(400) as $r) {
        $z[] = substr($r[0], 6, 2) . '.' . substr($r[0], 4, 2) . '.' . substr($r[0], 0, 4)
            . ';' . str_replace('.', ',', (string) $r[1])
            . ';' . str_replace('.', ',', (string) $r[2])
            . ';' . str_replace('.', ',', (string) $r[3])
            . ';' . str_replace('.', ',', (string) $r[4])
            . ';' . (int) $r[5]
            . ';' . ((int) $r[6] ? 'Demo' : 'echt');
    }
    return implode("\r\n", $z) . "\r\n";
}
