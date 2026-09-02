<?php
/**
 * Octopus Dynamic - Endpunkt fuer den Miniserver
 *
 * Liegt bewusst im unangemeldeten Bereich, damit Loxone ihn ohne
 * Zugangsdaten aufrufen kann - aber jeder Aufruf braucht das Token aus den
 * Einstellungen. Verglichen wird mit hash_equals, also in gleichbleibender
 * Zeit; ein einfaches == liesse sich ueber die Antwortzeit Zeichen fuer
 * Zeichen erraten.
 *
 * Aufruf:
 *   /plugins/<Ordner>/index.php?token=<TOKEN>&aktion=status
 *
 * Aktionen:
 *   status        eine Textzeile OCTOPUS;SCHLUESSEL=WERT;... (Vorgabe)
 *   json          kompletter Zustand als JSON, inklusive aller Viertelstunden
 *   debug         alle Viertelstunden als Klartext
 *   refresh       Preise sofort neu abrufen, dann status
 *   say           Testansage abspielen
 *   saytomorrow   Testansage "Preise fuer morgen" abspielen
 *   ptest         Test-Pushnachricht anstossen (setzt ptest fuer 5 Minuten)
 *
 * Dazu, unabhaengig von 'aktion':
 *   ?selftest=1   beantwortet NUR die Tokenfrage und loest nichts aus
 *
 * MQTT ist der Regelweg. Dieser Endpunkt ist die Rueckfallebene fuer alle,
 * die den MQTT-Gateway nicht nutzen wollen - und die Stelle, an der Loxone
 * eine Ansage oder einen Push-Test ausloest.
 *
 * ------------------------------------------------------------------
 * ZU DEN AUSLOESENDEN AUFRUFEN (say, saytomorrow, ptest, refresh)
 * ------------------------------------------------------------------
 * Sie laufen ueber GET, weil Loxone nur GET kann. Geschuetzt sind sie
 * durch das Token - und seit 1.1.0 zusaetzlich durch eine WIEDERHOLSPERRE.
 *
 * Der Grund: ein virtueller Ausgang, der versehentlich in einer Schleife
 * haengt, hat frueher den Music Server sekuendlich sprechen lassen und bei
 * 'refresh' sekuendlich eine vollstaendige Kraken-ANMELDUNG mit E-Mail und
 * Passwort ausgeloest - der erzwungene Abruf uebergeht den Token-Cache.
 * Die Sperre laesst den Aufruf nicht scheitern, sondern beantwortet ihn
 * mit dem letzten Stand und sagt, dass sie gegriffen hat.
 *
 * 'say' uebergeht bewusst den Schalter "Ansage" aus den Einstellungen:
 * dieser Aufruf IST die von Loxone gewollte Ansage, nicht die stuendliche.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

/* Erst fragen, dann laden - und wenn die Bibliothek fehlt, eine LESBARE
 * Antwort geben.
 *
 * Bis 1.1.3 stand hier ein blankes require_once. Gemessen gegen einen
 * laufenden Webserver in installierter Lage: fehlt oc_lib.php oder
 * planer.php, antwortet der Endpunkt mit HTTP 500 und einem Rumpf von
 * 0 Byte. Das trifft nach einem abgebrochenen Upgrade zu, und der
 * Miniserver behaelt dann still seinen letzten Wert - in der App sieht
 * alles normal aus. Die durchsuchten Pfade gehoeren in die Antwort, sonst
 * sucht man sie am Geraet zusammen. */
$oc_lib = __DIR__ . '/oc_lib.php';
if (!is_file($oc_lib)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'OCTOPUS;OK=0;ERR=BIBLIOTHEK_FEHLT;PFAD=' . $oc_lib . "\n";
    exit;
}
require_once $oc_lib;
if (!function_exists('oc_config')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'OCTOPUS;OK=0;ERR=BIBLIOTHEK_UNVOLLSTAENDIG;PFAD=' . $oc_lib . "\n";
    exit;
}

function oc_ende($code, $text)
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $text . "\n";
    exit;
}

