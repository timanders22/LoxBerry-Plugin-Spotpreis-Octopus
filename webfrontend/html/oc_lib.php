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
 * config/plugins, data/plugins UND config/system/general.json traegt. Das
 * trifft die uebliche Installation genauso wie eine an einem anderen Ort -
 * und es trifft auch den Fall, dass das Plugin noch als entpacktes Archiv
 * daliegt (dann findet es nichts und gibt einen Leerstring zurueck, was der
 * Aufrufer abfangen muss).
 *
 * general.json ist die entscheidende Bedingung. Bis 1.1.11 genuegten
 * config/plugins und webfrontend - genau diese Ordner hinterlaesst ein
 * Pruefstand auf einem Arbeitsrechner, und am 05.09.2026 hat eine solche
 * Suche dort C:\ als "LoxBerry" erkannt und Daten geloescht (Regeln/06). In
 * WSL gemessen (Pruefung-Spotpreis-Octopus-1.1.12, Faelle H1 und H2): in
 * einem fremden Baum ohne general.json nahm diese Bibliothek den Baum als
 * Wurzel, und bin/oc_cron.php schrieb dort Protokoll und Zwischenstaende.
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
                && is_file($d . '/config/system/general.json')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

/* Die Wurzel in der Reihenfolge der Hausregel: erst die Umgebung, dann die
 * Suche - und DANACH NICHTS MEHR.
 *
 * Bis 1.1.11 stand in oc_paths() und in bin/oc_cron.php als dritte Stufe ein
 * fest verdrahteter Systempfad (das Heimatverzeichnis des Benutzers
 * loxberry). Er macht jede Suche wirkungslos und trifft auf einem anders
 * installierten LoxBerry die falsche Anlage (in WSL gemessen,
 * Pruefung-Spotpreis-Octopus-1.1.12, Fall C14); dieselbe Stelle wurde in
 * Spotpreis-Tibber 0.9.18, ZendureSolarFlow 0.9.25 und Weissware 0.9.29
 * entfernt.
 *
 * Ein gesetztes LBHOMEDIR gilt mit config/plugins UND data/plugins darunter -
 * general.json wird hier nicht verlangt, damit Attrappen ohne sie
 * (Werkzeuge/lb) weiter tragen. Rueckgabe '' heisst "keine Wurzel"; jeder
 * Aufrufer muss das abfangen. */
function oc_lbhome()
{
    $h = getenv('LBHOMEDIR');
    if ($h && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')) {
        return rtrim($h, '/');
    }
    return lb_wurzel_ermitteln();
}

/* Fuer bin/oc_cron.php: ohne Wurzel nichts tun, eine Meldung auf stderr,
 * Rueckgabewert 1. Steht dort VOR oc_config(), denn schon deren
 * Selbstheilung schreibt.
 *
 * Bis 1.1.11 lief der Cron ohne Wurzel mit den Ersatzpfaden unter dem
 * Temp-Ordner los, und aus einem ausgepackten Archiv unterhalb einer echten
 * Wurzel schrieb er Protokoll und Zwischenstaende DER ANLAGE (in WSL
 * gemessen, Pruefung-Spotpreis-Octopus-1.1.12, Faelle B6, B7, H2). Bauart
 * tb_keine_wurzel_abbruch() aus Spotpreis-Tibber 0.9.19. */
function oc_keine_wurzel_abbruch($programm)
{
    $p = oc_paths();
    if ($p['home'] !== '') { return; }
    if ($p['archiv'] !== '') {
        fwrite(STDERR, $programm . ': Diese Datei liegt nicht in der Installation unter '
            . $p['archiv'] . "\n"
            . '(ausgepacktes Archiv oder Pruefordner). Damit nichts in die Anlage kommt,' . "\n"
            . 'wurde nichts geholt, nichts gesendet und nichts geschrieben.' . "\n"
            . 'Abhilfe: das Programm aus ' . $p['archiv'] . '/bin/plugins/<ordner> aufrufen' . "\n"
            . 'oder LBHOMEDIR und LBPPLUGINDIR ausdruecklich setzen.' . "\n");
        exit(1);
    }
    fwrite(STDERR, $programm . ': Es wurde kein LoxBerry-Wurzelverzeichnis gefunden.' . "\n"
        . '$LBHOMEDIR ist nicht gesetzt, und oberhalb von ' . __DIR__ . ' traegt kein' . "\n"
        . 'Verzeichnis config/plugins, data/plugins und config/system/general.json.' . "\n"
        . 'Es wurde nichts geholt, nichts gesendet und nichts geschrieben.' . "\n");
    exit(1);
}

function oc_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = oc_lbhome();
    $ordner = basename(dirname(__FILE__));   // installiert: .../html/plugins/<ordner>
    /* LBPPLUGINDIR ist die Auskunft von LoxBerry SELBST und hat Vorrang. Von
     * ihr zaehlt nur der letzte Pfadteil, und die Namen, die nachweislich
     * kein Pluginordner sind, gelten auch dort nicht (Bauart Spotpreis-Tibber
     * 0.9.19). Der feste Name greift nur, wo der abgeleitete kein
     * Pluginordner sein KANN - aus dem ausgepackten Archiv heisst er 'html'. */
    $lbp = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
    $lbp_gilt = ($lbp !== '' && !in_array($lbp, array('.', '/', 'html', 'bin', 'plugins'), true));
    if ($lbp_gilt) {
        $ordner = $lbp;
    } elseif ($ordner === '' || $ordner === '.' || $ordner === '/'
              || $ordner === 'html' || $ordner === 'bin' || $ordner === 'plugins') {
        $ordner = 'octopus';                 // Archiv: .../webfrontend/html
    }
    /* Archivmodus. Die Pfade DER ANLAGE gelten nur, wenn diese Bibliothek
     * dort installiert liegt (<Wurzel>/webfrontend/html/plugins/<ordner>,
     * physisch verglichen) oder der Aufrufer Wurzel UND Ordner ausdruecklich
     * nennt ($LBHOMEDIR und $LBPPLUGINDIR - so arbeiten die Pruefwerkzeuge mit
     * ihrer Attrappe, und so ruft die Deinstallation oc_cron.php). Sonst ist
     * das ein ausgepacktes Archiv oder ein Pruefordner, und es gelten die
     * Ersatzpfade weiter unten.
     *
     * Bis 1.1.11 nahm ein Archiv unterhalb einer echten Wurzel diese Wurzel
     * und den festen Namen 'octopus' - Konfiguration, Zugangsdaten, Daten und
     * Protokoll der Anlage; mit $LBHOMEDIR allein, wie es am Geraet in
     * /etc/environment steht, ebenso (in WSL gemessen,
     * Pruefung-Spotpreis-Octopus-1.1.12, Faelle B1, B2, B6, B7). Bauart
     * tb_paths() aus Spotpreis-Tibber 0.9.19. */
    $gefunden = $home;
    if ($home !== '') {
        $soll = @realpath($home . '/webfrontend/html/plugins/' . basename(__DIR__));
        $ist = @realpath(__DIR__);
        $installiert = ($soll !== false && $ist !== false && $soll === $ist);
        $ausdruecklich = $lbp_gilt && $home === rtrim((string) getenv('LBHOMEDIR'), '/');
        if (!$installiert && !$ausdruecklich) { $home = ''; }
    }
    if ($home !== '') {
        $p = array(
            'home'    => $home,
            'plugin'  => $ordner,
            'config'  => $home . '/config/plugins/' . $ordner . '/octopus.json',
            'backup'  => $home . '/config/plugins/' . $ordner . '.backup.json',
            'zugang'  => $home . '/config/plugins/' . $ordner . '/zugang.json',
            'datadir'    => $home . '/data/plugins/' . $ordner,
            'log'     => $home . '/log/plugins/' . $ordner . '/octopus.log',
            'general' => $home . '/config/system/general.json',
            'tmp'     => '/tmp/' . $ordner,
            'archiv'  => '',
        );
        return $p;
    }
    /* Keine Wurzel (Entwicklung, Pruefstand, fremder Baum) oder Archivmodus:
     * die Ersatzpfade unter dem Temp-Ordner, nie ein Pfad der Anlage und nie
     * einer ab der Laufwerkswurzel. bin/oc_cron.php steigt in beiden Faellen
     * vorher aus (oc_keine_wurzel_abbruch()). */
    $wurzel = dirname(dirname(__DIR__));
    $tmp = sys_get_temp_dir() . '/octopus';
    $p = array(
        'home'    => '',
        'plugin'  => $ordner,
        'config'  => $tmp . '/octopus.json',
        'backup'  => $tmp . '/octopus.backup.json',
        'zugang'  => $tmp . '/zugang.json',
        'datadir'    => $tmp . '/data',
        'log'     => $tmp . '/octopus.log',
        'general' => $wurzel . '/general.json',
        'tmp'     => $tmp,
        // Die gefundene Wurzel, wenn diese Datei NICHT darin installiert
        // liegt (Archivmodus) - fuer die Meldung; sonst leer.
        'archiv'  => $gefunden,
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
    $d = oc_paths()['datadir'];
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
 * Eine Datei ganz schreiben (C4, seit 1.1.16).
 *
 * Nebendatei mit Prozessnummer, Rechte VOR dem Inhalt, dann Laenge
 * (Rueckgabe von fwrite gegen strlen) und Ruecklesen pruefen - erst dann
 * umbenennen. Rueckgabe false heisst: am alten Stand hat sich nichts
 * geaendert.
 *
 * Bis 1.1.15 galt "fwrite() !== false" als Erfolg. In WSL gemessen mit
 * ulimit -f 16 (Pruefbericht code, Befund 4): von 43 600 Byte kamen 16 384
 * an, oc_config_write() meldete true, und die Zweitschrift wurde aus der
 * abgeschnittenen Datei kopiert - Konfiguration UND Rueckfallkopie waren
 * unlesbar, das Aktionstoken weg. Bauform ev_datei_schreiben() aus EVCC
 * 0.9.34 (Regeln/03, "atomar schreiben").
 */
function oc_datei_schreiben($pfad, $inhalt, $modus = 0600)
{
    $inhalt = (string) $inhalt;
    $ordner = dirname($pfad);
    if (!is_dir($ordner)) { @mkdir($ordner, 0775, true); }
    $neben = $pfad . '.neu.' . getmypid();
    if (is_file($neben)) { @unlink($neben); }
    $fh = @fopen($neben, 'xb');           // leer angelegt ...
    if ($fh === false) { return false; }
    @chmod($neben, $modus);                // ... sofort geschuetzt ...
    $n = @fwrite($fh, $inhalt);            // ... dann erst gefuellt
    $ok = ($n === strlen($inhalt)) && @fflush($fh);
    $ok = @fclose($fh) && $ok;
    if ($ok) {
        clearstatcache(true, $neben);
        $ok = (@filesize($neben) === strlen($inhalt))
              && ((string) @file_get_contents($neben) === $inhalt);
    }
    if (!$ok || !@rename($neben, $pfad)) {
        if (is_file($neben)) { @unlink($neben); }
        return false;
    }
    @chmod($pfad, $modus);
    return true;
}

/** Hat der Wert die Form eines Aktionstokens? ([A-Za-z0-9]{8,64}) */
function oc_token_form($t)
{
    return !is_array($t) && preg_match('/^[A-Za-z0-9]{8,64}$/', trim((string) $t)) === 1;
}

/** Traegt die JSON-Datei ein Aktionstoken in gueltiger Form? (C5) */
function oc_datei_hat_token($f)
{
    if (!is_file($f)) { return false; }
    $d = json_decode((string) @file_get_contents($f), true);
    return is_array($d) && isset($d['aktionstoken']) && oc_token_form($d['aktionstoken']);
}

/**
 * Nur anzeigen, nichts abrufen (O2, seit 1.1.16).
 *
 * Die Oberflaeche setzt den Schalter EINMAL ganz oben. Dann liest
 * oc_preise() nur den Zwischenspeicher des Minutentakts (preise.json, in
 * jedem Alter), oc_co2(), oc_umwelt() und oc_verbrauch() ebenso, und
 * oc_state() schreibt keinen Zustand und keine Hysterese fort. Bis 1.1.15
 * meldete sich JEDES Oeffnen der Oberflaeche bei Kraken an, sobald der
 * Zwischenspeicher aelter als 900 s war - auch bei "Plugin aktiv: Nein" -,
 * und fragte api.energy-charts.info; schwieg die Gegenstelle, lud die Seite
 * 30 s (Pruefbericht oberflaeche, Befund 2). Der Abruf gehoert dem
 * Minutentakt und den ausdruecklichen Knoepfen im Reiter Test.
 */
function oc_kein_abruf($setzen = null)
{
    static $an = false;
    if ($setzen !== null) { $an = (bool) $setzen; }
    return $an;
}

/**
 * Ein ausdruecklicher Knopf (Reiter Test: "Anmeldung pruefen", "Preise
 * jetzt abrufen") darf die Anmeldebremse uebergehen (C7).
 */
function oc_anmeldung_ausdruecklich($setzen = null)
{
    static $an = false;
    if ($setzen !== null) { $an = (bool) $setzen; }
    return $an;
}

/** Wo die Anmeldebremse liegt (Datenordner, uebersteht einen Neustart). */
function oc_anmeldesperre_datei()
{
    return oc_paths()['datadir'] . '/anmeldesperre.json';
}

/**
 * Bis wann ist die Anmeldung bei Kraken ausgesetzt? 0 = gar nicht (C7).
 *
 * Nach einer ABGELEHNTEN Anmeldung (FEHLER_ANMELDUNG) wartet der Abruf 30
 * Minuten. Bis 1.1.15 meldete sich das Plugin mit falschem Kennwort bei
 * jeder Neuberechnung neu an - gemessen: 6 Neuberechnungen, 6
 * Anmeldeversuche, 1 Protokollzeile (Pruefbericht code, Befund 7). Eine
 * Sperre des Kontos nach Fehlversuchen ist bei Octopus nicht gemessen.
 */
function oc_anmeldesperre()
{
    $f = oc_anmeldesperre_datei();
    if (!is_file($f)) { return 0; }
    $d = json_decode((string) @file_get_contents($f), true);
    $bis = (is_array($d) && isset($d['bis']) && !is_array($d['bis'])) ? (int) $d['bis'] : 0;
    return ($bis > time() && $bis <= time() + 7200) ? $bis : 0;
}

/**
 * Die Upgrade-Marke data/plugins/<ordner>.upgrade_laeuft (I2, Entscheidung 1).
 *
 * preupgrade.sh legt sie als Erstes an (Unixzeit), postupgrade.sh raeumt sie
 * ueber einen trap ab. Sie liegt NEBEN dem Datenordner, den
 * purge_installation loescht. Der Minutentakt ruht, solange sie juenger als
 * 3600 s ist (die 3600 s gelten nur fuer diese Startsperre; ob
 * zurueckgespielt wird, entscheidet allein ihr Vorhandensein).
 *
 * Rueckgabe null (keine Marke) oder array('alter' => Sekunden|null,
 * 'gilt' => bool). Unlesbar, aus der Zukunft oder aelter als 3600 s: gilt
 * nicht. Bauform au_marke() (AudiConnect 0.9.22).
 */
function oc_upgrade_marke()
{
    $d = oc_paths()['datadir'];
    $f = dirname($d) . '/' . basename($d) . '.upgrade_laeuft';
    if (!is_file($f)) { return null; }
    $roh = trim((string) @file_get_contents($f));
    if ($roh === '' || !preg_match('/^[0-9]+$/', $roh)) {
        return array('alter' => null, 'gilt' => false);
    }
    $alter = time() - (int) $roh;
    if ($alter < 0) { return array('alter' => $alter, 'gilt' => false); }
    return array('alter' => $alter, 'gilt' => $alter < 3600);
}

/**
 * Die Einmalmeldung der Oberflaeche (O1, PRG): nach jedem POST leitet die
 * Seite mit 303 um, das Ergebnis reist in dieser Datei (Datenordner, 0600,
 * 120 s gueltig) und wird nur beim GET gelesen - und dabei geloescht.
 * Aktionstoken und Formularmerkmal stehen darin nie im Klartext.
 * Bauform ev_meldung_ablegen() (EVCC 0.9.34).
 */
function oc_meldung_datei()
{
    return oc_paths()['datadir'] . '/einmalmeldung.json';
}

function oc_meldung_ablegen($daten)
{
    $daten['zeit'] = time();
    $geheim = array();
    $c = oc_config(false);
    if ((string) $c['aktionstoken'] !== '') {
        $geheim[] = (string) $c['aktionstoken'];
        $geheim[] = oc_formtoken($c);
    }
    // Das Sprechtoken fuer Alexa-NG (Ansage-2) - wie ein Kennwort.
    if ((string) $c['tts']['alexa_token'] !== '') { $geheim[] = (string) $c['tts']['alexa_token']; }
    array_walk_recursive($daten, function (&$w) use ($geheim) {
        if (is_string($w)) {
            foreach ($geheim as $g) { if ($g !== '') { $w = str_replace($g, '***', $w); } }
        }
    });
    $js = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($js) && oc_datei_schreiben(oc_meldung_datei(), $js, 0600);
}

function oc_meldung_abholen()
{
    $f = oc_meldung_datei();
    clearstatcache(true, $f);
    if (!is_file($f)) { return null; }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);                         // loeschen VOR der Anzeige
    if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) { return null; }
    return $d;
}

/* ==================================================================
 * Eingaben nach einer Beanstandung (X-2, Regeln/04, Hausregel 30.09.2026)
 * ==================================================================
 *
 * Seit der Umleitung nach jedem POST (O1, 1.1.16) zeigte der GET nach einer
 * Abweisung die GESPEICHERTEN Werte: wer im Einstellungsformular zehn Felder
 * richtig und eines falsch eingab, tippte alle elf neu. Jetzt reisen die
 * Eingaben des beanstandeten Formulars mit der Einmalmeldung (0600,
 * Datenordner, 120 s, beim GET gelesen und geloescht) - nur die Felder DIESES
 * Formulars, und nie ein Geheimnis: das Passwort reist nicht mit (sein Feld
 * bleibt leer und zeigt den Platzhalter), Aktionstoken und Formularmerkmal
 * ersetzt oc_meldung_ablegen() ohnehin durch ***. Die Aktionshaken "Token neu
 * wuerfeln" und "Zugangsdaten loeschen" reisen ebenfalls nicht mit: ein
 * zweites Absenden soll nie eine Aktion wiederholen, die man nicht erneut
 * angekreuzt hat. Nur nach einer Beanstandung: nach erfolgreichem Speichern
 * zeigt der GET die gespeicherten Werte.
 *
 * Eingesetzt wird am fertigen HTML des Formulars (oc_eingaben_einsetzen()),
 * nicht Feld fuer Feld im Quelltext: das Einstellungsformular hat rund
 * hundert Felder, und ein vergessenes waere still wieder "gespeicherter
 * Wert statt Eingabe".
 */

/** Die Formulare (Name des versteckten Merkmalfeldes) und was in ihnen NIE zurueckreist. */
function oc_eingaben_formulare()
{
    return array(
        'save'        => array('token_neu', 'tts_alexa_token', 'tts_alexa_token_weg'),
        'save_zugang' => array('z_passwort', 'zugang_loeschen'),
        'save_mqtt'   => array(),
    );
}

/** Felder, die als Liste name[] abgeschickt werden (Stundenhaken). */
function oc_eingaben_listen()
{
    return array('hours');
}

/** Taugt der Name als Feldname? name, name[3] oder name[] - sonst nichts. */
function oc_eingaben_name_ok($k)
{
    return is_string($k) && preg_match('/^[a-z][a-z0-9_]{0,40}(\[[0-9]{1,2}\]|\[\])?$/', $k) === 1;
}

