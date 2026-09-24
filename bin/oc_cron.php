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
 * Mit --mqtt-leeren (nur aus uninstall/uninstall): die zurueckbehaltenen
 * MQTT-Themen der Linie leeren und beim Broker nachlesen, sonst nichts.
 *
 * Laeuft ueber die Kommandozeile. cron/cron.01min leitet die Ausgabe nach
 * /dev/null - hier steht deshalb nur eine Zeile auf stdout, und alles,
 * was jemand spaeter lesen soll, geht ueber oc_log() in die Logdatei.
 *
 * Bis 1.1.3 stand hier, der Cron leite die Ausgabe in die Logdatei um.
 * Das war falsch, und zwanzig Zeilen weiter unten stand in derselben
 * Datei das Richtige. Ein Widerspruch in der eigenen Beschreibung ist
 * eine Fehlerquelle: der einzige Abbruch, der gar nichts tut, schrieb
 * seine Begruendung nach STDERR und damit ins Nichts.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Die Bibliothek: welche Lage gilt, entscheidet der eigene Ablageort, nicht
 * die Reihenfolge der Versuche. Installiert liegt diese Datei unter
 * <Wurzel>/bin/plugins/<ordner> und die Bibliothek unter
 * <Wurzel>/webfrontend/html/plugins/<ordner>, im ausgepackten Archiv unter
 * <archiv>/bin und <archiv>/webfrontend/html.
 *
 * Bis 1.1.11 standen hier drei Kandidaten in Reihe, zwei davon VOR der
 * eigenen Bibliothek: der Platzhalter des Installers, der im Archiv ein
 * RELATIVER Pfad bleibt und gegen das Arbeitsverzeichnis aufgeloest wird
 * (der Cron arbeitet aus /), und ohne Wurzel
 * /webfrontend/html/plugins/bin/oc_lib.php ab der Laufwerkswurzel. Was dort
 * lag, lief als Bibliothek - auch neben einer heilen eigenen (in WSL
 * gemessen, Pruefung-Spotpreis-Octopus-1.1.12, Faelle C11 bis C13). Dazu
 * suchte diese Datei die Wurzel selbst, ohne general.json und mit einem fest
 * verdrahteten Rueckfall; die Wurzel bestimmt jetzt allein oc_lbhome() in
 * oc_lib.php. Bauart bin/tb_cron.php aus Spotpreis-Tibber 0.9.19. */
if (basename(dirname(__DIR__)) === 'plugins' && basename(dirname(dirname(__DIR__))) === 'bin') {
    $oc_lib = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/oc_lib.php';
} else {
    $oc_lib = dirname(__DIR__) . '/webfrontend/html/oc_lib.php';   // Archiv
}
if (!is_file($oc_lib)) {
    fwrite(STDERR, "oc_lib.php nicht gefunden - Plugin unvollstaendig installiert?\n");
    exit(1);
}
require_once $oc_lib;

/* Ohne Wurzel, oder aus einem ausgepackten Archiv unterhalb einer Wurzel
 * (Archivmodus in oc_paths()): nichts holen, nichts senden, nichts
 * schreiben - und das VOR oc_config(), denn schon deren Selbstheilung
 * schreibt. Naeheres an oc_keine_wurzel_abbruch(). */
oc_keine_wurzel_abbruch('oc_cron.php');

/* --mqtt-leeren: aus der Deinstallation. Liest die Konfiguration ohne
 * Selbstheilung und schreibt nichts; gilt auch bei ausgeschaltetem Plugin,
 * denn zurueckbehalten steht, was je gesendet wurde. */