/**
 * Wiederholsperre. Rueckgabe: true = darf laufen.
 *
 * Kein Zaehler, kein Verlauf - eine Datei mit einem Zeitstempel. Wer sie
 * innerhalb der Sperrzeit wieder anfasst, bekommt false.
 *
 * EIN SCHUTZ, DER OFFEN AUSFAELLT, IST KEINER. Bis 1.1.3 wurde der
 * Rueckgabewert von file_put_contents verworfen. Gemessen mit einem
 * /tmp/octopus, das sich nicht anlegen liess: dreimal hintereinander
 * PTEST;OK=1;GESPERRT=0, weil die Sperrdatei nie entstand - der Schutz
 * gegen den schleifenden virtuellen Ausgang (sekuendliche Ansage,
 * sekuendliche Kraken-Anmeldung bei 'refresh') war vollstaendig weg, und
 * nichts sagte es. Mit beschreibbarem Ordner: 0 / 1 / 1.
 *
 * Laesst sich die Sperre nicht schreiben, gilt der Aufruf als GESPERRT und
 * es wird protokolliert. Fail closed: lieber eine Ansage zu wenig als eine
 * Schleife, die den Music Server sekuendlich sprechen laesst.
 */
function oc_sperre_frei($name, $sekunden)
{
    $f = oc_tmpdir() . '/sperre_' . preg_replace('/[^a-z0-9_]/i', '', (string) $name);
    if (is_file($f) && time() - (int) filemtime($f) < (int) $sekunden) { return false; }
    if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
    if (@file_put_contents($f, (string) time()) === false) {
        oc_log_if_changed('sperre', 'Wiederholsperre nicht schreibbar (' . $f
            . ') - der Aufruf wird abgewiesen, damit der Schutz nicht offen ausfaellt');
        return false;
    }
    return true;
}

/* NUR LESEN, fuer den ganzen Aufruf.
 *
 * Bis 1.1.3 rief diese Datei oc_config(), und die Funktion holt eine
 * fehlende oder leere Konfiguration aus der Zweitschrift zurueck. Gemessen
 * an der LoxBerry-Attrappe: ein einziger Aufruf OHNE Token, korrekt mit
 * 403 beantwortet, hat config/plugins/octopus/octopus.json neu geschrieben
 * - mit dem Aktionstoken aus der Sicherung. Stand dort ein aelteres Token,
 * waren danach alle Adressen im Miniserver ungueltig.
 *
 * Der Schalter steht hier ganz oben und gilt fuer den ganzen Aufruf, nicht
 * nur fuer diese eine Zeile: oc_state() und die Themenbildung rufen
 * oc_config() ihrerseits weiter. Naeheres an oc_nur_lesen(). */
oc_nur_lesen(true);
$cfg = oc_config();

/* ---------- Selbsttest ----------
 *
 * Hausstandard: jeder Aktionsendpunkt beantwortet ?selftest=1. Er prueft
 * das Token GENAUSO wie jeder andere Aufruf, loest aber nichts aus - kein
 * Geraetekontakt, kein Schreibzugriff, keine Ansage, kein MQTT.
 *
 * Er steht VOR der Abschaltpruefung: gerade wenn das Plugin abgeschaltet
 * ist, will man wissen, ob wenigstens die Adresse und das Token stimmen.
 */
/* is_string vor der Umwandlung: ?selftest[]=1 erzeugte bis 1.1.3 die
 * Zeichenkette "Array" und dazu eine Warnung im Protokoll des Webservers -
 * und zwar VOR jeder Tokenpruefung, ein unangemeldeter Aufrufer konnte das
 * Protokoll also beliebig fuellen. */
if (isset($_GET['selftest']) && is_string($_GET['selftest']) && $_GET['selftest'] === '1') {
    $s = (string) $cfg['aktionstoken'];
    if ($s === '') {
        oc_ende(403, 'SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET');
    }
    $i = (isset($_GET['token']) && !is_array($_GET['token'])) ? (string) $_GET['token'] : '';
    if ($i === '' || !hash_equals($s, $i)) {
        oc_ende(403, 'SELFTEST;OK=0;ERR=TOKEN');
    }
    oc_ende(200, 'SELFTEST;OK=1;TOKEN=OK;AKTIV=' . (empty($cfg['enabled']) ? 0 : 1)
               . ';FASSUNG=' . (oc_version() !== '' ? oc_version() : '-')
               . ';PLANER=' . PLAN_FASSUNG);
}

