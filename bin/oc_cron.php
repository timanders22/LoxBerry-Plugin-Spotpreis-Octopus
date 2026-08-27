<?php
/**
 * Octopus Dynamic - minuetlicher Lauf (aus cron/cron.01min)
 *
 * 1. Zustand aktualisieren (Preise-Cache 15 min, Zustand 4 min)
 * 2. Stuendliche Ansage und Meldung "Preise fuer morgen sind da"
 * 3. MQTT bei Aenderung, mindestens alle 30 Minuten
 * 4. Tageswerte kurz vor Mitternacht fortschreiben
 * 5. Monatsbericht am Monatsersten
 *
 * Laeuft ueber die Kommandozeile. Die Ausgabe leitet der Cron in die
 * Logdatei um - deshalb steht hier nur eine Zeile auf stdout.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/**
 * Den LoxBerry-Wurzelordner ohne festen Systempfad finden.
 *
 * DIESE DEFINITION MUSS VOR IHREM ERSTEN AUFRUF STEHEN.
 *
 * Bis 1.0.9 stand sie am DATEIENDE, in einem
 * "if (!function_exists(...)) { function ... }". Eine BEDINGTE
 * Funktionsdefinition hebt PHP nicht vor - beim Aufruf weiter unten gab es
 * die Funktion also noch nicht. Gemessen, unter 7.4 wie unter 8.4
 * wortgleich:
 *
 *     Fatal error: Uncaught Error: Call to undefined function
 *     lb_wurzel_ermitteln() in .../bin/oc_cron.php:25
 *
 * Getroffen wurde genau der Rueckfall, den der Kommentar darunter
 * verspricht: Platzhalter nicht ersetzt UND LBHOMEDIR nicht gesetzt. Und
 * weil cron.01min nach /dev/null umleitet, haette es niemand je gesehen.
 * Der Rueckfall war also nie eine Absicherung, sondern eine Erzaehlung.
 *
 * Sie traegt kein Plugin-Kuerzel und ist deshalb gegen eine
 * Doppeldefinition abgesichert - oc_lib.php bringt dieselbe Funktion mit,
 * und beide koennen im selben Prozess landen.
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

/* Die Bibliothek liegt im unangemeldeten Webbereich, weil der Endpunkt fuer
   Loxone sie ebenfalls braucht. Der Platzhalter wird bei der Installation
   ersetzt; die beiden Rueckfaelle greifen im Archiv und wenn ein
   Installationslauf den Platzhalter einmal nicht ersetzt hat. */
$oc_lib = 'REPLACELBPHTMLDIR/oc_lib.php';
if (!is_file($oc_lib)) {
    $oc_home = getenv('LBHOMEDIR');
    if (!$oc_home || !is_dir($oc_home)) {
        foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
            if ($k !== '' && is_dir($k)) { $oc_home = $k; break; }
        }
    }
    // Eigener Ablageort: <home>/bin/plugins/<ordner>
    $oc_ordner = basename(dirname(__FILE__));
    $oc_lib = $oc_home . '/webfrontend/html/plugins/' . $oc_ordner . '/oc_lib.php';
}
if (!is_file($oc_lib)) {
    $oc_lib = dirname(__DIR__) . '/webfrontend/html/oc_lib.php';   // Archiv
}
if (!is_file($oc_lib)) {
    fwrite(STDERR, "oc_lib.php nicht gefunden - Plugin unvollstaendig installiert?\n");
    exit(1);
}
require_once $oc_lib;

$cfg = oc_config();
if (empty($cfg['enabled'])) {
    echo "AUS\n";
    exit(0);
}

/* ==================================================================
 * Cron-Sperre - Pflicht, wo der Cron ins Netz geht
 * ==================================================================
 *
 * Der Takt ist eine Minute. Was ein Lauf im schlimmsten Fall braucht,
 * steht in den Zeitschranken der einzelnen Abrufe:
 *
 *     Kraken-Token   20 s
 *     Kraken-Preise  25 s   (bei abgelaufenem Token noch einmal 20 + 25 s)
 *     CO2            15 s
 *     PV-Prognose    12 s
 *     Speicherstand  12 s
 *     Verbrauch      12 s
 *     Ansage         10 s
 *                   ------
 *                   106 s, mit dem Wiederholversuch bis 151 s
 *
 * Zwei bis drei Laeufe koennen sich also ueberlappen. Sie schreiben in
 * dieselben Zwischenspeicher, dieselben said_-Merker und dieselbe
 * Signaturdatei - und der teuerste Fall ist der, in dem zwei Laeufe sich
 * gleichzeitig bei Kraken anmelden.
 *
 * LOCK_NB, nicht warten: ein wartender Lauf waere beim naechsten Takt
 * ohnehin ueberholt. Wer nicht drankommt, geht weg und sagt es einmal.
 *
 * Die Variable bleibt bis zum Programmende bestehen - gaebe man sie frei,
 * loeste sich die Sperre mitten im Lauf auf.
 */
