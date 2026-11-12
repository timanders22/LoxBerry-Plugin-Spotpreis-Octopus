<?php
/**
 * Gemeinsames Sicherungsmodul fuer die LoxBerry-Plugins des Hauses:
 * "Einstellungen sichern" und "Einstellungen zurueckspielen"
 *
 * DIESE DATEI IST IN MEHREREN PLUGINS BYTEWEISE GLEICH.
 * Die Stammfassung liegt unter Werkzeuge/gemeinsam/sicherung.php im
 * Arbeitsordner. Ein LoxBerry-Plugin kann keine Datei eines anderen Plugins
 * laden; deshalb traegt jede Linie eine Abschrift neben ihrer Bibliothek.
 * Werkzeuge/sicherung_einbauen.py legt sie byte-gleich hinein und meldet mit
 * --pruefen jede Abschrift, die von der Stammfassung abweicht, und jeden
 * Abschnitt [SICHERUNG] der Sprachdateien, der nicht zu
 * Werkzeuge/gemeinsam/sicherung_texte.json passt. Wer hier etwas aendert,
 * aendert es in der Stammfassung, hebt SICHERUNG_FASSUNG an, laesst
 * sicherung_selbsttest() (php sicherung.php --selbsttest) und
 * Werkzeuge/sicherung_pruefstand.py laufen und verteilt danach.
 *
 * Auftrag des Hausherrn vom 12.11.2026: eine gemeinsame Vorlage, damit sich
 * alle Plugins beim Sichern und Zurueckspielen gleich verhalten. Die Regeln,
 * die dieses Modul festschreibt (Entscheidungen des Hausherrn 12.11.2026):
 *
 *   1. Kennzeichen. Jede Sicherung traegt einen lesbaren Kopf mit dem
 *      Kennzeichen des Plugins (_plugin) und der Fassung (_fassung). Eine
 *      Datei mit falschem oder fehlendem Kennzeichen wird als Ganzes
 *      abgewiesen. Ausnahme: eine alte Sicherung der eigenen Linie, die das
 *      Kennzeichen noch nicht kennt - die Linie erkennt sie ueber ihren
 *      Uebersetzer ('uebersetzer'), und die Meldung sagt es.
 *   2. Unbekannte Schluessel einer eigenen Sicherung, oben und in Bloecken,
 *      werden verworfen und mit Namen gemeldet, nie mit Wert. Die Datei wird
 *      trotzdem zurueckgespielt.
 *   3. Ein leerer Wert ('' - genau die leere Zeichenkette) ueberschreibt
 *      keinen bestehenden. Er bleibt stehen und wird mit Namen genannt.
 *      Beidseits leer: keine Meldung. Listen und Felder sind keine leeren
 *      Werte in diesem Sinn; ein leeres Feld [] wird wie bisher uebernommen.
 *   4. Halb gueltig: wird ein bekannter Wert beanstandet, aendert sich gar
 *      nichts. Alle Beanstandungen werden gesammelt, nicht nur die erste.
 *   5. Inhalt: Aktionstoken und Zugangsdaten gehoeren hinein; der
 *      Formulartoken und die Sprechtoken nicht (das bestimmt die Linie in
 *      'sichern' und 'nie_aus_datei'). Ein selbsterzeugtes Geheimnis wird hier
 *      nie erzeugt.
 *   6. Danach zieht die Zweitschrift mit (Speicherfunktion der Linie, das
 *      Modul prueft es, wenn 'zweitschrift' genannt ist), der Dienst wird
 *      nachgezogen ('nachziehen'), und die Meldung sagt, was mit ihm geschah.
 *   7. Alles uebrige verhaelt sich wie bisher in der Linie: welche Schluessel
 *      fehlen duerfen ('fehlt'), welche Werte gelten ('pruefen', 'quer'),
 *      was vor dem Speichern noch geschieht ('vorbereiten').
 *
 * Die Funktionen tragen das neutrale Kuerzel 'sicherung_' (wie 'ansage_' in
 * sprachausgabe.php). Laufen zwei Abschriften in einem PHP-Prozess, laedt sich
 * das Modul nur einmal, und es gilt die zuerst geladene Abschrift. Deshalb:
 * keine Funktion entfernen oder umbenennen, keine Bedeutung eines Parameters
 * oder einer Rueckgabe aendern; Neues kommt als neue Funktion oder als neuer,
 * wahlfreier Schluessel der Beschreibung.
 *
 * Laeuft unter PHP 7.4 bis 8.5 ohne Meldung; keine Sprachmittel ab 8.0
 * (kein match, kein str_contains, keine nullbaren Typen).
 *
 * ------------------------------------------------------------------
 * Die Beschreibung $b, die die Linie uebergibt
 * ------------------------------------------------------------------
 *
 *   'kennzeichen'  Pflicht. Wert von _plugin, /^[A-Za-z0-9_.\-]{1,80}\z/.
 *                  Ein bestehendes Kennzeichen einer Linie bleibt, wie es ist.
 *   'kennzeichen_frueher'  Liste frueherer Kennzeichen derselben Linie.
 *   'hinweis'      Text fuer _hinweis (sagt, von welchem Plugin die Datei ist
 *                  und dass sie Geheimnisse traegt). Ohne Angabe K_HINWEIS.
 *   'fassung'      Fassung fuer _fassung: Text oder function () -> Text.
 *                  Ohne Angabe sicherung_fassung_lesen($b).
 *   'home', 'ordner', 'plugin_cfg'  fuer sicherung_fassung_lesen(): LoxBerry-
 *                  Wurzel (Plugin-Datenbank), Plugin-Ordner, plugin.cfg als
 *                  Rueckfall im entpackten Archiv.
 *   'kopf'         weitere Kopffelder (Schluessel mit _), nach _stand.
 *   'vorgaben'     Pflicht. Die Vorgaben der Linie; jeder Schluessel darin
 *                  ist bekannt. Der Ausgangspunkt beim Zurueckspielen.
 *   'schluessel'   Name => Knoten, verfeinert einzelne Schluessel:
 *                    'art'     'wert' (ab Werk) | 'block' | 'liste' | 'offen'
 *                    'schluessel' (block) Name => Knoten der Unterschluessel
 *                    'eintrag' (liste) Knoten eines Eintrags (meist block)
 *                    'zuordnung' (liste) Feld, ueber das ein Eintrag der
 *                              Datei seinem Gegenstueck am Ziel zugeordnet
 *                              wird (fuer Regel 3); '' = keine Zuordnung
 *                    'fehlt'   nur oben: 'pflicht' (ab Werk) | 'vorgabe' |
 *                              'behalten' (geltender Wert, wird genannt)
 *                    'pruefen' function ($wert, $name, $neu) -> '' | Text |
 *                              Liste von Texten; ersetzt $b['pruefen']
 *                    'wandeln' function ($wert) -> Wert, nach bestandener
 *                              Pruefung (Normalform der Linie)
 *                    'sichern' function ($wert) -> Wert beim Sichern
 *                              (z. B. ohne Sprechtoken)
 *                  Hilfen: sicherung_block(), sicherung_liste(), sicherung_offen().
 *                  'offen' heisst: Unterschluessel werden nicht gegen eine
 *                  Liste gehalten (dynamische Namen); die Pruefung der Linie
 *                  entscheidet.
 *   'alt'          Schluessel frueherer Fassungen, die still uebergangen
 *                  werden (wie bisher in der Linie).
 *   'nie_aus_datei' Schluessel, die nie aus einer Datei kommen (Vermerke);
 *                  still uebergangen, nie gesichert.
 *   'teile'        Name => function ($wert, $jetzt) -> array(Wert|null,
 *                  Beanstandungen[]); Teile der Datei, die nicht in die
 *                  Konfiguration gehen (eigene Geheimnisdatei). Ergebnis in
 *                  $r['teile'][Name]. Steht der Name auch in 'schluessel'
 *                  (als block), gelten Regel 2 und 3 auch dort; der geltende
 *                  Stand kommt aus 'teile_jetzt'[Name] (Wert oder Funktion).
 *   'pruefen'      function ($schluessel, $wert, $neu) -> '' | Text | Liste.
 *   'quer'         function ($neu, $r) -> Liste von array(Name, Text).
 *   'uebersetzer'  function ($daten) -> array|null: nur fuer eine Datei OHNE
 *                  Kennzeichen. Eine alte eigene Sicherung kommt (gegebenenfalls
 *                  umgeschrieben) zurueck, alles andere null.
 *   'vorbehandeln' function ($daten, $jetzt, $roh) -> $daten: nach Regel 2
 *                  und 3, vor der Pruefung (Bloecke vervollstaendigen ...).
 *   'jetzt'        function () -> die geltende Konfiguration.
 *   't'            function ($schluessel) -> Text (wie xx_t()); Abschnitt
 *                  'abschnitt', ab Werk SICHERUNG.
 *   'e'            function ($s) -> maskierter Text; ab Werk htmlspecialchars.
 *   'max_byte'     Obergrenze der Datei, ab Werk SICHERUNG_MAX_BYTE.
 * Fuer sicherung_zurueckspielen() dazu:
 *   'sperre'       function () -> Griff | null;  'freigeben' function ($griff)
 *   'vorbereiten'  function ($neu, $jetzt, $r) -> $neu (vor dem Speichern)
 *   'speichern'    function ($neu, $jetzt, $r) -> bool (zieht die Zweitschrift)
 *   'zweitschrift' function () -> array(Konfigurationsdatei, Zweitschrift)
 *   'nachziehen'   function ($neu, $jetzt, $r) -> Liste von Meldungen
 *   'log'          function ($zeile)
 *
 * ------------------------------------------------------------------
 * Das Ergebnis $r von sicherung_lesen()
 * ------------------------------------------------------------------
 *
 *   'ok'            true = zurueckspielbar
 *   'neu'           die neue Konfiguration (null, wenn nicht ok)
 *   'anzahl'        uebernommene Schluessel der obersten Ebene
 *   'eigen'         'kennzeichen' | 'frueher' | 'uebersetzt' | ''
 *   'fremd'         '' | 'form' | 'gross' | 'kennzeichen' | 'ohne'
 *   'kennzeichen_datei'  Kennzeichen der Datei (nur in Namensform, sonst '?')
 *   'fassung_datei' _fassung der Datei (nur in Namensform)
 *   'beanstandet'   Liste von array('name' => ..., 'text' => ...)
 *   'verworfen'     Namen unbekannter Schluessel (Regel 2)
 *   'leer_behalten' Namen leerer Werte, deren bisheriger Wert bleibt (Regel 3)
 *   'fehlt_behalten' Namen, die fehlen und den geltenden Wert behalten
 *   'fehlt_vorgabe' Namen, die fehlen und auf die Vorgabe gehen
 *   'teile'         Ergebnisse der Teile
 * Alle Namen stammen aus der Datei und gehen durch sicherung_name(): kein
 * Zeilenumbruch, kein Semikolon, hoechstens 64 Zeichen. Werte stehen in
 * keinem Feld ausser 'neu' und 'teile'.
 */