/* ---------- Abweisungen in der Hausform ----------
 *
 * "OCTOPUS;OK=0;ERR=<Grund>" statt eines deutschen Satzes. Der Grund:
 * dieser Endpunkt spricht mit Maschinen. Loxone kann den Grund so mit
 * einer Befehlserkennung herausziehen, und die Hausprobe
 * mqtt_vollstaendig_pruefen.py erkennt daran, dass ein Aufruf ohne Token
 * wirklich abgewiesen wurde - vorher stand dort "Token falsch.", das
 * Werkzeug fand kein ERR=TOKEN und meldete, der Aufruf loese ohne Token
 * aus. Er tat es nicht; das Werkzeug konnte es nur nicht sehen.
 *
 * Der erklaerende Satz steht dahinter, durch Semikolon getrennt - fuer
 * den Menschen, der die Adresse in den Browser tippt. */
/* DIE ANFRAGE VOR DEM DIENST.
 *
 * Die Abschaltpruefung stand bis 1.1.3 hier, VOR der Tokenpruefung.
 * Gemessen mit enabled=0: jeder Aufruf bekam HTTP 503 ERR=AUS - auch der
 * ohne Token, auch der mit falschem Token, auch der mit unbekannter
 * Aktion. Zwei Folgen, beide unerwuenscht: wer sich vertippt hatte, suchte
 * den Fehler bei der Abschaltung statt beim Token, und der Betriebszustand
 * der Anlage ging unangemeldet nach aussen. Im Reiter Test standen dadurch
 * vier rote Kreuze bei den Sicherheitszeilen, obwohl die Abweisung ohne
 * Token tadellos funktionierte.
 *
 * Sie steht jetzt hinter Token und Aktionsliste, unmittelbar vor der
 * Wirkung - Hausstandard "Der Endpunkt prueft die Anfrage, bevor er den
 * Dienst prueft". Der Selbsttest oben bleibt davor: gerade wenn das
 * Plugin aus ist, will man wissen, ob Adresse und Token stimmen. */
$soll = (string) $cfg['aktionstoken'];
if ($soll === '') {
    oc_ende(403, 'OCTOPUS;OK=0;ERR=KEIN_TOKEN_EINGERICHTET;TEXT=Reiter Einstellungen '
               . 'aufrufen und einmal speichern - dann wird eines erzeugt.');
}
/* is_array ZUERST: ?token[]=x ergibt sonst die Zeichenkette "Array" und
 * eine Warnung im Protokoll des Webservers. Und der Leervergleich VOR
 * hash_equals: hash_equals('', '') ist true. */
$ist = (isset($_GET['token']) && !is_array($_GET['token'])) ? (string) $_GET['token'] : '';
if ($ist === '' || !hash_equals($soll, $ist)) {
    oc_ende(403, 'OCTOPUS;OK=0;ERR=TOKEN');
}

$erlaubt = array('status', 'json', 'debug', 'refresh', 'say', 'saytomorrow', 'ptest');
/* is_string, nicht !is_array: ?aktion[]=x wurde bis 1.1.3 stillschweigend
 * zu 'status' zurechtgebogen, statt abgewiesen zu werden. Was nicht ins
 * Muster passt, wird gemeldet - Hausstandard "Eingaben abweisen, nicht
 * stillschweigend zurechtbiegen". Fehlt der Parameter ganz, bleibt es bei
 * der Vorgabe 'status'; das ist keine falsche Eingabe, sondern keine. */
if (isset($_GET['aktion']) && !is_string($_GET['aktion'])) {
    oc_ende(400, 'OCTOPUS;OK=0;ERR=AKTION;ERLAUBT=' . implode(',', $erlaubt));
}
$aktion = isset($_GET['aktion']) ? (string) $_GET['aktion'] : 'status';