/** Die Eingaben eines abgewiesenen POST fuer die Einmalmeldung. */
function oc_eingaben_sammeln($formular, $beanstandet)
{
    $liste = oc_eingaben_formulare();
    if (!isset($liste[$formular])) { return array(); }
    $nie = array_merge($liste[$formular], array('fmt', 'activetab', 'tab', $formular));
    $werte = array();
    foreach ($_POST as $k => $v) {
        if (!is_string($k) || in_array($k, $nie, true) || count($werte) >= 400) { continue; }
        if (in_array($k, oc_eingaben_listen(), true)) {
            $l = array();
            foreach ((array) $v as $w) {
                if (!is_array($w)) { $l[] = substr((string) $w, 0, 16); }
            }
            $werte[$k . '[]'] = array_slice($l, 0, 48);
            continue;
        }
        if (is_array($v)) {
            foreach ($v as $i => $w) {
                $n = $k . '[' . $i . ']';
                if (!is_array($w) && oc_eingaben_name_ok($n)) { $werte[$n] = substr((string) $w, 0, 1000); }
            }
            continue;
        }
        if (oc_eingaben_name_ok($k)) { $werte[$k] = substr((string) $v, 0, 1000); }
    }
    $felder = array();
    foreach ((array) $beanstandet as $k) {
        if (oc_eingaben_name_ok($k) && !in_array($k, $nie, true) && !in_array($k, $felder, true)) { $felder[] = $k; }
    }
    return array('formular' => $formular, 'werte' => $werte, 'felder' => $felder);
}

/** Die Eingaben aus der Einmalmeldung - nur, was die Regeln oben zulassen. */
function oc_eingaben_pruefen($e)
{
    $liste = oc_eingaben_formulare();
    if (!is_array($e) || !isset($e['formular']) || !is_string($e['formular'])
        || !isset($liste[$e['formular']])) {
        return array();
    }
    $f = $e['formular'];
    $nie = array_merge($liste[$f], array('fmt', 'activetab', 'tab', $f));
    $werte = array();
    if (isset($e['werte']) && is_array($e['werte'])) {
        foreach ($e['werte'] as $k => $w) {
            if (!oc_eingaben_name_ok($k) || in_array($k, $nie, true)) { continue; }
            if (substr($k, -2) === '[]') {
                if (is_array($w)) { $werte[$k] = array_values(array_filter($w, 'is_string')); }
            } elseif (is_string($w)) {
                $werte[$k] = $w;
            }
        }
    }
    $felder = array();
    if (isset($e['felder']) && is_array($e['felder'])) {
        foreach ($e['felder'] as $k) {
            if (oc_eingaben_name_ok($k) && !in_array($k, $nie, true)) { $felder[] = $k; }
        }
    }
    return array('formular' => $f, 'werte' => $werte, 'felder' => $felder);
}

/**
 * Die Eingaben in das fertige HTML EINES Formulars einsetzen und die
 * beanstandeten Felder markieren. Gehoeren die Eingaben zu einem anderen
 * Formular (oder gibt es keine), kommt das HTML unveraendert zurueck.
 * Ein Haken, der nicht abgeschickt wurde, war nicht gesetzt - das Formular
 * wurde als Ganzes abgeschickt.
 */
function oc_eingaben_einsetzen($html, $formular, $e)
{
    if (!is_array($e) || !isset($e['formular']) || $e['formular'] !== $formular
        || !isset($e['werte']) || !is_array($e['werte'])) {
        return $html;
    }
    $werte = $e['werte'];
    $felder = (isset($e['felder']) && is_array($e['felder'])) ? $e['felder'] : array();
    $nie = oc_eingaben_formulare();
    $nie = $nie[$formular];
    $attr = function ($tag, $name) {
        return preg_match('/\s' . $name . '="([^"]*)"/', $tag, $m)
            ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : null;
    };
    $marke = function ($tag, $name) use ($felder) {
        if (!in_array($name, $felder, true)) { return $tag; }
        if (preg_match('/\sclass="/', $tag)) {
            $tag = preg_replace('/\sclass="/', ' class="sm-beanstandet ', $tag, 1);
        } else {
            $tag = preg_replace('/^<([a-z]+)\b/', '<$1 class="sm-beanstandet"', $tag, 1);
        }
        return preg_replace('/^<([a-z]+)\b/', '<$1 aria-invalid="true"', $tag, 1);
    };
    $wert_setzen = function ($tag, $wert) {
        $neu = ' value="' . oc_e($wert) . '"';
        if (preg_match('/\svalue="[^"]*"/', $tag, $m, PREG_OFFSET_CAPTURE)) {
            return substr_replace($tag, $neu, $m[0][1], strlen($m[0][0]));
        }
        return substr($tag, 0, -1) . $neu . '>';
    };
    $html = preg_replace_callback('/<input\b[^>]*>/', function ($m) use ($werte, $nie, $attr, $marke, $wert_setzen) {
        $tag = $m[0];
        $name = $attr($tag, 'name');
        if ($name === null || in_array($name, $nie, true)) { return $tag; }
        $typ = strtolower((string) $attr($tag, 'type'));
        if ($typ === '') { $typ = 'text'; }
        if (in_array($typ, array('hidden', 'submit', 'button', 'file', 'password', 'reset', 'image'), true)) {
            return $marke($tag, $name);
        }
        if ($typ === 'checkbox' || $typ === 'radio') {
            $v = $attr($tag, 'value');
            $v = ($v === null) ? 'on' : $v;
            if (substr($name, -2) === '[]') {
                $an = isset($werte[$name]) && is_array($werte[$name]) && in_array($v, $werte[$name], true);
            } elseif ($typ === 'radio') {
                $an = isset($werte[$name]) && $werte[$name] === $v;
            } else {
                $an = isset($werte[$name]);
            }
            $tag = preg_replace('/\schecked(="[^"]*")?(?=[\s>\/])/', '', $tag);
            if ($an) { $tag = rtrim(substr($tag, 0, -1)) . ' checked>'; }
            return $marke($tag, $name);
        }
        if (isset($werte[$name]) && is_string($werte[$name])) { $tag = $wert_setzen($tag, $werte[$name]); }
        return $marke($tag, $name);
    }, $html);
    $html = preg_replace_callback('/(<select\b[^>]*>)(.*?)(<\/select>)/s', function ($m) use ($werte, $nie, $attr, $marke) {
        $name = $attr($m[1], 'name');
        if ($name === null || in_array($name, $nie, true)) { return $m[0]; }
        $innen = $m[2];
        if (isset($werte[$name]) && is_string($werte[$name])) {
            $soll = $werte[$name];
            $innen = preg_replace_callback('/<option\b[^>]*>/', function ($o) use ($soll, $attr) {
                $t = preg_replace('/\sselected(="[^"]*")?(?=[\s>\/])/', '', $o[0]);
                $v = $attr($t, 'value');
                return ($v !== null && $v === $soll) ? rtrim(substr($t, 0, -1)) . ' selected>' : $t;
            }, $innen);
        }
        return $marke($m[1], $name) . $innen . $m[3];
    }, $html);
    // Oben im Formular ein Satz, warum die Felder nicht den gespeicherten Stand zeigen.
    $hinweis = '<div class="sm-warnung">' . oc_t('EINST.EINGABEN_ZURUECK') . '</div>';
    return preg_replace_callback('/<form\b[^>]*>/', function ($m) use ($hinweis) {
        return $m[0] . "\n" . $hinweis;
    }, $html, 1);
}

/**
 * Eintraege laenger als eine Viertelstunde in Viertelstunden zerlegen (C2).
 *
 * oc_sammle_preise() las die Laenge jedes Eintrags ('len') und warf sie
 * weg: ein Stundeneintrag belegte nur seine erste Viertelstunde, die drei
 * uebrigen fehlten - gemessen an der Attrappe mit Stundeneintraegen
 * (validTo - validFrom = 3600): um 15:33 cur=0.000 rank=1 level=1
 * (Pruefbericht code, Befund 2). Eine vorhandene Viertelstunde gewinnt
 * immer; hoechstens 96 Viertelstunden je Eintrag. Der Demo-Zweig zerlegt
 * seine Stunden seit jeher selbst.
 */
function oc_slots_vierteln($slots)
{
    $out = array();
    foreach ((array) $slots as $ts => $s) { $out[(int) $ts] = $s; }
    foreach ((array) $slots as $ts => $s) {
        $ts = (int) $ts;
        $len = (is_array($s) && isset($s['len'])) ? (int) $s['len'] : 900;
        if (!is_array($s) || $len <= 900 || $ts % 900 !== 0) { continue; }
        $n = min(96, intdiv($len, 900));
        for ($k = 1; $k < $n; $k++) {
            $t = $ts + $k * 900;
            if (!isset($out[$t])) {
                $out[$t] = array('ct' => $s['ct'], 'net' => isset($s['net']) ? $s['net'] : null, 'len' => 900);
            }
        }
    }
    ksort($out);
    return $out;
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
        // Durchschnittspreis. Gerechnet wird seit 1.0.0 im Fahrplaner (oc_regeln()).
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

/**
 * Nur-Lesen-Betrieb fuer den ganzen Aufruf.
 *
 * EIN SCHALTER AM EINZELNEN AUFRUF HAETTE NICHT GEREICHT. oc_config() wird
 * an 19 Stellen dieser Datei gerufen - aus oc_state(), oc_preise(),
 * oc_themen(), oc_werte() und weiteren. Der Endpunkt ruft oc_state(), und
 * darueber waere die Selbstheilung trotzdem gelaufen; ein oc_config(false)
 * allein in webfrontend/html/index.php haette nur die erste von vielen
 * Stellen geschlossen und dabei ausgesehen, als sei die Sache erledigt.
 *
 * Der Endpunkt setzt den Schalter deshalb EINMAL ganz oben, und oc_config()
 * fragt ihn. Wer den Schalter nicht setzt - Oberflaeche und Cron -, merkt
 * von alldem nichts.
 */
function oc_nur_lesen($setzen = null)
{
    static $an = false;
    if ($setzen !== null) { $an = (bool) $setzen; }
    return $an;
}

/**
 * Die Lage der Konfigurationsdatei, wie sie VOR jeder Selbstheilung war
 * (Regeln/05: eine Zeile, die den Zustand meldet, merkt ihn sich, bevor die
 * Selbstheilung ihn beseitigt). Der erste Aufruf von oc_config() heilt; wer
 * danach nachsieht, saehe eine heile Datei. Der zuerst festgestellte Zustand
 * gilt deshalb fuer die Dauer des Prozesses.
 */
function oc_konfig_lage_merken($lage = null)
{
    static $erste = null;
    if ($lage !== null && $erste === null) { $erste = (string) $lage; }
    return $erste;
}

/** 'ok' | 'vorgabe' | 'zweitschrift' | 'kaputt' - jetzt, ohne Gedaechtnis. */
function oc_konfig_lage_jetzt()
{
    $p = oc_paths();
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    if ($roh === '' || $roh === '{}') {
        return is_file($p['backup']) ? 'zweitschrift' : 'vorgabe';
    }
    $d = json_decode($roh, true);
    if (!is_array($d)) { return 'kaputt'; }
    /* C5 (seit 1.1.16): gueltiges JSON OHNE Aktionstoken, waehrend die
     * Zweitschrift eines traegt - oc_config() heilt dann nach Inhalt. */
    if (!(isset($d['aktionstoken']) && oc_token_form($d['aktionstoken']))
        && oc_datei_hat_token($p['backup'])) {
        return 'ohne_token';
    }
    return 'ok';
}

/** Die Lage beim ersten Lesen in diesem Prozess. */
function oc_konfig_lage()
{
    $erste = oc_konfig_lage_merken();
    return $erste !== null ? $erste : oc_konfig_lage_jetzt();
}

/**
 * Fehlende und fremde Schluessel der Datei (Regeln/05: fremde werden
 * GENANNT und stehen gelassen). array(fehlend, fremd) oder null, wenn die
 * Datei nicht lesbar ist. Schluessel mit "_" (lesbarer Kopf) zaehlen nicht.
 */
function oc_konfig_schluessel()
{
    $p = oc_paths();
    if (!is_file($p['config'])) { return null; }
    $d = json_decode((string) @file_get_contents($p['config']), true);
    if (!is_array($d)) { return null; }
    $vorg = oc_vorgaben();
    $fehlend = array_values(array_diff(array_keys($vorg), array_keys($d)));
    $fremd = array();
    foreach (array_keys($d) as $k) {
        if (!array_key_exists($k, $vorg) && !($k !== '' && $k[0] === '_')) { $fremd[] = (string) $k; }
    }
    return array($fehlend, $fremd);
}

/**
 * Konfiguration lesen.
 *
 * $heilen = false liest NUR; ohne Angabe entscheidet oc_nur_lesen(). Das
 * ist die Betriebsart des unangemeldeten Endpunkts, und sie ist
 * Hausstandard ("Der unangemeldete Endpunkt darf nichts anlegen" /
 * "Die Selbstheilung gehoert hinter die Tokenpruefung").
 *
 * Bis 1.1.3 gab es den Schalter nicht, und webfrontend/html/index.php rief
 * diese Funktion in Zeile 77 - VOR der Tokenpruefung. Gemessen an der
 * LoxBerry-Attrappe, ein einziger Aufruf ohne jedes Token:
 *
 *   a) Konfigurationsordner geloescht, Sicherung vorhanden
 *      -> Antwort OCTOPUS;OK=0;ERR=TOKEN, und danach lag
 *         config/plugins/octopus/octopus.json wieder da.
 *   b) octopus.json auf "{}", Sicherung mit einem ANDEREN Aktionstoken
 *      -> Antwort OCTOPUS;OK=0;ERR=TOKEN, und danach stand das alte Token
 *         wieder in der Datei. Alle Adressen im Miniserver waeren damit
 *         ungueltig geworden.
 *   Gegenprobe mit heiler Konfiguration: keine Datei angefasst.
 *
 * Wer sich nicht ausweisen kann, loest keinen Schreibvorgang aus - auch
 * keinen, der harmlos aussieht.
 */
function oc_config($heilen = null)
{
    if ($heilen === null) { $heilen = !oc_nur_lesen(); }
    $p = oc_paths();
    oc_konfig_lage_merken(oc_konfig_lage_jetzt());
    /* Selbstheilung: fehlende, leere ODER BESCHAEDIGTE Konfiguration aus
     * der Sicherung holen.
     *
     * Bis 1.1.10 kannte diese Stelle nur "leer" und "{}". Eine Datei mit
     * kaputtem JSON fiel durch: json_decode gab null, daraus wurde ein
     * leeres Feld, die Vorgaben fuellten es auf - mit LEEREM Aktionstoken,
     * obwohl eine heile Zweitschrift daneben lag. Gemessen am Pruefstand
     * (kaputt_messen.py): Token leer, nichts beiseitegelegt, kein Wort im
     * Protokoll. Das naechste Speichern haette die Werkseinstellung ueber
     * die Sicherung geschrieben und ein neues Token erzeugt; jede Adresse
     * im Miniserver waere ungueltig geworden. Dieselbe Klasse hat aWATTar
     * in 1.2.20 behoben. */
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    $oc_kaputt = ($roh !== '' && $roh !== '{}' && !is_array(json_decode($roh, true)));
    /* HEILEN NACH INHALT, NICHT NACH FORM (C5, seit 1.1.16).
     *
     * Eine gueltige Datei OHNE Aktionstoken fiel durch beide Pruefungen:
     * sie ist weder leer noch kaputt. oc_config(true) lieferte token=LEER,
     * obwohl die Zweitschrift daneben eines trug, und die Oberflaeche
     * wuerfelte beim naechsten Oeffnen still ein neues - jede Adresse im
     * Miniserver bekam danach 403 (Pruefbericht code, Befund 5; Regeln/05,
     * "Selbstheilung entscheidet nach Inhalt"). Jetzt: die Datei geht als
     * .kaputt beiseite, die Zweitschrift mit Token kommt zurueck, eine
     * Protokollzeile nennt beides. */
    $oc_ohne_token = false;
    if ($roh !== '' && $roh !== '{}' && !$oc_kaputt) {
        $oc_d = json_decode($roh, true);
        $oc_ohne_token = !(isset($oc_d['aktionstoken']) && oc_token_form($oc_d['aktionstoken']))
                         && oc_datei_hat_token($p['backup']);
    }
    if ($heilen && $oc_kaputt) {
        $oc_weg = $p['config'] . '.kaputt.' . date('YmdHis');
        if (@rename($p['config'], $oc_weg)) {
            /* Die beiseitegelegte Datei traegt das Aktionstoken wie das
             * Original, und rename() behaelt dessen Rechte - eine von Hand
             * oder vor 1.1.10 angelegte Konfiguration also 644 bzw. 640 (in
             * WSL gemessen, Pruefung-Spotpreis-Octopus-1.1.12, Fall N2b).
             * Hausregel: die .kaputt-Datei 0600 (Regeln/05). */
            @chmod($oc_weg, 0600);
            oc_log('Konfiguration war beschaedigt und wurde beiseitegelegt: ' . basename($oc_weg));
            $roh = '';
        }
    }
    if ($heilen && $oc_ohne_token) {
        $oc_weg = $p['config'] . '.kaputt.' . date('YmdHis');
        if (@rename($p['config'], $oc_weg)) {
            @chmod($oc_weg, 0600);
            oc_log('Konfiguration trug kein Aktionstoken, die Zweitschrift schon: beiseitegelegt als '
                . basename($oc_weg) . ', die Zweitschrift wird zurueckgeholt.');
            $roh = '';
        }
    }
    if ($heilen && ($roh === '' || $roh === '{}') && is_file($p['backup'])) {
        /* is_dir() VOR mkdir(). Das @ genuegt nicht: ist ein eigener
         * Fehlerbehandler gesetzt - der Hauspruefstand tut das -, wird er
         * unabhaengig von error_reporting gerufen, und "mkdir(): File
         * exists" steht als Befund im Protokoll, obwohl nichts fehlt. */
        if (!is_dir(dirname($p['config']))) {
            @mkdir(dirname($p['config']), 0775, true);
        }
        if (@copy($p['backup'], $p['config'])) {
            // Die Zweitschrift traegt das Aktionstoken - dieselben Rechte.
            @chmod($p['config'], 0600);
            if ($oc_kaputt || $oc_ohne_token) { oc_log('Konfiguration aus der Sicherung zurueckgeholt.'); }
        }
        $roh = trim((string) @file_get_contents($p['config']));
    } elseif (!$heilen && ($roh === '' || $roh === '{}' || $oc_kaputt || $oc_ohne_token) && is_file($p['backup'])) {
        /* Nur lesen: die Sicherung wird verwendet, aber NICHT zurueck-
         * geschrieben. Sonst antwortete der Endpunkt einem berechtigten
         * Aufrufer mit Vorgabewerten, obwohl eine gueltige Sicherung
         * daneben liegt. */
        $roh = trim((string) @file_get_contents($p['backup']));
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
                         'zones' => '1', 'volume' => 8, 'lang' => 'de', 'template' => '',
                         // Ansage-2 (01.10.2026): Ausgabeweg Alexa-NG, ab Werk nicht gewaehlt.
                         'alexa_token' => '', 'alexa_geraet' => '');
    /* Auch die Ansage-Angaben kommen aus der Sicherungsdatei, wenn eine
     * zurueckgespielt wurde. Die Vorlage traegt eine Adresse - eine
     * ungeprueft uebernommene schickte den Ansagetext an einen fremden
     * Rechner. */
    if (!in_array($cfg['tts']['mode'], array('musicserver', 'ms4h', 'audioserver', 'custom', 'alexang'), true)) {
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
    /* Alexa-NG (Ansage-2): ein Token, das nicht seine Form hat, ist keines -
     * dann leer, und die Ansage sagt, dass es fehlt. */
    if (!oc_alexa_token_ok($cfg['tts']['alexa_token'])) { $cfg['tts']['alexa_token'] = ''; }
    $cfg['tts']['alexa_geraet'] = oc_text($cfg['tts']['alexa_geraet'], 200);

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
    /* ERST KODIEREN, DANN DEN RUECKGABEWERT ANSEHEN, DANN SCHREIBEN.
     *
     * json_encode() gibt bei ungueltigem UTF-8 false zurueck, und
     * file_put_contents(false) schreibt einen Leerstring und liefert 0 -
     * nicht false. Die Pruefung "=== false" griff also nicht. Gemessen mit
     * einem Regelnamen, der ein einzelnes Latin-1-Byte trug (das Formular
     * filtert nur Steuerzeichen und Anfuehrungszeichen, es kommt durch):
     *
     *     json_encode      -> bool(false), "Malformed UTF-8 characters"
     *     file_put_contents-> int(0), Datei 0 Byte
     *     oc_config_write  -> bool(true)      <- meldete Erfolg
     *
     * Danach war die Konfiguration leer UND - weil Zeile darunter kopiert -
     * die Zweitschrift ebenfalls. Das Aktionstoken war weg, die
     * Selbstheilung hatte nichts mehr zu holen, und die Oberflaeche sagte
     * "gespeichert". Unter 7.4 wie unter 8.4 gleich. */
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        oc_log('Konfiguration NICHT geschrieben: json_encode ist gescheitert ('
            . json_last_error_msg() . ') - der bisherige Stand bleibt unangetastet');
        return false;
    }
    /* RECHTE VOR INHALT. In dieser Datei steht das Aktionstoken. "Schreiben,
     * dann chmod" liesse sie fuer die Dauer des Schreibens mit den Vorgaben
     * der umask stehen - bei einem Geheimnis ist das der Unterschied
     * zwischen "kurz lesbar" und "nie lesbar".
     *
     * Bis 1.1.9 stand hier file_put_contents und danach 0640. Der
     * Hausstandard verlangt 0600, seit dem 13.09.2026 ausdruecklich auch
     * fuer das Aktionstoken (Regeln/05): wer es lesen kann, kann den
     * Endpunkt abfragen und - weil das Formularmerkmal daraus abgeleitet
     * wird - jedes Formular der Oberflaeche absenden. Die Schwesterlinie
     * aWATTar macht es seit je so; hier war es die Abweichung.
     *
     * Die Nebendatei traegt die Prozessnummer, sonst zerlegen zwei
     * gleichzeitige Schreiber einander. */
    /* C4 (seit 1.1.16): geschrieben wird ueber oc_datei_schreiben() - Laenge
     * gegen strlen, Ruecklesen, erst dann umbenennen. Eine kurze Schreibung
     * (volle Karte) liefert false, und Konfiguration UND Zweitschrift bleiben,
     * wie sie waren. Bis 1.1.15 meldete eine abgeschnittene Datei Erfolg, und
     * die Zweitschrift wurde aus ihr kopiert (Pruefbericht code, Befund 4). */
    if (!oc_datei_schreiben($p['config'], $json, 0600)) {
        oc_log('Konfiguration NICHT geschrieben: die Datei liess sich nicht vollstaendig schreiben '
            . '(voller Datentraeger?) - der bisherige Stand und die Zweitschrift bleiben unangetastet');
        return false;
    }
    /* Die Zweitschrift erst NACH dem geprueften Schreiben, aus demselben
     * Inhalt, mit denselben Rechten - und nur, wenn er ein Aktionstoken traegt:
     * eine Zweitschrift ohne Token haette die Selbstheilung nichts zu holen. */
    if (is_array($cfg) && isset($cfg['aktionstoken']) && oc_token_form($cfg['aktionstoken'])) {
        if (!oc_datei_schreiben($p['backup'], $json, 0600)) {
            oc_log('Die Zweitschrift liess sich nicht vollstaendig schreiben - die bisherige bleibt stehen.');
        }
    }
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
    /* Dieselbe Falle wie in oc_config_write(), nur ohne Zweitschrift
     * dahinter: ein Passwort mit einem Latin-1-Byte liess json_encode()
     * scheitern, file_put_contents schrieb 0 Byte und meldete 0 statt
     * false - und das Protokoll schrieb "Zugangsdaten gespeichert
     * (Passwortlaenge 7 Zeichen)", waehrend die Datei leer war. Danach nur
     * noch FEHLER_KEIN_ZUGANG, ohne dass irgendetwas darauf hinwies. */
    $json = json_encode($z, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        oc_log('Zugangsdaten NICHT geschrieben: json_encode ist gescheitert ('
            . json_last_error_msg() . ') - der bisherige Stand bleibt unangetastet');
        return false;
    }
    /* RECHTE VOR INHALT. Ein Klartextpasswort darf nicht einmal fuer die
     * Dauer eines Schreibvorgangs mit den umask-Vorgaben dastehen. Die
     * Nebendatei traegt dazu die Prozessnummer, damit zwei gleichzeitige
     * Schreibvorgaenge sich nicht ins Gehege kommen (Hausstandard
     * "Rechte vor Inhalt, und die Nebendatei traegt die Prozessnummer"). */
    /* C4 (seit 1.1.16): Laenge und Ruecklesen pruefen - bis 1.1.15 galt
     * "fwrite() !== false" als Erfolg (Pruefbericht code, Befund 4). */
    if (!oc_datei_schreiben($f, $json, 0600)) {
        oc_log('Zugangsdaten NICHT geschrieben: die Datei liess sich nicht vollstaendig schreiben '
            . '- der bisherige Stand bleibt');
        return false;
    }
    oc_weg(oc_datadir() . '/token.json');   // neue Zugangsdaten, altes Token verwerfen
    oc_weg(oc_anmeldesperre_datei());       // C7: neue Zugangsdaten, neuer Versuch
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
        /* C6 (seit 1.1.16): das ausdrueckliche Schliessen des cURL-Griffs ist
         * seit PHP 8.0 wirkungslos und meldet unter 8.5 zur LAUFZEIT
         * "deprecated" (php -l sieht das nicht). Nur noch hinter der
         * Fassungsweiche in DERSELBEN Zeile. */
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
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
        /* C6 (seit 1.1.16): die Kopfzeilen kommen aus
         * stream_get_meta_data()['wrapper_data'], und es gilt die LETZTE
         * Statuszeile (nach einer Umleitung stehen mehrere darin). Bis
         * 1.1.15 las diese Stelle die vordefinierte Kopfzeilen-Variable von
         * PHP und davon nur die ERSTE Zeile; PHP 8.5 meldet schon deren
         * Nennung als ueberholt, und entfaellt sie, hiesse jeder Code 0 und
         * jede Antwort Erfolg. Bauform ev_http_strom() (EVCC 0.9.34). */
        list($body, $erg['code']) = oc_http_strom($url, stream_context_create($opt));
        if ($body === false) {
            $erg['fehler'] = 'FEHLER_VERBINDUNG';
            return $erg;
        }
        $erg['body'] = (string) $body;
    }

    if ($erg['code'] >= 400) {
        $erg['fehler'] = 'FEHLER_HTTP:' . $erg['code'];
        return $erg;
    }
    $erg['ok'] = true;
    return $erg;
}