/* Kein Endpunkt (Regeln/03): die Datei liegt meist im unangemeldeten Baum
 * webfrontend/html/ und waere dort direkt aufrufbar. Ein direkter Aufruf ueber
 * den Webserver bekommt 403 und tut sonst nichts. */
if (PHP_SAPI !== 'cli') {
    $sicherung_einstieg = get_included_files();
    if (isset($sicherung_einstieg[0]) && realpath($sicherung_einstieg[0]) === realpath(__FILE__)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "SICHERUNG;OK=0;GRUND=KEIN_ENDPUNKT\n";
        exit;
    }
    unset($sicherung_einstieg);
}

if (!defined('SICHERUNG_FASSUNG')) {

define('SICHERUNG_FASSUNG', '1.0.0');
/** Groesste Sicherungsdatei, die gelesen wird (Byte). */
define('SICHERUNG_MAX_BYTE', 262144);

/* ==================================================================
 * Bausteine der Beschreibung
 * ================================================================== */

/** Ein Block (Feld mit festen Unterschluesseln). $knoten verfeinert einzelne. */
function sicherung_block(array $namen, array $knoten = array())
{
    $s = array();
    foreach ($namen as $n) { $s[(string) $n] = array('art' => 'wert'); }
    foreach ($knoten as $n => $k) { $s[(string) $n] = $k; }
    return array('art' => 'block', 'schluessel' => $s);
}

/** Eine Liste von Eintraegen mit festen Feldern; $zuordnung = Feld fuer Regel 3. */
function sicherung_liste(array $namen, $zuordnung = '', array $knoten = array())
{
    return array('art' => 'liste', 'eintrag' => sicherung_block($namen, $knoten),
                 'zuordnung' => is_string($zuordnung) ? $zuordnung : '');
}

/** Ein Feld mit dynamischen Unterschluesseln: die Pruefung der Linie entscheidet. */
function sicherung_offen()
{
    return array('art' => 'offen');
}

/* ==================================================================
 * Kleinigkeiten
 * ================================================================== */

/** Text zu einer Kennung; ohne Uebersetzer oder ohne Eintrag der Schluessel selbst. */
function sicherung_t($id, array $b)
{
    $ab = (isset($b['abschnitt']) && is_string($b['abschnitt'])) ? $b['abschnitt'] : 'SICHERUNG';
    $s = $ab . '.' . $id;
    if (isset($b['t']) && is_callable($b['t'])) { return (string) call_user_func($b['t'], $s); }
    return $s;
}

/** Maskieren fuer die Ausgabe. */
function sicherung_e($s, array $b)
{
    if (isset($b['e']) && is_callable($b['e'])) { return (string) call_user_func($b['e'], (string) $s); }
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Ein Name aus der Datei fuer Meldung und Protokoll: nur Namenszeichen, hoechstens 64. */
function sicherung_name($n)
{
    $n = (string) preg_replace('/[^A-Za-z0-9_.\-\[\]]/', '?', (string) $n);
    if ($n === '') { $n = '?'; }
    return strlen($n) > 64 ? substr($n, 0, 64) . '...' : $n;
}

/** Ist der Text ein Name in Namensform? (Kennzeichen, Fassung) */
function sicherung_namensform($s)
{
    return is_string($s) && preg_match('/^[A-Za-z0-9_.\-]{1,80}\z/', $s) === 1;
}

/** Regel 3: bleibt der bisherige Wert, weil die Datei an dieser Stelle leer ist? */
function sicherung_leer_bleibt($datei, $jetzt)
{
    if ($datei !== '') { return false; }
    if (is_bool($jetzt)) { return $jetzt; }
    return (is_string($jetzt) || is_int($jetzt) || is_float($jetzt)) && (string) $jetzt !== '';
}

/** Der Knoten zu einem Schluessel der obersten Ebene. */
function sicherung_knoten(array $b, $k)
{
    if (isset($b['schluessel'][$k]) && is_array($b['schluessel'][$k])) { return $b['schluessel'][$k]; }
    return array('art' => 'wert');
}

/** Liste der Namen ohne Doppel, Reihenfolge des ersten Auftretens. */
function sicherung_einmal(array $l)
{
    $aus = array();
    foreach ($l as $x) { if (!in_array($x, $aus, true)) { $aus[] = $x; } }
    return $aus;
}

/**
 * Die Fassung des Plugins ohne zweite Quelle: zuerst die Plugin-Datenbank des
 * LoxBerry (data/system/plugindatabase.json, Eintrag mit 'folder' = $b['ordner']),
 * dann die Zeile VERSION= der plugin.cfg ($b['plugin_cfg'], fuer das entpackte
 * Archiv). '' = nicht feststellbar. Eine feste Nummer im Code waere eine fuenfte
 * Stelle neben plugin.cfg, release.cfg, prerelease.cfg und README.
 */
function sicherung_fassung_lesen(array $b)
{
    $home = isset($b['home']) && is_string($b['home']) ? $b['home'] : '';
    $ordner = isset($b['ordner']) && is_string($b['ordner']) ? $b['ordner'] : '';
    if ($home !== '' && $ordner !== '' && is_file($home . '/data/system/plugindatabase.json')) {
        $d = json_decode((string) @file_get_contents($home . '/data/system/plugindatabase.json'), true);
        if (isset($d['plugins']) && is_array($d['plugins'])) {
            foreach ($d['plugins'] as $e) {
                if (is_array($e) && isset($e['folder']) && $e['folder'] === $ordner
                    && isset($e['version']) && sicherung_namensform((string) $e['version'])) {
                    return (string) $e['version'];
                }
            }
        }
    }
    $cfg = isset($b['plugin_cfg']) && is_string($b['plugin_cfg']) ? $b['plugin_cfg'] : '';
    if ($cfg !== '' && is_file($cfg)) {
        foreach ((array) @file($cfg, FILE_IGNORE_NEW_LINES) as $z) {
            if (preg_match('/^\s*VERSION\s*=\s*([A-Za-z0-9_.\-]{1,40})\s*$/', (string) $z, $m)) { return $m[1]; }
        }
    }
    return '';
}

/** Die Fassung fuer den Kopf. */
function sicherung_fassung(array $b)
{
    if (isset($b['fassung'])) {
        $f = is_callable($b['fassung']) ? call_user_func($b['fassung']) : $b['fassung'];
        if (is_string($f) && $f !== '') { return $f; }
    }
    return sicherung_fassung_lesen($b);
}

/** Die Beschreibung taugt? Rueckgabe: Liste der Maengel (Namen), leer = ja. */
function sicherung_beschreibung_mangel(array $b)
{
    $m = array();
    if (!isset($b['kennzeichen']) || !sicherung_namensform($b['kennzeichen'])) { $m[] = 'kennzeichen'; }
    if (!isset($b['vorgaben']) || !is_array($b['vorgaben']) || !$b['vorgaben']) { $m[] = 'vorgaben'; }
    foreach (array('pruefen', 'quer', 'uebersetzer', 'vorbehandeln', 'jetzt', 't', 'e', 'sperre', 'freigeben',
                   'vorbereiten', 'speichern', 'zweitschrift', 'nachziehen', 'log', 'fassung') as $f) {
        if (isset($b[$f]) && $f !== 'fassung' && !is_callable($b[$f])) { $m[] = $f; }
    }
    return $m;
}

/* ==================================================================
 * Sichern
 * ================================================================== */

/** Der Inhalt eines Knotens fuer die Sicherung. */
function sicherung_knoten_sichern(array $knoten, $w)
{
    if (isset($knoten['sichern']) && is_callable($knoten['sichern'])) { $w = call_user_func($knoten['sichern'], $w); }
    return $w;
}

/**
 * Die Sicherung als Feld: lesbarer Kopf (_hinweis, _plugin, _fassung, _stand,
 * dann $b['kopf']) und alle bekannten Schluessel aus $cfg in der Reihenfolge
 * der Vorgaben - auch die, die gleich der Vorgabe sind. Was $cfg nicht traegt,
 * fehlt auch in der Datei (dann sagt es _warnung). Ohne 'nie_aus_datei' und
 * 'alt'. $teile: weitere Teile der Datei (Name => Wert, z. B. Zugangsdaten
 * aus einer eigenen Datei). Traegt die Datei etwas, das das eigene
 * Zurueckspielen abwiese, steht in _warnung, was - nur Namen.
 */
function sicherung_bauen(array $b, array $cfg, array $teile = array())
{
    $kopf = array(
        '_hinweis' => (isset($b['hinweis']) && is_string($b['hinweis'])) ? $b['hinweis'] : sicherung_t('K_HINWEIS', $b),
        '_plugin'  => (string) $b['kennzeichen'],
        '_fassung' => sicherung_fassung($b),
        '_stand'   => date('Y-m-d H:i:s'),
    );
    if (isset($b['kopf']) && is_array($b['kopf'])) {
        foreach ($b['kopf'] as $k => $v) {
            if (is_string($k) && $k !== '' && $k[0] === '_' && !isset($kopf[$k])) { $kopf[$k] = $v; }
        }
    }
    $nie = isset($b['nie_aus_datei']) && is_array($b['nie_aus_datei']) ? $b['nie_aus_datei'] : array();
    $inhalt = array();
    foreach (array_keys($b['vorgaben']) as $k) {
        if (in_array($k, $nie, true) || !array_key_exists($k, $cfg)) { continue; }
        $inhalt[$k] = sicherung_knoten_sichern(sicherung_knoten($b, $k), $cfg[$k]);
    }
    foreach ($teile as $k => $v) {
        if (is_string($k) && isset($b['teile'][$k])) { $inhalt[$k] = $v; }
    }
    $daten = $kopf + $inhalt;
    $namen = sicherung_selbstpruefung($b, $daten, $cfg);
    if ($namen) {
        $daten = $kopf + array('_warnung' => sprintf(sicherung_t('K_WARNUNG', $b), implode(', ', $namen))) + $inhalt;
    }
    return $daten;
}

/** Das Feld als Datei: lesbar eingerueckt; '' wenn es sich nicht bilden laesst. */
function sicherung_json(array $daten)
{
    $js = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($js) ? $js : '';
}

/**
 * Wuerde das eigene Zurueckspielen diese Daten abweisen? Rueckgabe: die Namen der
 * beanstandeten Schluessel (leer = sie liesse sich zurueckspielen), nie Werte.
 * Dieselbe Pruefung wie beim Zurueckspielen, gegen $jetzt als Ziel.
 */
function sicherung_selbstpruefung(array $b, array $daten, $jetzt = null)
{
    $js = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($js)) { return array('-'); }
    $r = sicherung_lesen($b, $js, is_array($jetzt) ? $jetzt : array());
    if ($r['ok']) { return array(); }
    $namen = array();
    foreach ($r['beanstandet'] as $x) { $namen[] = $x['name']; }
    return $namen ? sicherung_einmal($namen) : array('-');
}

/* ==================================================================
 * Lesen und pruefen
 * ================================================================== */

/** Ein leeres Ergebnis. */
function sicherung_ergebnis()
{
    return array('ok' => false, 'neu' => null, 'anzahl' => 0, 'eigen' => '', 'fremd' => '',
                 'kennzeichen_datei' => '', 'fassung_datei' => '', 'beanstandet' => array(),
                 'verworfen' => array(), 'leer_behalten' => array(), 'fehlt_behalten' => array(),
                 'fehlt_vorgabe' => array(), 'teile' => array());
}

/** Eine Beanstandung aufnehmen ($text: '' | Text | Liste). */
function sicherung_beanstanden(array &$r, $name, $text)
{
    if (is_array($text)) {
        foreach ($text as $t) { $r['beanstandet'][] = array('name' => (string) $name, 'text' => (string) $t); }
        return;
    }
    $r['beanstandet'][] = array('name' => (string) $name, 'text' => (string) $text);
}

/**
 * Regel 2 und 3 an einem Knoten: unbekannte Unterschluessel verwerfen (Name in
 * $r['verworfen']), leere Werte durch den bisherigen ersetzen (Name in
 * $r['leer_behalten']). Rueckgabe: der bereinigte Wert. Was keine Form eines
 * Blocks oder einer Liste hat, bleibt unveraendert - das entscheidet die Pruefung.
 */
function sicherung_knoten_reinigen(array $knoten, $w, $j, $pfad, array &$r)
{
    $art = isset($knoten['art']) ? $knoten['art'] : 'wert';
    if ($art === 'block' && is_array($w)) {
        $schl = isset($knoten['schluessel']) && is_array($knoten['schluessel']) ? $knoten['schluessel'] : array();
        foreach (array_keys($w) as $sk) {
            $name = $pfad . '.' . sicherung_name($sk);
            if (!array_key_exists((string) $sk, $schl)) {
                $r['verworfen'][] = $name;
                unset($w[$sk]);
                continue;
            }
            $js = (is_array($j) && array_key_exists($sk, $j)) ? $j[$sk] : null;
            if (sicherung_leer_bleibt($w[$sk], $js)) {
                $w[$sk] = $js;
                $r['leer_behalten'][] = $name;
                continue;
            }
            $w[$sk] = sicherung_knoten_reinigen($schl[(string) $sk], $w[$sk], $js, $name, $r);
        }
        return $w;
    }
    if ($art === 'liste' && is_array($w)) {
        $eintrag = isset($knoten['eintrag']) && is_array($knoten['eintrag']) ? $knoten['eintrag'] : array('art' => 'offen');
        $zu = isset($knoten['zuordnung']) && is_string($knoten['zuordnung']) ? $knoten['zuordnung'] : '';
        foreach (array_keys($w) as $i) {
            if (!is_array($w[$i])) { continue; }
            $name = $pfad . '[' . (is_int($i) ? (string) ($i + 1) : sicherung_name($i)) . ']';
            $je = null;
            if ($zu !== '' && isset($w[$i][$zu]) && is_scalar($w[$i][$zu]) && (string) $w[$i][$zu] !== '' && is_array($j)) {
                foreach ($j as $kand) {
                    if (is_array($kand) && isset($kand[$zu]) && is_scalar($kand[$zu])
                        && (string) $kand[$zu] === (string) $w[$i][$zu]) { $je = $kand; break; }
                }
            }
            $w[$i] = sicherung_knoten_reinigen($eintrag, $w[$i], $je, $name, $r);
        }
        return $w;
    }
    return $w;
}

/**
 * Eine Sicherungsdatei lesen und pruefen, ohne etwas zu schreiben.
 * $jetzt: die geltende Konfiguration (fuer Regel 3 und 'behalten'); ohne
 * Angabe $b['jetzt'](). Rueckgabe: das Ergebnis $r (siehe Kopf).
 */
function sicherung_lesen(array $b, $roh, $jetzt = null)
{
    $r = sicherung_ergebnis();
    if ($jetzt === null && isset($b['jetzt']) && is_callable($b['jetzt'])) { $jetzt = call_user_func($b['jetzt']); }
    if (!is_array($jetzt)) { $jetzt = array(); }
    $bm = sicherung_beschreibung_mangel($b);
    if ($bm) {
        $r['fremd'] = 'form';
        sicherung_beanstanden($r, '-', sprintf(sicherung_t('K_BESCHREIBUNG', $b), implode(', ', $bm)));
        return $r;
    }
    $max = isset($b['max_byte']) && is_int($b['max_byte']) && $b['max_byte'] > 0 ? $b['max_byte'] : SICHERUNG_MAX_BYTE;
    if (!is_string($roh) || strlen($roh) > $max) {
        $r['fremd'] = 'gross';
        sicherung_beanstanden($r, '-', sprintf(sicherung_t('K_ZU_GROSS', $b), (int) ($max / 1024)));
        return $r;
    }
    $daten = json_decode($roh, true);
    if (!is_array($daten) || ($daten !== array() && array_keys($daten) === range(0, count($daten) - 1))) {
        $r['fremd'] = 'form';
        sicherung_beanstanden($r, '-', sicherung_t('K_KEIN_JSON', $b));
        return $r;
    }

    /* ---- Regel 1: das Kennzeichen ---- */
    $frueher = isset($b['kennzeichen_frueher']) && is_array($b['kennzeichen_frueher']) ? $b['kennzeichen_frueher'] : array();
    if (array_key_exists('_plugin', $daten)) {
        $kz = $daten['_plugin'];
        $r['kennzeichen_datei'] = sicherung_namensform($kz) ? $kz : '?';
        if ($kz === $b['kennzeichen']) {
            $r['eigen'] = 'kennzeichen';
        } elseif (is_string($kz) && in_array($kz, $frueher, true)) {
            $r['eigen'] = 'frueher';
        } else {
            $r['fremd'] = 'kennzeichen';
            sicherung_beanstanden($r, '_plugin', $r['kennzeichen_datei'] !== '?'
                ? sprintf(sicherung_t('K_FREMD', $b), sicherung_e($r['kennzeichen_datei'], $b))
                : sicherung_t('K_FREMD_OHNE_NAME', $b));
            return $r;
        }
    } else {
        $ue = (isset($b['uebersetzer']) && is_callable($b['uebersetzer'])) ? call_user_func($b['uebersetzer'], $daten) : null;
        if (!is_array($ue)) {
            $r['fremd'] = 'ohne';
            sicherung_beanstanden($r, '_plugin', sicherung_t('K_OHNE_KENNZEICHEN', $b));
            return $r;
        }
        $daten = $ue;
        $r['eigen'] = 'uebersetzt';
    }
    if (isset($daten['_fassung']) && sicherung_namensform($daten['_fassung'])) { $r['fassung_datei'] = $daten['_fassung']; }

    $vorgaben = $b['vorgaben'];
    $alt = isset($b['alt']) && is_array($b['alt']) ? $b['alt'] : array();
    $nie = isset($b['nie_aus_datei']) && is_array($b['nie_aus_datei']) ? $b['nie_aus_datei'] : array();
    $teile = isset($b['teile']) && is_array($b['teile']) ? $b['teile'] : array();

    /* ---- Regel 2 und 3: Unbekanntes verwerfen, Leeres stehen lassen ---- */
    $bereit = array();
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        if ($k !== '' && $k[0] === '_') { continue; }               // der lesbare Kopf
        if (in_array($k, $alt, true) || in_array($k, $nie, true)) { continue; }
        if (isset($teile[$k])) {
            if (!is_callable($teile[$k])) { continue; }
            /* Ein Teil mit Knoten in 'schluessel' durchlaeuft Regel 2 und 3 wie ein Block; der geltende
             * Stand des Teils kommt aus 'teile_jetzt' (Wert oder function () -> Wert). */
            if (isset($b['schluessel'][$k]) && is_array($b['schluessel'][$k])) {
                $tj = isset($b['teile_jetzt'][$k]) ? $b['teile_jetzt'][$k] : null;
                if (is_callable($tj)) { $tj = call_user_func($tj); }
                $w = sicherung_knoten_reinigen($b['schluessel'][$k], $w, $tj, $k, $r);
            }
            $tw = call_user_func($teile[$k], $w, $jetzt);
            $tw = is_array($tw) ? array_pad($tw, 2, array()) : array(null, array());
            if ($tw[1]) { sicherung_beanstanden($r, $k, $tw[1]); } else { $r['teile'][$k] = $tw[0]; $r['anzahl']++; }
            continue;
        }
        if (!array_key_exists($k, $vorgaben)) {
            $r['verworfen'][] = sicherung_name($k);
            continue;
        }
        $j = array_key_exists($k, $jetzt) ? $jetzt[$k] : null;
        if (sicherung_leer_bleibt($w, $j)) {
            $r['leer_behalten'][] = $k;
            $bereit[$k] = $j;
            continue;
        }
        $bereit[$k] = sicherung_knoten_reinigen(sicherung_knoten($b, $k), $w, $j, $k, $r);
    }
    if (isset($b['vorbehandeln']) && is_callable($b['vorbehandeln'])) {
        $vb = call_user_func($b['vorbehandeln'], $bereit, $jetzt, $daten);
        if (is_array($vb)) { $bereit = $vb; }
    }

    /* ---- Regel 4: jeden Wert pruefen, alles sammeln ---- */
    $neu = $vorgaben;
    foreach ($bereit as $k => $w) {
        if (!array_key_exists($k, $vorgaben)) { continue; }
        if (in_array($k, $r['leer_behalten'], true)) { $neu[$k] = $w; $r['anzahl']++; continue; }
        $knoten = sicherung_knoten($b, $k);
        $grund = '';
        if (isset($knoten['pruefen']) && is_callable($knoten['pruefen'])) {
            $grund = call_user_func($knoten['pruefen'], $w, $k, $neu);
        } elseif (isset($b['pruefen']) && is_callable($b['pruefen'])) {
            $grund = call_user_func($b['pruefen'], $k, $w, $neu);
        }
        if ($grund !== '' && $grund !== array() && $grund !== null) {
            sicherung_beanstanden($r, $k, $grund);
            continue;
        }
        if (isset($knoten['wandeln']) && is_callable($knoten['wandeln'])) { $w = call_user_func($knoten['wandeln'], $w); }
        $neu[$k] = $w;
        $r['anzahl']++;
    }

    /* ---- Fehlende Schluessel ---- */
    $fehlend = array();
    foreach (array_keys($vorgaben) as $k) {
        if (array_key_exists($k, $daten) || in_array($k, $nie, true)) { continue; }
        $knoten = sicherung_knoten($b, $k);
        $f = isset($knoten['fehlt']) ? $knoten['fehlt'] : 'pflicht';
        if ($f === 'vorgabe') {
            $r['fehlt_vorgabe'][] = $k;
        } elseif ($f === 'behalten') {
            if (array_key_exists($k, $jetzt)) { $neu[$k] = $jetzt[$k]; }
            $r['fehlt_behalten'][] = $k;
        } else {
            $fehlend[] = $k;
        }
    }
    if ($fehlend) {
        sicherung_beanstanden($r, implode(',', $fehlend),
            sprintf(sicherung_t('K_FEHLEND', $b), count($fehlend), sicherung_e(implode(', ', $fehlend), $b)));
    }
    if (isset($b['quer']) && is_callable($b['quer'])) {
        foreach ((array) call_user_func($b['quer'], $neu, $r) as $q) {
            if (is_array($q) && count($q) >= 2) { sicherung_beanstanden($r, (string) $q[0], (string) $q[1]); }
        }
    }
    if ($r['anzahl'] === 0 && !$r['beanstandet']) {
        sicherung_beanstanden($r, '-', sicherung_t('K_LEER', $b));
    }
    $r['verworfen'] = sicherung_einmal($r['verworfen']);
    $r['leer_behalten'] = sicherung_einmal($r['leer_behalten']);
    $r['ok'] = !$r['beanstandet'];
    $r['neu'] = $r['ok'] ? $neu : null;
    if (!$r['ok']) { $r['teile'] = array(); }
    return $r;
}