$oc_sperrdatei = oc_tmpdir() . '/cron.lock';
$oc_sperre = @fopen($oc_sperrdatei, 'c');
if ($oc_sperre === false) {
    // Kein Grund abzubrechen: ohne Sperre laufen heisst laufen wie bisher.
    oc_log_if_changed('cronsperre', 'Die Sperrdatei ' . $oc_sperrdatei
        . ' liess sich nicht anlegen - der Lauf geht ohne Sperre weiter.');
} elseif (!@flock($oc_sperre, LOCK_EX | LOCK_NB)) {
    oc_log_if_changed('cronsperre', 'Ein vorheriger Lauf ist noch nicht fertig - '
        . 'dieser Durchgang wird uebersprungen.');
    fclose($oc_sperre);
    echo "BELEGT\n";
    exit(0);
}

$st = oc_state();
oc_announce_check($st);

/* ---- Monatsbericht am Monatsersten, ab 8 Uhr, genau einmal ----
 *
 * Bis 0.9.1 stand hier:
 *
 *     if ((int) date('j') === 1 && date('H:i') === '08:05') {
 *
 * Das trifft ein Zeitfenster von genau 60 Sekunden. Nachgestellt mit 1000
 * simulierten Monaten und verschieden grossem Cron-Verzug:
 *
 *     Verzug bis 59 s   ->  0 % Ausfall
 *     Verzug bis 65 s   ->  6,5 % der Monate ohne Bericht
 *     Verzug bis 90 s   ->  22 % der Monate ohne Bericht
 *
 * Ein Verzug von ein paar Sekunden schadet also NICHT - der um fuenf
 * Sekunden verspaetete Lauf liegt immer noch bei 08:05:05. Gefaehrlich wird
 * erst, was laenger als eine volle Minute dauert oder den Lauf ganz
 * ausfallen laesst:
 *
 *   - der LoxBerry startet gerade neu oder ist aus,
 *   - ein Update laeuft,
 *   - das Plugin stand in dieser einen Minute auf "aus" (die Pruefung auf
 *     enabled weiter oben beendet das Skript, bevor es hierher kommt).
 *
 * In allen drei Faellen fiel der Bericht fuer den ganzen Monat aus, ohne
 * zweiten Versuch. Deshalb jetzt: 1. des Monats, ab 8 Uhr, und ein Marker,
 * der sagt, dass es schon erledigt ist.
 *
 * WO der Marker liegt, ist nicht gleichgueltig. Der naheliegende Ort
 * /tmp scheidet aus: oc_paths()['tmp'] zeigt auf /tmp/<ordner>, und /tmp
 * ist auf dem LoxBerry eine Ramdisk. Startet der Rechner am Ersten nach
 * dem Bericht neu, waere der Marker fort - und der naechste Lauf meldete
 * den Monatsbericht ein zweites Mal, samt Sprachansage. Der Marker gehoert
 * in den Datenordner, der den Neustart uebersteht.
 */
$oc_marke = oc_datadir() . '/monatsbericht_' . date('Ym') . '.done';
if ((int) date('j') === 1 && (int) date('G') >= 8 && !is_file($oc_marke)) {
    // Marker VOR dem Bericht setzen. Bricht die Auswertung ab, ist der
    // Bericht fuer diesen Monat verloren - eine Endlosschleife aus
    // Fehlversuchen mit Sprachansage waere schlimmer.
    @touch($oc_marke);
    // Marker der Vormonate wegraeumen, damit der Ordner nicht zulaeuft.
    foreach (glob(oc_datadir() . '/monatsbericht_*.done') ?: array() as $oc_alt) {
        if ($oc_alt !== $oc_marke && time() - (int) filemtime($oc_alt) > 40 * 86400) {
            @unlink($oc_alt);
        }
    }
    $mc = oc_month_compare(2);
    array_shift($mc);                 // laufender Monat raus, wir wollen den Vormonat
    $vm = $mc ? reset($mc) : null;
    if ($vm) {
        oc_log('MONATSBERICHT ' . $vm['monat'] . ': dynamisch (gewichtet) ' . $vm['dynp']
            . ' ct, fest ' . $vm['fix'] . ' ct -> '
            . ($vm['diff'] >= 0 ? 'dynamisch waere guenstiger gewesen um ' : 'fester Tarif war guenstiger um ')
            . abs($vm['diff']) . ' ct/kWh (' . abs($vm['euro']) . ' EUR)');
        if (!empty($cfg['notify']['audio'])) {
            $t = str_replace(array('%DYN%', '%FIX%'),
                array(oc_num($vm['dynp'], 1), oc_num($vm['fix'], 1)),
                oc_t('ANSAGE.MONATSBERICHT'));
            $t .= ' ' . str_replace('%D%', oc_num(abs($vm['diff']), 1),
                oc_t($vm['diff'] >= 0 ? 'ANSAGE.MONAT_DYN_BESSER' : 'ANSAGE.MONAT_FIX_BESSER'));
            oc_say($t);
        }
    }
}