/**
 * Eine Adresse ueber den Datenstrom abrufen (Ersatzweg ohne php-curl und
 * oc_holen()). Rueckgabe array(Rumpf oder false, HTTP-Code der LETZTEN
 * Statuszeile). Die Kopfzeilen kommen aus stream_get_meta_data() (C6).
 */
function oc_http_strom($url, $ctx)
{
    $fh = @fopen($url, 'rb', false, $ctx);
    if ($fh === false) { return array(false, 0); }
    $meta = @stream_get_meta_data($fh);
    $body = @stream_get_contents($fh);
    @fclose($fh);
    $kopf = (is_array($meta) && isset($meta['wrapper_data']) && is_array($meta['wrapper_data']))
        ? $meta['wrapper_data'] : array();
    $code = 0;
    foreach ($kopf as $z) {
        if (is_string($z) && preg_match('#^HTTP/\S+\s+([0-9]{3})#', $z, $m)) { $code = (int) $m[1]; }
    }
    return array($body === false ? '' : (string) $body, $code);
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
    /* ANMELDEBREMSE (C7, seit 1.1.16): nach einer ABGELEHNTEN Anmeldung 30
     * Minuten keine neue - der Grund bleibt stehen. Bis 1.1.15 gab es bei
     * falschem Kennwort je Neuberechnung einen Versuch, gemessen 6 bei 6
     * Laeufen. Ein ausdruecklicher Knopf im Reiter Test versucht es sofort. */
    $oc_sperre = oc_anmeldesperre();
    if ($oc_sperre > 0 && !oc_anmeldung_ausdruecklich()) {
        $fehler = 'FEHLER_ANMELDUNG';
        oc_log_if_changed('anmeldesperre', 'Anmeldung bei Kraken ausgesetzt bis '
            . date('H:i', $oc_sperre) . ' - die letzte wurde abgelehnt. Reiter Test, '
            . '"Anmeldung pruefen" versucht es sofort.');
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
        // C7: die naechste Anmeldung fruehestens in 30 Minuten.
        if (!oc_datei_schreiben(oc_anmeldesperre_datei(), json_encode(array('bis' => time() + 1800)), 0600)) {
            oc_log_if_changed('anmeldesperre_datei', 'Die Anmeldebremse liess sich nicht schreiben ('
                . oc_anmeldesperre_datei() . ') - der naechste Lauf versucht es wieder.');
        }
        return '';
    }
    $token = isset($d['data']['obtainKrakenToken']['token'])
        ? (string) $d['data']['obtainKrakenToken']['token'] : '';
    if ($token === '') {
        $fehler = 'FEHLER_KEIN_TOKEN';
        oc_log_if_changed('anmeldung', 'Antwort enthielt kein Token');
        return '';
    }
    /* Rechte vor Inhalt, Nebendatei mit Prozessnummer - wie bei den
     * Zugangsdaten. Ein Kraken-Token ist ein Ausweis: wer es hat, kommt an
     * die Vertragsdaten, ohne Passwort. */
    $vor = $f . '.tmp.' . getmypid();
    $fh = @fopen($vor, 'wb');
    if ($fh) {
        @chmod($vor, 0600);
        @fwrite($fh, json_encode(array('token' => $token, 'exp' => time() + 3300)));
        @fclose($fh);
        if (@rename($vor, $f)) { @chmod($f, 0600); } else { @unlink($vor); }
    }
    oc_log_if_changed('anmeldung', 'Token geholt, gueltig bis ' . date('H:i', time() + 3300));
    oc_weg(oc_anmeldesperre_datei());
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
    /* b1 (Verbesserungsbau 30.09.2026): die Antwort gekuerzt und maskiert
     * fuer den Reiter Test merken - HIER, wo ohnehin abgerufen wird, und
     * nirgends sonst. Die Anzeige liest nur die Datei. */
    oc_rohantwort_merken($r, $token);
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
                /* Die ECHTE Laenge (C2, seit 1.1.16): bis 1.1.15 auf 3600 s
                 * gekappt und danach nie gelesen. oc_slots_vierteln() zerlegt
                 * laengere Eintraege in Viertelstunden. */
                'len'    => min(86400, max(300, $bis - $von)),
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
 * Die letzte Antwort von Kraken (b1, Verbesserungsbau 30.09.2026)
 *
 * Wer wissen will, ob Octopus Viertelstunden oder Stunden liefert und in
 * welcher Zeitzone, musste bisher das Protokoll lesen - und dort steht nur
 * "96 Preiseintraege von ... bis ...". Jetzt zeigt der Reiter Test die
 * gekuerzte Rohantwort der LETZTEN Preisabfrage: Zahl der Eintraege,
 * Schrittweite, Zeitzone der Zeitangaben, die ersten und die letzten drei
 * Eintraege im Wortlaut, bei einer Ablehnung die Meldung.
 *
 * - Gemerkt wird nur in oc_kraken_preise(), also nur, wenn der Minutentakt
 *   oder ein ausdruecklicher Knopf ohnehin abruft. Es gibt keinen eigenen
 *   Abruf; die Oberflaeche liest nur diese Datei.
 * - Datenordner, Rechte 0600, ueber oc_datei_schreiben().
 * - Nie mit Kennwort, Token, Kundennummer oder E-Mail: maskiert wird ueber
 *   die vorhandenen Masken - oc_maske_konto() fuer die Kundennummer (wie im
 *   Protokoll), '***' fuer Kennwort, E-Mail, Kraken-Token, Aktionstoken und
 *   Formularmerkmal (wie oc_meldung_ablegen()). Dazu jede Adressform
 *   name@domain und jede weitere Kundennummer der Form A-xxxx. Maskiert
 *   wird beim Schreiben UND beim Lesen - aendern sich die Zugangsdaten,
 *   gilt die neue Maske auch fuer eine alte Datei.
 * ================================================================== */

function oc_rohantwort_datei()
{
    return oc_paths()['datadir'] . '/kraken_antwort.json';
}

/** Die Werte, die nie in Datei oder Seite stehen duerfen => ihre Maske; laengste zuerst. */
function oc_rohantwort_geheimnisse($token = '')
{
    $z = oc_zugang();
    $c = oc_config(false);
    $g = array();
    $kraken = array((string) $token);
    $tf = oc_paths()['datadir'] . '/token.json';
    if (is_file($tf)) {
        $t = json_decode((string) @file_get_contents($tf), true);
        if (is_array($t) && isset($t['token']) && is_string($t['token'])) { $kraken[] = $t['token']; }
    }
    foreach (array_merge(array(
                 is_string($z['passwort']) ? $z['passwort'] : '',
                 is_string($z['email']) ? $z['email'] : '',
                 is_string($c['aktionstoken']) ? $c['aktionstoken'] : '',
                 oc_formtoken($c)), $kraken) as $w) {
        if (strlen((string) $w) >= 3) { $g[(string) $w] = '***'; }
    }
    if (is_string($z['konto']) && strlen($z['konto']) >= 3) {
        $g[$z['konto']] = oc_maske_konto($z['konto']);
    }
    uksort($g, function ($a, $b) { return strlen($b) - strlen($a); });
    return $g;
}

/** Einen Text maskieren (Gross/klein egal). */
function oc_rohantwort_maskieren($s, $geheim)
{
    $s = (string) $s;
    foreach ($geheim as $w => $m) { $s = str_ireplace((string) $w, $m, $s); }
    // Jede Adressform, auch eine fremde, und jede weitere Kundennummer.
    $s = (string) preg_replace('/[A-Za-z0-9._%+\'-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '***', $s);
    $s = (string) preg_replace_callback('/\bA-[A-Za-z0-9]{4,20}\b/', function ($m) {
        return oc_maske_konto($m[0]);
    }, $s);
    return $s;
}

/** Alle Zeichenketten eines Feldes maskieren, Schluessel und Werte. */
function oc_rohantwort_feld_maskieren($d, $geheim)
{
    if (!is_array($d)) { return is_string($d) ? oc_rohantwort_maskieren($d, $geheim) : $d; }
    $neu = array();
    foreach ($d as $k => $v) {
        $neu[is_string($k) ? oc_rohantwort_maskieren($k, $geheim) : $k] = oc_rohantwort_feld_maskieren($v, $geheim);
    }
    return $neu;
}

/** Rekursiv die Roh-Eintraege mit validFrom/validTo sammeln (wie oc_sammle_preise()). */
function oc_rohantwort_eintraege($node, &$out)
{
    if (!is_array($node)) { return; }
    if (isset($node['validFrom']) && isset($node['validTo'])) {
        $out[] = $node;
        return;
    }
    foreach ($node as $kind) {
        if (is_array($kind)) { oc_rohantwort_eintraege($kind, $out); }
    }
}

/**
 * Die Antwort der Preisabfrage gekuerzt und maskiert ablegen.
 * $r ist das Ergebnis von oc_http(), $token das Kraken-Token der Anfrage.
 */
function oc_rohantwort_merken($r, $token = '')
{
    $body = isset($r['body']) ? (string) $r['body'] : '';
    $e = array('zeit' => time(), 'http' => isset($r['code']) ? (int) $r['code'] : 0,
               'fehler' => isset($r['fehler']) ? (string) $r['fehler'] : '', 'laenge' => strlen($body),
               'anzahl' => 0, 'schritte' => array(), 'zonen' => array(),
               'erste' => array(), 'letzte' => array(), 'meldung' => '');
    $d = json_decode(ltrim($body), true);
    if (is_array($d)) {
        if (!empty($d['errors']) && is_array($d['errors'])) {
            $m = (isset($d['errors'][0]['message']) && is_string($d['errors'][0]['message']))
                ? $d['errors'][0]['message'] : 'errors';
            $e['meldung'] = substr($m, 0, 300);
        }
        $roh = array();
        oc_rohantwort_eintraege(isset($d['data']) ? $d['data'] : $d, $roh);
        usort($roh, function ($a, $b) {
            return (int) @strtotime((string) (is_array($a['validFrom']) ? '' : $a['validFrom']))
                 - (int) @strtotime((string) (is_array($b['validFrom']) ? '' : $b['validFrom']));
        });
        $e['anzahl'] = count($roh);
        foreach ($roh as $n) {
            $von = is_array($n['validFrom']) ? '' : (string) $n['validFrom'];
            $bis = is_array($n['validTo']) ? '' : (string) $n['validTo'];
            $a = @strtotime($von);
            $b = @strtotime($bis);
            $min = ($a && $b && $b > $a) ? (string) (int) round(($b - $a) / 60) : '?';
            $e['schritte'][$min] = isset($e['schritte'][$min]) ? $e['schritte'][$min] + 1 : 1;
            $zone = preg_match('/(Z|[+-][0-9]{2}:?[0-9]{2})$/', trim($von), $zm) ? $zm[1] : '?';
            $e['zonen'][$zone] = isset($e['zonen'][$zone]) ? $e['zonen'][$zone] + 1 : 1;
        }
        ksort($e['schritte'], SORT_NUMERIC);
        $zeile = function ($n) {
            $s = json_encode($n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            return substr((string) $s, 0, 600);
        };
        $geheim = oc_rohantwort_geheimnisse($token);
        foreach (array_slice($roh, 0, 3) as $n) {
            $e['erste'][] = $zeile(oc_rohantwort_feld_maskieren($n, $geheim));
        }
        foreach (array_slice($roh, max(3, count($roh) - 3)) as $n) {
            $e['letzte'][] = $zeile(oc_rohantwort_feld_maskieren($n, $geheim));
        }
    } elseif ($body !== '') {
        // Kein JSON (Gateway, HTML): der Anfang, ohne Zeilenumbrueche.
        $e['meldung'] = substr(trim((string) preg_replace('/\s+/', ' ', $body)), 0, 300);
    }
    $e = oc_rohantwort_feld_maskieren($e, oc_rohantwort_geheimnisse($token));
    $js = json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if (!is_string($js) || !oc_datei_schreiben(oc_rohantwort_datei(), $js, 0600)) {
        oc_log_if_changed('rohantwort', 'Die letzte Antwort von Kraken liess sich nicht merken ('
            . oc_rohantwort_datei() . ') - der Reiter Test zeigt dann eine aeltere oder keine.');
    }
}

/** Die gemerkte Antwort lesen und erneut maskieren; null = keine. */
function oc_rohantwort_lesen()
{
    $f = oc_rohantwort_datei();
    if (!is_file($f)) { return null; }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!is_array($d) || !isset($d['zeit'])) { return null; }
    $d += array('http' => 0, 'fehler' => '', 'laenge' => 0, 'anzahl' => 0, 'schritte' => array(),
                'zonen' => array(), 'erste' => array(), 'letzte' => array(), 'meldung' => '');
    foreach (array('schritte', 'zonen', 'erste', 'letzte') as $k) {
        if (!is_array($d[$k])) { $d[$k] = array(); }
    }
    return oc_rohantwort_feld_maskieren($d, oc_rohantwort_geheimnisse());
}

/** Der Block im Reiter Test (liest nur, fragt nie). */
function oc_rohantwort_html($cfg)
{
    $h = '<h3 class="sm-h3">' . oc_t('TEST.H_ROH') . '</h3>'
       . '<p class="sm-small">' . oc_t('TEST.ROH_TEXT') . '</p>';
    if (!empty($cfg['demo'])) {
        $h .= '<div class="sm-hinweis">' . oc_t('TEST.ROH_DEMO') . '</div>';
    }
    $e = oc_rohantwort_lesen();
    if ($e === null) {
        return $h . '<div class="sm-hinweis">' . oc_t('TEST.ROH_KEINE') . '</div>';
    }
    $zahl = function ($liste) {
        $t = array();
        foreach ($liste as $k => $n) { $t[] = $k . ' (' . (int) $n . "\xC3\x97)"; }
        return implode(', ', $t);
    };
    $schritt = '';
    if (!$e['schritte']) {
        $schritt = '&ndash;';
    } elseif (count($e['schritte']) === 1) {
        $m = (string) key($e['schritte']);
        $schritt = oc_e($m . ' min');
        if ($m === '15') { $schritt .= ' &mdash; ' . oc_t('TEST.ROH_S15'); }
        if ($m === '60') { $schritt .= ' &mdash; ' . oc_t('TEST.ROH_S60'); }
    } else {
        $t = array();
        foreach ($e['schritte'] as $m => $n) { $t[$m . ' min'] = $n; }
        $schritt = oc_t('TEST.ROH_GEMISCHT') . ': ' . oc_e($zahl($t));
    }
    $zonen = array();
    foreach ($e['zonen'] as $z => $n) {
        $zonen[] = ($z === 'Z' ? 'UTC (Z)' : ($z === '?' ? oc_t('TEST.ROH_OHNE_ZONE') : 'UTC' . $z));
    }
    $r = function ($a, $b) { return '<tr><td>' . $a . '</td><td>' . $b . '</td></tr>'; };
    $h .= '<table class="sm-tbl"><tr><th>' . oc_t('TEST.SP_WAS') . '</th><th>' . oc_t('TEST.SP_WERT') . '</th></tr>';
    $h .= $r(oc_t('TEST.ROH_ZEIT'), oc_e(date('d.m.Y H:i:s', (int) $e['zeit'])));
    $h .= $r(oc_t('TEST.ROH_HTTP'), oc_e('HTTP ' . ((int) $e['http'] ?: '-') . ', '
        . (int) $e['laenge'] . ' Byte' . ($e['fehler'] !== '' ? ', ' . oc_fehlertext($e['fehler']) : '')));
    $h .= $r(oc_t('TEST.ROH_ANZAHL'), (string) (int) $e['anzahl']);
    $h .= $r(oc_t('TEST.ROH_SCHRITT'), $schritt);
    $h .= $r(oc_t('TEST.ROH_ZONE'), $zonen ? oc_e(implode(', ', $zonen)) : '&ndash;');
    if ((string) $e['meldung'] !== '') {
        $h .= $r(oc_t('TEST.ROH_MELDUNG'), '<span class="sm-mono">' . oc_e($e['meldung']) . '</span>');
    }
    $h .= '</table>';
    if ($e['erste']) {
        $txt = oc_t('TEST.ROH_ERSTE') . "\n" . implode("\n", array_map('strval', $e['erste']));
        if ($e['letzte']) {
            $txt .= "\n\n" . oc_t('TEST.ROH_LETZTE') . "\n" . implode("\n", array_map('strval', $e['letzte']));
        }
        $h .= '<div class="sm-pre">' . oc_e($txt) . '</div>';
    }
    return $h;
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

    $heute0 = strtotime('today 00:00');
    foreach (array($heute0, strtotime('tomorrow 00:00')) as $tag) {
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
                /* FEHLENDE PREISE FUER MORGEN SIND KEIN FEHLER.
                 *
                 * Die Boerse veroeffentlicht den Folgetag erst am Nachmittag.
                 * Bis 1.1.3 setzte dieser Zweig auch fuer 'morgen' einen
                 * Fehler, und weil er nur beim ERSTEN Mal gesetzt wird,
                 * blieb er stehen, obwohl der heutige Tag vollstaendig
                 * vorlag. Gemessen mit der echten Antwort von aWATTar fuer
                 * einen Tag ohne Daten ({"object":"list","data":[]}):
                 * 96 Viertelstunden geholt, Fehler 'FEHLER_DEMO' - und die
                 * Oberflaeche meldete jeden Vormittag einen Fehler, den es
                 * nicht gab.
                 *
                 * Gemeldet wird deshalb nur, wenn HEUTE fehlt. Ob morgen
                 * schon da ist, sagt ohnehin 'morgen_ok'. */
                if ($tag === $heute0 && $out['fehler'] === '') {
                    $out['fehler'] = $r['fehler'] !== '' ? $r['fehler'] : 'FEHLER_DEMO';
                }
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
    $erg = oc_preise_holen($force);
    /* Stundeneintraege und zusammengefasste Eintraege in Viertelstunden
     * zerlegen - auf JEDEM Weg, auch aus dem Zwischenspeicher (C2). */
    if (is_array($erg) && isset($erg['slots']) && is_array($erg['slots'])) {
        $erg['slots'] = oc_slots_vierteln($erg['slots']);
    }
    return $erg;
}

/** Wo der Grund des letzten gescheiterten Abrufs liegt (O2: fuer die Anzeige). */
function oc_abruf_fehler_datei()
{
    return oc_tmpdir() . '/abruf_fehler.txt';
}

function oc_preise_holen($force = false)
{
    $cfg = oc_config();
    $cache = oc_datadir() . '/preise.json';
    /* NUR ANZEIGEN (O2, seit 1.1.16): der Zwischenspeicher des
     * Minutentakts in JEDEM Alter, nie ein Abruf. Ist er aelter als der
     * Takt des Abrufs, gilt er als veraltet, und der Grund kommt aus dem
     * letzten Lauf des Takts. */
    if (oc_kein_abruf() && !$force) {
        $grund = is_file(oc_abruf_fehler_datei())
            ? trim((string) @file_get_contents(oc_abruf_fehler_datei())) : '';
        $c = is_file($cache) ? json_decode((string) @file_get_contents($cache), true) : null;
        if (is_array($c) && !empty($c['slots']) && is_array($c['slots'])) {
            $neu = array();
            foreach ($c['slots'] as $ts => $s) { $neu[(int) $ts] = $s; }
            ksort($neu);
            $c['slots'] = $neu;
            if (time() - (int) filemtime($cache) >= 900) {
                $c['veraltet'] = 1;
                $c['fehler'] = $grund !== '' ? $grund : 'FEHLER_NOCH_KEIN_ABRUF';
            }
            return $c;
        }
        return array('slots' => array(), 'fehler' => $grund !== '' ? $grund : 'FEHLER_NOCH_KEIN_ABRUF',
                     'demo' => !empty($cfg['demo']) ? 1 : 0, 'brutto_ok' => 1, 'stand' => 0);
    }
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
    // Den Grund fuer die Anzeige merken (O2) - leer nach einem Erfolg.
    @file_put_contents(oc_abruf_fehler_datei(), $r['slots'] ? '' : (string) $r['fehler']);
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
    // O2: die Oberflaeche liest nur, was der Takt geholt hat.
    if (oc_kein_abruf() && !$force) {
        $c = is_file($cache) ? json_decode((string) @file_get_contents($cache), true) : null;
        return is_array($c) ? $c + $leer : $leer;
    }
    $erg = $leer;
    $erg['ts'] = time();
    $jetzt = time() - (time() % 900);

    if ($cfg['pv_quelle'] !== '' && trim((string) $cfg['pv_url']) !== '') {
        $roh = oc_holen($cfg['pv_url'], $hf);
        if ($roh === null) {
            // Den GRUND weiterreichen, nicht pauschal "nicht erreichbar":
            // ein 404 und ein toter Port sind zwei verschiedene Fehler, und
            // der Anwender sucht sonst am falschen Ende.
            $erg['pv_meldung'] = $hf !== '' ? $hf : 'NICHT_ERREICHBAR';
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
        $roh = oc_holen($cfg['soc_url'], $hf);
        if ($roh === null) {
            $erg['soc_meldung'] = $hf !== '' ? $hf : 'NICHT_ERREICHBAR';
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
/**
 * Eine fremde JSON-Auskunft holen (PV-Prognose, Speicherstand, Verbrauch).
 *
 * DEN STATUS ANSEHEN, NICHT NUR DEN RUMPF. 'ignore_errors' => true liefert
 * den Rumpf auch bei 404 und 500 - bis 1.1.3 wurde er dann ungeprueft als
 * Nutzdaten weitergereicht. Gemessen gegen einen oertlichen Webserver:
 *
 *     /ok        200 + JSON   -> Feld            (richtig)
 *     /html      404 + HTML   -> null            (Ursache: "nicht erreichbar",
 *                                                 dabei war es sehr wohl da)
 *     /json500   500 + JSON   -> Feld            <- Fehlerrumpf als Prognose
 *     Port zu                 -> null            (richtig)
 *
 * Ein JSON-Fehlerrumpf wanderte damit in plan_pv_lesen(), und der
 * Fahrplaner rechnete mit einer Prognose, die keine war.
 *
 * Die Kopfzeilen sind dieselben wie in oc_http() - die Regel dazu steht
 * im Kopf jener Funktion und galt fuer diese hier bisher nicht.
 */
function oc_holen($url, &$fehler = null)
{
    /* $fehler traegt einen Schluessel aus [PLANMELD] der Sprachdatei -
     * nicht einen erfundenen Text und keine Zahl. Die Oberflaeche setzt
     * 'PLANMELD.' davor und schlaegt ihn nach; ein Schluessel, den es dort
     * nicht gibt, stuende woertlich auf dem Bildschirm. */
    $fehler = '';
    $url = trim((string) $url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        $fehler = 'ADRESSE';
        return null;
    }
    $ctx = stream_context_create(array('http' => array(
        'timeout' => 12,
        'header' => "User-Agent: LoxBerry Octopus\r\n"
                  . "Accept: application/json\r\n"
                  . "Accept-Language: de,en;q=0.8\r\n"
                  . "Accept-Encoding: identity\r\n",
        'follow_location' => 0,
        'max_redirects' => 1,
        'ignore_errors' => true)));
    /* C6 (seit 1.1.16): Rumpf und LETZTE Statuszeile ueber
     * oc_http_strom() - ohne die vordefinierte Kopfzeilen-Variable, die
     * PHP 8.5 als ueberholt meldet. Entfiele sie, wuerde ein Fehlerrumpf
     * (404/500) wieder als Prognose gelesen. */
    list($r, $code) = oc_http_strom($url, $ctx);
    if ($r === false) {
        $fehler = 'NICHT_ERREICHBAR';
        return null;
    }
    if ($code >= 400 || ($code > 0 && $code < 200)) {
        /* Die Zahl gehoert ins Protokoll, nicht in die Beschriftung: eine
         * Meldung je Statuscode waere eine Sprachdatei voller Zahlen. */
        oc_log_if_changed('holen', 'Abruf beantwortet mit HTTP ' . $code . ' (' . $url . ')');
        $fehler = 'HTTP_FEHLER';
        return null;
    }
    $d = json_decode($r, true);
    if (!is_array($d)) {
        $fehler = 'KEINE_ANTWORT';
        return null;
    }
    return $d;
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
 * Die Einzelrechnung von 0.9.1 (oc_regel_werte() samt zwei Helfern) ist in
 * 1.1.11 entfernt: sie wurde seit dem Fahrplaner nirgends mehr aufgerufen,
 * und der Satz, der Reiter Test stelle sie zum Vergleich daneben, stimmte
 * nicht (tote_helfer.py). Der Planer bringt drei Dinge dazu, die eine
 * einzelne Regel nicht wissen kann - die Frist, das gemeinsame
 * Leistungsbudget und die PV-Prognose.
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
    /* Ohne Preise ist kein Tageswert bekannt: -1 (Entscheidung 5/8, seit
     * 1.1.16). Bis 1.1.15 stand hier 0, und avg_morgen/min_morgen gingen
     * jeden Vormittag als 0.000 hinaus - eine Null ist ein Preis. */
    return array('n' => 0, 'avg' => -1, 'minp' => -1, 'mints' => 0, 'minh' => -1, 'minm' => 0,
                 'maxp' => -1, 'maxts' => 0, 'maxh' => -1, 'maxm' => 0,
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
        // Kein Fenster, kein Preis: -1 statt 0 (Entscheidung 5/8, seit 1.1.16).
        return array('ts' => 0, 'h' => -1, 'm' => 0, 'in' => -1, 'ct' => -1);
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
    /* O2: im Nur-Anzeige-Betrieb gilt der Zustand des Takts, solange er
     * dieselbe Viertelstunde meint - in jedem Alter. */
    $grenze = (oc_kein_abruf() && !$force) ? 900 : 240;
    if (!$force && is_file($cache) && time() - filemtime($cache) < $grenze) {
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
    $ok = ($heute !== null && $heute['n'] > 0);
    $morgen_da = ($morgen !== null && $morgen['n'] > 0);

    /* ---- Ersatzwerte, falls eine Viertelstunde fehlt (C1, C2, seit 1.1.16) ----
     *
     * Uebertragen aus Spotpreis-aWATTar 1.2.30 (spot_lib.php:1487-1526 und
     * :1646-1672): fehlt ein Preis, ist die Frage nicht "welche Zahl passt am
     * besten", sondern "welche Zahl richtet keinen Schaden an". Eine 0 ist die
     * schlechteste - sie sieht wie die guenstigste Viertelstunde des Tages aus.
     * Bis 1.1.15 stand hier 0.0: gemessen an der Attrappe (Pruefbericht code,
     * Befunde 1 und 2) gingen jeden Vormittag PM00-23 und die hinteren
     * PR-Stunden als 0.000 an den Spot Price Optimizer, und fehlte die
     * laufende Viertelstunde, kam CUR=0 RANK=1 LEVEL=1 bei OK=1.
     *
     * Ersatzwert ist der TAGESHOECHSTPREIS (fuer die rollende Sicht der
     * hoehere der beiden Tage), damit wird die Stunde nie gewaehlt. cur_fehlt
     * sagt an, dass fuer die laufende Viertelstunde ein Ersatzwert steht;
     * pr_ersatz zaehlt die Stunden der rollenden Reihe mit Ersatzwert. Ohne
     * jeden Preis (ok=0) tragen alle Preise -1 - dann antwortet der Endpunkt
     * ohnehin mit 503, und ueber MQTT geht nur das Signal (C3). */
    $ph_ersatz = $ok ? round((float) $heute['maxp'], 3) : -1.0;
    $pm_ersatz = $morgen_da ? round((float) $morgen['maxp'], 3) : $ph_ersatz;
    $pr_ersatz = max($ph_ersatz, $pm_ersatz);
    // Der Nettoanteil der teuersten Viertelstunde - nie eine negative Zahl
    // aus dem Nichts, sonst hiesse es "neg=1" und eine Regel schaltete ein.
    $n_ersatz = $pr_ersatz;
    foreach (array($morgen_da && $pm_ersatz >= $ph_ersatz ? $morgen : null, $ok ? $heute : null) as $tg) {
        if ($tg !== null && isset($slots[(int) $tg['maxts']]['net']) && $slots[(int) $tg['maxts']]['net'] !== null) {
            $n_ersatz = round((float) $slots[(int) $tg['maxts']]['net'], 3);
            break;
        }
    }
    $cur_fehlt = isset($slots[$slotstart]) ? 0 : 1;
    if ($ok) {
        $cur = isset($slots[$slotstart]) ? round((float) $slots[$slotstart]['ct'], 3) : $pr_ersatz;
        if (isset($slots[$slotstart])) {
            $curn = ($slots[$slotstart]['net'] !== null) ? round((float) $slots[$slotstart]['net'], 3) : 0.0;
        } else {
            $curn = $n_ersatz;
        }
        $next = isset($slots[$slotstart + 900]) ? round((float) $slots[$slotstart + 900]['ct'], 3) : $pr_ersatz;
        $curh = isset($std[$hstart]) ? $std[$hstart] : $pr_ersatz;
        $nexth = isset($std[$hstart + 3600]) ? $std[$hstart + 3600] : $pr_ersatz;
    } else {
        $cur = -1.0; $curn = -1.0; $next = -1.0; $curh = -1.0; $nexth = -1.0;
    }

    // Rang der laufenden Viertelstunde in den naechsten 24 Stunden
    $fenster24 = array();
    foreach ($slots as $ts => $s) {
        if ($ts >= $slotstart && $ts < $slotstart + 86400) { $fenster24[$ts] = round((float) $s['ct'], 3); }
    }
    $werte = array_values($fenster24);
    sort($werte);
    $rang = 1;
    foreach ($werte as $v) { if ($v < $cur) { $rang++; } }
    /* RANG 1..n (seit 1.1.16): verglichen wird mit denselben auf 3 Stellen
     * gerundeten Werten wie cur - bis 1.1.15 stand cur gerundet gegen
     * ungerundete Werte, und es kam rankd=0 bzw. rank=n+1 heraus (gemessen:
     * rank=97 rankd=0 bei n=96, Stundeneintraege). Ein Ersatzwert ausserhalb
     * der 24 Stunden (Hoechstpreis von morgen oder schon vorbei) zaehlt als
     * teuerster Platz n. Dieselbe Stelle hat Spotpreis-aWATTar 1.2.30. */
    if (count($werte)) { $rang = min($rang, count($werte)); }

    // Rang der laufenden Stunde in den naechsten 24 Stunden
    $std24 = array();
    foreach ($std as $ts => $v) {
        if ($ts >= $hstart && $ts < $hstart + 86400) { $std24[$ts] = $v; }
    }
    $wh = array_values($std24);
    sort($wh);
    $rangh = 1;
    foreach ($wh as $v) { if ($v < $curh) { $rangh++; } }
    if (count($wh)) { $rangh = min($rangh, count($wh)); }

    /* OHNE GUELTIGE PREISE IST DAS NIVEAU NICHT BEKANNT.
     *
     * Bis 1.1.8 stand hier 2 als Anfangswert, und 2 heisst laut
     * Sprachdatei 'normal'. Am Geraet gemessen (13.09.2026, kein
     * Octopus-Vertrag, also ok=0 und n=0): octopus/level ging mit 2
     * hinaus, als sei der Preis gerade normal. -1 heisst 'nicht
     * bekannt' - dieselbe Schreibweise, die dieses Plugin bei
     * fenster_start, fenster_in, co2_minh und plan_soc schon
     * benutzt. Fehlt nur die laufende Viertelstunde, gilt der
     * Ersatzwert (Tageshoechstpreis) - nie mehr "guenstig" aus einer 0. */
    $level = $ok ? 2 : -1;
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
        'cur_fehlt'   => $ok ? $cur_fehlt : 1,
        'cur_netto'   => $curn,
        'cur_h'       => $curh,
        'next'        => $next,
        'next_h'      => $nexth,
        // Nur ein bekannter Preis kann negativ sein - -1 heisst "ohne Aussage".
        'neg'         => ($ok && $curn < 0) ? 1 : 0,
        /* EIN RANG OHNE PREISE IST KEIN RANG.
         *
         * $rang faengt bei 1 an und wird je guenstigerem Wert erhoeht;
         * bei leerer Liste bleibt er 1 - und 1 heisst laut Sprachdatei
         * 'guenstigste'. Eine Loxone-Regel 'schalten, wenn Rang <= 3'
         * schaltet damit, ohne dass ein einziger Preis vorliegt. Am
         * Geraet gemessen (13.09.2026): octopus/rank 1 und
         * octopus/rank_h 1 bei octopus/ok 0 und n=0.
         *
         * rankd hatte fuer genau diesen Fall schon einen Ersatzwert
         * (99), rank und rank_h nicht - drei Zeilen auseinander in
         * derselben Aufzaehlung. Alle drei tragen jetzt -1; die 99 war
         * nirgends beschrieben und deshalb keine Zusage. -1 ist die
         * Schreibweise, die dieses Plugin ohnehin fuehrt. */
        'rank'        => count($werte) ? $rang : -1,
        'rankd'       => count($werte) ? count($werte) + 1 - $rang : -1,
        'n'           => count($werte),
        'rank_h'      => count($wh) ? $rangh : -1,
        'n_h'         => count($wh),
        'level'       => $level,
        'heute'       => $heute !== null ? $heute : oc_tagstats_leer(),
        'morgen'      => $morgen !== null ? $morgen : oc_tagstats_leer(),
        'tomorrow_ok' => $morgen_da ? 1 : 0,
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
     * 240 Sekunden bekam ihn und damit einen verstuemmelten Zustand.
     *
     * Merksatz fuer den naechsten, der hier etwas anhaengt: ein
     * Zwischenspeicher wird geschrieben, wenn der Wert FERTIG ist. */

    // Stundenmittel fuer den Spot Price Optimizer: der Baustein hat nur
    // 24 Preiseingaenge, Viertelstunden nimmt er nicht an.
    $stdh = oc_stunden($slots);
    $st['profil_heute'] = array();
    $st['profil_morgen'] = array();
    $st['profil_relativ'] = array();
    /* PH/PM MEINEN ORTSSTUNDEN, NICHT "MITTERNACHT PLUS h STUNDEN" (seit
     * 1.1.4, gemessen an 29.03. und 25.10.2026). PR bleibt bei der Addition:
     * es ist als "in %d Stunden" beschriftet.
     *
     * FEHLT EIN PREIS (C1, seit 1.1.16): PH mit dem Tageshoechstpreis von
     * heute, PR mit dem hoeheren der beiden Tageshoechstpreise (aWATTar
     * 1.2.30, spot_lib.php:1646-1672). PM ohne veroeffentlichte Preise fuer
     * morgen: -1 und morgen_ok=0 (Entscheidung 5/8) - fehlt nur eine Stunde
     * von morgen (Zeitumstellung), der Hoechstpreis von morgen. */
    $t0 = strtotime('today 00:00');
    $m0 = strtotime('tomorrow 00:00');
    $dh = getdate($t0);
    $dm = getdate($m0);
    $st['pr_ersatz'] = 0;
    for ($h = 0; $h < 24; $h++) {
        $kh = mktime($h, 0, 0, $dh['mon'], $dh['mday'], $dh['year']);
        $km = mktime($h, 0, 0, $dm['mon'], $dm['mday'], $dm['year']);
        $st['profil_heute'][$h] = isset($stdh[$kh]) ? $stdh[$kh] : $ph_ersatz;
        $st['profil_morgen'][$h] = isset($stdh[$km]) ? $stdh[$km] : ($morgen_da ? $pm_ersatz : -1.0);
        if (isset($stdh[$hstart + $h * 3600])) {
            $st['profil_relativ'][$h] = $stdh[$hstart + $h * 3600];
        } else {
            $st['profil_relativ'][$h] = $pr_ersatz;
            $st['pr_ersatz']++;
        }
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

    /* Die Oberflaeche (O2) rechnet nur fuer die Anzeige: sie schreibt weder
     * die Hysterese noch den Zwischenspeicher fort - das bleibt dem Takt. */
    if (oc_kein_abruf() && !$force) { return $st; }

    /* Die Hysterese fortschreiben: welcher Block laeuft gerade, und bis
     * wann? Erst NACH der Rechnung, damit der naechste Lauf ihn vorfindet.
     * Siehe oc_laufend_fortschreiben(). */
    oc_laufend_fortschreiben($st['regeln'], $slotstart);

    /* ERST JETZT den Zwischenspeicher schreiben - $st ist vollstaendig. */
    @file_put_contents($cache, json_encode($st));

    oc_log_if_changed('zustand', 'jetzt=' . $st['cur'] . ' ct rang=' . $st['rank'] . '/' . $st['n']
        . ' niveau=' . $st['level'] . ' morgen=' . $st['tomorrow_ok'] . ' demo=' . $st['demo']
        . ($st['cur_fehlt'] && $st['ok'] ? ' (Ersatzwert: laufende Viertelstunde fehlt)' : ''));
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
    /* O2 (seit 1.1.16): die Oberflaeche fragt energy-charts nicht selbst -
     * der Zwischenspeicher des Takts, hoechstens sechs Stunden alt, sonst
     * "unbekannt". Bis 1.1.15 ging jedes Oeffnen ohne Zwischenspeicher dorthin
     * (rendern.py: "1 Linie wollte ins Netz"). */
    if (oc_kein_abruf() && !$force) {
        if (is_file($cache) && time() - (int) filemtime($cache) < 6 * 3600) {
            $c = json_decode((string) @file_get_contents($cache), true);
            if (is_array($c) && isset($c['ok'])) { return $c; }
        }
        return $aus;
    }
    $r = oc_http('https://api.energy-charts.info/co2eq?country=de', null, array(), 15);
    $d = $r['ok'] ? json_decode($r['body'], true) : null;
    if (!isset($d['unix_seconds']) || !is_array($d['unix_seconds'])) {
        /* EINE ALTERSGRENZE GILT AUCH HIER.
         *
         * Der Zwischenspeicher oben laeuft nach 1800 s ab; dieser
         * Rueckfallweg hatte bis 1.1.3 gar keine Grenze. Gemessen mit einem
         * 48 Stunden alten co2.json und einem Abruf, der scheitert:
         * oc_co2() lieferte ok=1 und now=111 g/kWh, als waere es eben
         * gemessen worden - und es gibt kein Thema, das das Alter verraet.
         * Damit trug auch das Schaltsignal octopus/co2_clean einen
         * beliebig alten Wert.
         *
         * Sechs Stunden sind grosszuegig: die Quelle liefert stuendlich,
         * und ein paar fehlgeschlagene Abrufe hintereinander sollen die
         * Anzeige nicht loeschen. Was aelter ist, gilt als unbekannt -
         * ok=0, und co2_clean faellt auf 0. Das ist die sichere Richtung:
         * "nicht sauber" schaltet nichts ein.
         *
         * Bei den Preisen macht das Plugin es seit jeher richtig
         * ('veraltet', 'alter', 'stand'); hier fehlte das Gegenstueck. */
        if (is_file($cache) && time() - (int) filemtime($cache) < 6 * 3600) {
            $c = json_decode((string) @file_get_contents($cache), true);
            if (is_array($c)) { return $c; }
        }
        if (is_file($cache)) {
            oc_log_if_changed('co2alt', 'Zwischenspeicher ist aelter als sechs Stunden - '
                . 'CO2 gilt als unbekannt, statt einen alten Wert als aktuellen auszugeben');
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
    /* O9 (seit 1.1.16): ohne Mqtt-Abschnitt ist nichts feststellbar -
     * 'vorhanden' bleibt 0. Bis 1.1.15 stand dann oben "ohne Autostart kommt
     * nichts am Miniserver an", obwohl der Kommentar an der Ausgabestelle
     * Stille verspricht (Pruefbericht oberflaeche, Befund 9). */
    if (!is_array($m) || !$m) { return $g; }
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
        // C2 (seit 1.1.16): 1 = fuer die laufende Viertelstunde steht der
        // Ersatzwert (Tageshoechstpreis). Name wie in aWATTar (cur_fehlt).
        'cur_fehlt'     => array('THEMA.CUR_FEHLT', ''),
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
        // C1 (seit 1.1.16): wie viele der 24 Stunden den Ersatzwert tragen.
        $t['pr_ersatz'] = array('THEMA.PR_ERSATZ', '');
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

/**
 * Kachelname eines Themas fuer die Loxone-Vorlage (Regeln/07: der Comment
 * wird zum Anzeigenamen und bleibt kurz). Eigene Texte LOXNAME.* - der
 * Abschnitt KACHEL.* beschriftet schon die Statuskacheln der Oberflaeche,
 * und THEMA.* bleibt die Erklaerung dort. Fehlt ein LOXNAME-Text, gilt der
 * THEMA-Text. Bei Schaltregeln steht der Regelname (hoechstens 12 Zeichen)
 * an Stelle von "Regel N".
 */
function oc_kachel_text($info)
{
    $s = substr((string) $info[0], 6);
    $roh = oc_t('LOXNAME.' . $s);
    if ($roh === 'LOXNAME.' . $s) { return oc_thema_text($info); }
    if (strpos($s, 'REGEL_') === 0 && isset($info[2])) {
        $name = (isset($info[3]) && preg_match('/^ \((.*)\)$/su', (string) $info[3], $m)) ? trim($m[1]) : '';
        $wer = $name !== '' ? preg_replace('/^(.{0,12}).*$/su', '$1', $name)
                            : sprintf(oc_t('LOXNAME.REGEL'), (int) $info[2]);
        return sprintf($roh, $wer);
    }
    return isset($info[2]) ? sprintf($roh, (int) $info[2]) : $roh;
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
        'cur_fehlt'     => isset($st['cur_fehlt']) ? (int) $st['cur_fehlt'] : 0,
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
            $w[sprintf('ph%02d', $h)] = isset($st['profil_heute'][$h]) ? $st['profil_heute'][$h] : -1;
            $w[sprintf('pm%02d', $h)] = isset($st['profil_morgen'][$h]) ? $st['profil_morgen'][$h] : -1;
        }
    }
    if ($modus === 'relativ' || $modus === 'beides') {
        for ($h = 0; $h < 24; $h++) {
            $w[sprintf('pr%02d', $h)] = isset($st['profil_relativ'][$h]) ? $st['profil_relativ'][$h] : -1;
        }
        $w['pr_ersatz'] = isset($st['pr_ersatz']) ? (int) $st['pr_ersatz'] : 0;
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
/**
 * Welche Themen gehen ZURUECKBEHALTEN (retained) hinaus?
 *
 * Hausstandard seit 03.09.2026 (Regeln/07): Zustaende retained, damit
 * Loxone nach einem Neustart des Miniservers oder des Gateways sofort den
 * Stand hat; Messwerte mit Zeitbezug nicht, damit kein alter Wert als
 * aktuell erscheint; das Lebenszeichen nie.
 *
 * Bis 1.1.8 ging ALLES fluechtig hinaus. Am Geraet gemessen (13.09.2026):
 * unter octopus/# lagen 0 zurueckbehaltene Themen, waehrend andere Linien
 * am selben Broker 19 bis 59 fuehrten. Nach einem Neustart des Miniservers
 * standen die Eingaenge also leer, bis sich der jeweilige Wert das naechste
 * Mal AENDERTE - und die Sendebremse haelt gleiche Werte zurueck, das kann
 * bei einer Einstellung wie plan_budget beliebig lange dauern.
 *
 * Der UDP-Weg kann das: `retain <thema> <wert>` statt `publish <thema>
 * <wert>` (mqttgateway.pl, sub udpin). Am laufenden Gateway nachgemessen -
 * 30 Datagramme je Befehlswort, danach --retained-only: genau das mit
 * `retain` lag im Broker, das mit `publish` nicht.
 *
 * NICHT in dieser Tabelle stehen mit Absicht:
 *   status/ok, status/ts, status/zaehler - das Lebenszeichen. Wer es
 *       zurueckbehaelt, laesst nach einem gestorbenen Cron fuer immer
 *       "laeuft" im Broker stehen.
 *   regelN_aktiv und die uebrigen Regelwerte - das sind Schaltsignale fuer
 *       den laufenden Augenblick. Ein stehengebliebenes 1 liesse einen
 *       Verbraucher eingeschaltet; nach einem Neustart ist 0 die sichere
 *       Richtung, und der naechste Minutenlauf setzt den richtigen Wert.
 *   alle Preise, Raenge, Fenster, CO2-Werte, Stundenprofile und `alter` -
 *       Messwerte mit Zeitbezug bzw. eine Dauer.
 *   ann und ptest - sie wechseln allein durch Zeitablauf; ptest lebt fuenf
 *       Minuten.
 *   ok, morgen_ok, dyn_monat, diff_monat, euro_monat, shift_jahr -
 *       BERICHTIGT in 1.1.12, bis 1.1.11 standen sie hier. Die Frage ist,
 *       ob ein Wert OHNE neue Nachricht allein durch den Lauf der Zeit falsch
 *       wird: morgen_ok um Mitternacht, die Werte des juengsten Monats der
 *       Historie (oc_month_compare(1)) zum Monatswechsel, shift_jahr
 *       (gleitendes Fenster der letzten sieben Tage) mit jedem Tag. Und ok
 *       ("gueltige Preise liegen vor", aus dem eigenen Speicher) ist eine
 *       Aussage des Dienstes ueber sich selbst - nach dem Entscheid vom
 *       18./19.09.2026 nie retained (Regeln/07, Abschnitt 2): stirbt der
 *       Cron, stuende die 1 fuer immer im Broker. Die Altwerte raeumt
 *       oc_mqtt_altlast() einmal ab (in WSL gemessen,
 *       Pruefung-Spotpreis-Octopus-1.1.12, Faelle R1 bis R12). Preis: nach
 *       einem Neustart von Broker oder Gateway fehlen sie, bis der naechste
 *       volle Satz hinausgeht (halbstuendlich).
 *
 * Zurueckbehalten bleibt, was eine EINSTELLUNG ist und wahr bleibt, bis der
 * Anwender sie aendert - dann geht sie als Aenderung neu hinaus.
 */
function oc_retain_liste()
{
    return array(
        /* Demo-Modus eingeschaltet (demo in der Konfiguration). */
        'demo'         => 1,
        /* Freigaben aus der Konfiguration. */
        'audio'        => 1,
        'push'         => 1,
        /* Einstellungen des Fahrplaners. */
        'plan_budget'  => 1,
        'plan_budget2' => 1,
        /* Der eingetragene feste Arbeitspreis. */
        'fix'          => 1,
    );
}

/**
 * Geht dieses Thema zurueckbehalten hinaus?
 *
 * $nutzlast wird mitgegeben, wo sie schon feststeht: eine LEERE Nutzlast
 * LOESCHT ein zurueckbehaltenes Thema im Broker. Sie geht deshalb immer
 * als publish hinaus, auch wenn die Tabelle retain sagt.
 */
function oc_retain_fuer($thema, $nutzlast = null)
{
    if ($nutzlast !== null && (string) $nutzlast === '') { return 0; }
    $l = oc_retain_liste();
    return isset($l[(string) $thema]) ? 1 : 0;
}

/**
 * Die Themen, die frueher zurueckbehalten hinausgingen und es heute nicht
 * mehr tun: oc_retain_liste() der Archive 1.1.9, 1.1.10 und 1.1.11 (gelesen
 * am 24.09.2026; bis 1.1.8 ging nichts zurueckbehalten hinaus, status/* nie).
 * Ihre Altwerte stehen auf bestehenden Anlagen im Broker, bis jemand sie
 * loescht - ein spaeteres publish ersetzt einen zurueckbehaltenen Wert nicht.
 */
function oc_mqtt_frueher_behalten()
{
    return array('ok', 'morgen_ok', 'dyn_monat', 'diff_monat', 'euro_monat', 'shift_jahr');
}

/**
 * Den Broker fragen, welche der Themen $themen er zurueckbehaelt - in EINER
 * Verbindung, ein SUBSCRIBE mit allen Filtern.
 *
 * Rueckgabe array('lage' => 'ok'|'unbekannt', 'belegt' => array(thema => true)).
 * 'ok' heisst: der Broker hat das Abonnement bestaetigt (oder einen Wert
 * geschickt); was dann nicht unter 'belegt' steht, ist leer. 'unbekannt':
 * er war nicht zu fragen (keine Wurzel, keine Verbindung, Anmeldung
 * abgewiesen, keine Antwort).
 *
 * Warum ueberhaupt fragen: gesendet wird ueber den UDP-Eingang des Gateways,
 * und dort meldet sendto() auch fuer ein verworfenes Datagramm Erfolg. Am
 * Geraet gemessen (Regeln/07, "Ein Absender merkt nichts davon", Nachtraege
 * vom 19.09.2026): Beschattungswaechter 0.9.19 und KODI-NG 1.2.7 setzten
 * ihren Merker nach dem Senden, der Eingang verwarf ~70 %, und der Altwert
 * stand weiter im Broker. Belegt ist das Abraeumen erst, wenn der Broker
 * selbst sagt, dass nichts mehr dasteht.
 *
 * MQTT 3.1.1 von Hand, nur CONNECT, SUBSCRIBE (QoS 0) und DISCONNECT - ohne
 * fremde Bibliothek; wortgleich mit tb_mqtt_behalten_liste() aus
 * Spotpreis-Tibber 0.9.19 bis auf die Kennung und die Lesestelle der
 * general.json. Die Anmeldung nimmt Brokeruser/Brokerpass aus der
 * general.json (Regeln/07, Abschnitt 2); das Kennwort steht nur im
 * CONNECT-Paket, nie in einem Protokoll und nie auf einer Kommandozeile.
 */
function oc_mqtt_behalten_liste(array $themen, $leeren = false)
{
    /* $leeren (M1, seit 1.1.16): was der Broker als belegt meldet, wird auf
     * DERSELBEN Verbindung mit leerer, zurueckbehaltener Nutzlast geloescht
     * (PUBLISH, Kopfbyte 0x31). 'geleert' nennt die Themen. Nachgelesen wird
     * vom Aufrufer mit einer neuen Verbindung. */
    $aus = array('lage' => 'unbekannt', 'belegt' => array(), 'geleert' => array());
    $soll = array();
    foreach ($themen as $t) {
        if ((string) $t !== '') { $soll[(string) $t] = true; }
    }
    if (!$soll) {
        $aus['lage'] = 'ok';
        return $aus;
    }
    $p = oc_paths();
    if ($p['home'] === '' || !is_file($p['general'])) { return $aus; }
    $gen = json_decode((string) @file_get_contents($p['general']), true);
    if (!is_array($gen)) { return $aus; }
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) { $m = $gen['Mqtt']; }
    elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) { $m = $gen['mqtt']; }
    if (!$m) { return $aus; }
    $hol = function ($gross, $klein) use ($m) {
        if (isset($m[$gross])) { return (string) $m[$gross]; }
        return isset($m[$klein]) ? (string) $m[$klein] : '';
    };
    $host = trim($hol('Brokerhost', 'brokerhost'));
    if ($host === '' || $host === 'localhost') { $host = '127.0.0.1'; }
    $port = (int) $hol('Brokerport', 'brokerport');
    if ($port <= 0 || $port > 65535) { $port = 1883; }
    $benutzer = $hol('Brokeruser', 'brokeruser');
    $kennwort = $hol('Brokerpass', 'brokerpass');

    $s = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 2);
    if (!$s) { return $aus; }
    stream_set_timeout($s, 1);

    $zk = function ($t) { return pack('n', strlen($t)) . $t; };
    $laenge = function ($n) {
        $o = '';
        do {
            $b = $n % 128;
            $n = intdiv($n, 128);
            if ($n > 0) { $b |= 128; }
            $o .= chr($b);
        } while ($n > 0);
        return $o;
    };
    /* Genau $n Bytes lesen oder null - bei Zeitablauf und Verbindungsende. */
    $lies = function ($n) use ($s) {
        $d = '';
        while (strlen($d) < $n) {
            $t = @fread($s, $n - strlen($d));
            if ($t === false || $t === '') {
                $meta = stream_get_meta_data($s);
                if (!empty($meta['timed_out']) || !empty($meta['eof']) || feof($s)) { return null; }
                continue;
            }
            $d .= $t;
        }
        return $d;
    };
    /* Ein Paket: array(kopfbyte, rumpf) oder null. */
    $paket = function () use ($lies) {
        $k = $lies(1);
        if ($k === null) { return null; }
        $n = 0; $mult = 1;
        for ($i = 0; $i < 4; $i++) {
            $b = $lies(1);
            if ($b === null) { return null; }
            $n += (ord($b) & 127) * $mult;
            $mult *= 128;
            if (!(ord($b) & 128)) { break; }
        }
        $r = ($n > 0) ? $lies($n) : '';
        return ($r === null) ? null : array(ord($k), $r);
    };

    $flags = 0x02;                                  // saubere Sitzung
    $nutz = $zk('ocrueck' . getmypid());
    if ($benutzer !== '') {
        $flags |= 0x80;
        // Ein Kennwort ohne Benutzer laesst MQTT 3.1.1 nicht zu (Abschnitt
        // CONNECT, Kennwort-Merkmal).
        if ($kennwort !== '') { $flags |= 0x40; }
    }
    $kopf = $zk('MQTT') . chr(4) . chr($flags) . pack('n', 10);
    if ($benutzer !== '') {
        $nutz .= $zk($benutzer);
        if ($kennwort !== '') { $nutz .= $zk($kennwort); }
    }
    /* Laenge statt "!== false" (Bauart B, Regeln/03; seit 1.1.16): eine kurze
     * Schreibung ist kein CONNECT. */
    $connect = chr(0x10) . $laenge(strlen($kopf . $nutz)) . $kopf . $nutz;
    if (@fwrite($s, $connect) === strlen($connect)) {
        $ack = $paket();
        if ($ack !== null && ($ack[0] >> 4) === 2 && strlen($ack[1]) >= 2 && ord($ack[1][1]) === 0) {
            $sub = pack('n', 1);
            foreach (array_keys($soll) as $t) { $sub .= $zk($t) . chr(0); }
            @fwrite($s, chr(0x82) . $laenge(strlen($sub)) . $sub);
            $bestaetigt = false;
            $ende = microtime(true) + 3.0;
            while (microtime(true) < $ende) {
                $pk = $paket();
                if ($pk === null) { break; }           // Zeitablauf: nichts mehr gekommen
                $art = $pk[0] >> 4;
                if ($art === 9) {
                    /* Je Filter ein Rueckgabebyte hinter der Paketkennung, in der
                       Reihenfolge des SUBSCRIBE; ab 0x80 heisst abgelehnt (etwa
                       durch eine ACL). Danach schickt der Broker nichts - ungeprueft
                       hiesse das "nichts belegt", und der Merker laege auf einer
                       Antwort, die keine war (in WSL gemessen, Pruefung-Spotpreis-Octopus-1.1.15,
                       Faelle S3, S4, S7, S9, S11). Bauart bw_mqtt_behalten_liste(),
                       Beschattungswaechter 0.9.21. */
                    $rc = (string) substr($pk[1], 2);
                    if (strlen($rc) !== count($soll)) { break; }
                    $abgelehnt = false;
                    for ($i = 0; $i < strlen($rc); $i++) {
                        if (ord($rc[$i]) >= 0x80) { $abgelehnt = true; }
                    }
                    if ($abgelehnt) { break; }
                    $bestaetigt = true;
                    // Zurueckbehaltenes kommt unmittelbar nach dem SUBACK.
                    $ende = min($ende, microtime(true) + 1.0);
                } elseif ($art === 3 && strlen($pk[1]) >= 2) {
                    $tl = unpack('n', substr($pk[1], 0, 2));
                    $t = substr($pk[1], 2, $tl[1]);
                    $versatz = 2 + $tl[1] + ((($pk[0] >> 1) & 3) > 0 ? 2 : 0);
                    $wert = (string) substr($pk[1], $versatz);
                    if (isset($soll[$t]) && ($pk[0] & 1) && $wert !== '') {
                        $aus['belegt'][$t] = true;
                        if (count($aus['belegt']) === count($soll)) { break; }
                    }
                }
            }
            if ($bestaetigt || $aus['belegt']) { $aus['lage'] = 'ok'; }
            /* LOESCHEN UEBER DIE SCHON OFFENE TCP-VERBINDUNG (M1, seit 1.1.16).
             *
             * Bis 1.1.15 ging die Loeschung nur ueber den UDP-Eingang des
             * Gateways. Der verwirft unter Last Datagramme: am Geraet blieben am
             * 30.09.2026 5 von 6 Themen stehen, im Pruefstand mit 70 % Verlust
             * in 10 von 10 Laeufen 1-4 Themen (Pruefbericht mqtt, M1). TCP
             * verliert nichts; der Broker loescht ein Thema bei leerer
             * zurueckbehaltener Nutzlast. Bauform APC-UPS 1.2.15
             * (broker_leeren) und Chromecast4lox (am Geraet 11 -> 0 bzw.
             * 2 -> 0). QoS 0: die Bestaetigung ist das Nachlesen.
             *
             * Reihenfolge: erst ABBESTELLEN (sonst schickt der Broker jede
             * Loeschung an uns zurueck), dann die Loeschungen, dann PINGREQ
             * als Schranke - ein Broker bearbeitet die Pakete einer Verbindung
             * der Reihe nach; mit PINGRESP sind die Loeschungen durch. Bis
             * dahin wird alles gelesen: bleibt beim Schliessen Ungelesenes im
             * Puffer, setzt das System die Verbindung hart zurueck, und der
             * Broker verwirft, was er noch nicht gelesen hat (unter Windows
             * gemessen: 2 von 6 geloescht, ohne Schranke). */
            if ($leeren && $aus['lage'] === 'ok' && $aus['belegt']) {
                $unsub = pack('n', 2);
                foreach (array_keys($soll) as $t) { $unsub .= $zk($t); }
                @fwrite($s, chr(0xA2) . $laenge(strlen($unsub)) . $unsub);
                foreach (array_keys($aus['belegt']) as $t) {
                    $rumpf = $zk($t);
                    $pk = chr(0x31) . $laenge(strlen($rumpf)) . $rumpf;
                    if (@fwrite($s, $pk) === strlen($pk)) { $aus['geleert'][] = $t; }
                }
                @fwrite($s, chr(0xC0) . chr(0));
                $schranke = microtime(true) + 3.0;
                while (microtime(true) < $schranke) {
                    $pk = $paket();
                    if ($pk === null || ($pk[0] >> 4) === 13) { break; }
                }
            }
        }
        @fwrite($s, chr(0xE0) . chr(0));
    }
    fclose($s);
    return $aus;
}

/**
 * Welche Altwerte muessen in diesem Lauf noch abgeraeumt werden?
 *
 * Rueckgabe array('lage' => 'erledigt'|'belegt'|'unbekannt',
 *                 'themen' => array(<thema ohne praefix>, ...)).
 *
 * Je Lauf, bis der Merker liegt:
 *   1. den Broker nach allen Themen aus oc_mqtt_frueher_behalten() fragen;
 *   2. keines belegt -> Merker schreiben, nichts abraeumen ('erledigt');
 *      einige belegt -> genau diese abraeumen, kein Merker ('belegt'); der
 *      Aufrufer sendet dann VOLL, damit die leere retain-Nutzlast
 *      unmittelbar vor dem gueltigen Wert steht;
 *      nicht zu fragen -> alle, aber nur unmittelbar vor einem Wert, der
 *      ohnehin hinausgeht ('unbekannt'), kein Merker.
 * Der Merker traegt die Kennung "leer-bestaetigt <praefix>: <Themenliste>":
 * ein anderer Inhalt - ein anderes Praefix, eine andere Liste - gilt nicht,
 * und ein Merker, den eine Vorfassung unter anderem Namen angelegt haette,
 * ebenso wenig. purge_installation raeumt ihn bei jedem Upgrade mit ab;
 * dann wird genau einmal nachgefragt. Bauart tb_mqtt_altlast() aus
 * Spotpreis-Tibber 0.9.19.
 */
function oc_mqtt_altlast($praefix)
{
    static $cache = array();
    $praefix = (string) $praefix;
    if (isset($cache[$praefix])) { return $cache[$praefix]; }
    $liste = oc_mqtt_frueher_behalten();
    $merker = oc_datadir() . '/.mqtt_altlast_geraeumt';
    $kennung = 'leer-bestaetigt ' . $praefix . ': ' . implode(' ', $liste);
    if (is_file($merker) && trim((string) @file_get_contents($merker)) === $kennung) {
        return $cache[$praefix] = array('lage' => 'erledigt', 'themen' => array());
    }
    $voll = array();
    foreach ($liste as $t) { $voll[] = $praefix . '/' . $t; }
    $f = oc_mqtt_behalten_liste($voll);
    if ($f['lage'] === 'ok' && !$f['belegt']) {
        if (@file_put_contents($merker, $kennung . "\n") === false) {
            oc_log_if_changed('mqtt_merker', 'Der Merker ' . $merker . ' liess sich nicht schreiben - '
                . 'der Broker wird im naechsten Lauf wieder gefragt.');
        } else {
            oc_log('MQTT: unter ' . $praefix . '/ steht keines der ' . count($liste)
                . ' frueher zurueckbehaltenen Themen mehr im Broker (vom Broker bestaetigt).');
        }
        return $cache[$praefix] = array('lage' => 'erledigt', 'themen' => array());
    }
    if ($f['lage'] === 'ok') {
        $l = strlen($praefix) + 1;
        $t = array();
        foreach (array_keys($f['belegt']) as $v) { $t[] = substr($v, $l); }
        return $cache[$praefix] = array('lage' => 'belegt', 'themen' => $t);
    }
    oc_log_if_changed('mqtt_rueckfrage', 'Der Broker liess sich nicht befragen, ob unter '
        . $praefix . '/ noch frueher zurueckbehaltene Werte stehen. Sie werden deshalb '
        . 'unmittelbar vor jedem Senden geloescht, bis der Broker antwortet.');
    return $cache[$praefix] = array('lage' => 'unbekannt', 'themen' => $liste);
}

/**
 * Alle Themen, die diese Linie je zurueckbehalten gesendet hat - fuer die
 * Deinstallation: die heutige Retain-Tabelle und die frueheren Eintraege.
 */
function oc_mqtt_leer_themen()
{
    $t = array();
    foreach (oc_mqtt_frueher_behalten() as $k) { $t[$k] = true; }
    foreach (array_keys(oc_retain_liste()) as $k) { $t[$k] = true; }
    ksort($t);
    return array_keys($t);
}

/**
 * Aus der Deinstallation (bin/oc_cron.php --mqtt-leeren): die
 * zurueckbehaltenen Themen der Linie leeren.
 *
 * SEIT 1.1.16 DIREKT AM BROKER (M1): oc_mqtt_praefix_leeren() fragt den
 * Broker und loescht, was dort steht, auf derselben TCP-Verbindung; danach
 * wird nachgelesen, hoechstens $runden Runden. Bis 1.1.15 ging die Loeschung
 * ueber den UDP-Eingang des Gateways, der unter Last Datagramme verwirft - am
 * Geraet blieben am 30.09.2026 5 von 6 Themen stehen. Der UDP-Eingang ist nur
 * noch der Rueckfall, wenn der Broker nicht zu fragen ist; die Ausgabe sagt
 * dann, dass nicht nachgelesen wurde. Abgeraeumt wird unter dem eingestellten
 * Praefix und unter jedem frueher eingestellten (M2).
 *
 * Bis 1.1.11 raeumte die Deinstallation nichts ab: die zurueckbehaltenen
 * Themen blieben im Broker, und nach jedem Neustart von Broker oder Gateway
 * bekam der Miniserver sie wieder - von einem Plugin, das es nicht mehr gibt
 * (in WSL gemessen, Pruefung-Spotpreis-Octopus-1.1.12, Faelle U1, U3, U4,
 * U6). Bauart tb_mqtt_leeren() aus Spotpreis-Tibber 0.9.19.
 *
 * Liest die Konfiguration ohne Selbstheilung und schreibt weder Protokoll
 * noch Datei. Ausgabe im Format der Hakenskripte (<OK>/<INFO>/<WARNING>).
 * Rueckgabe 0 geleert oder nicht nachpruefbar, 1 es steht noch etwas bzw. der
 * Eingang war nicht erreichbar, 2 nicht moeglich.
 */
function oc_mqtt_leeren($runden = 3, $pause = 1.0)
{
    /* Aus der Deinstallation (bin/oc_cron.php --mqtt-leeren): die
     * zurueckbehaltenen Themen der Linie leeren - unter dem eingestellten
     * Praefix UND unter jedem frueher eingestellten (M2, seit 1.1.16; die
     * Liste fuehrt die Oberflaeche beim Praefixwechsel, mqtt_praefixe.json im
     * Datenordner). Liest die Konfiguration ohne Selbstheilung und schreibt
     * weder Protokoll noch Datei. Ausgabe im Format der Hakenskripte.
     * Rueckgabe 0 geleert oder nichts zu leeren, 1 es steht noch etwas bzw.
     * nicht nachpruefbar, 2 nicht moeglich. */
    oc_nur_lesen(true);
    $cfg = oc_config();
    $liste = array(oc_mqtt_thema_saeubern($cfg['mqtt_topic'], 'octopus'));
    foreach (oc_mqtt_praefixe_gemerkt() as $p) {
        if (!in_array($p, $liste, true)) { $liste[] = $p; }
    }
    $rc = 0;
    foreach ($liste as $p) {
        $e = oc_mqtt_praefix_leeren($p, $runden, $pause);
        foreach ($e['zeilen'] as $z) { echo $z . "\n"; }
        $rc = max($rc, (int) $e['rc']);
    }
    return $rc;
}

/**
 * Die zurueckbehaltenen Themen der Linie unter EINEM Praefix leeren (M1, M2).
 *
 * Weg: der Broker selbst (oc_mqtt_behalten_liste() mit $leeren), Runde fuer
 * Runde nachgelesen, hoechstens $runden Runden. Nur wenn der Broker nicht zu
 * fragen ist, geht es wie bis 1.1.15 ueber den UDP-Eingang des Gateways -
 * ohne Beleg, und die Ausgabe sagt das. Nur eigene Themen
 * (oc_mqtt_leer_themen()), nie ein fremdes unter demselben Praefix.
 *
 * Rueckgabe array('rc' => 0|1|2, 'weg' => 'tcp'|'udp'|'-', 'offen' => Liste,
 * 'geleert' => Anzahl, 'zeilen' => Ausgabezeilen <OK>/<INFO>/<WARNING>).
 */
function oc_mqtt_praefix_leeren($praefix, $runden = 3, $pause = 1.0)
{
    $praefix = (string) $praefix;
    $alle = array();
    foreach (oc_mqtt_leer_themen() as $t) { $alle[] = $praefix . '/' . $t; }
    $n = count($alle);
    $erg = array('rc' => 0, 'weg' => 'tcp', 'offen' => array(), 'geleert' => 0, 'zeilen' => array());
    $offen = $alle;
    $gefragt = false;
    $bestaetigt = false;
    for ($r = 1; $r <= max(1, (int) $runden); $r++) {
        if ($r > 1) { usleep((int) (max(0.1, (float) $pause) * 1000000)); }
        $f = oc_mqtt_behalten_liste($offen, true);
        if ($f['lage'] !== 'ok') { break; }
        $gefragt = true;
        $erg['geleert'] += count($f['geleert']);
        $offen = array_keys($f['belegt']);
        if (!$offen) { $bestaetigt = true; break; }
    }
    if ($gefragt && !$bestaetigt) {
        // Nach der letzten Runde noch einmal nur lesen.
        usleep(300000);
        $f = oc_mqtt_behalten_liste($offen);
        if ($f['lage'] === 'ok') {
            $offen = array_keys($f['belegt']);
            $bestaetigt = !$offen;
        } else {
            $gefragt = false;
        }
    }
    if ($gefragt && $bestaetigt) {
        $erg['zeilen'][] = $erg['geleert'] > 0
            ? '<OK> MQTT: ' . $erg['geleert'] . ' zurueckbehaltene Themen unter ' . $praefix
              . '/ direkt am Broker geloescht; der Broker bestaetigt: keines der ' . $n
              . ' Themen steht mehr zurueckbehalten.'
            : '<OK> MQTT: der Broker bestaetigt: keines der ' . $n . ' Themen unter ' . $praefix
              . '/ steht zurueckbehalten - nichts zu leeren.';
        return $erg;
    }
    if ($gefragt) {
        $erg['rc'] = 1;
        $erg['offen'] = $offen;
        $erg['zeilen'][] = '<WARNING> MQTT: ' . count($offen) . ' Themen stehen noch zurueckbehalten im Broker ('
            . implode(', ', array_slice($offen, 0, 5)) . (count($offen) > 5 ? ', ...' : '')
            . '). Von Hand: mosquitto_pub -r -n -t <thema>';
        return $erg;
    }
    /* Rueckfall: der Broker liess sich nicht befragen (keine Verbindung,
     * Anmeldung abgewiesen, Lesen verweigert). Dann der UDP-Eingang des
     * Gateways - "retain <thema> " mit leerer Nutzlast, die Form, die das
     * Gateway als Loeschung liest (Regeln/07, Nachtraege vom 19.09.2026). */
    $erg['weg'] = 'udp';
    $g = oc_gateway();
    if (!$g['udpport']) {
        $erg['rc'] = 2;
        $erg['weg'] = '-';
        $erg['zeilen'][] = '<INFO> MQTT: der Broker liess sich nicht befragen, und in der general.json steht '
            . 'kein UDP-Eingangsport des Gateways - zurueckbehaltene Themen unter ' . $praefix
            . '/ wurden nicht geleert.';
        return $erg;
    }
    $strom = @stream_socket_client('udp://127.0.0.1:' . (int) $g['udpport'], $errno, $errstr, 2);
    if (!$strom) {
        $erg['rc'] = 1;
        $erg['zeilen'][] = '<WARNING> MQTT: weder der Broker noch der UDP-Eingang des Gateways waren '
            . 'erreichbar - zurueckbehaltene Themen unter ' . $praefix . '/ wurden nicht geleert.';
        return $erg;
    }
    $datagramme = 0;
    for ($r = 1; $r <= max(1, (int) $runden); $r++) {
        if ($r > 1) { usleep((int) (max(0.1, (float) $pause) * 1000000)); }
        foreach ($alle as $t) {
            @fwrite($strom, 'retain ' . $t . ' ');
            $datagramme++;
        }
    }
    fclose($strom);
    $erg['zeilen'][] = '<INFO> MQTT: der Broker liess sich nicht befragen - ' . $n . ' Themen unter ' . $praefix
        . '/ mit leerer Nutzlast an den UDP-Eingang ' . (int) $g['udpport'] . ' des Gateways gesendet ('
        . $datagramme . ' Datagramme), nicht nachgelesen. Der UDP-Eingang verwirft unter Last '
        . 'Datagramme; was stehen bleibt, laesst sich mit mosquitto_pub -r -n -t <thema> von Hand loeschen.';
    return $erg;
}

/** Wo die frueher benutzten Praefixe liegen (M2). */
function oc_mqtt_praefixe_datei()
{
    return oc_paths()['datadir'] . '/mqtt_praefixe.json';
}

/** Die frueher eingestellten Praefixe, nur solche in der Form eines Themas. */
function oc_mqtt_praefixe_gemerkt()
{
    $f = oc_mqtt_praefixe_datei();
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    $out = array();
    foreach (is_array($d) ? $d : array() as $p) {
        if (is_string($p) && strlen($p) <= 64
            && preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#', $p)) {
            $out[$p] = true;
        }
    }
    return array_keys($out);
}

/**
 * Ein Praefix in die Liste aufnehmen (M2). Die Deinstallation raeumt unter
 * jedem Eintrag ab. preupgrade.sh sichert die Datei, postupgrade.sh spielt sie
 * zurueck (der Upgrade raeumt den Datenordner ab).
 */
function oc_mqtt_praefix_merken($praefix)
{
    $praefix = (string) $praefix;
    if (!preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#', $praefix) || strlen($praefix) > 64) { return false; }
    $l = oc_mqtt_praefixe_gemerkt();
    if (in_array($praefix, $l, true)) { return true; }
    $l[] = $praefix;
    oc_datadir();
    return oc_datei_schreiben(oc_mqtt_praefixe_datei(), json_encode(array_values(array_slice($l, -20))), 0644);
}

/** Die Themen, die bei JEDEM Lauf hinausgehen, geaendert oder nicht (M3). */
function oc_mqtt_jeder_lauf()
{
    return array('ok', 'alter');
}

/** Die Themen, die bei einem Ausfall (ok=0) hinausgehen - nur das Signal (C3). */
function oc_mqtt_ausfall_themen()
{
    return array('ok', 'alter', 'status/ok', 'status/ts', 'status/zaehler');
}

/** Wo der Merker der zuletzt gesendeten Werte liegt. */
function oc_mqtt_merker()
{
    return oc_tmpdir() . '/mqtt_letzte.json';
}

/**
 * Werte veroeffentlichen - NUR DIE GEAENDERTEN, dazu immer das Lebenszeichen.
 *
 * Bis 1.1.10 ging bei jeder Aenderung der Signatur der volle Satz hinaus:
 * gemessen (mqtt_diff_messen.py) schickte eine einzige Aenderung - audio
 * eingeschaltet - alle 90 Themen. Regeln/07: "nur Aenderungen und den
 * vollen Satz in grobem Takt". Am Geraet steht die Warteschlange des
 * Gateway-UDP-Eingangs regelmaessig ueber 200 kB; jeder Stoss verschlechtert
 * das fuer alle Plugins.
 *
 * $erzwingen = true schickt alles (halbstuendlich aus dem Cron, und der
 * Knopf im Reiter Test). Der Merker wird NUR fortgeschrieben, wenn wirklich
 * gesendet wurde - sonst fehlten Felder, die tagelang gleich stehen, nach
 * einem Lauf ohne UDP-Port dauerhaft. Verglichen wird der FORMATIERTE
 * Wert als Zeichenkette (aus dem Merker kommt er ueber json_decode zurueck).
 */
function oc_mqtt_publish($st = null, $nur_lebenszeichen = false, $erzwingen = false)
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
    /* Die Altwerte frueher zurueckbehaltener Themen abraeumen, solange der
     * Broker sie noch haelt - oc_mqtt_altlast() fragt ihn VORHER. Meldet er
     * welche, geht dieser Lauf VOLL hinaus: sonst stuende die Loeschung nur
     * vor den Themen, die sich gerade geaendert haben, und ein Altwert mit
     * unveraendertem Wert bliebe bis zum halbstuendlichen Vollsatz stehen
     * (in WSL gemessen, Pruefung-Spotpreis-Octopus-1.1.12, Faelle R8, R12). */
    $alt = oc_mqtt_altlast($praefix);
    if ($alt['lage'] === 'belegt') { $erzwingen = true; }
    $raeumen = array_flip($alt['themen']);

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
    /* BEI AUSFALL NUR DAS SIGNAL (C3, seit 1.1.16; Regeln/07 "Ueber MQTT geht
     * bei einer Stoerung nur das Signal hinaus, nicht die Werte").
     *
     * Ohne gueltige Preise (ok=0) gehen nur ok, alter, das Lebenszeichen und
     * regelN_aktiv=0 hinaus. Bis 1.1.15 gingen cur, cur_h, next, avg_heute, min_heute und
     * fenster_ct als 0.000 hinaus und ueberschrieben in Loxone den letzten
     * Preis - eine Null ist ein Preis (Pruefbericht code, Befund 3). Die
     * uebrigen Themen behalten in Loxone ihren letzten Wert; der Merker
     * behaelt fuer sie den zuletzt GESENDETEN Stand, damit sie nach dem Ausfall
     * nur bei einer Aenderung erneut hinausgehen. */
    $ausfall = (!$nur_lebenszeichen && is_array($st) && empty($st['ok']));
    if ($ausfall) {
        $werte = array_intersect_key($werte, array_flip(oc_mqtt_ausfall_themen()));
        /* Dazu die Schaltsignale: regelN_aktiv=0 fuer jede Regel, bei JEDEM
         * Ausfall-Lauf (Entscheidung des Hausherrn vom 30.09.2026, zu C3).
         * Ein Schaltsignal darf nicht auf 1 stehen bleiben; der Endpunkt
         * meldet im Ausfall ebenso aktiv=0. Keine Preise. */
        for ($oc_ri = 1; $oc_ri <= OC_REGELN; $oc_ri++) { $werte['regel' . $oc_ri . '_aktiv'] = 0; }
    }
    /* DIESELBE FORMATIERUNG WIE DIE HTTP-ZEILE.
     *
     * oc_wert_formatieren() entscheidet an der EINHEIT, nicht am
     * PHP-Typ - der Kommentar dort begruendet das damit, dass derselbe
     * Preis sonst einmal als 12.000 und einmal als 12 herauskommt, je
     * nachdem ob frisch gerechnet oder aus dem Zwischenspeicher gelesen.
     * Bis 1.1.3 benutzte nur die HTTP-Zeile sie; der MQTT-Weg schickte
     * den rohen Wert. Gemessen ueber alle 162 Themen: gleiche Namen,
     * gleiche Anzahl, aber 105 Werte in anderer Schreibweise
     * (HTTP 20.230 gegen MQTT 20.23). Fuer Loxone ist beides dieselbe
     * Zahl - fuer den Menschen, der beide Wege nebeneinanderlegt, und
     * fuer ein Werkzeug, das sie vergleicht, ist es das nicht. Und die
     * Hausregel verlangt EINE Quelle fuer beide Wege.
     *
     * Die Themenliste wird dafuer einmal geholt, nicht je Wert. */
    $info_alle = oc_themen();
    $formatiert = array();
    foreach ($werte as $k => $v) {
        $formatiert[$k] = oc_mqtt_wert_saeubern(oc_wert_formatieren($k, $v,
            isset($info_alle[$k]) ? $info_alle[$k] : null));
    }
    $merken = null;
    if (!$nur_lebenszeichen) {
        $alt_merker = array();
        if (is_file(oc_mqtt_merker())) {
            $d = json_decode((string) @file_get_contents(oc_mqtt_merker()), true);
            if (is_array($d)) { $alt_merker = $d; }
        }
        // Im Ausfall bleibt der Merker der nicht gesendeten Themen stehen (C3).
        $merken = $ausfall ? $alt_merker : array();
        foreach ($formatiert as $k => $w) {
            /* 'alter' zaehlt Minuten seit dem Abruf - wie das Lebenszeichen
             * geht es jeden Lauf mit und ist keine Aenderung (Regeln/07:
             * ALTER in der Signatur macht die Bremse wirkungslos; gemessen
             * bis 1.1.10: jede Neuberechnung des Zustands = 90 Themen).
             *
             * 'ok' EBENSO (M3, seit 1.1.16; Regeln/07 Z. 272: "Bei jedem
             * Durchlauf gehen mindestens OK, ALTER und ein Stoerungszaehler
             * hinaus"). Bis 1.1.15 ging "ok 0" beim Uebergang in den Ausfall
             * ein einziges Mal hinaus; mit 50 % Verlust am UDP-Eingang kam es
             * in 4 von 10 Laeufen nicht an, und Loxone stand bis zum
             * naechsten Vollsatz (30 min) auf ok 1 (Pruefbericht mqtt, M3). */
            if (strpos((string) $k, 'status/') !== 0 && !in_array($k, oc_mqtt_jeder_lauf(), true)) {
                $merken[$k] = (string) $w;
            }
        }
        $vorher = $erzwingen ? array() : $alt_merker;
        foreach ($formatiert as $k => $w) {
            // Im Ausfall geht das Signal bei jedem Lauf hinaus, nicht nur bei Aenderung.
            if ($ausfall || strpos((string) $k, 'status/') === 0 || in_array($k, oc_mqtt_jeder_lauf(), true)) { continue; }
            if (array_key_exists($k, $vorher) && (string) $vorher[$k] === (string) $w) { unset($formatiert[$k]); }
        }
    }
    $behalten = 0;
    $gesendet = 0;
    foreach ($formatiert as $k => $wert) {
        /* Das Befehlswort entscheidet die Tabelle, nicht der Aufruf -
         * sonst ginge das Lebenszeichen zurueckbehalten hinaus oder
         * die Zustaende fluechtig. */
        /* Die leere retain-Nutzlast loescht den zurueckbehaltenen Wert
         * (mqttgateway.pl, sub udpin; am Geraet am 19.09.2026 belegt,
         * Regeln/07). Sie geht UNMITTELBAR vor dem gueltigen Wert hinaus:
         * wer das Thema abonniert hat, bekommt die Loeschung als leere
         * Nachricht, und der naechste Wert steht gleich dahinter. */
        if (isset($raeumen[$k])) {
            $leer = 'retain ' . $praefix . '/' . $k . ' ';
            @socket_sendto($s, $leer, strlen($leer), 0, '127.0.0.1', $g['udpport']);
        }
        $verb = oc_retain_fuer($k, $wert) ? 'retain' : 'publish';
        if ($verb === 'retain') { $behalten++; }
        $msg = $verb . ' ' . $praefix . '/' . $k . ' ' . $wert;
        if (@socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $g['udpport']) !== false) { $gesendet++; }
    }
    socket_close($s);
    if ($merken !== null && $gesendet > 0) {
        @file_put_contents(oc_mqtt_merker(), json_encode($merken));
    }
    oc_log_if_changed('mqttzahl', $gesendet . ' Themen gesendet, davon '
        . $behalten . ' zurueckbehalten');
    return $gesendet > 0;
}

/* ==================================================================
 * Ansage (TTS) und Meldungen
 * ================================================================== */

/* ---- Ausgabeweg Alexa-NG (Ansage-2, Auftrag des Hausherrn 01.10.2026) ----
 *
 * Das eigene Plugin des Hausherrn (https://github.com/timanders22/LoxBerry-Plugin-Alexa-NG)
 * laesst Echo-Geraete sprechen. Octopus schickt die Ansage per POST an dessen
 * Endpunkt auf demselben LoxBerry: aktion=sprechen, token, geraet (leer =
 * Standardgeraet, dann nicht mitgeschickt), text. Das Token steht NIE in einer
 * Adresse (Adressen landen in Protokollen von Webservern). Erfolg ist nur eine
 * Antwort, die mit SPRECHEN;OK=1 beginnt. Die Adresse ist fest; ein Pruefstand
 * kann sie vor dem Laden der Bibliothek setzen. */
if (!defined('OC_ALEXANG_ADRESSE')) {
    define('OC_ALEXANG_ADRESSE', 'http://127.0.0.1/plugins/alexang/index.php');
}

/** Form des Sprechtokens von Alexa-NG: 8 bis 128 Zeichen aus A-Z a-z 0-9 _ -. */
function oc_alexa_token_ok($t)
{
    return is_string($t) && (bool) preg_match('/^[A-Za-z0-9_-]{8,128}$/', $t);
}

/** Ergebnis der letzten Ansage dieses Aufrufs (fuer die Testansage), nie mit Token. */
function oc_ansage_letzte($setzen = null)
{
    static $letzte = '';
    if ($setzen !== null) { $letzte = (string) $setzen; }
    return $letzte;
}

/** Eine Ansage ueber Alexa-NG. Rueckgabe true nur bei SPRECHEN;OK=1. */
function oc_alexa_sprechen($text)
{
    $cfg = oc_config();
    $tok = (string) $cfg['tts']['alexa_token'];
    $geraet = (string) $cfg['tts']['alexa_geraet'];
    if ($tok === '') {
        oc_ansage_letzte('kein Sprechtoken hinterlegt');
        oc_log('Ansage uebersprungen: Ausgabeweg Alexa-NG, aber kein Sprechtoken hinterlegt');
        return false;
    }
    $felder = array('aktion' => 'sprechen', 'token' => $tok);
    if ($geraet !== '') { $felder['geraet'] = $geraet; }
    $felder['text'] = (string) $text;
    $r = oc_http(OC_ALEXANG_ADRESSE, http_build_query($felder, '', '&'),
        array('Content-Type: application/x-www-form-urlencoded'), 10);
    $rumpf = trim(str_replace($tok, '***', (string) $r['body']));
    $ok = $r['ok'] && strpos($rumpf, 'SPRECHEN;OK=1') === 0;
    $anzeige = 'OK';
    if ($ok) {
        $was = 'OK';
    } else {
        $grund = preg_match('/(?:^|;)GRUND=([A-Za-z0-9_]{1,40})/', $rumpf, $m) ? $m[1] : '';
        $was = ((int) $r['code'] > 0 ? 'HTTP ' . (int) $r['code']
                : ((string) $r['fehler'] !== '' ? (string) $r['fehler'] : 'keine Antwort'))
             . ($grund !== '' ? ', GRUND=' . $grund : '');
        if ($grund === '' && $rumpf !== '') {
            $was .= ', Antwort "' . substr(preg_replace('/[^A-Za-z0-9;=_.:*-]/', '', $rumpf), 0, 60) . '"';
        }
        // Fuer die Testansage: ein Verbindungsfehler als Satz (das Protokoll behaelt den Schluessel).
        $anzeige = ((int) $r['code'] === 0 && (string) $r['fehler'] !== '')
            ? oc_fehlertext((string) $r['fehler']) . ' (' . $was . ')' : $was;
    }
    oc_ansage_letzte($anzeige);
    oc_log('Ansage an Alexa-NG' . ($geraet !== '' ? ' (Geraet ' . $geraet . ')' : '')
        . ': "' . $text . '" -> ' . ($ok ? 'OK' : 'FEHLER ' . $was));
    return $ok;
}

/** TTS-Adresse bauen. Bei mode=audioserver gibt es keine - dann null. */
function oc_tts_url($text)
{
    $cfg = oc_config();
    $t = $cfg['tts'];
    if ($t['mode'] === 'audioserver') {
        return null;    // Original Loxone Audioserver: Ansage nur ueber Loxone Config
    }
    if ($t['mode'] === 'alexang') {
        // Die feste Adresse ohne Token; '' heisst: kein Sprechtoken hinterlegt.
        return (string) $t['alexa_token'] === '' ? '' : OC_ALEXANG_ADRESSE;
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
    $cfg = oc_config();
    if ($cfg['tts']['mode'] === 'alexang') { return oc_alexa_sprechen($text); }
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

/**
 * Grenzen fuer den virtuellen Eingang: array(MinVal, MaxVal).
 *
 * WARUM UEBERHAUPT. Bis 1.1.3 trugen alle 90 Eingaenge MinVal="-2147483647"
 * und MaxVal="2147483647". Loxone zieht daraus die Reglergrenzen UND die
 * Plausibilitaetspruefung; wer alles offen laesst, verschenkt beides.
 *
 * WOHER DIE ZAHLEN KOMMEN - jede einzeln, damit niemand sie fuer gemessen
 * haelt, wo sie eine Wahl ist:
 *
 *   ct/kWh  -100..200   die Schranke, die das Plugin fuer 'schwelle' und
 *                       'cheap'/'expensive' selbst fuehrt (oc_schranken()
 *                       und webfrontend/htmlauth/index.php). Negativpreise
 *                       gibt es wirklich, deshalb kein 0.
 *   kW      0..200      die Schranke von budget_kw, ebendort.
 *   h       -1..23      Stunde des Tages. -1 heisst "nicht bekannt";
 *                       gemessen an einem Zustand ohne Preise senden
 *                       fenster_start und co2_minh genau das.
 *   min     -1..1440    Minuten; -1 ebenso gemessen an fenster_in und
 *                       regelN_in.
 *   %       -1..100     plan_soc sendet -1, wenn kein Speicherstand
 *                       vorliegt - gemessen.
 *   s       0..2147483647   status/ts ist eine Unix-Zeit. Hier ist die
 *                       weite Grenze richtig und keine Bequemlichkeit.
 *   ''      0..999      Merker, Raenge, Zaehler. Die 999 ist gemessen:
 *                       oc_zaehler() rechnet modulo 1000.
 *   g/kWh   0..1000     CO2-Intensitaet. GEWAEHLT, nicht gemessen - die
 *                       deutsche Erzeugung lag nie darueber, aber eine
 *                       Obergrenze hat mir niemand bestaetigt.
 *   EUR     -10000..10000  GEWAEHLT. Monats- und Jahresdifferenzen eines
 *                       Haushalts liegen weit darunter; die Grenze soll
 *                       nur den Regler brauchbar machen.
 *   kWh     0..1000     GEWAEHLT, dieselbe Ueberlegung (Tagessumme PV).
 *
 * Die Grenze steht damit an EINER Stelle - nicht je Vorlagenart neu.
 */
function oc_thema_grenzen($einheit, $schluessel = '')
{
    /* Vier Felder tragen seit 1.1.9 -1 als 'nicht bekannt' und
     * brauchen deshalb MinVal=-1, obwohl sie einheitenlos sind: ohne
     * das steht in der Visualisierung eine 0, und 0 waere bei einem
     * Rang eine Aussage statt einer Luecke. Die uebrigen
     * einheitenlosen Felder (Merker, Zaehler) bleiben bei 0. */
    $k = (string) $schluessel;
    if ($k === 'rank' || $k === 'rankd' || $k === 'rank_h') { return array(-1, 999); }
    if ($k === 'level') { return array(-1, 3); }
    /* M5 (seit 1.1.16): die Grenze gegen den rechenbaren Wert. Ein Wert ueber
     * MaxVal wird im Miniserver zu 0 (Regeln/07). 'alter' traegt ohne je einen
     * Abruf 9999 und nach einem Tag Ausfall mehr als 1440 - mit MaxVal 1440
     * stand dann 0, also "frisch". fenster_in reicht ueber den ganzen
     * bekannten Horizont (bis 48 h), regelN_in und regelN_rest ueber den
     * Horizont der Regel (bis 48 h): gemessen 1860 bzw. 1800 min gegen
     * MaxVal 1440 (Pruefbericht mqtt, M5). Die Vorlage muss neu importiert
     * werden, damit die neuen Grenzen gelten. */
    if ($k === 'alter') { return array(-1, 9999); }
    if ($k === 'fenster_in' || preg_match('/^regel[0-9]+_(in|rest)$/', $k)) { return array(-1, 2880); }
    switch ((string) $einheit) {
        case 'ct/kWh': return array(-100, 200);
        case 'kW':     return array(0, 200);
        case 'kWh':    return array(0, 1000);
        case 'EUR':    return array(-10000, 10000);
        case '%':      return array(-1, 100);
        case 'h':      return array(-1, 23);
        case 'min':    return array(-1, 1440);
        case 'g/kWh':  return array(0, 1000);
        case 's':      return array(0, 2147483647);
    }
    return array(0, 999);
}

function oc_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    /* HintText steht VORN, und das erste Kindelement ist <Info>. Beides
     * fehlte bis 1.1.3, ebenso Unit und HintText je Befehl. Gemessen an
     * der erzeugten Datei: templateType kam 0x vor, HintText 0x, Unit= 0x,
     * und MinVal="-2147483647" 90x. Reihenfolge und Werte stammen aus den
     * Original-Ausfuhren von Loxone Config; uebernommen ist der gepruefte
     * Nachbau ap_xml_virtual_in_http() aus APC-UPS, nicht eine eigene
     * Erfindung. Die Schwesterlinie aWATTar traegt ihn schon. */
    $o .= '<VirtualInHttp ';
    $o .= 'HintText="' . oc_x(isset($kopf['hint']) ? $kopf['hint'] : '') . '" ';
    $o .= 'Title="' . oc_x($kopf['title']) . '" ';
    $o .= 'Comment="' . oc_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . oc_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . oc_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    $o .= "	" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        /* Ohne Unit steht am virtuellen Eingang eine nackte Zahl, und die
         * Einheit findet nur, wer den Kommentar aufklappt. Sie liegt in
         * oc_themen() je Thema bereit und wurde bisher nur an den Kommentar
         * gehaengt. Loxone Config legt sie beim Import als Kindelement
         * <Display Unit="..."/> ab, nicht als Attribut des Befehls -
         * ankommen tut sie trotzdem. */
        $einheit = isset($c['einheit']) ? trim((string) $c['einheit']) : '';
        $unit = $einheit === '' ? '<v.1>' : '<v.1> ' . $einheit;
        $min = isset($c['min']) && $c['min'] !== null ? $c['min'] : 0;
        $max = isset($c['max']) && $c['max'] !== null ? $c['max'] : 100;
        $o .= "	" . '<VirtualInHttpCmd ';
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
        $o .= 'MinVal="' . oc_x($min) . '" ';
        $o .= 'MaxVal="' . oc_x($max) . '" ';
        $o .= 'Unit="' . oc_x($unit) . '" ';
        $o .= 'HintText=""';
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
            $g = oc_thema_grenzen(isset($info[1]) ? $info[1] : '', $k);
            $cmds[] = array('title' => 'OCTOPUS_' . $flach,
                            'comment' => 'Octopus: ' . oc_kachel_text($info),
                            'einheit' => isset($info[1]) ? $info[1] : '',
                            'min' => $g[0], 'max' => $g[1],
                            'check' => $flach . '=\v;');
        }
        return array('VI_octopus_http.xml', oc_xml_virtual_in_http(array(
            'title'   => 'Octopus Dynamic (HTTP)',
            'address' => 'http://' . $host . '/plugins/' . oc_paths()['plugin']
                       . '/index.php?token=' . (string) $cfg['aktionstoken'] . '&aktion=status',
            'polling' => '300',
            'comment' => $fuss,
        ), $cmds));
    }

    $cmds = array();
    foreach (oc_themen() as $k => $info) {
        $g = oc_thema_grenzen(isset($info[1]) ? $info[1] : '', $k);
        $cmds[] = array(
            // Der Gateway bildet den Titel aus dem Thema und ersetzt dabei
            // den Schraegstrich - siehe oc_thema_flach().
            // M6 (seit 1.1.16): auch der Schraegstrich IM Praefix wird zum
            // Unterstrich, wie der Gateway den Namen bildet (aus haus/strom
            // wird haus_strom_cur, nicht haus/strom_cur).
            'title'   => oc_thema_flach($praefix . '/' . $k),
            'einheit' => isset($info[1]) ? $info[1] : '',
            'min'     => $g[0], 'max' => $g[1],
            /* Kachelname (Regeln/07): kurz, mit Vorsatz. Bis 1.1.10 stand
             * hier der Erklaertext - 67 von 90 ueber 40 Zeichen. */
            'comment' => 'Octopus: ' . oc_kachel_text($info) . ($info[1] !== '' ? ' [' . $info[1] . ']' : ''),
            'check'   => ' ',
        );
    }
    return array('VI_octopus_eingaenge.xml', oc_xml_virtual_in_http(array(
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
    /* Ohne Wurzel gibt es keine Plugin-Datenbank. Bis 1.1.11 stand hier dann
     * '/data/system/plugindatabase.json' ab der Laufwerkswurzel, und die
     * Fassung, die dort stand, galt (in WSL gemessen,
     * Pruefung-Spotpreis-Octopus-1.1.12, Fall C6). */
    $home = oc_paths()['home'];
    if ($home === '') { return oc_version_aus_cfg(); }
    $f = $home . '/data/system/plugindatabase.json';
    if (!is_file($f)) { return oc_version_aus_cfg(); }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!isset($d['plugins']) || !is_array($d['plugins'])) { return oc_version_aus_cfg(); }
    foreach ($d['plugins'] as $e) {
        if (isset($e['folder']) && $e['folder'] === oc_paths()['plugin']) {
            $v = isset($e['version']) ? (string) $e['version'] : '';
            if ($v !== '') { return $v; }
        }
    }
    return oc_version_aus_cfg();
}

/**
 * Die Fassung aus der plugin.cfg - der Rueckfall fuer den Fall, dass es
 * keine Plugin-Datenbank gibt.
 *
 * WOZU. data/system/plugindatabase.json ist die Auskunft des LoxBerry
 * selbst und die einzige, die im INSTALLIERTEN Zustand vorliegt - die
 * plugin.cfg wandert bei der Installation nicht mit. Im entpackten
 * Archiv ist es genau umgekehrt, und das ist der Prueffall: der
 * Endpunkt beantwortete ?selftest=1 dort mit FASSUNG=-, waehrend die
 * Schwesterlinie Tibber ihre Nummer nennt. Gemessen am Hauspruefstand.
 *
 * parse_ini_file() taugt fuer die plugin.cfg NICHT: sie kommentiert mit
 * '#', PHPs INI-Zerleger kennt nur ';' und bricht an der ersten
 * Kommentarzeile ab. Deshalb wird die eine Zeile selbst gesucht.
 */
function oc_version_aus_cfg()
{
    $cfg = dirname(dirname(dirname(__FILE__))) . '/plugin.cfg';
    if (!is_file($cfg)) { return ''; }
    foreach (file($cfg, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $z) {
        if (preg_match('/^\s*VERSION\s*=\s*(\S+)\s*$/', (string) $z, $m)) {
            return (string) $m[1];
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
        /* Installiert: <home>/templates/plugins/<ordner>/lang. Ohne Wurzel
         * NICHTS ab der Laufwerkswurzel: bis 1.1.11 hiess das
         * '' . '/templates/plugins/octopus/lang', und was dort lag, galt vor
         * den eigenen Sprachdateien (in WSL gemessen,
         * Pruefung-Spotpreis-Octopus-1.1.12, Fall C1; dieselbe Stelle in
         * tb_t() von Spotpreis-Tibber 0.9.19). */
        $pfad = $p['home'] !== '' ? $p['home'] . '/templates/plugins/' . $p['plugin'] . '/lang' : '';
        if ($pfad === '' || !is_dir($pfad)) {
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
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte,
 * Zugangsdaten|null) - VIER Felder, an jeder Rueckgabestelle.
 */
function oc_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        /* VIER Felder, wie an der anderen Rueckgabestelle. Bis 1.1.3
         * standen hier drei, und die Aufrufstelle zerlegt in vier
         * (webfrontend/htmlauth/index.php). Unter PHP 7.4 blieb das
         * still, unter PHP 8.4 steht dann
         * 'Warning: Undefined array key 3' ueber der Oberflaeche -
         * gemessen an beiden Fassungen. E_WARNING ist von
         * error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE) NICHT
         * gedeckt, und display_errors steht in der Oberflaeche auf 1. */
        return array(null, array(oc_t('EINST.SICH_KEIN_JSON')), 0, null);
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
            /* O5 (seit 1.1.16): ein Feld statt einer Zeichenkette wird
             * beanstandet, nicht zu '' gemacht. Bis 1.1.15 loeschte eine
             * Sicherung mit zugang.email als Liste die gueltige Adresse, und
             * die Meldung sagte "uebernommen" (Pruefbericht oberflaeche,
             * Befund 5). */
            $oc_zk_falsch = false;
            foreach (array('email', 'passwort', 'konto') as $oc_zk) {
                if (isset($w[$oc_zk]) && !is_string($w[$oc_zk])) {
                    $mangel[] = sprintf(oc_t('EINST.SICH_WERT'), 'zugang.' . $oc_zk);
                    $oc_zk_falsch = true;
                }
            }
            if ($oc_zk_falsch) { continue; }
            $zugang = array(
                'email'    => oc_text(isset($w['email']) ? $w['email'] : '', 200),
                'passwort' => (isset($w['passwort']) && !is_array($w['passwort']))
                              ? (string) $w['passwort'] : '',
                'konto'    => oc_text(isset($w['konto']) ? $w['konto'] : '', 40),
            );
            /* Nr. 19 (B-Nachzug 01.10.2026): was oc_text() veraendert hat
             * (gekuerzt, Leerzeichen zusammengefasst, Steuerzeichen), ist nicht
             * mehr der Wert aus der Datei - beanstandet statt still uebernommen. */
            if (($zugang['email'] !== '' && !oc_email_gueltig($zugang['email']))
                || (isset($w['email']) && $zugang['email'] !== trim($w['email']))) {
                $mangel[] = sprintf(oc_t('EINST.SICH_WERT'), 'zugang.email');
            }
            if (($zugang['konto'] !== '' && !oc_konto_gueltig($zugang['konto']))
                || (isset($w['konto']) && $zugang['konto'] !== trim($w['konto']))) {
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
        /* Nr. 19 (B-Nachzug 01.10.2026): auch die Innereien der vier Felder.
         * Bis 1.1.18 zaehlte nur, DASS es ein Feld ist; oc_config() klemmte
         * danach still (regeln.0.n = 99 wurde 12, tts.volume = 500 wurde 100). */
        $oc_innen = oc_sicherung_feld_mangel($k, $w);
        if ($oc_innen) {
            foreach ($oc_innen as $oc_in) {
                $mangel[] = sprintf(oc_t('EINST.SICH_WERT'),
                                     htmlspecialchars($oc_in, ENT_QUOTES, 'UTF-8'));
            }
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
    /* FEHLENDE Schluessel sind eine Beanstandung, kein stiller Rueckfall.
     *
     * Bis hierher war die Vorgabenliste der Ausgangspunkt, und nur was in
     * der Datei stand wurde darueber geschrieben. Eine Datei mit einem
     * einzigen Schluessel lief damit ohne Beanstandung durch, wurde
     * gespeichert, und alle uebrigen Einstellungen fielen auf Werk
     * zurueck - quittiert mit "1 Wert uebernommen".
     *
     * Gemessen an VolkswagenID 0.9.11 am 03.09.2026 unter PHP 7.4 und 8.4:
     * dort fiel dabei auch das Aktionstoken auf '', und jede im Miniserver
     * eingetragene Adresse war stumm ungueltig. Am 07.09.2026 ueber den
     * Bestand ausgerollt (30 Linien).
     *
     * Der Hausstandard sagt: eine halb gueltige Datei aendert gar nichts.
     * Verglichen wird gegen die VORGABEN, nicht gegen $bekannt: was
     * ausserhalb der Konfigurationsdatei liegt - Zugangsdaten in einer
     * eigenen Datei - faellt nicht auf Werk zurueck und darf hier fehlen. */
    $fehlend = array();
    foreach (array_keys(oc_vorgaben()) as $fk) {
        if (!array_key_exists($fk, $daten)) {
            $fehlend[] = $fk;
        }
    }
    if ($fehlend) {
        $mangel[] = sprintf(oc_t('EINST.SICH_FEHLEND'), count($fehlend),
            htmlspecialchars(implode(', ', $fehlend), ENT_QUOTES, 'UTF-8'));
    }
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
        /* Nr. 19 (B-Nachzug 01.10.2026): dieselbe Form wie im Reiter MQTT. Bis
         * 1.1.18 ging "/octopus/" durch und wurde still "octopus", ein leeres
         * Praefix still "octopus". */
        if ($k === 'mqtt_topic' && !preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#', $t)) {
            return 'kein_thema';
        }
        /* Was oc_text() beim Lesen veraendern wuerde (doppelte Leerzeichen,
         * Laenge), waere nach dem Zurueckspielen ein anderer Wert. Leerraum
         * am Rand darf still fallen (Ausnahme der Entscheidung 19). */
        if (oc_text($t, (int) $s[1]) !== trim($t)) {
            return 'nicht_sauber';
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
    /* Kopf und volle Konfiguration (mit dem Haken samt Zugangsdaten) kommen
     * aus oc_sicherung_teile() - derselben Stelle, die X-3 prueft. */
    list($kopf, $cfg) = oc_sicherung_teile($mit_zugang);
    /* X-3 (Verbesserungsbau 30.09.2026): Wuerde das Zurueckspielen genau
     * dieser Datei abgewiesen, sagt es auch die Datei selbst - als
     * Kopfschluessel, den das Einlesen uebergeht, und nur mit den Namen der
     * Schluessel, nie mit Werten. Geliefert wird sie trotzdem. */
    $mangel = oc_sicherung_altwerte($mit_zugang);
    if ($mangel) {
        $kopf['_warnung'] = 'Diese Sicherung wuerde beim Zurueckspielen abgewiesen: '
            . implode(' ', $mangel) . ' - in der Oberflaeche berichtigen und neu sichern.';
    }
    $daten = $kopf + $cfg;
    $js = json_encode($daten,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    /* json_encode kann false liefern. Ein blankes "echo json_encode(...);
     * exit;" haette dann eine 0-Byte-Datei geliefert, die wie eine
     * Sicherung aussieht. Der Aufrufer meldet stattdessen einen Fehler. */
    return $js === false ? '' : $js;
}

/**
 * Kopf und Inhalt der Sicherungsdatei, ohne Warnung (X-3). Der Inhalt ist die
 * Konfiguration, wie oc_config() sie liefert (samt Aktionstoken), und mit dem
 * Haken die Zugangsdaten - dieselbe Reihenfolge wie bis 1.1.17.
 *
 * NUR BEKANNTE SCHLUESSEL (Octopus-S1, Entscheidung 16; B-Nachzug
 * 01.10.2026): array_intersect_key() mit oc_vorgaben(), wie EVCC. Bis 1.1.18
 * nahm die Sicherung auch einen fremden Schluessel mit, den oc_config() nach
 * Regeln/05 stehen laesst - und das Zurueckspielen wies die EIGENE Datei
 * damit ab. Der Reiter Test nennt fremde Schluessel weiterhin.
 * Steht hinter oc_sicherung_bauen(), damit die Ausfuhr die erste Funktion
 * ihrer Art bleibt (Werkzeuge/sicherung_pruefen.py sieht die erste an).
 */
function oc_sicherung_teile($mit_zugang = false)
{
    $cfg = array_intersect_key(oc_config(), oc_vorgaben());
    /* Das Sprechtoken fuer Alexa-NG geht nie mit (Ansage-2, wie in der
     * Sprachsteuerung): es ist ein Kennwort des anderen Plugins, und das
     * Zurueckspielen behaelt das laufende. */
    unset($cfg['tts']['alexa_token']);
    $kopf = array(
        '_hinweis' => 'Sicherung der Einstellungen des LoxBerry-Plugins Octopus Dynamic.'
                    . ' Im Reiter Einstellungen unter "Einstellungen zurueckspielen"'
                    . ' wieder einlesen.',
        '_plugin'  => 'octopus',
        '_fassung' => oc_version(),
        '_stand'   => date('Y-m-d H:i:s'),
        '_zugang'  => $mit_zugang ? 'enthalten' : 'nicht enthalten',
    );
    if ($mit_zugang) {
        $z = oc_zugang();
        $cfg['zugang'] = array(
            'email' => $z['email'], 'passwort' => $z['passwort'], 'konto' => $z['konto'],
        );
    }
    return array($kopf, $cfg);
}

/**
 * Wuerde das Zurueckspielen der eigenen Sicherung abgewiesen? (X-3)
 *
 * DIESELBE Pruefung wie beim Zurueckspielen: oc_sicherung_lesen() ueber
 * genau die Datei, die der Knopf liefert. Zwei Pruefungen liefen irgendwann
 * auseinander. Rueckgabe: die Beanstandungen als Klartext (sie nennen nur
 * Schluessel, nie Werte), leer = die Datei besteht.
 *
 * Moeglich ist das, wo oc_config() einen Wert nicht selbst in die Form
 * bringt: eine Kundennummer oder E-Mail in zugang.json, die nicht zur Form
 * passt (nur mit dem Haken "Zugangsdaten mitsichern"), oder ein von Hand
 * eingetragener Wert, den oc_config() stehen laesst, das Formular aber nicht
 * annaehme (etwa doppelte Leerzeichen, Zonen "1,"). Ein fremder Schluessel
 * gehoert seit S1 nicht mehr dazu: die Sicherung nimmt ihn nicht mit.
 */
function oc_sicherung_altwerte($mit_zugang = false)
{
    list($kopf, $cfg) = oc_sicherung_teile($mit_zugang);
    $js = json_encode($kopf + $cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($js)) { return array(); }   // das meldet der Knopf selbst (SICH_SCHREIBFEHLER)
    $pr = oc_sicherung_lesen($js);
    if ($pr[0] !== null) { return array(); }
    $aus = array();
    foreach ((array) $pr[1] as $m) {
        $t = trim(html_entity_decode(strip_tags((string) $m), ENT_QUOTES, 'UTF-8'));
        if ($t !== '' && !in_array($t, $aus, true)) { $aus[] = $t; }
    }
    return $aus;
}

/**
 * Die Innereien der vier Felder einer Sicherung pruefen (Nr. 19, B-Nachzug
 * 01.10.2026) - mit denselben Grenzen wie das Formular. Rueckgabe: die Namen
 * der unbrauchbaren Eintraege (regeln.0.n, tts.volume ...), leer = in Ordnung.
 *
 * Fehlende innere Schluessel sind erlaubt: eine Sicherung einer aelteren
 * Fassung kennt etwa min_lauf noch nicht, und oc_config() setzt die Vorgabe -
 * das ist kein Zurechtbiegen eines Werts, den jemand gewaehlt hat. Fremde
 * innere Schluessel werden uebergangen. Das Sprechtoken fuer Alexa-NG steht
 * nie in einer Sicherung; das Zurueckspielen behaelt das laufende.
 */
function oc_sicherung_feld_mangel($k, $w)
{
    if (!is_array($w)) { return array(); }
    $aus = array();
    $zahl = function ($v, $min, $max, $ganz) {
        if (is_bool($v) || $v === null || is_array($v)) { return false; }
        $roh = trim((string) $v);
        $t = str_replace(',', '.', $roh);
        if (!is_numeric($t) || ($ganz && !preg_match('/^-?[0-9]+$/', $roh))) { return false; }
        return (float) $t >= (float) $min && (float) $t <= (float) $max;
    };
    $haken = function ($v) {
        return is_bool($v) || ((is_int($v) || is_string($v)) && in_array((string) $v, array('0', '1'), true));
    };
    $text = function ($v, $max, $muster = null) {
        if (!is_string($v) || oc_text($v, $max) !== trim($v) || preg_match('/[\x00-\x1F\x7F"]/', $v)) { return false; }
        $v = trim($v);   // Leerraum am Rand darf still fallen
        return $v === '' || $muster === null || (bool) preg_match($muster, $v);
    };
    /* Je Schluessel: array(Art, ...). z/g Zahl, b Haken, w Wahl, t Text. */
    $pruefe = function ($wo, $feld, $spec) use (&$aus, $zahl, $haken, $text) {
        foreach ($spec as $sk => $s) {
            if (!array_key_exists($sk, $feld)) { continue; }
            $v = $feld[$sk];
            if ($s[0] === 'z' || $s[0] === 'g') { $gut = $zahl($v, $s[1], $s[2], $s[0] === 'g'); }
            elseif ($s[0] === 'b') { $gut = $haken($v); }
            elseif ($s[0] === 'w') { $gut = is_string($v) && in_array($v, $s[1], true); }
            else { $gut = $text($v, $s[1], isset($s[2]) ? $s[2] : null); }
            if (!$gut) { $aus[] = $wo . '.' . $sk; }
        }
    };
    if ($k === 'months') {
        foreach ($w as $i => $v) {
            if (!is_int($i) || $i < 0 || $i > 11 || !$zahl($v, 0, 20000, false)) { $aus[] = 'months.' . $i; }
        }
    } elseif ($k === 'regeln') {
        $spec = array(
            'aktiv' => array('b'), 'neg' => array('b'), 'name' => array('t', 40),
            'art' => array('w', oc_regel_arten()),
            'n' => array('g', 1, 12), 'von' => array('g', 0, 23), 'bis' => array('g', 0, 23),
            'horizont' => array('g', 1, 48), 'schwelle' => array('z', -100, 200),
            'prozent' => array('g', 0, 90), 'rang' => array('g', 1, 99),
            'leistung' => array('z', 0, 100), 'energie' => array('z', 0, 500),
            'frist' => array('g', -1, 23), 'pv_sperre' => array('z', 0, 500),
            'soc_min' => array('g', 0, 100), 'soc_max' => array('g', 0, 100),
            'min_lauf' => array('g', 0, 720), 'min_pause' => array('g', 0, 720),
        );
        foreach ($w as $i => $r) {
            if (!is_int($i) || $i < 0 || $i >= OC_REGELN) { $aus[] = 'regeln.' . $i; continue; }
            if (is_array($r)) { $pruefe('regeln.' . $i, $r, $spec); }   // kein Feld: meldet die Stelle weiter unten
        }
    } elseif ($k === 'notify') {
        $pruefe('notify', $w, array(
            'audio' => array('b'), 'push' => array('b'), 'only_cheap' => array('b'),
            'negative' => array('b'), 'tomorrow' => array('b'), 'lb' => array('b'),
            'lb_stunden' => array('g', 1, 72),
        ));
        if (array_key_exists('hours', $w)) {
            $gesehen = array();
            foreach ((is_array($w['hours']) ? $w['hours'] : array(null)) as $h) {
                if (!$zahl($h, 0, 23, true) || in_array((int) $h, $gesehen, true)) {
                    $aus[] = 'notify.hours';
                    break;
                }
                $gesehen[] = (int) $h;
            }
        }
    } elseif ($k === 'tts') {
        $pruefe('tts', $w, array(
            'mode' => array('w', array('musicserver', 'ms4h', 'audioserver', 'custom', 'alexang')),
            'ip' => array('t', 100, '/^[A-Za-z0-9._-]+$/'),
            'port' => array('g', 1, 65535),
            'volume' => array('g', 1, 100),
            'lang' => array('t', 8, '/^[a-z]{2,8}$/'),
            'template' => array('t', 400, '#^https?://#i'),
            'alexa_geraet' => array('t', 200),
        ));
        // Zonen: Pflicht, dieselbe Form wie im Formular.
        if (array_key_exists('zones', $w)
            && !(is_string($w['zones']) && preg_match('/^[0-9]+([ ,~]+[0-9]+)*$/', trim($w['zones'])))) {
            $aus[] = 'tts.zones';
        }
        // Die Sprache darf nicht leer sein (das Formular verlangt 2 bis 8 Buchstaben).
        if (array_key_exists('lang', $w) && is_string($w['lang']) && trim($w['lang']) === '') { $aus[] = 'tts.lang'; }
        /* Das Sprechtoken fuer Alexa-NG geht nie in eine Sicherung, und das
         * Zurueckspielen behaelt das laufende. Traegt eine Datei trotzdem eines,
         * wuerde es still verworfen - deshalb beanstandet. */
        if (array_key_exists('alexa_token', $w) && $w['alexa_token'] !== '') { $aus[] = 'tts.alexa_token'; }
    }
    return array_values(array_unique($aus));
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

    /* loxberry_log.php nachladen, wenn es da ist - der Cron laedt es nicht.
     * Nur aus der Wurzel der Anlage: ohne Wurzel hiess das bis 1.1.11
     * '/libs/phplib/loxberry_log.php' ab der Laufwerkswurzel, und was dort
     * lag, lief als Code dieses Plugins (in WSL gemessen,
     * Pruefung-Spotpreis-Octopus-1.1.12, Fall C4). */
    $oc_home = oc_paths()['home'];
    $lib = $oc_home !== '' ? $oc_home . '/libs/phplib/loxberry_log.php' : '';
    if (!function_exists('notify_ext') && $lib !== '' && is_file($lib)) { @require_once $lib; }
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
    // O2: die Oberflaeche liest nur, was der Takt geholt hat.
    if (oc_kein_abruf() && !$force) {
        $c = is_file($cache) ? json_decode((string) @file_get_contents($cache), true) : null;
        return is_array($c) ? $c + $leer : $leer;
    }
    $erg = $leer;
    $erg['ts'] = time();
    $roh = oc_holen($cfg['verbrauch_url'], $hf);
    if ($roh === null) {
        $erg['meldung'] = $hf !== '' ? $hf : 'NICHT_ERREICHBAR';
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