/* ==================================================================
 * Zurueckspielen
 * ================================================================== */

/**
 * Lesen, pruefen, (unter der Sperre der Linie) speichern, Zweitschrift
 * nachmessen, Dienst nachziehen. Rueckgabe: das Ergebnis $r von
 * sicherung_lesen() und dazu 'gespeichert' (bool), 'zweitschrift'
 * ('' = nicht gefragt, 'mit', 'abweichend', 'fehlt') und 'dienst' (Liste der
 * Meldungen aus 'nachziehen'). Eine halb gueltige Datei aendert nichts.
 */
function sicherung_zurueckspielen(array $b, $roh)
{
    $griff = (isset($b['sperre']) && is_callable($b['sperre'])) ? call_user_func($b['sperre']) : null;
    $jetzt = (isset($b['jetzt']) && is_callable($b['jetzt'])) ? call_user_func($b['jetzt']) : array();
    if (!is_array($jetzt)) { $jetzt = array(); }
    $r = sicherung_lesen($b, $roh, $jetzt);
    $r['gespeichert'] = false;
    $r['zweitschrift'] = '';
    $r['dienst'] = array();
    if ($r['ok']) {
        $neu = $r['neu'];
        if (isset($b['vorbereiten']) && is_callable($b['vorbereiten'])) {
            $vb = call_user_func($b['vorbereiten'], $neu, $jetzt, $r);
            if (is_array($vb)) { $neu = $vb; }
        }
        $r['neu'] = $neu;
        $r['gespeichert'] = (isset($b['speichern']) && is_callable($b['speichern']))
            ? (bool) call_user_func($b['speichern'], $neu, $jetzt, $r) : false;
        if ($r['gespeichert'] && isset($b['zweitschrift']) && is_callable($b['zweitschrift'])) {
            $r['zweitschrift'] = sicherung_zweitschrift_lage(call_user_func($b['zweitschrift']));
        }
    }
    if ($griff !== null && isset($b['freigeben']) && is_callable($b['freigeben'])) { call_user_func($b['freigeben'], $griff); }
    if ($r['gespeichert'] && isset($b['nachziehen']) && is_callable($b['nachziehen'])) {
        $r['dienst'] = array_values(array_filter((array) call_user_func($b['nachziehen'], $r['neu'], $jetzt, $r), 'is_string'));
    }
    if (isset($b['log']) && is_callable($b['log'])) { call_user_func($b['log'], sicherung_protokollzeile($r)); }
    return $r;
}