/* Die Kurzform ?ptest=1 als Zweitschreibweise.
 *
 * Das Haus prueft Aktionsendpunkte mit '?ptest=1&token=...' - so misst
 * mqtt_vollstaendig_pruefen.py, ob ein Aufruf OHNE Token wirklich nichts
 * ausloest. Dieses Plugin kannte nur '?aktion=ptest' und beantwortete die
 * Kurzform mit einer Statuszeile; das Werkzeug haette also gemessen, dass
 * nichts passiert - und recht behalten, ohne etwas geprueft zu haben.
 *
 * Die ausfuehrliche Form bleibt die dokumentierte; hier steht nur eine
 * zweite Schreibweise fuer dieselbe Sache, keine zweite Wirkung. */
if (isset($_GET['ptest']) && is_string($_GET['ptest'])
    && $_GET['ptest'] === '1' && !isset($_GET['aktion'])) {
    $aktion = 'ptest';
}
if (!in_array($aktion, $erlaubt, true)) {
    oc_ende(400, 'OCTOPUS;OK=0;ERR=AKTION;ERLAUBT=' . implode(',', $erlaubt));
}

/* ---------- Erst jetzt: laeuft der Dienst ueberhaupt? ----------
 * Die Begruendung fuer diese Stelle steht oben ueber der Tokenpruefung. */
if (empty($cfg['enabled'])) {
    oc_ende(503, 'OCTOPUS;OK=0;ERR=AUS;TEXT=Das Plugin ist in den Einstellungen abgeschaltet.');
}

/* ---------- Test-Pushnachricht ---------- */
if ($aktion === 'ptest') {
    if (!oc_sperre_frei('ptest', 10)) {
        oc_ende(200, 'PTEST;OK=1;DAUER=300;GESPERRT=1');
    }
    /* Den Rueckgabewert ansehen: bis 1.1.3 meldete diese Aktion OK=1, auch
     * wenn der Merker gar nicht geschrieben werden konnte. Loxone bekam
     * dann eine Erfolgsmeldung fuer eine Pushnachricht, die nie kommt, und
     * der Anwender suchte den Fehler im Benachrichtigungs-Baustein. */
    if (@file_put_contents(oc_tmpdir() . '/ptest', '1') === false) {
        oc_log('Test-Pushnachricht angefordert, aber der Merker liess sich nicht schreiben ('
            . oc_tmpdir() . '/ptest)');
        oc_ende(500, 'PTEST;OK=0;ERR=MERKER;TEXT=Der Merker liess sich nicht schreiben.');
    }
    /* SOFORT senden, nicht erst beim naechsten Cron-Lauf.
     *
     * Das Fenster des Merkers ist fuenf Minuten breit. Wer bis zum
     * naechsten Minutentakt wartet, verschenkt davon bis zu eine ganze -
     * und wenn die Sendebremse gerade greift, laege der Merker sogar bis
     * zum halbstuendlichen Lebenszeichen. Der Sinn des Knopfes ist, dass
     * es in Loxone SOFORT blinkt.
     *
     * Die Signaturdatei wird dabei verworfen, damit der naechste
     * Cron-Lauf den Merker auch wieder auf 0 meldet - sonst bliebe die
     * Eins stehen, bis sich sonst etwas aendert. */
    oc_weg(oc_tmpdir() . '/mqtt_sig.txt');
    oc_mqtt_publish();
    oc_log('Test-Pushnachricht angefordert (ptest=1 fuer 5 Minuten), sofort per MQTT gemeldet');
    oc_ende(200, 'PTEST;OK=1;DAUER=300;GESPERRT=0');
}

/* ---------- Testansagen ---------- */
if ($aktion === 'say' || $aktion === 'saytomorrow') {
    header('Content-Type: text/plain; charset=utf-8');
    /* 20 Sekunden: lang genug, dass eine Schleife nicht durchkommt, kurz
     * genug, dass zwei gewollte Ansagen hintereinander moeglich bleiben. */
    if (!oc_sperre_frei('say', 20)) {
        echo "SAY;OK=0;GESPERRT=1;TEXT=\n";
        exit;
    }
    $st = oc_state();
    $text = $aktion === 'saytomorrow' ? oc_tomorrow_text($st) : oc_announce_text($st);
    if ($text === '') { $text = oc_t('ANSAGE.TEST_LEER'); }
    $ok = oc_say($text);
    echo 'SAY;OK=' . ($ok ? 1 : 0) . ';GESPERRT=0;TEXT=' . oc_mqtt_wert_saeubern($text) . "\n";
    exit;
}