if (in_array('--mqtt-leeren', isset($argv) ? (array) $argv : array(), true)) {
    exit(oc_mqtt_leeren());
}

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
    /* Den Rueckgabewert ansehen: laesst sich der Marker nicht schreiben,
     * bleibt !is_file($oc_marke) bis Mitternacht wahr, und der Bericht
     * liefe bei JEDEM Minutenlauf erneut - samt Sprachansage. Genau die
     * Endlosschleife, die der Kommentar darueber verhindern will. Dann
     * lieber einmal melden und den Monatsbericht auslassen; er ist eine
     * Zusammenfassung, kein Messwert, der verloren ginge. */
    $oc_marke_ok = @touch($oc_marke);
    if (!$oc_marke_ok) {
        oc_log('Monatsbericht ausgelassen: der Erledigt-Marker liess sich nicht schreiben ('
            . $oc_marke . ') - sonst liefe er jede Minute erneut, samt Ansage');
    }
    // Marker der Vormonate wegraeumen, damit der Ordner nicht zulaeuft.
    foreach (glob(oc_datadir() . '/monatsbericht_*.done') ?: array() as $oc_alt) {
        if ($oc_alt !== $oc_marke && time() - (int) filemtime($oc_alt) > 40 * 86400) {
            @unlink($oc_alt);
        }
    }
    /* DEN VORMONAT AM SCHLUESSEL SUCHEN, NICHT DEN ERSTEN WEGWERFEN.
     *
     * Bis 1.1.3 stand hier array_shift() mit dem Kommentar "laufender
     * Monat raus". Der laufende Monat steht aber gar nicht in der Liste:
     * oc_month_compare() baut sie aus history.csv, und dort entsteht eine
     * Zeile erst um 23:50 fuer den abgelaufenen Tag (oder um 00:05 als
     * Nachtrag fuer den Vortag). Am 1. um 8 Uhr ist die juengste Zeile
     * also der LETZTE TAG DES VORMONATS - array_shift() warf damit genau
     * den Monat weg, um den es geht. Gemessen mit 31 Juli- und 31
     * Augustzeilen und ohne Septemberzeile: gewaehlt wurde 202607.
     *
     * Nebenbei behoben: stand nur ein einziger Monat in der Historie,
     * leerte array_shift() die Liste, $vm wurde null - der erste
     * Monatsbericht ueberhaupt fiel still aus, und der Marker war schon
     * gesetzt. */
    $oc_soll = date('Ym', strtotime('first day of last month'));
    $vm = null;
    if ($oc_marke_ok) {
        foreach (oc_month_compare(3) as $oc_m => $oc_e) {
            if ((string) $oc_m === $oc_soll) { $vm = $oc_e; break; }
        }
    }
    /* Nur melden, wenn wirklich die Tageswerte fehlen - nicht auch dann,
     * wenn der Marker schon gescheitert ist. Sonst stuenden zwei
     * Begruendungen fuer denselben Ausfall im Protokoll, und die zweite
     * waere falsch. */
    if ($oc_marke_ok && $vm === null) {
        oc_log('Monatsbericht: fuer ' . $oc_soll . ' liegen keine Tageswerte vor - nichts zu melden');
    }
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
   und das Plugin schickte jede Minute ALLE Werte. Das sind ab Werk 90
   und mit eingeschaltetem Stundenprofil bis zu 162 - nachgezaehlt an
   oc_werte(), nicht geschaetzt. Hier stand bis 1.1.3 die Zahl 150, die
   in keiner Einstellung herauskommt. Sie gehen statt
   dessen eigens hinaus, und zwar immer. */
/* Den Zaehler VOR oc_werte() weiterdrehen: oc_werte() liest den Stand,
 * es dreht ihn nicht selbst. Sonst stuende in der Meldung die Nummer des
 * vorigen Durchgangs. */
oc_zaehler();
/* Seit 1.1.11 keine Signatur mehr: oc_mqtt_publish() schickt nur die
 * geaenderten Themen und immer das Lebenszeichen (Merker mqtt_letzte.json).
 * Den vollen Satz gibt es halbstuendlich - ein neu gestarteter Broker
 * kennt die zurueckbehaltenen Werte sonst nicht mehr (Regeln/07). */
$beat = oc_tmpdir() . '/mqtt_beat';
$voll = !is_file($beat) || time() - filemtime($beat) > 1800;
if (oc_mqtt_publish($st, false, $voll) && $voll) {
    @touch($beat);
}
@unlink(oc_tmpdir() . '/mqtt_sig.txt');   // Rest bis 1.1.10

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