/** Steht die Zweitschrift inhaltsgleich neben der Konfiguration? $p = array(Datei, Zweitschrift). */
function sicherung_zweitschrift_lage($p)
{
    if (!is_array($p) || count($p) < 2 || !is_string($p[0]) || !is_string($p[1])) { return 'fehlt'; }
    if (!is_file($p[1])) { return 'fehlt'; }
    $a = @file_get_contents($p[0]);
    $z = @file_get_contents($p[1]);
    if (!is_string($a) || !is_string($z)) { return 'fehlt'; }
    if ($a === $z) { return 'mit'; }
    $ja = json_decode($a, true);
    $jz = json_decode($z, true);
    return (is_array($ja) && $ja === $jz) ? 'mit' : 'abweichend';
}

/** Eine Protokollzeile: Zahlen und Namen, nie Werte. */
function sicherung_protokollzeile(array $r)
{
    if (!$r['ok']) {
        $n = array();
        foreach ($r['beanstandet'] as $x) { $n[] = sicherung_name($x['name']); }
        return 'Sicherung abgewiesen (' . ($r['fremd'] !== '' ? $r['fremd'] : 'Werte') . '): '
             . implode(', ', array_slice(sicherung_einmal($n), 0, 20));
    }
    $z = 'Sicherung zurueckgespielt: ' . (int) $r['anzahl'] . ' Werte'
       . ($r['eigen'] === 'uebersetzt' ? ', aeltere Sicherung ohne Kennzeichen' : '')
       . ($r['fassung_datei'] !== '' ? ', aus Fassung ' . $r['fassung_datei'] : '');
    foreach (array('verworfen' => 'verworfen', 'leer_behalten' => 'leer, bisheriger Wert bleibt',
                   'fehlt_behalten' => 'fehlt, bisheriger Wert bleibt') as $f => $t) {
        if (!empty($r[$f])) { $z .= '; ' . $t . ': ' . implode(', ', array_slice($r[$f], 0, 20)); }
    }
    if (isset($r['gespeichert']) && !$r['gespeichert']) { $z .= '; NICHT gespeichert'; }
    if (isset($r['zweitschrift']) && $r['zweitschrift'] !== '' && $r['zweitschrift'] !== 'mit') {
        $z .= '; Zweitschrift ' . $r['zweitschrift'];
    }
    return (string) preg_replace('/[\x00-\x1F\x7F]/', '?', $z);
}