/* Der erzwungene Abruf ist der teuerste Aufruf ueberhaupt: er verwirft das
 * Kraken-Token und meldet sich neu an. 60 Sekunden Sperre - das ist immer
 * noch haeufiger, als der Cron ohnehin abruft. Gesperrt heisst NICHT
 * gescheitert: es kommt der letzte bekannte Stand, und die Zeile sagt es. */
$erzwungen = ($aktion === 'refresh');
$gesperrt = 0;
if ($erzwungen && !oc_sperre_frei('refresh', 60)) {
    $erzwungen = false;
    $gesperrt = 1;
}
$st = oc_state($erzwungen);

/* ---------- JSON ---------- */
if ($aktion === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    $st['werte'] = oc_werte($st);
    echo json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

/* ---------- Klartext-Auflistung ---------- */
if ($aktion === 'debug') {
    printf("QUELLE=%s  STAND=%s  SLOTS=%d  FEHLER=%s\n",
        $st['demo'] ? 'DEMO (simuliert, kein Octopus-Vertrag)' : 'Octopus (Kraken)',
        $st['stand'] ? date('d.m.Y H:i:s', $st['stand']) : '-',
        $st['slots_n'], $st['fehler'] !== '' ? $st['fehler'] : '-');
    printf("Jetzt %02d:%02d Uhr: %.3f ct/kWh brutto (netto %.3f) | Stunde %.3f | Rang %d von %d | Niveau %d\n",
        $st['stunde'], $st['minute'], $st['cur'], $st['cur_netto'], $st['cur_h'],
        $st['rank'], $st['n'], $st['level']);
    printf("Guenstigstes %d-Stunden-Fenster: ab %02d:%02d Uhr (in %d min), Schnitt %.3f ct\n",
        $st['fenster_len'], $st['fenster']['h'], $st['fenster']['m'],
        $st['fenster']['in'], $st['fenster']['ct']);
    if ($st['co2_ok']) {
        printf("CO2: jetzt %d g/kWh | sauberste Stunde %02d Uhr mit %d g | Schnitt %d g\n",
            $st['co2'], $st['co2_minh'], $st['co2_min'], $st['co2_avg']);
    }
    echo "\n";
    foreach (array('heute' => 'HEUTE', 'morgen' => 'MORGEN') as $k => $label) {
        echo "-- $label --\n";
        if (empty($st[$k]['slots'])) { echo "(keine Daten)\n\n"; continue; }
        foreach ($st[$k]['slots'] as $ts => $ct) {
            printf("%s  %8.3f ct/kWh\n", date('H:i', (int) $ts), $ct);
        }
        printf("Min %02d:%02d %.3f | Max %02d:%02d %.3f | Schnitt %.3f (%d Viertelstunden)\n\n",
            $st[$k]['minh'], $st[$k]['minm'], $st[$k]['minp'],
            $st[$k]['maxh'], $st[$k]['maxm'], $st[$k]['maxp'],
            $st[$k]['avg'], $st[$k]['n']);
    }
}

/* ---------- Eine Zeile fuer die Befehlserkennung ----------
 *
 * Der Schraegstrich der Lebenszeichen-Themen wird zum Unterstrich - genau
 * so, wie der MQTT-Gateway es auch macht. Damit heisst derselbe Wert auf
 * beiden Wegen gleich, und die Loxone-Vorlage passt zu beiden.
 *
 * Das Zahlenformat entscheidet die EINHEIT des Themas, nicht der PHP-Typ -
 * siehe oc_wert_formatieren(). */
$zeile = 'OCTOPUS';
if ($gesperrt) { $zeile .= ';GESPERRT=1'; }
$oc_info = oc_themen();
foreach (oc_werte($st) as $k => $v) {
    $zeile .= ';' . strtoupper(oc_thema_flach($k)) . '='
        . oc_wert_formatieren($k, $v, isset($oc_info[$k]) ? $oc_info[$k] : null);
}
echo $zeile . "\n";