/* ---- MQTT: bei Aenderung, mindestens alle 30 Minuten ----
   ann und ptest gehoeren in die Signatur: sie wechseln minutengenau, und
   ohne sie wuerde das Meldefenster erst beim naechsten Stundenschlag
   veroeffentlicht.

   DIE LEBENSZEICHEN GEHOEREN NICHT IN DIE SIGNATUR. status/ts traegt die
   Uhrzeit und status/zaehler eine laufende Nummer - beide aendern sich bei
   JEDEM Lauf. Stuenden sie in der Signatur, waere die Bremse wirkungslos
   und das Plugin schickte jede Minute alle 150 Werte. Sie gehen statt
   dessen eigens hinaus, und zwar immer. */
/* Den Zaehler VOR oc_werte() weiterdrehen: oc_werte() liest den Stand,
 * es dreht ihn nicht selbst. Sonst stuende in der Meldung die Nummer des
 * vorigen Durchgangs. */
oc_zaehler();
$werte = oc_werte($st);
$fuer_sig = array();
foreach ($werte as $k => $v) {
    if (strpos((string) $k, 'status/') === 0) { continue; }
    $fuer_sig[$k] = $v;
}
$sig = json_encode($fuer_sig);
$sigf = oc_tmpdir() . '/mqtt_sig.txt';
$beat = oc_tmpdir() . '/mqtt_beat';
$alt = is_file($sigf) ? (string) @file_get_contents($sigf) : '';
if ($sig !== $alt || !is_file($beat) || time() - filemtime($beat) > 1800) {
    if (oc_mqtt_publish($st)) {
        @file_put_contents($sigf, $sig);
        @touch($beat);
    }
} else {
    // Nichts Neues - aber das Lebenszeichen geht trotzdem hinaus.
    oc_mqtt_publish(null, true);
}

/* ---- Die Glocke des LoxBerry ---- */
oc_notify_pruefen($st);

/* ---- Tageswerte sichern ----
 *
 * Drei Zeitpunkte statt einem:
 *   ab 23:40  den fertigen Tageswert beiseitelegen (ueberschreibt sich)
 *   ab 23:50  ihn in die Historie schreiben, wie bisher
 *   ab 00:05  den VORTAG nachtragen, falls er fehlt
 *
 * Bis 1.0.9 gab es nur den mittleren Schritt. War der LoxBerry in diesen
 * zehn Minuten aus, im Neustart oder im Update, war der Tag fuer immer
 * verloren - und die Historie ist die Grundlage des ganzen
 * Kostenvergleichs. Es ist dieselbe Klasse Fehler, die fuer den
 * Monatsbericht schon einmal behoben wurde. */
$std = (int) date('G');
$min = (int) date('i');
if ($std === 23 && $min >= 40) {
    oc_tagesstand_merken($st);
}
if ($std === 23 && $min >= 50) {
    oc_history_add($st);
}
if ($std === 0 && $min >= 5) {
    oc_history_nachtrag();
}

/* ---- Alte Zwischendateien aufraeumen ---- */
if (rand(0, 60) === 0) {
    foreach (glob(oc_datadir() . '/demo_*.json') ?: array() as $f) {
        if (time() - (int) filemtime($f) > 10 * 86400) { @unlink($f); }
    }
    // Die Wiederholsperren des Endpunkts: nach einem Tag sind sie erledigt.
    foreach (glob(oc_tmpdir() . '/sperre_*') ?: array() as $f) {
        if (time() - (int) filemtime($f) > 86400) { @unlink($f); }
    }
}

echo "OK\n";