/**
 * Die Meldungen zu einem Ergebnis. Rueckgabe: array('fehler' => Texte,
 * 'hinweise' => Texte), fertig maskiert. Beanstandungen der Linie kommen mit
 * ihrem eigenen Text (die Linie hat ihn maskiert); Namen gehen durch 'e'.
 */
function sicherung_meldungen(array $b, array $r)
{
    $f = array();
    $h = array();
    if (!$r['ok']) {
        $t = array();
        foreach ($r['beanstandet'] as $x) {
            $t[] = $x['text'] !== '' ? $x['text'] : sprintf(sicherung_t('K_WERT', $b), sicherung_e($x['name'], $b));
        }
        $f[] = sicherung_t('K_ABGELEHNT', $b) . ' ' . implode(' ', sicherung_einmal($t));
        return array('fehler' => $f, 'hinweise' => $h);
    }
    if (isset($r['gespeichert']) && !$r['gespeichert']) {
        $f[] = sicherung_t('K_SCHREIBFEHLER', $b);
        return array('fehler' => $f, 'hinweise' => $h);
    }
    $h[] = sprintf(sicherung_t('K_UEBERNOMMEN', $b), (int) $r['anzahl']);
    if ($r['eigen'] === 'uebersetzt') { $h[] = sicherung_t('K_ALT_ERKANNT', $b); }
    $listen = array('verworfen' => 'K_VERWORFEN', 'leer_behalten' => 'K_LEER_BEHALTEN', 'fehlt_behalten' => 'K_FEHLT_BEHALTEN');
    foreach ($listen as $feld => $id) {
        if (!empty($r[$feld])) { $h[] = sprintf(sicherung_t($id, $b), sicherung_e(implode(', ', $r[$feld]), $b)); }
    }
    if (isset($r['zweitschrift']) && ($r['zweitschrift'] === 'abweichend' || $r['zweitschrift'] === 'fehlt')) {
        $f[] = sicherung_t('K_ZWEITSCHRIFT', $b);
    }
    if (isset($r['dienst']) && is_array($r['dienst'])) {
        foreach ($r['dienst'] as $d) { $h[] = $d; }
    }
    return array('fehler' => $f, 'hinweise' => $h);
}

