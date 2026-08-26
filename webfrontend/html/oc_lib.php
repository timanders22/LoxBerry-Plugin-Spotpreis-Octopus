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
    ) + plan_global_vorgabe();
}

function oc_config()
{
    $p = oc_paths();
    // Selbstheilung: fehlende oder leere Konfiguration aus der Sicherung holen
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    if (($roh === '' || $roh === '{}') && is_file($p['backup'])) {
        @mkdir(dirname($p['config']), 0775, true);
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
    );
    if (!is_array($cfg['notify']['hours'])) { $cfg['notify']['hours'] = array(); }

    if (!is_array($cfg['tts'])) { $cfg['tts'] = array(); }
    $cfg['tts'] += array('mode' => 'musicserver', 'ip' => '', 'port' => 7091,
                         'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => '');

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
        $r['name'] = trim((string) $r['name']);
        $r['art'] = in_array($r['art'], array('fenster', 'stunden', 'schwelle', 'mittel'), true) ? $r['art'] : 'fenster';
        $r['n'] = max(1, min(12, (int) $r['n']));
        $r['von'] = max(0, min(23, (int) $r['von']));
        $r['bis'] = max(0, min(23, (int) $r['bis']));
        $r['horizont'] = max(1, min(48, (int) $r['horizont']));
        $r['schwelle'] = (float) $r['schwelle'];
        $r['prozent'] = max(0, min(90, (int) $r['prozent']));
        // Felder des Fahrplaners. Hier wird gekappt, nicht abgewiesen - das
        // Abweisen macht die Oberflaeche beim Speichern.
        $r['rang'] = max(1, min(99, (int) $r['rang']));
        $r['leistung'] = max(0.0, min(100.0, (float) $r['leistung']));
        $r['energie'] = max(0.0, min(500.0, (float) $r['energie']));
        $r['frist'] = (int) $r['frist'];
        if ($r['frist'] < 0 || $r['frist'] > 23) { $r['frist'] = -1; }
        $r['pv_sperre'] = max(0.0, min(500.0, (float) $r['pv_sperre']));
        $r['soc_min'] = max(0, min(100, (int) $r['soc_min']));
        $r['soc_max'] = max(0, min(100, (int) $r['soc_max']));
        $cfg['regeln'][$i] = $r;
    }
    // Fahrplaner, global
    $cfg['budget_kw'] = max(0.0, min(200.0, (float) $cfg['budget_kw']));
    $cfg['pv_bonus'] = max(0.0, min(100.0, (float) $cfg['pv_bonus']));
    $cfg['pv_schwelle'] = max(1, min(100000, (int) $cfg['pv_schwelle']));
    if (!in_array($cfg['pv_quelle'], array('', 'forecast_solar', 'objekt', 'liste'), true)) {
        $cfg['pv_quelle'] = '';
    }
    if (!in_array($cfg['pv_einheit'], array('wh', 'w', 'kw'), true)) {
        $cfg['pv_einheit'] = 'wh';
    }
    if (!in_array($cfg['profil_ein'], array('aus', 'absolut', 'relativ', 'beides'), true)) {
        $cfg['profil_ein'] = 'aus';
    }
    return $cfg;
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
    @unlink(oc_tmpdir() . '/state.json');   // Zustand mit neuen Schwellen neu rechnen
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
    @unlink(oc_datadir() . '/token.json');   // neue Zugangsdaten, altes Token verwerfen
    @unlink(oc_tmpdir() . '/state.json');
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
            @unlink(oc_datadir() . '/token.json');
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

/** Vorgabe einer Schaltregel. */
function oc_regel_vorgabe()
{
    return array_merge(array(
        'aktiv' => 0,
        'name' => '',
        'art' => 'fenster',   // fenster | stunden | schwelle | mittel
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
        'mittel'   => (float) $st['heute']['avg'],
    ), array(
        'budget_kw'   => $cfg['budget_kw'],
        'pv_bonus'    => $cfg['pv_bonus'],
        'pv_schwelle' => $cfg['pv_schwelle'],
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
        'mittel'   => (float) $st['heute']['avg'],
    ), array(
        'budget_kw'   => $cfg['budget_kw'],
        'pv_bonus'    => $cfg['pv_bonus'],
        'pv_schwelle' => $cfg['pv_schwelle'],
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

    @file_put_contents($cache, json_encode($st));
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
    foreach ($st['regeln'] as $r) {
        if (!empty($r['aktiv'])) { $st['planlast'] += (float) $r['leistung']; }
    }
    $st['planlast'] = round($st['planlast'], 2);

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
    $prof = oc_profil(); $ws = 0.0; $w = 0.0;
    foreach ($st['heute']['hours'] as $ts => $ct) {
        $h = (int) date('G', (int) $ts);
        $g = isset($prof[$h]) ? $prof[$h] : 1.0;
        $ws += ((float) $ct) * $g;
        $w += $g;
    }
    $avgw = $w > 0 ? round($ws / $w, 3) : $st['heute']['avg'];
    $zeilen[] = $tag . ';' . $st['heute']['avg'] . ';' . $st['heute']['minp'] . ';'
              . $st['heute']['maxp'] . ';' . $avgw . ';' . (int) $st['co2_avg'] . ';' . (int) $st['demo'];
    if (count($zeilen) > 400) { $zeilen = array_slice($zeilen, -400); }
    @file_put_contents($f, implode("\n", $zeilen) . "\n");
    oc_log('Tageswerte gesichert: Schnitt ' . $st['heute']['avg'] . ' ct (gewichtet ' . $avgw
        . '), Min ' . $st['heute']['minp'] . ', Max ' . $st['heute']['maxp']
        . ($st['demo'] ? ' [DEMO]' : ''));
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
    }
    // Fahrplaner, global
    $t['plan_budget'] = array('THEMA.PLAN_BUDGET', 'kW');
    $t['plan_last']   = array('THEMA.PLAN_LAST', 'kW');
    $t['plan_pv']     = array('THEMA.PLAN_PV', 'kWh');
    $t['plan_soc']    = array('THEMA.PLAN_SOC', '%');
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
    }
    // Fahrplaner, global
    $w['plan_budget'] = (float) $cfg['budget_kw'];
    $w['plan_last'] = isset($st['planlast']) ? (float) $st['planlast'] : 0.0;
    $w['plan_pv'] = (isset($st['pv_summe']) && $st['pv_summe'] !== null) ? (float) $st['pv_summe'] : 0.0;
    $w['plan_soc'] = (isset($st['soc']) && $st['soc'] !== null) ? (float) $st['soc'] : -1;
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

function oc_mqtt_publish($st = null)
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
    if ($st === null) { $st = oc_state(); }
    $praefix = trim((string) $cfg['mqtt_topic']);
    if ($praefix === '') { $praefix = 'octopus'; }

    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) {
        oc_log_if_changed('mqtt', 'UDP-Socket konnte nicht angelegt werden');
        return false;
    }
    foreach (oc_werte($st) as $k => $v) {
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
            $cmds[] = array('title' => 'OCTOPUS_' . strtoupper($k),
                            'comment' => oc_thema_text($info),
                            'check' => strtoupper($k) . '=\v;');
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
            'title'   => $praefix . '_' . $k,
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
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(oc_t('EINST.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = oc_t('EINST.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}