/* ==================================================================
 * Texte
 * ================================================================== */

/**
 * Alle Kennungen, zu denen ein Satz im Abschnitt [SICHERUNG] der Sprachdateien
 * stehen soll. Werkzeuge/gemeinsam/sicherung_texte.json traegt genau diese
 * Schluessel in de und en; sicherung_selbsttest() und
 * Werkzeuge/sicherung_einbauen.py halten beides gegeneinander.
 */
function sicherung_sprachschluessel()
{
    return array('K_HINWEIS', 'K_WARNUNG', 'K_BESCHREIBUNG', 'K_ZU_GROSS', 'K_KEIN_JSON', 'K_FREMD',
                 'K_FREMD_OHNE_NAME', 'K_OHNE_KENNZEICHEN', 'K_FEHLEND', 'K_LEER', 'K_WERT', 'K_ABGELEHNT',
                 'K_SCHREIBFEHLER', 'K_UEBERNOMMEN', 'K_ALT_ERKANNT', 'K_VERWORFEN', 'K_LEER_BEHALTEN',
                 'K_FEHLT_BEHALTEN', 'K_ZWEITSCHRIFT');
}

/* ==================================================================
 * Selbsttest
 * ================================================================== */

/**
 * Rechnet die Regeln an einer erfundenen Linie nach, ohne Dateien und ohne Netz.
 * Rueckgabe: array(Zahl der Faelle, Fehlschlaege, Zeilen).
 */
function sicherung_selbsttest()
{
    $faelle = 0;
    $fehl = 0;
    $zeilen = array();
    $pruef = function ($name, $ist, $soll) use (&$faelle, &$fehl, &$zeilen) {
        $faelle++;
        if ($ist !== $soll) {
            $fehl++;
            $zeilen[] = 'FEHL ' . $name . ': ist ' . var_export($ist, true) . ', soll ' . var_export($soll, true);
        }
    };
    $TOK = 'pruefzeichen0123456789ab';
    $GEHEIM = 'Kennwort-Selbsttest-77';
    $gespeichert = array();
    $b = array(
        'kennzeichen' => 'LoxBerry-Plugin-Selbsttest',
        'kennzeichen_frueher' => array('selbsttest'),
        'fassung' => '9.8.7',
        'vorgaben' => array('aktionstoken' => '', 'thema' => 'st', 'takt' => 60, 'ein' => 0, 'spaet' => 1,
                            'werk' => 5, 'tts' => array('mode' => 'aus', 'ip' => '', 'alexa_token' => ''),
                            'liste' => array(), 'frei' => array()),
        'schluessel' => array(
            'tts' => sicherung_block(array('mode', 'ip', 'alexa_token'), array()) + array(
                'sichern' => function ($w) { if (is_array($w)) { unset($w['alexa_token']); } return $w; }),
            'liste' => sicherung_liste(array('name', 'wert', 'kennwort'), 'name'),
            'frei' => sicherung_offen(),
            'zugang' => sicherung_block(array('kennwort')),
            'spaet' => array('fehlt' => 'behalten'),
            'werk' => array('fehlt' => 'vorgabe'),
        ),
        'alt' => array('altschluessel'),
        'nie_aus_datei' => array('vermerk'),
        'teile' => array('zugang' => function ($w) {
            if (!is_array($w) || !isset($w['kennwort']) || !is_string($w['kennwort'])) { return array(null, array('zugang ungueltig')); }
            return array(array('kennwort' => $w['kennwort']), array());
        }),
        'teile_jetzt' => array('zugang' => function () use ($GEHEIM) { return array('kennwort' => $GEHEIM); }),
        'pruefen' => function ($k, $w) {
            if ($k === 'takt') { return (is_int($w) && $w >= 10 && $w <= 3600) ? '' : 'takt ausserhalb'; }
            if ($k === 'thema') { return (is_string($w) && preg_match('/^[a-z]{1,20}\z/', $w)) ? '' : 'thema unzulaessig'; }
            if ($k === 'aktionstoken') { return (is_string($w) && preg_match('/^[a-z0-9]{0,64}\z/', $w)) ? '' : 'token unzulaessig'; }
            if ($k === 'tts') { return (is_array($w) && isset($w['mode']) && in_array($w['mode'], array('aus', 'musicserver'), true)) ? '' : 'tts unzulaessig'; }
            if ($k === 'liste') { return is_array($w) ? '' : 'liste unzulaessig'; }
            return '';
        },
        'quer' => function ($neu) { return ($neu['takt'] === 13) ? array(array('takt', 'takt 13 geht nicht mit thema')) : array(); },
        'uebersetzer' => function ($d) {
            return (isset($d['_hinweis']) && is_string($d['_hinweis']) && strpos($d['_hinweis'], 'Selbsttest-Sicherung') === 0) ? $d : null;
        },
        'speichern' => function ($neu) use (&$gespeichert) { $gespeichert[] = $neu; return true; },
        'nachziehen' => function () { return array('Dienst neu gestartet.'); },
        't' => function ($s) { return $s . ' [%s]'; },
    );
    $jetzt = array('aktionstoken' => $TOK, 'thema' => 'alt', 'takt' => 120, 'ein' => 1, 'spaet' => 0, 'werk' => 7,
                   'tts' => array('mode' => 'musicserver', 'ip' => '192.168.1.5', 'alexa_token' => 'Sprech0123456789'),
                   'liste' => array(array('name' => 'a', 'wert' => 'x', 'kennwort' => $GEHEIM)), 'frei' => array('z1' => 1),
                   'vermerk' => 'v');
    $b['jetzt'] = function () use ($jetzt) { return $jetzt; };

    // --- Bauen: Kopf, Inhalt, keine Sprechtoken, keine Vermerke ---
    $daten = sicherung_bauen($b, $jetzt, array('zugang' => array('kennwort' => $GEHEIM)));
    $pruef('kopf plugin', $daten['_plugin'], 'LoxBerry-Plugin-Selbsttest');
    $pruef('kopf fassung', $daten['_fassung'], '9.8.7');
    $pruef('kopf reihenfolge', array_slice(array_keys($daten), 0, 4), array('_hinweis', '_plugin', '_fassung', '_stand'));
    $pruef('token drin', $daten['aktionstoken'], $TOK);
    $pruef('zugang drin', $daten['zugang']['kennwort'], $GEHEIM);
    $pruef('sprechtoken nicht drin', isset($daten['tts']['alexa_token']), false);
    $pruef('vermerk nicht drin', isset($daten['vermerk']), false);
    $pruef('keine warnung', isset($daten['_warnung']), false);
    $js = sicherung_json($daten);
    $pruef('json', is_string($js) && $js !== '', true);

    // --- Unveraendert zurueck ---
    $r = sicherung_lesen($b, $js, $jetzt);
    $pruef('eigen ok', $r['ok'], true);
    $pruef('eigen kennzeichen', $r['eigen'], 'kennzeichen');
    $pruef('eigen anzahl', $r['anzahl'], 10);
    $pruef('eigen nichts verworfen', $r['verworfen'], array());
    $pruef('eigen thema', $r['neu']['thema'], 'alt');
    $pruef('eigen zugang', $r['teile']['zugang']['kennwort'], $GEHEIM);
    $pruef('eigen fassung datei', $r['fassung_datei'], '9.8.7');
    $pruef('eigen vermerk kommt nicht aus der datei', $r['neu']['vermerk'] ?? 'fehlt', 'fehlt');

    // --- Regel 2: Unbekanntes oben, im Block, in der Liste ---
    $d2 = $daten;
    $d2['zz_oben'] = 'boese';
    $d2['tts']['zz_tief'] = 'boese';
    $d2['liste'][0]['zz_zeile'] = 'boese';
    $d2['frei']['zz_offen'] = 2;
    $r = sicherung_lesen($b, json_encode($d2), $jetzt);
    $pruef('r2 ok', $r['ok'], true);
    $pruef('r2 namen (Reihenfolge der Datei)', $r['verworfen'], array('tts.zz_tief', 'liste[1].zz_zeile', 'zz_oben'));
    $pruef('r2 tts ohne', array_key_exists('zz_tief', $r['neu']['tts']), false);
    $pruef('r2 zeile ohne', array_key_exists('zz_zeile', $r['neu']['liste'][0]), false);
    $pruef('r2 oben ohne', array_key_exists('zz_oben', $r['neu']), false);
    $pruef('r2 offen bleibt', $r['neu']['frei']['zz_offen'], 2);
    $pruef('r2 kein wert in namen', strpos(implode(',', $r['verworfen']), 'boese'), false);
    $d2t = $daten;
    $d2t['zugang']['zz_teil'] = 'boese';
    $d2t['zugang']['kennwort'] = '';
    $r = sicherung_lesen($b, json_encode($d2t), $jetzt);
    $pruef('r2 im teil verworfen', $r['verworfen'], array('zugang.zz_teil'));
    $pruef('r3 im teil: bisheriges kennwort bleibt', array($r['teile']['zugang']['kennwort'], $r['leer_behalten']),
           array($GEHEIM, array('zugang.kennwort')));
    $d2b = $daten;
    $d2b["zz\nZEILE;X=1"] = 1;
    $r = sicherung_lesen($b, json_encode($d2b), $jetzt);
    $pruef('r2 name bereinigt', $r['verworfen'], array('zz?ZEILE?X?1'));

    // --- Regel 3: leer ---
    $d3 = $daten;
    $d3['aktionstoken'] = '';
    $d3['tts']['ip'] = '';
    $d3['liste'][0]['kennwort'] = '';
    $r = sicherung_lesen($b, json_encode($d3), $jetzt);
    $pruef('r3 ok', $r['ok'], true);
    $pruef('r3 token bleibt', $r['neu']['aktionstoken'], $TOK);
    $pruef('r3 ip bleibt', $r['neu']['tts']['ip'], '192.168.1.5');
    $pruef('r3 kennwort der zeile bleibt (zuordnung name)', $r['neu']['liste'][0]['kennwort'], $GEHEIM);
    $pruef('r3 namen', $r['leer_behalten'], array('aktionstoken', 'tts.ip', 'liste[1].kennwort'));
    $leer = $jetzt;
    $leer['aktionstoken'] = '';
    $leer['tts']['ip'] = '';
    $r = sicherung_lesen($b, json_encode($d3), $leer);
    $pruef('r3 beidseits leer still', $r['leer_behalten'], array('liste[1].kennwort'));
    $pruef('r3 beidseits leer bleibt leer', $r['neu']['aktionstoken'], '');
    $d3b = $daten;
    $d3b['liste'][0]['name'] = 'b';
    $d3b['liste'][0]['kennwort'] = '';
    $r = sicherung_lesen($b, json_encode($d3b), $jetzt);
    $pruef('r3 ohne gegenstueck leer', $r['neu']['liste'][0]['kennwort'], '');
    $d3c = $daten;
    $d3c['liste'] = array();
    $r = sicherung_lesen($b, json_encode($d3c), $jetzt);
    $pruef('r3 leere liste wird uebernommen', $r['neu']['liste'], array());
    $pruef('leer bleibt: 0 ist kein leerer wert', sicherung_leer_bleibt('', 0), true);
    $pruef('leer bleibt: null', sicherung_leer_bleibt('', null), false);
    $pruef('leer bleibt: feld', sicherung_leer_bleibt('', array(1)), false);
    $pruef('leer bleibt: 0 in der datei', sicherung_leer_bleibt(0, 'x'), false);

    // --- Regel 4: halb gueltig, alles gesammelt ---
    $d4 = $daten;
    $d4['takt'] = 5;
    $d4['thema'] = 'A B';
    $d4['zz_oben'] = 1;
    $r = sicherung_lesen($b, json_encode($d4), $jetzt);
    $pruef('r4 abgewiesen', $r['ok'], false);
    $pruef('r4 neu null', $r['neu'], null);
    $pruef('r4 beide gesammelt', count($r['beanstandet']), 2);
    $pruef('r4 teile leer', $r['teile'], array());
    $n = count($gespeichert);
    $r = sicherung_zurueckspielen($b, json_encode($d4));
    $pruef('r4 nichts gespeichert', count($gespeichert), $n);
    $d4b = $daten;
    $d4b['takt'] = 13;
    $r = sicherung_lesen($b, json_encode($d4b), $jetzt);
    $pruef('r4 querpruefung', $r['beanstandet'], array(array('name' => 'takt', 'text' => 'takt 13 geht nicht mit thema')));

    // --- Regel 1: Kennzeichen ---
    $d1 = $daten;
    $d1['_plugin'] = 'octopus';
    $r = sicherung_lesen($b, json_encode($d1), $jetzt);
    $pruef('r1 fremd', array($r['ok'], $r['fremd'], $r['kennzeichen_datei']), array(false, 'kennzeichen', 'octopus'));
    $d1['_plugin'] = array('x');
    $r = sicherung_lesen($b, json_encode($d1), $jetzt);
    $pruef('r1 fremd ohne namensform', array($r['ok'], $r['kennzeichen_datei']), array(false, '?'));
    $d1 = $daten;
    unset($d1['_plugin']);
    $r = sicherung_lesen($b, json_encode($d1), $jetzt);
    $pruef('r1 ohne', array($r['ok'], $r['fremd']), array(false, 'ohne'));
    $d1['_hinweis'] = 'Selbsttest-Sicherung von frueher';
    $r = sicherung_lesen($b, json_encode($d1), $jetzt);
    $pruef('r1 alt erkannt', array($r['ok'], $r['eigen']), array(true, 'uebersetzt'));
    $d1 = $daten;
    $d1['_plugin'] = 'selbsttest';
    $r = sicherung_lesen($b, json_encode($d1), $jetzt);
    $pruef('r1 frueheres kennzeichen', array($r['ok'], $r['eigen']), array(true, 'frueher'));
    $r = sicherung_lesen($b, '[1,2]', $jetzt);
    $pruef('form liste', $r['fremd'], 'form');
    $r = sicherung_lesen($b, 'kein json', $jetzt);
    $pruef('form text', $r['fremd'], 'form');
    $r = sicherung_lesen($b, str_repeat(' ', SICHERUNG_MAX_BYTE + 1), $jetzt);
    $pruef('zu gross', $r['fremd'], 'gross');

    // --- Fehlende Schluessel ---
    $d5 = $daten;
    unset($d5['spaet'], $d5['werk'], $d5['ein']);
    $r = sicherung_lesen($b, json_encode($d5), $jetzt);
    $pruef('fehlt pflicht', array($r['ok'], $r['beanstandet'][0]['name']), array(false, 'ein'));
    $d5['ein'] = 0;
    $r = sicherung_lesen($b, json_encode($d5), $jetzt);
    $pruef('fehlt behalten', array($r['ok'], $r['neu']['spaet'], $r['fehlt_behalten']), array(true, 0, array('spaet')));
    $pruef('fehlt vorgabe', array($r['neu']['werk'], $r['fehlt_vorgabe']), array(5, array('werk')));
    $pruef('alt still', sicherung_lesen($b, json_encode($daten + array('altschluessel' => 1)), $jetzt)['verworfen'], array());
    $nurkopf = array('_plugin' => 'LoxBerry-Plugin-Selbsttest');
    $r = sicherung_lesen($b, json_encode($nurkopf), $jetzt);
    $pruef('nur kopf abgewiesen', $r['ok'], false);

    // --- Zurueckspielen, Meldungen, Protokoll: keine Werte ---
    $prot = array();
    $b['log'] = function ($z) use (&$prot) { $prot[] = $z; };
    $r = sicherung_zurueckspielen($b, json_encode($d3 + array('zz_oben' => $GEHEIM)));
    $pruef('zurueck gespeichert', $r['gespeichert'], true);
    $pruef('zurueck dienst', $r['dienst'], array('Dienst neu gestartet.'));
    $m = sicherung_meldungen($b, $r);
    $alles = implode("\n", array_merge($m['fehler'], $m['hinweise'], $prot));
    $pruef('meldung ohne token', strpos($alles, $TOK), false);
    $pruef('meldung ohne kennwort', strpos($alles, $GEHEIM), false);
    $pruef('meldung nennt verworfen', strpos($alles, 'zz_oben') !== false, true);
    $pruef('meldung nennt leer', strpos($alles, 'tts.ip') !== false, true);
    $pruef('protokoll eine zeile', count($prot), 1);
    $r = sicherung_lesen($b, json_encode($d4), $jetzt);
    $m = sicherung_meldungen($b, $r);
    $pruef('meldung abgelehnt', count($m['fehler']), 1);

    // --- Selbstpruefung beim Sichern ---
    $kaputt = $jetzt;
    $kaputt['takt'] = 1;
    $daten_k = sicherung_bauen($b, $kaputt);
    $pruef('warnung bei eigener unbrauchbarer', isset($daten_k['_warnung']) && strpos($daten_k['_warnung'], 'takt') !== false, true);
    $pruef('warnung ohne wert', isset($daten_k['_warnung']) && strpos($daten_k['_warnung'], $TOK) === false, true);

    // --- Beschreibung, Fassung, Texte ---
    $pruef('beschreibung ohne kennzeichen', sicherung_beschreibung_mangel(array('vorgaben' => array('a' => 1))), array('kennzeichen'));
    $pruef('fassung ohne quelle', sicherung_fassung_lesen(array()), '');
    $pruef('namensform', array(sicherung_namensform('a.b-c_1'), sicherung_namensform("a\n"), sicherung_namensform('')), array(true, false, false));
    $pruef('schluessel zahl', count(sicherung_sprachschluessel()), 19);
    $pruef('schluessel eindeutig', count(array_unique(sicherung_sprachschluessel())), 19);
    return array($faelle, $fehl, $zeilen);
}

}   // Ende: if (!defined('SICHERUNG_FASSUNG'))

/* Kommandozeile: php sicherung.php --selbsttest (nur, wenn diese Datei selbst gestartet wurde). */
if (PHP_SAPI === 'cli' && isset($argv) && is_array($argv) && isset($argv[0])
    && realpath($argv[0]) === realpath(__FILE__) && in_array('--selbsttest', $argv, true)) {
    list($sicherung_n, $sicherung_f, $sicherung_z) = sicherung_selbsttest();
    foreach ($sicherung_z as $sicherung_zz) { echo $sicherung_zz, "\n"; }
    echo 'Rechenkern sicherung.php ' . SICHERUNG_FASSUNG . ': ' . $sicherung_n . ' Faelle geprueft, ' . $sicherung_f . " Fehlschlaege.\n";
    exit($sicherung_f === 0 ? 0 : 1);
}
