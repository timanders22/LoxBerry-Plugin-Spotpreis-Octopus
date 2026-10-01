<?php
/**
 * Octopus Dynamic - Bedienoberflaeche
 *
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Kostenvergleich | Test | Logdateien
 *
 * Die Fassungsnummer steht hier bewusst NICHT. Sie kommt aus der
 * Plugindatenbank von LoxBerry, siehe oc_version() in oc_lib.php.
 *
 * WICHTIG: LBWeb::lbheader() setzt SDK-Globals (unter anderem $cfg aus
 * general.json als stdClass) und wuerde gleichnamige Plugin-Variablen
 * ueberschreiben - deshalb tragen hier ALLE Variablen ein oc_-Praefix.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

/* ---- Bibliothek finden: installiert im html-Zweig, im Archiv daneben ----
 *
 * Welche Lage gilt, entscheidet der eigene Ablageort, nicht die Reihenfolge
 * der Versuche: liegt diese Datei unter <Wurzel>/webfrontend/htmlauth/
 * plugins/<ordner>, ist sie installiert, sonst liegt sie in einem
 * ausgepackten Archiv. Bis 1.1.11 wurden zwei Kandidaten der Reihe nach
 * probiert, der installierte VOR der eigenen Bibliothek - aus einem Archiv
 * unter / war das /html/plugins/htmlauth/oc_lib.php ab der Laufwerkswurzel,
 * und was dort lag, lief als Bibliothek (in WSL gemessen,
 * Pruefung-Spotpreis-Octopus-1.1.12, Fall C8; Bauart Spotpreis-Tibber
 * 0.9.19). */
$oc_ordner = basename(__DIR__);
if (basename(dirname(__DIR__)) === 'plugins' && basename(dirname(dirname(__DIR__))) === 'htmlauth') {
    $oc_kand = dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . $oc_ordner . '/oc_lib.php';
} else {
    $oc_kand = dirname(__DIR__) . '/html/oc_lib.php';
}
if (is_file($oc_kand)) { require_once $oc_kand; }
if (!function_exists('oc_config')) {
    echo '<p>oc_lib.php nicht gefunden. Das Plugin ist unvollstaendig installiert.</p>';
    exit;
}

/* DIE OBERFLAECHE RUFT NICHTS AB (O2, seit 1.1.16).
 *
 * Ein Seitenaufruf meldet sich nie bei Kraken an und fragt energy-charts
 * nicht: angezeigt wird, was der Minutentakt in die Zwischenspeicher gelegt
 * hat. Bis 1.1.15 erzeugten drei Seitenaufrufe hintereinander 2, 1, 1
 * Anfragen an api.oeg-kraken.energy - je eine Anmeldung mit dem hinterlegten
 * Kennwort, auch bei "Plugin aktiv: Nein" -, und schwieg die Gegenstelle,
 * lud die Seite 30 s (Pruefbericht oberflaeche, Befund 2). Abrufen tun nur
 * der Takt und die ausdruecklichen Knoepfe im Reiter Test
 * (oc_test_ausfuehren() hebt den Schalter dort auf). */
oc_kein_abruf(true);

$oc_p = oc_paths();
if ($oc_p['home'] && file_exists($oc_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $oc_p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $oc_p['home'] . '/libs/phplib/loxberry_web.php';
}

/* Eine Wache, einmal zugewiesen - und jeder Handler fragt sie.
 *
 * Vorher stand an elf Stellen $_SERVER['REQUEST_METHOD'] === 'POST'.
 * Das ist zweierlei: erstens elf Gelegenheiten, sich zu vertippen,
 * und zweitens greift es auf einen Schluessel zu, den es nicht immer gibt -
 * am PHP-CLI fehlt er, und PHP 8 meldet dann eine Warnung je Stelle, PHP 7.4
 * schweigt. Am Webserver ist der Schluessel immer da, der Unterschied fiel
 * also nur am Pruefstand auf; aber ein Wert, den man elfmal ungeprueft
 * liest, ist eine Wette.
 *
 * Und es ist die Form, die das Hauswerkzeug sicherung_verdrahtung.py
 * messen kann: es sucht nach 'if ($wache && isset($_POST[...]))' und prueft,
 * ob die Wache VOR dem Zweig zugewiesen wird. Eine undefinierte Variable
 * waere false - lautlos, und der Knopf taete nie etwas. */
$oc_ist_post = (isset($_SERVER['REQUEST_METHOD'])
                && $_SERVER['REQUEST_METHOD'] === 'POST');
$oc_gespeichert = false;
$oc_fehler      = array();   // alle Beanstandungen sammeln, nicht nur die letzte
/* Die Meldungsablage - das Gegenstueck zu $oc_fehler.
 *
 * SIE HAT BIS 1.0.9 GEFEHLT. Der Rueckspielzweig schrieb in
 * $oc_meldungen[], und diese Ablage wurde nirgends angelegt und nirgends
 * ausgegeben: gemessen mit einer Suche ueber die ganze Datei, GENAU EIN
 * Vorkommen, und das war das Schreiben. Ein erfolgreiches Zurueckspielen
 * meldete deshalb gar nichts - der Bediener drueckte den Knopf, und
 * sichtbar geschah nichts. */
$oc_meldungen   = array();
/* X-2 (Regeln/04): die Eingaben eines abgewiesenen Formulars und die Namen
 * der beanstandeten Felder - sie reisen mit der Einmalmeldung. */
$oc_eingaben    = array();
$oc_bean        = array();
/* Die erlaubten Werte des Fristfeldes: -1 fuer "keine Frist" und die
 * Stunden 0 bis 23. Als Text, weil das Formular Text liefert. */
$oc_stunden_wahl = array_merge(array('-1'), array_map('strval', range(0, 23)));
/* Ausgabe des Planer-Selbsttests. Der Handler dazu steht weiter unten bei
 * den anderen - hinter dem Wachposten, nicht davor. */
$oc_plantest = '';
$oc_hinweis     = '';
$oc_test_titel  = '';
$oc_test_text   = '';

/* Wer einen Reiter hinzufuegt, muss diese Positivliste mitziehen - sonst
   springt die Seite nach jedem Absenden zurueck auf Einstellungen. */
$oc_muster = '/^tab-(settings|mqtt|loxone|costs|test|log)$/';
$oc_tab = 'tab-settings';
if (preg_match($oc_muster, (string) (isset($_POST['activetab']) ? $_POST['activetab'] : ''))) {
    $oc_tab = $_POST['activetab'];
} elseif (isset($_GET['form']) && preg_match($oc_muster, 'tab-' . $_GET['form'])) {
    $oc_tab = 'tab-' . $_GET['form'];
}

$oc_cfg = oc_config();
$oc_zug = oc_zugang();

/* ==================================================================
 * Das Aktionstoken beim ERSTEN Oeffnen erzeugen
 * ==================================================================
 *
 * Bis 1.0.9 entstand es erst beim ersten Speichern. Das genuegte, solange
 * es nur die Adressen im Miniserver betraf. Seit 1.1.0 haengt auch das
 * FORMULARMERKMAL daran - und ohne Token gaebe es keines, der Wachposten
 * unten wiese jedes Formular ab, und niemand koennte je speichern.
 *
 * Danach wird es nur noch auf ausdruecklichen Wunsch neu gewuerfelt: es
 * steckt in den Adressen im Miniserver.
 */
/* STILL NUR BEI EINER NEUINSTALLATION (C5, seit 1.1.16; Regeln/05
 * "Selbstheilung entscheidet nach Inhalt").
 *
 * Bis 1.1.15 wurde hier bei JEDEM leeren Token still ein neues gewuerfelt -
 * auch wenn die Datei Einstellungen trug und nur das Token fehlte. Jede
 * Adresse im Miniserver bekam danach 403, und nichts stand im Protokoll
 * (Pruefbericht code, Befund 5). Heute heilt oc_config() eine Datei ohne
 * Token aus einer Zweitschrift mit Token. Kommt hier trotzdem kein Token an,
 * gibt es keine Zweitschrift, aus der es kaeme: dann entsteht ein neues -
 * still nur, wenn es noch gar keine Konfiguration gab (Lage 'vorgabe':
 * keine Datei oder "{}" ohne Zweitschrift), sonst mit einer Protokollzeile
 * und einem Hinweis ueber den Reitern. */
if ((string) $oc_cfg['aktionstoken'] === '') {
    $oc_cfg['aktionstoken'] = oc_token_erzeugen();
    $oc_tok_geschrieben = oc_config_write($oc_cfg);
    $oc_cfg = oc_config();
    if (oc_konfig_lage() !== 'vorgabe') {
        oc_log('Aktionstoken fehlte in der Konfiguration (Lage: ' . oc_konfig_lage()
            . '), und keine Zweitschrift trug eines - ein NEUES wurde erzeugt'
            . ($oc_tok_geschrieben ? '' : ', liess sich aber nicht speichern')
            . '. Die Adressen im Miniserver muessen es tragen.');
        $oc_fehler[] = oc_t('MELDUNG.TOKEN_STILL_NEU');
    }
}
$oc_fmt = oc_formtoken($oc_cfg);

/* ==================================================================
 * Der Wachposten - EINE Pruefung am Eingang, nicht eine je Handler
 * ==================================================================
 *
 * Bis 1.0.9 hatte dieses Plugin gar keine: gemessen mit einer Suche ueber
 * den ganzen webfrontend-Zweig, kein 'formtoken', kein 'fmt', kein
 * 'hash_hmac'. Eine fremde Seite konnte im angemeldeten Browser einen
 * Preisabruf, ein MQTT-Senden oder eine Sprachansage ausloesen - es
 * genuegte ein Formular, das auf diese Adresse zeigt.
 *
 * WARUM AM EINGANG UND NICHT JE HANDLER: einen einzelnen Handler kann man
 * beim Erweitern vergessen, den Eingang nicht. Faellt die Pruefung durch,
 * wird $_POST GELEERT - danach laeuft kein Zweig mehr an, ohne dass jeder
 * einzelne davon wissen muesste.
 *
 * Der aktive Reiter bleibt stehen: die Meldung soll dort erscheinen, wo
 * der Bediener gerade war.
 */
if ($oc_ist_post && !oc_formtoken_ok($oc_cfg)) {
    $oc_fehler[] = oc_t('MELDUNG.CSRF');
    oc_log('Ein Formular ohne gueltiges Merkmal wurde abgewiesen.');
    $oc_behalten = isset($_POST['activetab']) ? $_POST['activetab'] : null;
    $_POST = array();
    if ($oc_behalten !== null) { $_POST['activetab'] = $oc_behalten; }
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
/* ================= Selbsttest des Planers =================
 * Er rechnet nur, spricht mit niemandem und braucht keine Preise. */
if ($oc_ist_post && isset($_POST['plantest'])) {
    list($oc_pt_n, $oc_pt_f, $oc_plantest) = plan_selbsttest();
    $oc_tab = 'tab-test';
}

/* ================= Loxone-Vorlage herunterladen ================= */
if ($oc_ist_post && isset($_POST['download'])) {
    $oc_art = ((string) $_POST['download'] === 'http_in') ? 'http_in' : 'mqtt_in';
    list($oc_name, $oc_inhalt) = oc_vorlage($oc_art);
    header('Content-Type: application/x-download');
    /* Die Anfuehrungszeichen um den Dateinamen sind Pflicht: ohne sie
     * bricht jeder Name, der ein Leerzeichen enthaelt. Die beiden
     * anderen Downloads dieser Datei setzen sie seit jeher, nur dieser
     * nicht - heute faellt es nicht auf, weil kein erzeugter Name ein
     * Leerzeichen hat. Es ist die Falle fuer den naechsten Namen. */
    header('Content-Disposition: attachment; filename="' . $oc_name . '"');
    header('Content-Length: ' . strlen($oc_inhalt));
    echo $oc_inhalt;
    exit;
}

/* ================= Protokoll leeren ================= */
if ($oc_ist_post && isset($_POST['clearlog'])) {
    @mkdir(dirname($oc_p['log']), 0775, true);
    // Ueber oc_log_setzen(): dieselbe Sperre wie beim Kuerzen im Cron-Lauf,
    // damit sich Leeren und Anhaengen nicht in die Quere kommen.
    oc_log_setzen($oc_p['log'], '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Oberflaeche)\n");
    $oc_tab = 'tab-log';
}

/* ================= Testaktionen ================= */
if ($oc_ist_post && isset($_POST['test'])) {
    require_once __DIR__ . '/oc_test.php';
    list($oc_test_titel, $oc_test_text) = oc_test_ausfuehren((string) $_POST['test']);
    $oc_tab = 'tab-test';
}

/* ================= Zugangsdaten speichern ================= */
if ($oc_ist_post && isset($_POST['save_zugang'])) {
    /* NICHTS MEHR ENTFERNEN (Nr. 19, B-Nachzug 01.10.2026).
     *
     * Bis 1.1.18 strich $oc_saeubern Steuerzeichen und Anfuehrungszeichen
     * still heraus: 'A-12"34AB' wurde 'A-1234AB' und gespeichert. Jetzt wird
     * nur Leerraum am Rand abgeschnitten; ein Steuer- oder
     * Anfuehrungszeichen macht die Angabe unbrauchbar, und dann wird NICHTS
     * gespeichert (Entscheidung 16).
     *
     * Die Geschichte davor:
     *
     * Bis 1.1.3 strich diese Funktion auch den Apostroph, und sie lief
     * ueber die E-Mail-Adresse. Gemessen an einer Adresse mit Apostroph
     * im lokalen Teil: das Zeichen fiel weg, und weil das Ergebnis eine
     * GUELTIGE Adresse ist, bestand sie danach die Pruefung und wurde
     * gespeichert - ohne ein Wort. Die Kraken-Anmeldung scheiterte
     * danach dauerhaft, und im Reiter Test stand trotzdem ein Haken bei
     * der Zeile 'E-Mail hinterlegt'. Der Apostroph ist im lokalen Teil
     * einer Adresse zulaessig; was nicht ins Muster passt, wird
     * abgewiesen und gemeldet, nicht stillschweigend beschnitten.
     *
     * Das Anfuehrungszeichen bleibt draussen: es hat in keiner der drei
     * Angaben etwas verloren und wuerde die JSON-Ablage belasten. */
    $oc_zroh = function ($k) {
        if (!isset($_POST[$k])) { return ''; }
        return is_array($_POST[$k]) ? "\0" : trim((string) $_POST[$k]);
    };
    $oc_zfalsch = function ($s, $max) {
        return $s === "\0" || strlen($s) > $max || preg_match('/[\x00-\x1F\x7F"]/', $s);
    };
    $oc_mail  = $oc_zroh('z_email');
    $oc_konto = $oc_zroh('z_konto');
    $oc_zabweis = false;
    // Ein leeres Passwortfeld loescht NICHTS - sonst stuende irgendwann ein
    // Benutzername ohne Passwort in der Datei, und das sieht man von aussen nicht.
    $oc_pw = (isset($_POST['z_passwort']) && !is_array($_POST['z_passwort'])) ? (string) $_POST['z_passwort'] : '';
    if ($oc_pw === '') { $oc_pw = $oc_zug['passwort']; }
    /* Nr. 19: ein Passwortfeld als Liste (z_passwort[]=x) wurde bis 1.1.18 zu
     * "Array" - mit PHP-Warnung - und als Passwort gespeichert. */
    if (isset($_POST['z_passwort']) && is_array($_POST['z_passwort'])) {
        $oc_fehler[] = sprintf(oc_t('MELDUNG.WAHL_ABGEWIESEN'), 'z_passwort', '[]');
        $oc_bean[] = 'z_passwort';
        $oc_zabweis = true;
    }

    if ($oc_mail !== '' && ($oc_zfalsch($oc_mail, 200) || !oc_email_gueltig($oc_mail))) {
        $oc_fehler[] = oc_t('MELDUNG.MAIL_UNGUELTIG');
        $oc_bean[] = 'z_email';
        $oc_zabweis = true;
    }
    // Die Form der Kundennummer ist bekannt: A- gefolgt von Ziffern und/oder
    // Buchstaben. Was nicht passt, wird abgewiesen statt zurechtgebogen.
    if ($oc_konto !== '' && ($oc_zfalsch($oc_konto, 40) || !oc_konto_gueltig($oc_konto))) {
        $oc_fehler[] = oc_t('MELDUNG.KONTO_UNGUELTIG');
        $oc_bean[] = 'z_konto';
        $oc_zabweis = true;
    }
    if (isset($_POST['zugang_loeschen'])) {
        $oc_mail = ''; $oc_pw = ''; $oc_konto = '';
    } elseif ($oc_zabweis) {
        // Nr. 16: abgewiesen - nichts gespeichert, auch nicht die richtigen Angaben.
        $oc_fehler[] = oc_t('MELDUNG.NICHTS_GESPEICHERT');
    }
    /* Entschieden wird an den Beanstandungen DIESES Formulars. Bis 1.1.18
     * stand hier !$oc_fehler - eine Meldung von weiter oben (etwa "Token
     * fehlte, neu erzeugt") hielt das Speichern still auf. */
    if (!$oc_zabweis || isset($_POST['zugang_loeschen'])) {
        if (oc_zugang_write($oc_mail, $oc_pw, $oc_konto)) {
            $oc_hinweis = isset($_POST['zugang_loeschen'])
                ? oc_t('MELDUNG.ZUGANG_GELOESCHT') : oc_t('MELDUNG.ZUGANG_GESPEICHERT');
            $oc_zug = oc_zugang();
        } else {
            $oc_fehler[] = str_replace('%F%', oc_e($oc_p['zugang']), oc_t('MELDUNG.ZUGANG_FEHLER'));
            // Nicht gespeichert: auch dann bleiben die Eingaben stehen (X-2).
            $oc_eingaben = oc_eingaben_sammeln('save_zugang', array());
        }
    }
    /* X-2: abgewiesen - E-Mail und Kundennummer reisen zurueck ins Formular,
     * das Passwort nie. */
    if ($oc_bean && !isset($_POST['zugang_loeschen'])) {
        $oc_eingaben = oc_eingaben_sammeln('save_zugang', $oc_bean);
    }
    $oc_tab = 'tab-settings';
}

/* ================= Einstellungen speichern ================= */
if ($oc_ist_post && isset($_POST['save'])) {
    /* ABWEISEN STATT VERBIEGEN (O3, O6; seit 1.1.16).
     *
     * Bis 1.1.15 wurde jede unbrauchbare Eingabe still zurechtgebogen und
     * gespeichert. Gemessen mit zwoelf Faellen (Pruefbericht oberflaeche,
     * Befund 3): alle zwoelf verbogen, keine Beanstandung, "Einstellungen
     * gespeichert" - "1;2" wurde Zone 12, "abc" wurde der Werkswert 20 statt
     * der bisherigen 25, 2.5 wurde 2, 150 wurde 100, "de-DE" wurde "dede".
     * Eine Regel mit Beanstandung stand trotzdem in der Datei (Befund 6).
     *
     * Jetzt: jeder unbrauchbare Wert wird mit Feld und Grenzen gemeldet, und
     * dann wird NICHTS gespeichert - der bisherige Stand bleibt ganz, nie der
     * Werkswert. Die Grenzen sind dieselben wie in oc_schranken() und
     * oc_config(). Ein Feld, das gar nicht im Formular stand, behaelt still
     * seinen bisherigen Wert; ein leeres Pflichtfeld ist unbrauchbar. Seit dem
     * B-Nachzug (01.10.2026, Entscheidung 16) halten auch die fehlenden
     * Feldnamen und der fehlende Pfad einer Quelle das Speichern auf - bis
     * 1.1.18 standen sie nur als Hinweis da, und der Rest wurde gespeichert. */
    $oc_abweis = array();
    $oc_roh = function ($k, $i = null) {
        if (!isset($_POST[$k])) { return null; }
        $v = $_POST[$k];
        if ($i !== null) {
            if (!is_array($v) || !isset($v[$i])) { return null; }
            $v = $v[$i];
        }
        return is_array($v) ? "\0" : trim((string) $v);
    };
    $oc_pruef = function ($name, $v, $bisher, $min, $max, $ganz) use (&$oc_abweis, &$oc_bean) {
        if ($v === null) { return $bisher; }
        $t = str_replace(',', '.', $v);
        $gut = ($v !== '' && $v !== "\0" && is_numeric($t)
                && (!$ganz || preg_match('/^-?[0-9]+$/', $v))
                && (float) $t >= (float) $min && (float) $t <= (float) $max);
        if (!$gut) {
            $oc_bean[] = $name;
            $oc_abweis[] = sprintf(oc_t('MELDUNG.WERT_ABGEWIESEN'), oc_e($name),
                oc_e($v === "\0" ? '[]' : $v), oc_e((string) $min), oc_e((string) $max),
                oc_t($ganz ? 'MELDUNG.GANZE_ZAHL' : 'MELDUNG.ZAHL'));
            return $bisher;
        }
        return $ganz ? (int) $v : (float) $t;
    };
    $oc_z = function ($k, $bisher, $min, $max) use ($oc_roh, $oc_pruef) {
        return $oc_pruef($k, $oc_roh($k), $bisher, $min, $max, false);
    };
    $oc_g = function ($k, $bisher, $min, $max) use ($oc_roh, $oc_pruef) {
        return $oc_pruef($k, $oc_roh($k), $bisher, $min, $max, true);
    };
    /* Eine Auswahl aus einer festen Liste: ein fremder Wert ist unbrauchbar. */
    $oc_wahl = function ($name, $v, $bisher, $liste) use (&$oc_abweis, &$oc_bean) {
        if ($v === null) { return $bisher; }
        if (!in_array($v, $liste, true)) {
            $oc_bean[] = $name;
            $oc_abweis[] = sprintf(oc_t('MELDUNG.WAHL_ABGEWIESEN'), oc_e($name), oc_e($v === "\0" ? '[]' : $v));
            return $bisher;
        }
        return $v;
    };
    /* Ein Text: Steuerzeichen und Anfuehrungszeichen werden nicht mehr still
     * entfernt, sondern abgewiesen. Nr. 19 (B-Nachzug 01.10.2026): ebenso,
     * was oc_config() beim Lesen veraendern wuerde - doppelte Leerzeichen
     * fasst oc_text() zusammen, und "Wallbox  Garage" stand bis 1.1.18
     * gespeichert und danach als "Wallbox Garage" da. */
    $oc_text = function ($name, $v, $bisher, $max, $mit_apostroph) use (&$oc_abweis, &$oc_bean) {
        if ($v === null) { return $bisher; }
        $muster = $mit_apostroph ? '/[\x00-\x1F\x7F"\']/u' : '/[\x00-\x1F\x7F"]/u';
        $laenge = function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v);
        if ($v === "\0" || preg_match($muster, $v) || $laenge > $max || oc_text($v, $max) !== $v) {
            $oc_bean[] = $name;
            $oc_abweis[] = sprintf(oc_t('MELDUNG.TEXT_ABGEWIESEN'), oc_e($name), $max);
            return $bisher;
        }
        return $v;
    };
    $oc_neu = $oc_cfg;
    $oc_neu['enabled']        = isset($_POST['enabled']) ? 1 : 0;
    $oc_neu['demo']           = isset($_POST['demo']) ? 1 : 0;
    $oc_neu['demo_aufschlag'] = $oc_z('demo_aufschlag', $oc_cfg['demo_aufschlag'], 0, 100);
    $oc_neu['demo_vat']       = $oc_z('demo_vat', $oc_cfg['demo_vat'], 0, 30);
    $oc_neu['cheap']          = $oc_z('cheap', $oc_cfg['cheap'], 0, 200);
    $oc_neu['expensive']      = $oc_z('expensive', $oc_cfg['expensive'], 0, 400);
    $oc_neu['window']         = $oc_g('window', $oc_cfg['window'], 1, 12);
    $oc_neu['profil_ein'] = $oc_wahl('profil_ein', $oc_roh('profil_ein'), $oc_cfg['profil_ein'],
        array('aus', 'absolut', 'relativ', 'beides'));
    // ---- Schaltregeln ----
    $oc_neu['regeln'] = array();
    for ($oc_i = 0; $oc_i < OC_REGELN; $oc_i++) {
        $oc_alt = $oc_cfg['regeln'][$oc_i];
        $oc_rn = function ($feld, $min, $max, $ganz) use ($oc_i, $oc_roh, $oc_pruef, $oc_alt) {
            return $oc_pruef($feld . '[' . $oc_i . ']', $oc_roh($feld, $oc_i),
                $oc_alt[substr($feld, 2)], $min, $max, $ganz);
        };
        $oc_frist = $oc_wahl('r_frist[' . $oc_i . ']', $oc_roh('r_frist', $oc_i), (string) $oc_alt['frist'],
            $oc_stunden_wahl);
        $oc_neu['regeln'][$oc_i] = array(
            'aktiv' => $oc_roh('r_aktiv', $oc_i) !== null ? 1 : 0,
            // Der Name landet im Kommentar der Loxone-Vorlage.
            'name' => $oc_text('r_name[' . $oc_i . ']', $oc_roh('r_name', $oc_i), $oc_alt['name'], 40, false),
            'art' => $oc_wahl('r_art[' . $oc_i . ']', $oc_roh('r_art', $oc_i), $oc_alt['art'], oc_regel_arten()),
            'n' => $oc_rn('r_n', 1, 12, true),
            'von' => $oc_rn('r_von', 0, 23, true),
            'bis' => $oc_rn('r_bis', 0, 23, true),
            'horizont' => $oc_rn('r_horizont', 1, 48, true),
            'schwelle' => $oc_rn('r_schwelle', -100, 200, false),
            'prozent' => $oc_rn('r_prozent', 0, 90, true),
            'neg' => $oc_roh('r_neg', $oc_i) !== null ? 1 : 0,
            // ---- Fahrplaner ----
            'rang' => $oc_rn('r_rang', 1, 99, true),
            'leistung' => $oc_rn('r_leistung', 0, 100, false),
            'energie' => $oc_rn('r_energie', 0, 500, false),
            'frist' => (int) $oc_frist,
            'pv_sperre' => $oc_rn('r_pv_sperre', 0, 500, false),
            'soc_min' => $oc_rn('r_soc_min', 0, 100, true),
            'soc_max' => $oc_rn('r_soc_max', 0, 100, true),
            // ---- Taktschutz ----
            'min_lauf' => $oc_rn('r_min_lauf', 0, 720, true),
            'min_pause' => $oc_rn('r_min_pause', 0, 720, true),
        );
        $oc_rw = $oc_neu['regeln'][$oc_i];
        // O6: eine beanstandete Regel wird nicht gespeichert - gar nichts wird es.
        if ($oc_rw['aktiv'] && $oc_rw['energie'] > 0 && $oc_rw['leistung'] <= 0) {
            $oc_abweis[] = sprintf(oc_t('REGEL.FEHLER_ENERGIE_OHNE_LEISTUNG'), $oc_i + 1);
            $oc_bean[] = 'r_energie[' . $oc_i . ']';
            $oc_bean[] = 'r_leistung[' . $oc_i . ']';
        }
        if ($oc_rw['soc_min'] > 0 && $oc_rw['soc_max'] > 0
            && $oc_rw['soc_min'] >= $oc_rw['soc_max']) {
            $oc_abweis[] = sprintf(oc_t('REGEL.FEHLER_SOC_REIHE'), $oc_i + 1);
            $oc_bean[] = 'r_soc_min[' . $oc_i . ']';
            $oc_bean[] = 'r_soc_max[' . $oc_i . ']';
        }
    }
    // ---- Fahrplaner, global ----
    /* Die Schranken sind dieselben wie in oc_schranken() - stuenden hier
     * andere Zahlen, gaebe es zwei Wahrheiten, und der Selbsttest im Reiter
     * Test meldet genau das. */
    $oc_neu['budget_kw'] = $oc_z('budget_kw', $oc_cfg['budget_kw'], 0, 200);
    $oc_neu['pv_bonus'] = $oc_z('pv_bonus', $oc_cfg['pv_bonus'], 0, 100);
    $oc_neu['pv_schwelle'] = $oc_g('pv_schwelle', $oc_cfg['pv_schwelle'], 1, 100000);
    $oc_neu['budget2_kw'] = $oc_z('budget2_kw', $oc_cfg['budget2_kw'], 0, 200);
    $oc_neu['budget2_von'] = $oc_g('budget2_von', $oc_cfg['budget2_von'], 0, 23);
    $oc_neu['budget2_bis'] = $oc_g('budget2_bis', $oc_cfg['budget2_bis'], 0, 23);
    $oc_neu['hysterese'] = isset($_POST['hysterese']) ? 1 : 0;
    $oc_neu['verbrauch_quelle'] = $oc_wahl('verbrauch_quelle', $oc_roh('verbrauch_quelle'),
        $oc_cfg['verbrauch_quelle'], array('', 'objekt', 'liste'));
    $oc_neu['verbrauch_einheit'] = $oc_wahl('verbrauch_einheit', $oc_roh('verbrauch_einheit'),
        $oc_cfg['verbrauch_einheit'], array('wh', 'w', 'kw'));
    $oc_neu['pv_quelle'] = $oc_wahl('pv_quelle', $oc_roh('pv_quelle'), $oc_cfg['pv_quelle'],
        array('', 'forecast_solar', 'objekt', 'liste'));
    $oc_neu['pv_einheit'] = $oc_wahl('pv_einheit', $oc_roh('pv_einheit'), $oc_cfg['pv_einheit'],
        array('wh', 'w', 'kw'));
    foreach (array('pv_url' => 500, 'pv_pfad' => 200, 'pv_zeitfeld' => 200, 'pv_wertfeld' => 200,
                   'soc_url' => 500, 'soc_pfad' => 200, 'verbrauch_url' => 500, 'verbrauch_pfad' => 200,
                   'verbrauch_zeitfeld' => 200, 'verbrauch_wertfeld' => 200) as $oc_f2 => $oc_max) {
        $oc_neu[$oc_f2] = $oc_text($oc_f2, $oc_roh($oc_f2), $oc_cfg[$oc_f2], $oc_max, true);
    }
    /* EINE BEANSTANDETE ADRESSE WIRD ABGEWIESEN, NICHT UEBERNOMMEN. Bis
     * 1.1.3 gespeichert und beanstandet, bis 1.1.15 zurueckgesetzt und der
     * Rest gespeichert; jetzt wie jeder andere unbrauchbare Wert. */
    foreach (array('pv_url', 'soc_url', 'verbrauch_url') as $oc_f2) {
        if ($oc_neu[$oc_f2] === '' || preg_match('#^https?://#i', $oc_neu[$oc_f2])) { continue; }
        $oc_abweis[] = sprintf(oc_t('PLAN.FEHLER_URL'), $oc_f2 === 'verbrauch_url'
            ? oc_t('VERB.L_URL') : oc_t('PLAN.L_' . strtoupper($oc_f2)));
        $oc_neu[$oc_f2] = $oc_cfg[$oc_f2];   // den bisherigen Stand behalten
        $oc_bean[] = $oc_f2;
    }
    /* NR. 16 (B-Nachzug 01.10.2026): fehlende Feldnamen und ein fehlender
     * Pfad sind eine Beanstandung wie jede andere - NICHTS wird gespeichert,
     * das leere Feld ist markiert, die Eingaben kommen zurueck (X-2). Bis
     * 1.1.18 standen beide Saetze nur in $oc_fehler, und der Rest wurde
     * gespeichert. Die Meldung nennt den Abschnitt: dieselben Saetze gelten
     * fuer die PV-Prognose und fuer den echten Verbrauch. */
    foreach (array('verbrauch' => oc_t('VERB.H_TITEL'), 'pv' => oc_t('PLAN.L_PV_QUELLE')) as $oc_qa => $oc_qt) {
        $oc_q = $oc_neu[$oc_qa . '_quelle'];
        if ($oc_q === 'liste') {
            $oc_qleer = array();
            foreach (array('_zeitfeld', '_wertfeld') as $oc_qf) {
                if ($oc_neu[$oc_qa . $oc_qf] === '') { $oc_qleer[] = $oc_qa . $oc_qf; }
            }
            if ($oc_qleer) {
                $oc_abweis[] = $oc_qt . ': ' . oc_t('PLAN.FEHLER_FELDNAMEN');
                foreach ($oc_qleer as $oc_qf) { $oc_bean[] = $oc_qf; }
            }
        }
        if ($oc_q !== '' && $oc_q !== 'forecast_solar' && $oc_neu[$oc_qa . '_pfad'] === '') {
            $oc_abweis[] = $oc_qt . ': ' . oc_t('PLAN.FEHLER_PFAD');
            $oc_bean[] = $oc_qa . '_pfad';
        }
    }
    if ($oc_neu['cheap'] >= $oc_neu['expensive']) {
        $oc_abweis[] = oc_t('MELDUNG.SCHWELLEN');
        $oc_bean[] = 'cheap';
        $oc_bean[] = 'expensive';
    }
    $oc_neu['co2_enabled']    = isset($_POST['co2_enabled']) ? 1 : 0;
    $oc_neu['co2_clean']      = $oc_z('co2_clean', $oc_cfg['co2_clean'], 0, 1000);

    $oc_neu['fixed_price']      = $oc_z('fixed_price', $oc_cfg['fixed_price'], 0, 200);
    $oc_neu['fix_grund']        = $oc_z('fix_grund', $oc_cfg['fix_grund'], 0, 500);
    $oc_neu['dyn_grund']        = $oc_z('dyn_grund', $oc_cfg['dyn_grund'], 0, 500);
    $oc_neu['fix_sofortbonus']  = $oc_z('fix_sofortbonus', $oc_cfg['fix_sofortbonus'], 0, 5000);
    $oc_neu['fix_neubonus']     = $oc_z('fix_neubonus', $oc_cfg['fix_neubonus'], 0, 5000);
    $oc_neu['fix_neubonus_pct'] = $oc_z('fix_neubonus_pct', $oc_cfg['fix_neubonus_pct'], 0, 100);
    $oc_neu['fix_rabatt']       = $oc_z('fix_rabatt', $oc_cfg['fix_rabatt'], 0, 100);
    $oc_neu['shift_kwh']        = $oc_z('shift_kwh', $oc_cfg['shift_kwh'], 0, 100);

    // Monatsverbraeuche: ein leeres Feld heisst "nicht gepflegt" (0); sobald
    // einer gepflegt ist, ergibt ihre Summe den Jahresverbrauch.
    /* Nr. 19 (B-Nachzug 01.10.2026): gespeichert wie getippt. Bis 1.1.18
     * rundete round(..., 1) still - aus 100.45 wurde 100.5. */
    $oc_neu['months'] = array();
    $oc_msum = 0.0;
    for ($oc_i = 0; $oc_i < 12; $oc_i++) {
        $oc_v = $oc_roh('months', $oc_i);
        $oc_bisher = isset($oc_cfg['months'][$oc_i]) ? (float) $oc_cfg['months'][$oc_i] : 0.0;
        $oc_v = ($oc_v === '') ? 0.0 : $oc_pruef('months[' . $oc_i . ']', $oc_v, $oc_bisher, 0, 20000, false);
        $oc_neu['months'][$oc_i] = (float) $oc_v;
        $oc_msum += (float) $oc_v;
    }
    /* Nr. 19: das Feld wird IMMER geprueft. Bis 1.1.18 wurde es bei
     * gepflegten Monaten gar nicht angesehen - "abc" verschwand still. Der
     * gespeicherte Wert bleibt bei gepflegten Monaten deren Summe (Hilfe). */
    $oc_jahr = $oc_g('consumption', $oc_cfg['consumption'], 100, 100000);
    $oc_neu['consumption'] = $oc_msum > 0 ? (int) round($oc_msum) : $oc_jahr;

    /* mqtt_enabled und mqtt_topic werden hier NICHT mehr angefasst: sie
     * wohnen im Reiter MQTT und haben dort ein eigenes Formular.
     * $oc_neu kommt aus $oc_cfg, die Werte ueberleben unveraendert. */

    // Token einmal erzeugen und behalten. Wer es neu wuerfelt, muss die
    // Adressen im Miniserver anpassen - deshalb nur auf ausdruecklichen Wunsch.
    if (isset($_POST['token_neu']) || (string) $oc_neu['aktionstoken'] === '') {
        $oc_neu['aktionstoken'] = oc_token_erzeugen();
    }

    $oc_std = array();
    foreach ((array) (isset($_POST['hours']) ? $_POST['hours'] : array()) as $oc_h) {
        if (is_array($oc_h) || !preg_match('/^[0-9]{1,2}$/', (string) $oc_h) || (int) $oc_h > 23) {
            $oc_bean[] = 'hours[]';
            $oc_abweis[] = sprintf(oc_t('MELDUNG.WAHL_ABGEWIESEN'), 'hours[]', oc_e(is_array($oc_h) ? '[]' : (string) $oc_h));
            continue;
        }
        if (!in_array((int) $oc_h, $oc_std, true)) { $oc_std[] = (int) $oc_h; }
    }
    sort($oc_std);
    $oc_neu['notify'] = array(
        'audio'      => isset($_POST['notify_audio']) ? 1 : 0,
        'push'       => isset($_POST['notify_push']) ? 1 : 0,
        'hours'      => $oc_std,
        'only_cheap' => isset($_POST['only_cheap']) ? 1 : 0,
        'negative'   => isset($_POST['neg_always']) ? 1 : 0,
        'tomorrow'   => isset($_POST['notify_tomorrow']) ? 1 : 0,
        // Die Glocke des LoxBerry (ab 1.1.0)
        'lb'         => isset($_POST['notify_lb']) ? 1 : 0,
        'lb_stunden' => $oc_g('notify_lb_stunden', $oc_cfg['notify']['lb_stunden'], 1, 72),
    );
    $oc_ttsip = $oc_roh('tts_ip');
    if ($oc_ttsip === null) {
        $oc_ttsip = (string) $oc_cfg['tts']['ip'];
    } elseif ($oc_ttsip !== '' && (strlen($oc_ttsip) > 100 || !preg_match('/^[A-Za-z0-9._-]+$/', $oc_ttsip))) {
        // Nr. 19: laenger als 100 Zeichen kuerzte oc_config() bis 1.1.18 still.
        $oc_abweis[] = oc_t('MELDUNG.TTS_IP');
        $oc_bean[] = 'tts_ip';
        $oc_ttsip = (string) $oc_cfg['tts']['ip'];
    }
    $oc_zonen = $oc_roh('tts_zones');
    if ($oc_zonen === null) {
        $oc_zonen = (string) $oc_cfg['tts']['zones'];
    } elseif (!preg_match('/^[0-9]+([ ,~]+[0-9]+)*$/', $oc_zonen)) {
        $oc_bean[] = 'tts_zones';
        $oc_abweis[] = sprintf(oc_t('MELDUNG.ZONEN_ABGEWIESEN'), oc_e($oc_zonen === "\0" ? '[]' : $oc_zonen));
        $oc_zonen = (string) $oc_cfg['tts']['zones'];
    }
    $oc_sprache = $oc_roh('tts_lang');
    if ($oc_sprache === null) {
        $oc_sprache = (string) $oc_cfg['tts']['lang'];
    } elseif (!preg_match('/^[a-z]{2,8}$/', $oc_sprache)) {
        $oc_bean[] = 'tts_lang';
        $oc_abweis[] = sprintf(oc_t('MELDUNG.SPRACHE_ABGEWIESEN'), oc_e($oc_sprache === "\0" ? '[]' : $oc_sprache));
        $oc_sprache = (string) $oc_cfg['tts']['lang'];
    }
    $oc_vorlage = $oc_text('tts_template', $oc_roh('tts_template'), (string) $oc_cfg['tts']['template'], 400, false);
    if ($oc_vorlage !== '' && !preg_match('#^https?://#i', $oc_vorlage)) {
        $oc_bean[] = 'tts_template';
        $oc_abweis[] = sprintf(oc_t('MELDUNG.WAHL_ABGEWIESEN'), 'tts_template', oc_e($oc_vorlage));
        $oc_vorlage = (string) $oc_cfg['tts']['template'];
    }
    $oc_tmodus = $oc_wahl('tts_mode', $oc_roh('tts_mode'), $oc_cfg['tts']['mode'],
                          array('musicserver', 'ms4h', 'audioserver', 'custom', 'alexang', 'cc4lox'));
    /* Alexa-NG (Ansage-2): das Sprechtoken ist ein Kennwort - leer heisst
     * unveraendert, der Haken loescht es, eine falsche Form wird abgewiesen
     * (nichts gespeichert). Ohne Token ist der Ausgabeweg Alexa-NG unbrauchbar. */
    $oc_atok = (string) $oc_cfg['tts']['alexa_token'];
    $oc_atok_roh = $oc_roh('tts_alexa_token');
    if (isset($_POST['tts_alexa_token_weg'])) {
        $oc_atok = '';
    } elseif ($oc_atok_roh !== null && $oc_atok_roh !== '') {
        if (oc_alexa_token_ok($oc_atok_roh)) {
            $oc_atok = $oc_atok_roh;
        } else {
            $oc_abweis[] = oc_t('MELDUNG.ALEXA_TOKEN_FORM');
            $oc_bean[] = 'tts_alexa_token';
        }
    }
    if ($oc_tmodus === 'alexang' && $oc_atok === '' && !in_array('tts_alexa_token', $oc_bean, true)) {
        $oc_abweis[] = oc_t('MELDUNG.ALEXA_TOKEN_FEHLT');
        $oc_bean[] = 'tts_alexa_token';
    }
    $oc_ageraet = $oc_text('tts_alexa_geraet', $oc_roh('tts_alexa_geraet'),
        (string) $oc_cfg['tts']['alexa_geraet'], 200, false);
    /* Google-Lautsprecher (Ansage-3): ein EIGENES Sprechtoken mit denselben
     * Regeln wie das von Alexa-NG; Geraet wie dort; Lautstaerke leer = -1
     * (Ansagelautstaerke des Chromecast-Plugins), sonst eine ganze Zahl 0-100. */
    $oc_gtok = (string) $oc_cfg['tts']['google_token'];
    $oc_gtok_roh = $oc_roh('tts_google_token');
    if (isset($_POST['tts_google_token_weg'])) {
        $oc_gtok = '';
    } elseif ($oc_gtok_roh !== null && $oc_gtok_roh !== '') {
        if (oc_google_token_ok($oc_gtok_roh)) {
            $oc_gtok = $oc_gtok_roh;
        } else {
            $oc_abweis[] = oc_t('MELDUNG.GOOGLE_TOKEN_FORM');
            $oc_bean[] = 'tts_google_token';
        }
    }
    if ($oc_tmodus === 'cc4lox' && $oc_gtok === '' && !in_array('tts_google_token', $oc_bean, true)) {
        $oc_abweis[] = oc_t('MELDUNG.GOOGLE_TOKEN_FEHLT');
        $oc_bean[] = 'tts_google_token';
    }
    $oc_ggeraet = $oc_text('tts_google_geraet', $oc_roh('tts_google_geraet'),
        (string) $oc_cfg['tts']['google_geraet'], 200, false);
    $oc_glaut_roh = $oc_roh('tts_google_laut');
    $oc_glaut = ($oc_glaut_roh === '') ? -1
        : $oc_pruef('tts_google_laut', $oc_glaut_roh, (int) $oc_cfg['tts']['google_laut'], 0, 100, true);
    $oc_neu['tts'] = array(
        'mode'     => $oc_tmodus,
        'ip'       => $oc_ttsip,
        'port'     => $oc_g('tts_port', $oc_cfg['tts']['port'], 1, 65535),
        'zones'    => $oc_zonen,
        'volume'   => $oc_g('tts_volume', $oc_cfg['tts']['volume'], 1, 100),
        'lang'     => $oc_sprache,
        'template' => $oc_vorlage,
        'alexa_token'  => $oc_atok,
        'alexa_geraet' => $oc_ageraet,
        'google_token'  => $oc_gtok,
        'google_geraet' => $oc_ggeraet,
        'google_laut'   => $oc_glaut,
    );

    if ($oc_abweis) {
        foreach ($oc_abweis as $oc_a) { $oc_fehler[] = $oc_a; }
        $oc_fehler[] = oc_t('MELDUNG.NICHTS_GESPEICHERT');
        // X-2: die Eingaben reisen zurueck ins Formular, die beanstandeten markiert.
        $oc_eingaben = oc_eingaben_sammeln('save', $oc_bean);
    } elseif (oc_config_write($oc_neu)) {
        $oc_gespeichert = true;
        $oc_cfg = oc_config();
        if (isset($_POST['token_neu'])) { $oc_hinweis = oc_t('MELDUNG.TOKEN_NEU'); }
    } else {
        $oc_fehler[] = str_replace('%F%', oc_e($oc_p['config']), oc_t('MELDUNG.SPEICHERN_FEHLER'));
        // Nicht gespeichert: auch dann bleiben die Eingaben stehen (X-2).
        $oc_eingaben = oc_eingaben_sammeln('save', array());
    }
    $oc_tab = 'tab-settings';
}

/* ---------------- MQTT (eigener Reiter, eigenes Formular) ----------------
 *
 * Eigenes Formular UND eigener Handler gehoeren zusammen. Loesten beide
 * Formulare denselben Handler aus, setzte dieser die Haken des jeweils
 * nicht abgeschickten Formulars per isset() auf 0. */
if ($oc_ist_post && isset($_POST['save_mqtt'])) {
    $oc_mcfg = oc_config();
    $oc_alt_praefix = (string) $oc_mcfg['mqtt_topic'];
    $oc_mcfg['mqtt_enabled'] = isset($_POST['mqtt_enabled']) ? 1 : 0;
    /* ABWEISEN STATT ENTFERNEN (O4, seit 1.1.16).
     *
     * Bis 1.1.15 wurden unerlaubte Zeichen still entfernt ("haus/strom preis"
     * wurde "haus/strompreis", "Einstellungen gespeichert"); bei "!!!" stand
     * "Es wurde octopus eingetragen", gespeichert wurde aber nichts - auch das
     * zugleich abgewaehlte MQTT nicht -, und ein Schreibfehler blieb stumm
     * (Pruefbericht oberflaeche, Befund 4). Jetzt: ein unbrauchbares Praefix
     * wird abgewiesen, und es wird NICHTS gespeichert - auch der Haken nicht
     * (Entscheidung 16, B-Nachzug 01.10.2026; bis 1.1.18 wurde der Haken
     * trotzdem gespeichert, "MQTT_TEIL"). Die Eingaben kommen zurueck (X-2),
     * ein Schreibfehler wird gemeldet. Erlaubt ist, was
     * oc_wert_pruefen() und die Sicherung als Thema gelten lassen, ohne
     * Schraegstrich am Rand und ohne leere Stufe. */
    $oc_mroh = (isset($_POST['mqtt_topic']) && !is_array($_POST['mqtt_topic']))
        ? trim((string) $_POST['mqtt_topic']) : null;
    $oc_praefix_abgewiesen = false;
    if ($oc_mroh !== null && $oc_mroh !== $oc_alt_praefix) {
        if ($oc_mroh !== '' && strlen($oc_mroh) <= 64
            && preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#', $oc_mroh)) {
            $oc_mcfg['mqtt_topic'] = $oc_mroh;
        } else {
            $oc_fehler[] = sprintf(oc_t('MELDUNG.TOPIC_UNGUELTIG'), oc_e($oc_mroh), oc_e($oc_alt_praefix));
            $oc_praefix_abgewiesen = true;
        }
    } elseif (isset($_POST['mqtt_topic']) && is_array($_POST['mqtt_topic'])) {
        $oc_fehler[] = sprintf(oc_t('MELDUNG.TOPIC_UNGUELTIG'), '[]', oc_e($oc_alt_praefix));
        $oc_praefix_abgewiesen = true;
    }
    if ($oc_praefix_abgewiesen) {
        // Nr. 16: nichts speichern, auch den Haken nicht. X-2: die Eingaben
        // (Praefix markiert, Haken wie abgeschickt) reisen zurueck ins Formular.
        $oc_fehler[] = oc_t('MELDUNG.NICHTS_GESPEICHERT');
        $oc_eingaben = oc_eingaben_sammeln('save_mqtt', array('mqtt_topic'));
    } elseif (oc_config_write($oc_mcfg)) {
        $oc_hinweis = oc_t('MELDUNG.GESPEICHERT');
        $oc_cfg = oc_config();
        list($oc_pm, $oc_pf) = oc_ui_praefix_wechsel($oc_alt_praefix, (string) $oc_cfg['mqtt_topic']);
        $oc_meldungen = array_merge($oc_meldungen, $oc_pm);
        $oc_fehler = array_merge($oc_fehler, $oc_pf);
    } else {
        $oc_fehler[] = str_replace('%F%', oc_e($oc_p['config']), oc_t('MELDUNG.SPEICHERN_FEHLER'));
        // Nicht gespeichert: auch dann bleiben die Eingaben stehen (X-2).
        $oc_eingaben = oc_eingaben_sammeln('save_mqtt', array());
    }
    $oc_tab = 'tab-mqtt';
}

/**
 * Nach einem Praefixwechsel (M2, seit 1.1.16): die zurueckbehaltenen Themen
 * unter dem ALTEN Praefix direkt am Broker abraeumen (derselbe Weg wie die
 * Deinstallation), beide Praefixe merken (die Deinstallation raeumt unter
 * jedem gemerkten ab) und den Merker der gesendeten Werte verwerfen, damit
 * der naechste Lauf unter dem neuen Praefix den vollen Satz schickt.
 * Bis 1.1.15 blieben die sechs Einstellungswerte unter dem alten Praefix
 * fuer immer im Broker, auch nach der Deinstallation (Pruefbericht mqtt, M2).
 * Rueckgabe array(Meldungen, Fehler).
 */
function oc_ui_praefix_wechsel($alt, $neu)
{
    $m = array();
    $f = array();
    if ((string) $alt === (string) $neu || (string) $alt === '') { return array($m, $f); }
    oc_mqtt_praefix_merken($alt);
    oc_mqtt_praefix_merken($neu);
    oc_weg(oc_mqtt_merker());
    oc_weg(oc_tmpdir() . '/mqtt_beat');
    $e = oc_mqtt_praefix_leeren($alt, 2, 0.5);
    foreach ($e['zeilen'] as $z) {
        $t = oc_e(preg_replace('/^<[A-Z]+> /', '', (string) $z));
        if ((int) $e['rc'] === 0) { $m[] = $t; } else { $f[] = $t . ' ' . oc_t('MELDUNG.PRAEFIX_REST'); }
    }
    oc_log('MQTT-Praefix gewechselt von ' . $alt . ' auf ' . $neu . ': '
        . strip_tags(implode(' ', $e['zeilen'])));
    return array($m, $f);
}


function oc_n($v, $d = 2) { return number_format((float) $v, $d, ',', '.'); }

/** Balkendiagramm der Viertelstundenpreise (heute und morgen). */
function oc_chart($st)
{
    $rows = array();
    foreach (array('heute', 'morgen') as $tag) {
        if (empty($st[$tag]['slots'])) { continue; }
        foreach ($st[$tag]['slots'] as $ts => $ct) {
            $rows[] = array($tag, (int) $ts, (float) $ct);
        }
    }
    if (!$rows) { return '<div class="sm-hilfe">' . oc_t('TEXT.CHART_LEER') . '</div>'; }
    $w = 940; $h = 210; $x0 = 42; $y0 = 10; $pw = $w - $x0 - 10; $ph = $h - $y0 - 36;
    $vals = array_map(function ($r) { return $r[2]; }, $rows);
    $mx = max($vals); $mn = min(0, min($vals));
    $span = max(0.001, $mx - $mn);
    $bw = $pw / max(1, count($rows));
    $jetzt = time(); $slot = $jetzt - ($jetzt % 900);
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" role="img" '
         . 'style="width:100%;height:auto;background:#fafafa;border:1px solid #e0e0e0;border-radius:8px;" '
         . 'xmlns="http://www.w3.org/2000/svg">';
    for ($i = 0; $i <= 4; $i++) {
        $v = $mn + $span * $i / 4;
        $y = $y0 + $ph - $ph * ($v - $mn) / $span;
        $svg .= '<line x1="' . $x0 . '" y1="' . round($y, 1) . '" x2="' . ($x0 + $pw)
              . '" y2="' . round($y, 1) . '" stroke="#e5e5e5"/>';
        $svg .= '<text x="' . ($x0 - 5) . '" y="' . round($y + 3, 1)
              . '" font-size="9" fill="#999" text-anchor="end">' . number_format($v, 0) . '</text>';
    }
    foreach ($rows as $i => $r) {
        $x = $x0 + $i * $bw;
        $y = $y0 + $ph - $ph * ($r[2] - $mn) / $span;
        $basis = $y0 + $ph - $ph * (0 - $mn) / $span;
        $farbe = ($r[1] === $slot) ? '#e65100' : ($r[0] === 'heute' ? '#6dac20' : '#9ccc65');
        if ($r[2] < 0) { $farbe = '#1565c0'; }
        $top = min($y, $basis); $hh = max(1, abs($basis - $y));
        $svg .= '<rect x="' . round($x + 0.3, 1) . '" y="' . round($top, 1)
              . '" width="' . round(max(0.6, $bw - 0.6), 2) . '" height="' . round($hh, 1)
              . '" fill="' . $farbe . '"><title>' . date('d.m. H:i', $r[1]) . ' &ndash; '
              . number_format($r[2], 2) . ' ct</title></rect>';
        if ((int) date('G', $r[1]) % 3 === 0 && (int) date('i', $r[1]) === 0) {
            $svg .= '<text x="' . round($x, 1) . '" y="' . ($h - 16)
                  . '" font-size="8" fill="#999" text-anchor="middle">' . date('G', $r[1]) . '</text>';
        }
    }
    $heute_n = count(array_filter($rows, function ($r) { return $r[0] === 'heute'; }));
    $mid = $x0 + $bw * $heute_n;
    if ($heute_n > 0 && $mid < $x0 + $pw) {
        $svg .= '<line x1="' . round($mid, 1) . '" y1="' . $y0 . '" x2="' . round($mid, 1)
              . '" y2="' . ($y0 + $ph) . '" stroke="#bbb" stroke-dasharray="4,3"/>';
        $svg .= '<text x="' . round($mid + 4, 1) . '" y="' . ($y0 + 12)
              . '" font-size="9" fill="#999">' . oc_t('TEXT.MORGEN') . '</text>';
    }
    $svg .= '<text x="' . $x0 . '" y="' . ($h - 3) . '" font-size="9" fill="#999">'
          . oc_t('TEXT.CHART_LEGENDE') . '</text>';
    return $svg . '</svg>';
}

$oc_rahmen = class_exists('LBWeb', false);

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das. */
if ($oc_ist_post && isset($_POST['oc_sichern'])) {
    /* Der Haken entscheidet, ob die Zugangsdaten mitgehen.
     *
     * Bis 1.0.9 gingen sie NIE mit - der Warntext am Knopf behauptete das
     * Gegenteil ("Die Datei enthaelt Ihre Zugangsdaten"), und gemessen am
     * erzeugten Download stimmte es nicht: oc_config() kennt weder E-Mail
     * noch Passwort noch Kundennummer, die wohnen in zugang.json. Damit
     * war der erklaerte Zweck - der Umzug auf einen zweiten LoxBerry -
     * nicht erfuellbar: dort stuenden alle Felder richtig, und es kaemen
     * trotzdem keine Preise.
     *
     * Jetzt entscheidet der Bediener, und der Dateiname sagt es mit. */
    $oc_mit_zugang = isset($_POST['mit_zugang']);
    $oc_js = oc_sicherung_bauen($oc_mit_zugang);
    if ($oc_js !== '') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="octopus_einstellungen'
               . ($oc_mit_zugang ? '_mit_zugang' : '') . '_'
               . date('Ymd_His') . '.json"');
        header('Content-Length: ' . strlen($oc_js));
        echo $oc_js;
        exit;
    }
    $oc_fehler[] = oc_t('EINST.SICH_SCHREIBFEHLER');
}

/* ================= Historie als CSV herunterladen ================= */
if ($oc_ist_post && isset($_POST['oc_csv'])) {
    $oc_csv = oc_history_csv();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="octopus_historie_'
           . date('Ymd') . '.csv"');
    header('Content-Length: ' . strlen($oc_csv));
    echo $oc_csv;
    exit;
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei des
 * Servers unterschieben. Dann die Groessengrenze - eine Sicherung dieses
 * Plugins ist wenige Kilobyte gross; alles darueber wird gar nicht gelesen. */
if ($oc_ist_post && isset($_POST['oc_zurueck'])) {
    if (!isset($_FILES['oc_sicherung']) || !is_array($_FILES['oc_sicherung'])
        || !isset($_FILES['oc_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['oc_sicherung']['tmp_name'])) {
        $oc_fehler[] = oc_t('EINST.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['oc_sicherung']['size'] > 65536) {
        /* 64 kB. Eine Sicherung dieses Plugins ist wenige Kilobyte gross;
         * alles darueber wird gar nicht erst gelesen. */
        $oc_fehler[] = oc_t('EINST.SICH_ZU_GROSS');
    } else {
        list($oc_neu, $oc_mangel, $oc_n, $oc_neu_zugang) = oc_sicherung_lesen(
            (string) @file_get_contents($_FILES['oc_sicherung']['tmp_name']));
        if ($oc_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird
             * nichts. */
            $oc_fehler[] = oc_t('EINST.SICH_ABGELEHNT') . ' '
                            . implode(' ', $oc_mangel);
        } else {
            /* ---- Das Aktionstoken (C5, O8; seit 1.1.16) ----
             *
             * Eine Sicherung mit LEEREM Token behaelt das laufende. Bis 1.1.15
             * wurde dann still ein neues gewuerfelt, und darunter standen zwei
             * Saetze, die sich widersprachen: "es wurde ein neues erzeugt" und
             * "mit der Sicherung kam auch das Aktionstoken" (Pruefbericht
             * oberflaeche, Befund 8). Jetzt eine Meldung, die stimmt: das
             * laufende bleibt; ein neues nur, wenn es noch keines gab; der
             * Hinweis "die Adressen muessen umgestellt werden" nur, wenn die
             * Datei wirklich ein ANDERES Token brachte. */
            $oc_tok_alt = (string) $oc_cfg['aktionstoken'];
            $oc_tok_lage = 'datei';
            if ((string) $oc_neu['aktionstoken'] === '') {
                if ($oc_tok_alt !== '') {
                    $oc_neu['aktionstoken'] = $oc_tok_alt;
                    $oc_tok_lage = 'behalten';
                } else {
                    $oc_neu['aktionstoken'] = oc_token_erzeugen();
                    $oc_tok_lage = 'neu';
                }
            }
            $oc_alt_praefix = (string) $oc_cfg['mqtt_topic'];
            /* Alexa-NG (Ansage-2): das Sprechtoken steht nie in einer
             * Sicherung (eine Datei, die eines traegt, weist die Pruefung ab) -
             * das laufende bleibt. */
            if (is_array($oc_neu['tts'])) {
                $oc_neu['tts']['alexa_token'] = (string) $oc_cfg['tts']['alexa_token'];
                $oc_neu['tts']['google_token'] = (string) $oc_cfg['tts']['google_token'];   // Ansage-3
            }
            if (!oc_config_write($oc_neu)) {
                $oc_fehler[] = oc_t('EINST.SICH_SCHREIBFEHLER');
            } else {
                $oc_meldungen[] = sprintf(oc_t('EINST.SICH_UEBERNOMMEN'), $oc_n);
                if ($oc_tok_lage === 'behalten') {
                    $oc_meldungen[] = oc_t('EINST.SICH_TOKEN_BEHALTEN');
                } elseif ($oc_tok_lage === 'neu') {
                    $oc_meldungen[] = oc_t('EINST.SICH_TOKEN_LEER');
                } elseif ((string) $oc_neu['aktionstoken'] !== $oc_tok_alt) {
                    $oc_meldungen[] = oc_t('EINST.SICH_TOKEN_NEU');
                }

                /* Die Zugangsdaten kommen aus derselben Datei, gehen aber
                 * in ihre eigene mit Rechten 0600 - nicht in die
                 * Konfiguration, die die Oberflaeche anzeigt. */
                if (is_array($oc_neu_zugang)) {
                    if (oc_zugang_write($oc_neu_zugang['email'], $oc_neu_zugang['passwort'],
                                        $oc_neu_zugang['konto'])) {
                        $oc_meldungen[] = oc_t('EINST.SICH_ZUGANG_DA');
                    } else {
                        $oc_fehler[] = str_replace('%F%', oc_e($oc_p['zugang']),
                            oc_t('MELDUNG.ZUGANG_FEHLER'));
                    }
                }

                $oc_cfg = oc_config();
                $oc_zug = oc_zugang();
                $oc_fmt = oc_formtoken($oc_cfg);
                oc_weg(oc_tmpdir() . '/state.json');
                oc_weg(oc_tmpdir() . '/laufend.json');
                // Eine Sicherung kann das Praefix aendern - dann dasselbe Abraeumen (M2).
                list($oc_pm, $oc_pf) = oc_ui_praefix_wechsel($oc_alt_praefix, (string) $oc_cfg['mqtt_topic']);
                $oc_meldungen = array_merge($oc_meldungen, $oc_pm);
                $oc_fehler = array_merge($oc_fehler, $oc_pf);
                oc_log('Einstellungen zurueckgespielt: ' . $oc_n . ' Werte'
                    . (is_array($oc_neu_zugang) ? ' samt Zugangsdaten' : '')
                    . ', Aktionstoken: ' . $oc_tok_lage . '. Der Zustand wird neu gerechnet.');
            }
        }
    }
    $oc_tab = 'tab-settings';
}

/* ================= JEDER POST ENDET MIT EINER UMLEITUNG (O1, seit 1.1.16) =================
 *
 * Regeln/04: header('Location: index.php?form=...', true, 303) und exit; das
 * Ergebnis reist als Einmalmeldung (data/plugins/<ordner>/einmalmeldung.json,
 * 0600, 120 s gueltig, nur beim GET gelesen und dabei geloescht). Bis 1.1.15
 * lieferte jeder POST die Seite direkt: F5 wiederholte die Ansage, den
 * MQTT-Vollversand, den Merker der Test-Pushnachricht und das Speichern
 * (Pruefbericht oberflaeche, Befund 1). Die Downloads (Vorlage, Sicherung,
 * Historie) sind oben schon mit exit fertig. Auch die Abweisung durch den
 * Wachposten geht diesen Weg. */
if ($oc_ist_post) {
    if (!oc_meldung_ablegen(array(
            'meldungen' => array_values($oc_meldungen), 'fehler' => array_values($oc_fehler),
            'hinweis' => (string) $oc_hinweis, 'gespeichert' => $oc_gespeichert ? 1 : 0,
            'test_titel' => (string) $oc_test_titel, 'test_text' => (string) $oc_test_text,
            'plantest' => (string) $oc_plantest, 'eingaben' => $oc_eingaben))) {
        oc_log('Die Einmalmeldung liess sich nicht schreiben - das Ergebnis des letzten '
            . 'Knopfdrucks ist nach der Umleitung nicht zu sehen.');
    }
    header('Location: index.php?form=' . substr($oc_tab, 4), true, 303);
    exit;
}
$oc_einmal = oc_meldung_abholen();
if ($oc_einmal !== null) {
    $oc_liste = function ($k) use ($oc_einmal) {
        return (isset($oc_einmal[$k]) && is_array($oc_einmal[$k]))
            ? array_values(array_filter($oc_einmal[$k], 'is_string')) : array();
    };
    $oc_eintext = function ($k) use ($oc_einmal) {
        return (isset($oc_einmal[$k]) && is_string($oc_einmal[$k])) ? $oc_einmal[$k] : '';
    };
    $oc_meldungen = array_merge($oc_meldungen, $oc_liste('meldungen'));
    $oc_fehler = array_merge($oc_fehler, $oc_liste('fehler'));
    if ($oc_eintext('hinweis') !== '') { $oc_hinweis = $oc_eintext('hinweis'); }
    $oc_gespeichert = !empty($oc_einmal['gespeichert']);
    $oc_test_titel = $oc_eintext('test_titel');
    $oc_test_text = $oc_eintext('test_text');
    $oc_plantest = $oc_eintext('plantest');
    // X-2: oc_eingaben_einsetzen() liest sie von hier.
    $oc_eingaben = oc_eingaben_pruefen(isset($oc_einmal['eingaben']) ? $oc_einmal['eingaben'] : null);
}


/* ================= Anzeige vorbereiten =================
 *
 * HINTER ALLEN HANDLERN, und das ist Absicht.
 *
 * Bis 1.0.9 stand dieser Block VOR dem Sicherungszweig. Wer eine
 * Sicherung zurueckspielte, bekam die Meldung "42 Werte uebernommen"
 * und sah darunter unveraendert seine alten Eingaben - $oc_st, $oc_kos
 * und $oc_hist waren laengst gerechnet, als der Handler schrieb.
 *
 * Man koennte das im Handler nachziehen. Dann muss es aber jeder
 * kuenftige Handler auch tun, und einen davon vergisst man. Deshalb
 * steht der Block jetzt hier: Bibliothek, Konfiguration, Wachposten,
 * Reiterwahl, ALLE Handler samt Downloads, ANZEIGE VORBEREITEN, dann
 * erst lbheader(), dann HTML.
 */
$oc_st  = oc_state();
$oc_gw  = oc_gateway();
$oc_mon = oc_months();
$oc_kos = oc_cost_compare();
$oc_hist = oc_history_read(60);
$oc_ver = oc_version();
$oc_hoursel = array_map('intval', (array) $oc_cfg['notify']['hours']);
$oc_tts = $oc_cfg['tts'];
$oc_ip  = oc_eigene_ip();
$oc_endpunkt = 'http://' . $oc_ip . '/plugins/' . $oc_p['plugin'] . '/index.php';

$oc_loglines = array();
if (is_file($oc_p['log'])) {
    $oc_loglines = array_slice(
        array_reverse(file($oc_p['log'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()),
        0, 300);
}

if ($oc_rahmen) {
    LBWeb::lbheader('Octopus Dynamic' . ($oc_ver !== '' ? ' ' . $oc_ver : ''),
        'https://wiki.loxberry.de/', 'help.html');
}

?>
<style>
/* Hausstandard - wortgetreu aus VORLAGE_hausstandard.css.html */
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
    overflow: auto; margin: 8px 0; }
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
.sm-an  { color: #1a7f1a; font-weight: 700; }
/* Ein beanstandetes Feld nach der Umleitung (X-2, Regeln/04). */
.sm-wrap .sm-beanstandet { border: 2px solid #c62828 !important; background: #fff5f5 !important; }
/* Die vier Klassen des Meldekastens. Sie wurden im Reiter Test seit
 * jeher BENUTZT und waren nirgends definiert - hausstandard_pruefen.py
 * meldete '.sm-alert benutzt, aber nirgends definiert'. Die einzige
 * Selbstpruefzeile, die Reiterleiste, Positivliste und Flaechen
 * gegeneinander haelt, stand deshalb als nackter Fliesstext da: Haken
 * und Kreuz sahen gleich aus. Wortgleich aus der Schwesterlinie
 * Spotpreis-aWATTar uebernommen. */
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok   { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err  { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-warn { background: #fff8e1; border: 1px solid #ffe082; }
.sm-aus { color: #b00000; font-weight: 700; }
/* Ergaenzungen dieses Plugins */
.sm-reihe { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-reihe > div { flex: 1; min-width: 170px; }
.sm-reihe > div > label { display: block; font-weight: 600; font-size: 0.88em; color: #555;
    margin: 10px 0 4px; min-height: 2.6em; display: flex; align-items: flex-end; }
.sm-wrap input[type=text], .sm-wrap input[type=password], .sm-wrap input[type=number],
.sm-wrap select, .sm-wrap textarea {
    width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px;
    font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0; vertical-align: middle; }
/* Eine Tabelle, die breiter ist als das Fenster, braucht ihre eigene
 * Rollleiste: .sm-tbl hat width:100%, .sm-wrap hat max-width ohne
 * Ueberlauf - die letzte Spalte ist sonst UNERREICHBAR, nicht bloss
 * unbequem. Gemessen am gerenderten HTML: zwei Tabellen mit sieben und
 * acht Spalten, und .sm-breit kam im ganzen Plugin nicht vor.
 * Wortgleich aus VORLAGE_hausstandard.css.html. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen. Ueber die volle
 * Breite und mit data-role="none" sieht ein <select> aus wie ein
 * Textfeld; der eingebaute Pfeil sitzt am rechten Rand und faellt dort
 * nicht auf. Am Geraet gemeldet. Hier sind es 14 Auswahlfelder.
 * Die Raute im SVG wird als %23 geschrieben: eine rohe Raute beendet
 * in einer CSS-Adresse den Wert. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
/* Plugin-eigener Zusatz zur Vorlagenregel .sm-pre: Endpunktantworten und
   Adressen umbrechen, statt aus dem Kasten zu laufen. Bis 1.1.10 stand das
   IN der Vorlagenzeile - die ist wortgetreu zu halten (CLAUDE.md, Kern 9). */
.sm-pre { white-space: pre-wrap; }
.sm-stunden { display: flex; flex-wrap: wrap; gap: 4px; margin: 6px 0; }
.sm-stunden label { display: inline-flex; align-items: center; gap: 5px; white-space: nowrap;
    background: #f5f5f5; border: 1px solid #ddd; border-radius: 6px; padding: 5px 9px;
    margin: 0; font-weight: 500; font-size: 0.85em; width: 96px; box-sizing: border-box; }
.sm-stunden label:hover { background: #eef7e4; border-color: #6dac20; }
.sm-monate { display: flex; flex-wrap: wrap; gap: 8px; margin: 6px 0; }
.sm-monate > div { width: 110px; }
.sm-monate label { margin: 0 0 2px; font-size: 0.8em; font-weight: 600; color: #555; min-height: 0; }
.sm-monate input { padding: 6px 8px; font-size: 0.9em; text-align: right; }
.sm-demo { border: 1px solid #b39ddb; background: #f3e5f5; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.92em; }

/* Nachgetragene Definitionen (CSS-Luecken-Durchgang 13.08.2026):
   benutzt, aber nie definiert - wortgleich aus der Hausstandard-Vorlage. */
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-row { display: flex; gap: 12px; }
.sm-row > div { flex: 1; }
.sm-small { font-size: 0.88em; color: #555; }
</style>
<div class="sm-wrap">

<?php if ($oc_gespeichert) { ?>
<div class="sm-hinweis"><b><?php echo oc_t('MELDUNG.GESPEICHERT'); ?></b>
<?php echo oc_t('MELDUNG.GESPEICHERT_ZUSATZ'); ?></div>
<?php } ?>
<?php if ($oc_hinweis !== '') { ?><div class="sm-hinweis"><?php echo $oc_hinweis; ?></div><?php } ?>
<?php
/* Meldungen und Fehler stehen AUSSERHALB der Reiterflaechen - sie sollen
 * sichtbar sein, egal auf welchem Reiter die Seite aufklappt.
 *
 * $oc_meldungen wurde bis 1.0.9 zwar beschrieben, aber nie angelegt und
 * nie ausgegeben. Ein erfolgreiches Zurueckspielen meldete deshalb gar
 * nichts. */
foreach ($oc_meldungen as $oc_m) { ?><div class="sm-hinweis"><?php echo $oc_m; ?></div><?php }
foreach ($oc_fehler as $oc_f) { ?><div class="sm-warnung"><?php echo $oc_f; ?></div><?php }
?>

<?php
/* ---- Der MQTT-Gateway steht nicht auf Autostart ----
 *
 * UEBER ALLEN REITERN, nicht nur als Zeile in einer Tabelle im Reiter
 * MQTT. Es ist die haeufigste Ursache dafuer, dass am Miniserver nichts
 * ankommt - und wer den Reiter MQTT nie oeffnet, sah den Hinweis nie.
 *
 * Gewarnt wird nur, wenn der Wert wirklich AUS ist. Ist er nicht
 * feststellbar (kein LoxBerry, keine general.json), bleibt es still: eine
 * Warnung, die auf jedem Entwicklungsrechner erscheint, liest bald
 * niemand mehr. Und wer gar kein MQTT benutzt, geht die Frage nichts an. */
if (!empty($oc_cfg['mqtt_enabled']) && $oc_gw['vorhanden'] && !$oc_gw['autostart']) { ?>
<div class="sm-warnung"><b>MQTT:</b> <?php echo oc_t('MQTT.AUTOSTART_AUS'); ?></div>
<?php } ?>

<?php if (!empty($oc_st['demo'])) { ?>
<div class="sm-demo"><b><?php echo oc_t('TEXT.DEMO_TITEL'); ?></b>
<?php echo str_replace(array('%A%', '%V%'),
    array(oc_n($oc_cfg['demo_aufschlag'], 2), oc_n($oc_cfg['demo_vat'], 1)),
    oc_t('TEXT.DEMO_ERKLAERUNG')); ?></div>
<?php } ?>

<?php if ($oc_st['fehler'] !== '' && !$oc_st['ok']) { ?>
<div class="sm-warnung"><b><?php echo oc_t('TEXT.KEIN_ABRUF'); ?></b>
<?php echo oc_e(oc_fehlertext($oc_st['fehler'])); ?></div>
<?php } elseif (!empty($oc_st['veraltet'])) { ?>
<div class="sm-warnung"><?php echo str_replace('%F%', oc_e(oc_fehlertext($oc_st['fehler'])),
    oc_t('TEXT.VERALTET')); ?></div>
<?php } ?>

<?php if ($oc_st['ok']) { ?>
<div class="sm-kacheln">
  <div class="sm-kachel"><small><?php echo oc_t('KACHEL.JETZT'); ?></small>
    <b><?php echo oc_n($oc_st['cur'], 2); ?></b><small>ct/kWh<?php
    // C2: fuer die laufende Viertelstunde steht der Ersatzwert (Tageshoechstpreis).
    echo !empty($oc_st['cur_fehlt']) ? ' &middot; ' . oc_t('TEXT.ERSATZWERT') : ''; ?></small></div>
  <div class="sm-kachel"><small><?php echo oc_t('KACHEL.STUNDE'); ?></small>
    <b><?php echo oc_n($oc_st['cur_h'], 2); ?></b><small>ct/kWh</small></div>
  <div class="sm-kachel"><small><?php echo oc_t('KACHEL.RANG'); ?></small>
    <b><?php echo (int) $oc_st['rank']; ?></b><small><?php echo oc_t('KACHEL.VON'); ?>
    <?php echo (int) $oc_st['n']; ?></small></div>
  <div class="sm-kachel"><small><?php echo oc_t('KACHEL.NIVEAU'); ?></small>
    <b><?php echo $oc_st['level'] == 1 ? oc_t('TEXT.GUENSTIG')
        : ($oc_st['level'] == 3 ? oc_t('TEXT.TEUER') : oc_t('TEXT.NORMAL')); ?></b>
    <small><?php echo $oc_st['neg'] ? oc_t('TEXT.NEGATIV') : '&nbsp;'; ?></small></div>
  <div class="sm-kachel"><small><?php echo str_replace('%N%', (int) $oc_st['fenster_len'],
        oc_t('KACHEL.FENSTER')); ?></small>
    <b><?php echo $oc_st['fenster']['in'] >= 0
        ? sprintf('%02d:%02d', $oc_st['fenster']['h'], $oc_st['fenster']['m']) : '&ndash;'; ?></b>
    <small><?php echo $oc_st['fenster']['in'] >= 0
        ? oc_n($oc_st['fenster']['ct'], 2) . ' ct' : '&nbsp;'; ?></small></div>
<?php if (!empty($oc_st['co2_ok'])) { ?>
  <div class="sm-kachel"><small>CO&#8322;</small>
    <b><?php echo (int) $oc_st['co2']; ?></b><small>g/kWh<?php
    echo !empty($oc_st['co2_clean']) ? ' &middot; ' . oc_t('TEXT.SAUBER') : ''; ?></small></div>
<?php } ?>
</div>
<div class="sm-hilfe">
<?php echo oc_t('TEXT.HEUTE'); ?>: <?php echo oc_n($oc_st['heute']['minp'], 2); ?> ct
<?php echo oc_t('TEXT.UM'); ?> <?php printf('%02d:%02d', $oc_st['heute']['minh'], $oc_st['heute']['minm']); ?>
&middot; <?php echo oc_n($oc_st['heute']['maxp'], 2); ?> ct
<?php echo oc_t('TEXT.UM'); ?> <?php printf('%02d:%02d', $oc_st['heute']['maxh'], $oc_st['heute']['maxm']); ?>
&middot; &Oslash; <?php echo oc_n($oc_st['heute']['avg'], 2); ?> ct
<?php if ($oc_st['tomorrow_ok']) { ?>
&nbsp;|&nbsp; <?php echo oc_t('TEXT.MORGEN'); ?>: <?php echo oc_n($oc_st['morgen']['minp'], 2); ?> ct
<?php echo oc_t('TEXT.UM'); ?> <?php printf('%02d:%02d', $oc_st['morgen']['minh'], $oc_st['morgen']['minm']); ?>
&middot; <?php echo oc_n($oc_st['morgen']['maxp'], 2); ?> ct
<?php echo oc_t('TEXT.UM'); ?> <?php printf('%02d:%02d', $oc_st['morgen']['maxh'], $oc_st['morgen']['maxm']); ?>
&middot; &Oslash; <?php echo oc_n($oc_st['morgen']['avg'], 2); ?> ct
<?php } else { ?>&nbsp;|&nbsp; <?php echo oc_t('TEXT.MORGEN_OFFEN'); ?><?php } ?>
&nbsp;|&nbsp; <?php echo oc_t('TEXT.STAND'); ?>:
<?php echo $oc_st['stand'] ? date('d.m.Y H:i', $oc_st['stand']) : '&ndash;'; ?>
</div>
<div style="margin-top:8px;"><?php echo oc_chart($oc_st); ?></div>
<?php } ?>

<!-- Reiterleiste: echte Links, JavaScript faengt den Klick ab. -->
<?php
/*
 * Die Reiter waren schon echte Verweise - was fehlte, war die Klasse
 * sm-active AUF DEM SERVER.
 *
 * .sm-seite steht auf display:none, sichtbar wird eine Flaeche erst durch
 * .sm-active. Diese Klasse vergab bis 0.9.1 ausschliesslich das JavaScript
 * am Seitenende; im ausgelieferten HTML kam sm-active gar nicht vor. Ohne
 * JavaScript standen Kopfzeile und Reiterleiste da, darunter nichts.
 *
 * $oc_tab wurde serverseitig laengst ermittelt und nur ans JavaScript
 * weitergereicht. Diese Liste, die Positivliste in $oc_muster und die id
 * der Flaechen muessen deckungsgleich bleiben - alle drei.
 */
$oc_reiter = array(
    'tab-settings' => oc_t('REITER.EINSTELLUNGEN'),
    'tab-mqtt'     => oc_t('REITER.MQTT'),
    'tab-loxone'   => oc_t('REITER.LOXONE'),
    'tab-costs'    => oc_t('REITER.KOSTEN'),
    'tab-test'     => oc_t('REITER.TEST'),
    'tab-log'      => oc_t('REITER.LOG'),
);
?>
<?php
/* DIE REITERLEISTE STEHT AUSGESCHRIEBEN - das ist Absicht.
 *
 * Bis 1.1.1 entstand sie aus einer foreach-Schleife. Das sieht sauberer aus
 * und hat einen Preis, den man nicht sieht: hausstandard_pruefen.py findet
 * die Reiter dann nicht mehr und meldet in der Spalte "tab" einen Strich -
 * seit jeher, ohne dass es jemandem aufgefallen waere. Eine Bauweise, die
 * eine Pruefung blind macht, kostet mehr, als sie spart.
 *
 * Die Aufloesung ist nicht "Schleife oder Hand", sondern beides:
 * ausschreiben UND die Uebereinstimmung nachrechnen lassen. Die Prueffzeile
 * im Reiter Test haelt die DREI Stellen gegeneinander - die Positivliste
 * $oc_muster, dieses Feld $oc_reiter und die ids der Flaechen. Wer einen
 * Reiter ergaenzt und eine der drei vergisst, bekommt dort ein Kreuz statt
 * einer Seite, die nach jedem Absenden auf Einstellungen zurueckspringt.
 */
?>
<div class="sm-tabs">
    <a class="sm-tab<?php echo $oc_tab === 'tab-settings' ? ' sm-active' : ''; ?>" data-ziel="tab-settings"
       href="index.php?form=settings"><?php echo $oc_reiter['tab-settings']; ?></a>
    <a class="sm-tab<?php echo $oc_tab === 'tab-mqtt' ? ' sm-active' : ''; ?>" data-ziel="tab-mqtt"
       href="index.php?form=mqtt"><?php echo $oc_reiter['tab-mqtt']; ?></a>
    <a class="sm-tab<?php echo $oc_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" data-ziel="tab-loxone"
       href="index.php?form=loxone"><?php echo $oc_reiter['tab-loxone']; ?></a>
    <a class="sm-tab<?php echo $oc_tab === 'tab-costs' ? ' sm-active' : ''; ?>" data-ziel="tab-costs"
       href="index.php?form=costs"><?php echo $oc_reiter['tab-costs']; ?></a>
    <a class="sm-tab<?php echo $oc_tab === 'tab-test' ? ' sm-active' : ''; ?>" data-ziel="tab-test"
       href="index.php?form=test"><?php echo $oc_reiter['tab-test']; ?></a>
    <a class="sm-tab<?php echo $oc_tab === 'tab-log' ? ' sm-active' : ''; ?>" data-ziel="tab-log"
       href="index.php?form=log"><?php echo $oc_reiter['tab-log']; ?></a>
</div>

<!-- ==================== Reiter: Einstellungen ==================== -->
<div class="sm-seite<?php echo $oc_tab === 'tab-settings' ? ' sm-active' : ''; ?>" id="tab-settings">

<h2><?php echo oc_t('EINST.H_ZUGANG'); ?></h2>
<div class="sm-hinweis"><?php echo oc_t('EINST.ZUGANG_ERKLAERUNG'); ?></div>
<?php ob_start(); /* X-2: das Formular laeuft durch oc_eingaben_einsetzen() */ ?>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="fmt" value="<?php echo oc_e($oc_fmt); ?>">
<input data-role="none" type="hidden" name="save_zugang" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<div class="sm-reihe">
  <div>
    <label><?php echo oc_t('EINST.EMAIL'); ?></label>
    <input data-role="none" type="text" name="z_email" value="<?php echo oc_e($oc_zug['email']); ?>"
           placeholder="dein.name@mail.de">
    <div class="sm-hilfe"><?php echo oc_t('EINST.EMAIL_HILFE'); ?></div>
  </div>
  <div>
    <label><?php echo oc_t('EINST.PASSWORT'); ?></label>
    <input data-role="none" type="password" name="z_passwort" value="" autocomplete="new-password"
           placeholder="<?php echo $oc_zug['passwort'] !== ''
               ? oc_e(str_replace('%N%', strlen($oc_zug['passwort']), oc_t('EINST.PW_HINTERLEGT')))
               : oc_e(oc_t('EINST.PW_LEER')); ?>">
    <div class="sm-hilfe"><?php echo oc_t('EINST.PASSWORT_HILFE'); ?></div>
  </div>
  <div>
    <label><?php echo oc_t('EINST.KONTO'); ?></label>
    <input data-role="none" type="text" name="z_konto" value="<?php echo oc_e($oc_zug['konto']); ?>"
           placeholder="A-1234ABCD">
    <div class="sm-hilfe"><?php echo oc_t('EINST.KONTO_HILFE'); ?></div>
  </div>
</div>
<label style="display:inline-flex;align-items:center;gap:6px;margin-top:10px;font-weight:600;">
  <input data-role="none" type="checkbox" name="zugang_loeschen" value="1">
  <?php echo oc_t('EINST.ZUGANG_LOESCHEN'); ?>
</label>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i><?php echo oc_t('LEGENDE.AKTION'); ?></span>
<span><i class="sm-punkt sm-b-lesen"></i><?php echo oc_t('LEGENDE.LESEN'); ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo oc_t('EINST.ZUGANG_SPEICHERN'); ?></button>
</div>
<div class="sm-hilfe"><?php echo str_replace('%F%',
    '<span class="sm-mono">' . oc_e($oc_p['zugang']) . '</span>', oc_t('EINST.ZUGANG_DATEI')); ?></div>
</form>
<?php echo oc_eingaben_einsetzen(ob_get_clean(), 'save_zugang', $oc_eingaben); ?>

<?php ob_start(); /* X-2 */ ?>
<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="fmt" value="<?php echo oc_e($oc_fmt); ?>">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?php echo oc_t('EINST.H_BETRIEB'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
  <input data-role="none" type="checkbox" name="enabled" <?php echo !empty($oc_cfg['enabled']) ? 'checked' : ''; ?>>
  <?php echo oc_t('EINST.AKTIV'); ?>
</label>
<div class="sm-hilfe"><?php echo oc_t('EINST.AKTIV_HILFE'); ?></div>

<label style="display:inline-flex;align-items:center;gap:6px;margin-top:12px;font-weight:600;">
  <input data-role="none" type="checkbox" name="demo" <?php echo !empty($oc_cfg['demo']) ? 'checked' : ''; ?>>
  <?php echo oc_t('EINST.DEMO'); ?>
</label>
<div class="sm-hilfe"><?php echo oc_t('EINST.DEMO_HILFE'); ?></div>
<div class="sm-reihe">
  <div>
    <label><?php echo oc_t('EINST.DEMO_AUFSCHLAG'); ?></label>
    <input data-role="none" type="text" name="demo_aufschlag" value="<?php echo oc_e($oc_cfg['demo_aufschlag']); ?>" placeholder="15.0">
  </div>
  <div>
    <label><?php echo oc_t('EINST.DEMO_VAT'); ?></label>
    <input data-role="none" type="text" name="demo_vat" value="<?php echo oc_e($oc_cfg['demo_vat']); ?>" placeholder="19">
  </div>
  <div></div>
</div>

<h2><?php echo oc_t('EINST.H_BEWERTUNG'); ?></h2>
<div class="sm-hilfe"><?php echo oc_t('EINST.BEWERTUNG_HILFE'); ?></div>
<div class="sm-reihe">
  <div>
    <label><?php echo oc_t('EINST.CHEAP'); ?></label>
    <input data-role="none" type="text" name="cheap" value="<?php echo oc_e($oc_cfg['cheap']); ?>" placeholder="20">
  </div>
  <div>
    <label><?php echo oc_t('EINST.EXPENSIVE'); ?></label>
    <input data-role="none" type="text" name="expensive" value="<?php echo oc_e($oc_cfg['expensive']); ?>" placeholder="35">
  </div>
  <div>
    <label><?php echo oc_t('EINST.WINDOW'); ?></label>
    <input data-role="none" type="text" name="window" value="<?php echo (int) $oc_cfg['window']; ?>" placeholder="3">
    <div class="sm-hilfe"><?php echo oc_t('EINST.WINDOW_HILFE'); ?></div>
  </div>
</div>

<h2><?php echo oc_t('PLAN.H_TITEL'); ?></h2>
<div class="sm-hinweis"><?php echo oc_t('PLAN.ERKLAERUNG'); ?></div>
<div class="sm-row">
  <div><label><?php echo oc_t('PLAN.L_BUDGET_KW'); ?></label>
    <input data-role="none" type="text" name="budget_kw" value="<?php echo oc_e($oc_cfg['budget_kw']); ?>" placeholder="0">
    <div class="sm-hilfe"><?php echo oc_t('PLAN.H_BUDGET_KW'); ?></div></div>
  <div><label><?php echo oc_t('PLAN.L_PV_BONUS'); ?></label>
    <input data-role="none" type="text" name="pv_bonus" value="<?php echo oc_e($oc_cfg['pv_bonus']); ?>" placeholder="0">
    <div class="sm-hilfe"><?php echo oc_t('PLAN.H_PV_BONUS'); ?></div></div>
  <div><label><?php echo oc_t('PLAN.L_PV_SCHWELLE'); ?></label>
    <input data-role="none" type="number" name="pv_schwelle" value="<?php echo (int) $oc_cfg['pv_schwelle']; ?>" min="1" max="100000">
    <div class="sm-hilfe"><?php echo oc_t('PLAN.H_PV_SCHWELLE'); ?></div></div>
</div>
<div class="sm-row">
  <div><label><?php echo oc_t('PLAN.L_BUDGET2_KW'); ?></label>
    <input data-role="none" type="text" name="budget2_kw" value="<?php echo oc_e($oc_cfg['budget2_kw']); ?>" placeholder="0">
    <div class="sm-hilfe"><?php echo oc_t('PLAN.H_BUDGET2_KW'); ?></div></div>
  <div><label><?php echo oc_t('PLAN.L_BUDGET2_VON'); ?></label>
    <input data-role="none" type="number" name="budget2_von" value="<?php echo (int) $oc_cfg['budget2_von']; ?>" min="0" max="23"></div>
  <div><label><?php echo oc_t('PLAN.L_BUDGET2_BIS'); ?></label>
    <input data-role="none" type="number" name="budget2_bis" value="<?php echo (int) $oc_cfg['budget2_bis']; ?>" min="0" max="23">
    <div class="sm-hilfe"><?php echo oc_t('PLAN.H_BUDGET2_ZEIT'); ?></div></div>
</div>
<label style="display:inline-flex;align-items:center;gap:8px;margin-top:8px;font-weight:600;">
  <input data-role="none" type="checkbox" name="hysterese" <?php echo !empty($oc_cfg['hysterese']) ? 'checked' : ''; ?>>
  <?php echo oc_t('PLAN.L_HYSTERESE'); ?>
</label>
<div class="sm-hilfe"><?php echo oc_t('PLAN.H_HYSTERESE'); ?></div>
<div class="sm-row">
  <div><label><?php echo oc_t('PLAN.L_PV_QUELLE'); ?></label>
    <select data-role="none" name="pv_quelle">
<?php foreach (array('', 'forecast_solar', 'objekt', 'liste') as $oc_q2) { ?>
      <option value="<?php echo oc_e($oc_q2); ?>"<?php echo $oc_cfg['pv_quelle'] === $oc_q2 ? ' selected' : ''; ?>><?php echo oc_e(oc_t('PLAN.QUELLE_' . ($oc_q2 === '' ? 'AUS' : strtoupper($oc_q2)))); ?></option>
<?php } ?>
    </select></div>
  <div><label><?php echo oc_t('PLAN.L_PV_URL'); ?></label>
    <input data-role="none" type="text" name="pv_url" value="<?php echo oc_e($oc_cfg['pv_url']); ?>" placeholder="https://api.forecast.solar/estimate/...">
    <div class="sm-hilfe"><?php echo oc_t('PLAN.H_PV_URL'); ?></div></div>
  <div><label><?php echo oc_t('PLAN.L_PV_EINHEIT'); ?></label>
    <select data-role="none" name="pv_einheit">
<?php foreach (array('wh', 'w', 'kw') as $oc_e4) { ?>
      <option value="<?php echo $oc_e4; ?>"<?php echo $oc_cfg['pv_einheit'] === $oc_e4 ? ' selected' : ''; ?>><?php echo oc_e(oc_t('PLAN.EINHEIT_' . strtoupper($oc_e4))); ?></option>
<?php } ?>
    </select>
    <div class="sm-hilfe"><?php echo oc_t('PLAN.H_PV_EINHEIT'); ?></div></div>
</div>
<div class="sm-row">
  <div><label><?php echo oc_t('PLAN.L_PV_PFAD'); ?></label>
    <input data-role="none" type="text" name="pv_pfad" value="<?php echo oc_e($oc_cfg['pv_pfad']); ?>" placeholder="forecasts">
    <div class="sm-hilfe"><?php echo oc_t('PLAN.H_PV_PFAD'); ?></div></div>
  <div><label><?php echo oc_t('PLAN.L_PV_ZEITFELD'); ?></label>
    <input data-role="none" type="text" name="pv_zeitfeld" value="<?php echo oc_e($oc_cfg['pv_zeitfeld']); ?>" placeholder="period_end"></div>
  <div><label><?php echo oc_t('PLAN.L_PV_WERTFELD'); ?></label>
    <input data-role="none" type="text" name="pv_wertfeld" value="<?php echo oc_e($oc_cfg['pv_wertfeld']); ?>" placeholder="pv_estimate">
    <div class="sm-hilfe"><?php echo oc_t('PLAN.H_PV_FELDER'); ?></div></div>
</div>
<div class="sm-row">
  <div><label><?php echo oc_t('PLAN.L_SOC_URL'); ?></label>
    <input data-role="none" type="text" name="soc_url" value="<?php echo oc_e($oc_cfg['soc_url']); ?>" placeholder="http://loxberry/plugins/...">
    <div class="sm-hilfe"><?php echo oc_t('PLAN.H_SOC_URL'); ?></div></div>
  <div><label><?php echo oc_t('PLAN.L_SOC_PFAD'); ?></label>
    <input data-role="none" type="text" name="soc_pfad" value="<?php echo oc_e($oc_cfg['soc_pfad']); ?>" placeholder="geraete.1.soc">
    <div class="sm-hilfe"><?php echo oc_t('PLAN.H_SOC_PFAD'); ?></div></div>
</div>

<h2><?php echo oc_t('VERB.H_TITEL'); ?></h2>
<div class="sm-hinweis"><?php echo oc_t('VERB.ERKLAERUNG'); ?></div>
<div class="sm-row">
  <div><label><?php echo oc_t('VERB.L_QUELLE'); ?></label>
    <select data-role="none" name="verbrauch_quelle">
<?php foreach (array('', 'objekt', 'liste') as $oc_q3) { ?>
      <option value="<?php echo oc_e($oc_q3); ?>"<?php echo $oc_cfg['verbrauch_quelle'] === $oc_q3 ? ' selected' : ''; ?>><?php echo oc_e(oc_t('PLAN.QUELLE_' . ($oc_q3 === '' ? 'AUS' : strtoupper($oc_q3)))); ?></option>
<?php } ?>
    </select></div>
  <div><label><?php echo oc_t('VERB.L_URL'); ?></label>
    <input data-role="none" type="text" name="verbrauch_url" value="<?php echo oc_e($oc_cfg['verbrauch_url']); ?>" placeholder="http://loxberry/plugins/...">
    <div class="sm-hilfe"><?php echo oc_t('VERB.H_URL'); ?></div></div>
  <div><label><?php echo oc_t('VERB.L_EINHEIT'); ?></label>
    <select data-role="none" name="verbrauch_einheit">
<?php foreach (array('wh', 'w', 'kw') as $oc_e5) { ?>
      <option value="<?php echo $oc_e5; ?>"<?php echo $oc_cfg['verbrauch_einheit'] === $oc_e5 ? ' selected' : ''; ?>><?php echo oc_e(oc_t('PLAN.EINHEIT_' . strtoupper($oc_e5))); ?></option>
<?php } ?>
    </select></div>
</div>
<div class="sm-row">
  <div><label><?php echo oc_t('VERB.L_PFAD'); ?></label>
    <input data-role="none" type="text" name="verbrauch_pfad" value="<?php echo oc_e($oc_cfg['verbrauch_pfad']); ?>" placeholder="werte"></div>
  <div><label><?php echo oc_t('VERB.L_ZEITFELD'); ?></label>
    <input data-role="none" type="text" name="verbrauch_zeitfeld" value="<?php echo oc_e($oc_cfg['verbrauch_zeitfeld']); ?>" placeholder="zeit"></div>
  <div><label><?php echo oc_t('VERB.L_WERTFELD'); ?></label>
    <input data-role="none" type="text" name="verbrauch_wertfeld" value="<?php echo oc_e($oc_cfg['verbrauch_wertfeld']); ?>" placeholder="wh">
    <div class="sm-hilfe"><?php echo oc_t('VERB.H_FELDER'); ?></div></div>
</div>
<?php
/* Was zuletzt geholt wurde - und der Grund, wenn nichts ankam. Ohne diese
 * Zeile weiss niemand, ob die Adresse taugt: der Kostenvergleich saehe
 * genauso aus wie mit dem geschaetzten Profil. */
$oc_vb = oc_verbrauch();
if ($oc_cfg['verbrauch_quelle'] !== '') { ?>
<div class="sm-hinweis"><?php
if (is_array($oc_vb['profil'])) {
    echo str_replace('%T%', (int) $oc_vb['tage'], oc_t('VERB.STAND_OK'));
} else {
    echo str_replace('%M%', oc_e($oc_vb['meldung'] !== '' ? $oc_vb['meldung'] : '-'),
        oc_t('VERB.STAND_FEHLT'));
}
?></div>
<?php } ?>
<?php $oc_umw = oc_umwelt();
if ($oc_cfg['pv_quelle'] !== '' || $oc_cfg['soc_url'] !== '') { ?>
<div class="sm-hinweis">
  <?php /* Ohne oc_e(): der Text enthaelt absichtlich <b>-Auszeichnung. Mit
           Maskierung stand hier woertlich "<b>" auf der Seite. Die beiden
           eingesetzten Werte sind unbedenklich - oc_num() liefert eine
           formatierte Zahl, sonst steht dort ein Gedankenstrich. */
        echo sprintf(oc_t('PLAN.STAND'),
      $oc_umw['pv_summe'] === null ? '–' : oc_num($oc_umw['pv_summe'], 1),
      $oc_umw['soc'] === null ? '–' : oc_num($oc_umw['soc'], 0)); ?>
<?php if (!empty($oc_umw['pv_meldung'])) { ?>
  <br>PV: <?php echo oc_e(oc_t('PLANMELD.' . $oc_umw['pv_meldung'])); ?>
<?php } ?>
<?php if (!empty($oc_umw['soc_meldung'])) { ?>
  <br><?php echo oc_t('PLAN.SPEICHER'); ?>: <?php echo oc_e(oc_t('PLANMELD.' . $oc_umw['soc_meldung'])); ?>
<?php } ?>
</div>
<?php } ?>

<h2><?php echo oc_t('REGEL.H_TITEL'); ?></h2>
<div class="sm-hinweis"><?php echo oc_t('REGEL.ERKLAERUNG'); ?></div>
<?php for ($oc_i = 0; $oc_i < OC_REGELN; $oc_i++) {
    $oc_rr = $oc_cfg['regeln'][$oc_i]; ?>
<div class="sm-step">
  <label style="display:inline-flex;align-items:center;gap:8px;font-weight:600;">
    <input data-role="none" type="checkbox" name="r_aktiv[<?php echo $oc_i; ?>]" value="1" <?php echo !empty($oc_rr['aktiv']) ? 'checked' : ''; ?>>
    <?php echo sprintf(oc_t('REGEL.L_AKTIV'), $oc_i + 1); ?>
  </label>
  <div class="sm-row" style="margin-top:8px;">
    <div><label><?php echo oc_t('REGEL.L_NAME'); ?></label>
      <input data-role="none" type="text" name="r_name[<?php echo $oc_i; ?>]" value="<?php echo oc_e($oc_rr['name']); ?>" placeholder="<?php echo oc_t('REGEL.P_NAME'); ?>"></div>
    <div><label><?php echo oc_t('REGEL.L_ART'); ?></label>
      <select data-role="none" name="r_art[<?php echo $oc_i; ?>]">
<?php /* Die Liste kommt aus oc_regel_arten(). Bis 1.0.9 stand sie hier
         ein zweites Mal - eine neue Art waere an einer der beiden
         Stellen vergessen worden, und das Formular haette etwas
         angeboten, das die Konfiguration wieder verwirft. */
foreach (oc_regel_arten() as $oc_a) { ?>
        <option value="<?php echo $oc_a; ?>"<?php echo $oc_rr['art'] === $oc_a ? ' selected' : ''; ?>><?php echo oc_e(oc_t('REGEL.ART_' . strtoupper($oc_a))); ?></option>
<?php } ?>
      </select></div>
  </div>
  <div class="sm-row">
    <div><label><?php echo oc_t('REGEL.L_N'); ?></label>
      <input data-role="none" type="number" name="r_n[<?php echo $oc_i; ?>]" value="<?php echo (int) $oc_rr['n']; ?>" min="1" max="12"></div>
    <div><label><?php echo oc_t('REGEL.L_SCHWELLE'); ?></label>
      <input data-role="none" type="text" name="r_schwelle[<?php echo $oc_i; ?>]" value="<?php echo oc_e($oc_rr['schwelle']); ?>"></div>
    <div><label><?php echo oc_t('REGEL.L_PROZENT'); ?></label>
      <input data-role="none" type="number" name="r_prozent[<?php echo $oc_i; ?>]" value="<?php echo (int) $oc_rr['prozent']; ?>" min="0" max="90"></div>
  </div>
  <div class="sm-row">
    <div><label><?php echo oc_t('REGEL.L_VON'); ?></label>
      <input data-role="none" type="number" name="r_von[<?php echo $oc_i; ?>]" value="<?php echo (int) $oc_rr['von']; ?>" min="0" max="23"></div>
    <div><label><?php echo oc_t('REGEL.L_BIS'); ?></label>
      <input data-role="none" type="number" name="r_bis[<?php echo $oc_i; ?>]" value="<?php echo (int) $oc_rr['bis']; ?>" min="0" max="23"></div>
    <div><label><?php echo oc_t('REGEL.L_HORIZONT'); ?></label>
      <input data-role="none" type="number" name="r_horizont[<?php echo $oc_i; ?>]" value="<?php echo (int) $oc_rr['horizont']; ?>" min="1" max="48"></div>
    <div><label><?php echo oc_t('REGEL.L_FRIST'); ?></label>
      <select data-role="none" name="r_frist[<?php echo $oc_i; ?>]">
        <option value="-1"<?php echo (int) $oc_rr['frist'] < 0 ? ' selected' : ''; ?>><?php echo oc_t('REGEL.FRIST_KEINE'); ?></option>
<?php for ($oc_h = 0; $oc_h < 24; $oc_h++) { ?>
        <option value="<?php echo $oc_h; ?>"<?php echo (int) $oc_rr['frist'] === $oc_h ? ' selected' : ''; ?>><?php echo sprintf('%02d:00', $oc_h); ?></option>
<?php } ?>
      </select>
      <div class="sm-hilfe"><?php echo oc_t('REGEL.H_FRIST'); ?></div></div>
  </div>
  <div class="sm-row">
    <div><label><?php echo oc_t('REGEL.L_RANG'); ?></label>
      <input data-role="none" type="number" name="r_rang[<?php echo $oc_i; ?>]" value="<?php echo (int) $oc_rr['rang']; ?>" min="1" max="99">
      <div class="sm-hilfe"><?php echo oc_t('REGEL.H_RANG'); ?></div></div>
    <div><label><?php echo oc_t('REGEL.L_LEISTUNG'); ?></label>
      <input data-role="none" type="text" name="r_leistung[<?php echo $oc_i; ?>]" value="<?php echo oc_e($oc_rr['leistung']); ?>" placeholder="0">
      <div class="sm-hilfe"><?php echo oc_t('REGEL.H_LEISTUNG'); ?></div></div>
    <div><label><?php echo oc_t('REGEL.L_ENERGIE'); ?></label>
      <input data-role="none" type="text" name="r_energie[<?php echo $oc_i; ?>]" value="<?php echo oc_e($oc_rr['energie']); ?>" placeholder="0">
      <div class="sm-hilfe"><?php echo oc_t('REGEL.H_ENERGIE'); ?></div></div>
  </div>
  <div class="sm-row">
    <div><label><?php echo oc_t('REGEL.L_PV_SPERRE'); ?></label>
      <input data-role="none" type="text" name="r_pv_sperre[<?php echo $oc_i; ?>]" value="<?php echo oc_e($oc_rr['pv_sperre']); ?>" placeholder="0">
      <div class="sm-hilfe"><?php echo oc_t('REGEL.H_PV_SPERRE'); ?></div></div>
    <div><label><?php echo oc_t('REGEL.L_SOC_MIN'); ?></label>
      <input data-role="none" type="number" name="r_soc_min[<?php echo $oc_i; ?>]" value="<?php echo (int) $oc_rr['soc_min']; ?>" min="0" max="100"></div>
    <div><label><?php echo oc_t('REGEL.L_SOC_MAX'); ?></label>
      <input data-role="none" type="number" name="r_soc_max[<?php echo $oc_i; ?>]" value="<?php echo (int) $oc_rr['soc_max']; ?>" min="0" max="100">
      <div class="sm-hilfe"><?php echo oc_t('REGEL.H_SOC'); ?></div></div>
  </div>
  <div class="sm-row">
    <div><label><?php echo oc_t('REGEL.L_MIN_LAUF'); ?></label>
      <input data-role="none" type="number" name="r_min_lauf[<?php echo $oc_i; ?>]" value="<?php echo (int) $oc_rr['min_lauf']; ?>" min="0" max="720">
      <div class="sm-hilfe"><?php echo oc_t('REGEL.H_MIN_LAUF'); ?></div></div>
    <div><label><?php echo oc_t('REGEL.L_MIN_PAUSE'); ?></label>
      <input data-role="none" type="number" name="r_min_pause[<?php echo $oc_i; ?>]" value="<?php echo (int) $oc_rr['min_pause']; ?>" min="0" max="720">
      <div class="sm-hilfe"><?php echo oc_t('REGEL.H_MIN_PAUSE'); ?></div></div>
    <div></div>
  </div>
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="r_neg[<?php echo $oc_i; ?>]" value="1" <?php echo !empty($oc_rr['neg']) ? 'checked' : ''; ?>>
    <?php echo oc_t('REGEL.L_NEG'); ?>
  </label>
  <div class="sm-hilfe"><?php echo sprintf(oc_t('REGEL.H_AUSGANG'), $oc_i + 1, $oc_i + 1, $oc_i + 1, $oc_i + 1); ?></div>
</div>
<?php } ?>
<div class="sm-feld">
  <label for="profil_ein"><?php echo oc_t('REGEL.L_PROFIL'); ?></label>
  <select data-role="none" id="profil_ein" name="profil_ein">
<?php foreach (array('aus', 'absolut', 'relativ', 'beides') as $oc_pv) { ?>
    <option value="<?php echo $oc_pv; ?>"<?php echo (string) $oc_cfg['profil_ein'] === $oc_pv ? ' selected' : ''; ?>><?php echo oc_e(oc_t('REGEL.PROFIL_' . strtoupper($oc_pv))); ?></option>
<?php } ?>
  </select>
  <div class="sm-hilfe"><?php echo oc_t('REGEL.H_PROFIL'); ?></div>
</div>

<h2><?php echo oc_t('EINST.H_CO2'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
  <input data-role="none" type="checkbox" name="co2_enabled" <?php echo !empty($oc_cfg['co2_enabled']) ? 'checked' : ''; ?>>
  <?php echo oc_t('EINST.CO2_AN'); ?>
</label>
<div class="sm-hilfe"><?php echo oc_t('EINST.CO2_HILFE'); ?></div>
<div class="sm-reihe">
  <div>
    <label><?php echo oc_t('EINST.CO2_CLEAN'); ?></label>
    <input data-role="none" type="text" name="co2_clean" value="<?php echo oc_e($oc_cfg['co2_clean']); ?>" placeholder="200">
  </div>
  <div></div><div></div>
</div>

<h2><?php echo oc_t('EINST.H_MELDUNG'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
  <input data-role="none" type="checkbox" name="notify_audio" <?php echo !empty($oc_cfg['notify']['audio']) ? 'checked' : ''; ?>>
  <?php echo oc_t('EINST.ANSAGE_AN'); ?>
</label><br>
<label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-weight:600;">
  <input data-role="none" type="checkbox" name="notify_push" <?php echo !empty($oc_cfg['notify']['push']) ? 'checked' : ''; ?>>
  <?php echo oc_t('EINST.PUSH_AN'); ?>
</label>
<div class="sm-hilfe"><?php echo oc_t('EINST.PUSH_HILFE'); ?></div>

<h3><?php echo oc_t('EINST.STUNDEN'); ?></h3>
<div class="sm-hilfe"><?php echo oc_t('EINST.STUNDEN_HILFE'); ?></div>
<div class="sm-stunden">
<?php for ($oc_h = 0; $oc_h < 24; $oc_h++) { ?>
  <label><input data-role="none" type="checkbox" name="hours[]" value="<?php echo $oc_h; ?>"
    <?php echo in_array($oc_h, $oc_hoursel, true) ? 'checked' : ''; ?>>
    <?php printf('%02d:00', $oc_h); ?></label>
<?php } ?>
</div>
<label style="display:inline-flex;align-items:center;gap:6px;margin-top:8px;font-weight:600;">
  <input data-role="none" type="checkbox" name="only_cheap" <?php echo !empty($oc_cfg['notify']['only_cheap']) ? 'checked' : ''; ?>>
  <?php echo oc_t('EINST.ONLY_CHEAP'); ?>
</label><br>
<label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-weight:600;">
  <input data-role="none" type="checkbox" name="neg_always" <?php echo !empty($oc_cfg['notify']['negative']) ? 'checked' : ''; ?>>
  <?php echo oc_t('EINST.NEG_ALWAYS'); ?>
</label><br>
<label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-weight:600;">
  <input data-role="none" type="checkbox" name="notify_tomorrow" <?php echo !empty($oc_cfg['notify']['tomorrow']) ? 'checked' : ''; ?>>
  <?php echo oc_t('EINST.TOMORROW'); ?>
</label>
<br>
<label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;margin-top:6px;">
  <input data-role="none" type="checkbox" name="notify_lb" <?php echo !empty($oc_cfg['notify']['lb']) ? 'checked' : ''; ?>>
  <?php echo oc_t('EINST.NOTIFY_LB'); ?>
</label>
<div class="sm-row" style="max-width:260px;">
  <div><label><?php echo oc_t('EINST.NOTIFY_LB_STUNDEN'); ?></label>
    <input data-role="none" type="number" name="notify_lb_stunden" value="<?php echo (int) $oc_cfg['notify']['lb_stunden']; ?>" min="1" max="72"></div>
</div>
<div class="sm-hilfe"><?php echo oc_t('EINST.NOTIFY_LB_HILFE'); ?></div>

<h3><?php echo oc_t('EINST.H_TTS'); ?></h3>
<div class="sm-reihe">
  <div>
    <label><?php echo oc_t('EINST.TTS_MODE'); ?></label>
    <select data-role="none" name="tts_mode">
      <option value="musicserver" <?php echo $oc_tts['mode'] === 'musicserver' ? 'selected' : ''; ?>><?php echo oc_t('EINST.TTS_MS'); ?></option>
      <option value="ms4h" <?php echo $oc_tts['mode'] === 'ms4h' ? 'selected' : ''; ?>><?php echo oc_t('EINST.TTS_MS4H'); ?></option>
      <option value="audioserver" <?php echo $oc_tts['mode'] === 'audioserver' ? 'selected' : ''; ?>><?php echo oc_t('EINST.TTS_AS'); ?></option>
      <option value="custom" <?php echo $oc_tts['mode'] === 'custom' ? 'selected' : ''; ?>><?php echo oc_t('EINST.TTS_CUSTOM'); ?></option>
      <option value="alexang" <?php echo $oc_tts['mode'] === 'alexang' ? 'selected' : ''; ?>><?php echo oc_t('EINST.TTS_ALEXA'); ?></option>
      <option value="cc4lox" <?php echo $oc_tts['mode'] === 'cc4lox' ? 'selected' : ''; ?>><?php echo oc_t('EINST.TTS_GOOGLE'); ?></option>
    </select>
  </div>
  <div>
    <label><?php echo oc_t('EINST.TTS_IP'); ?></label>
    <input data-role="none" type="text" name="tts_ip" value="<?php echo oc_e($oc_tts['ip']); ?>" placeholder="192.168.1.20">
  </div>
  <div>
    <label><?php echo oc_t('EINST.TTS_PORT'); ?></label>
    <input data-role="none" type="text" name="tts_port" value="<?php echo (int) $oc_tts['port']; ?>" placeholder="7091">
  </div>
  <div>
    <label><?php echo oc_t('EINST.TTS_ZONES'); ?></label>
    <input data-role="none" type="text" name="tts_zones" value="<?php echo oc_e($oc_tts['zones']); ?>" placeholder="1,2">
  </div>
  <div>
    <label><?php echo oc_t('EINST.TTS_VOLUME'); ?></label>
    <input data-role="none" type="text" name="tts_volume" value="<?php echo (int) $oc_tts['volume']; ?>" placeholder="8">
    <div class="sm-hilfe"><?php echo oc_t('EINST.TTS_VOL_HILFE'); ?></div>
  </div>
  <div>
    <label><?php echo oc_t('EINST.TTS_LANG'); ?></label>
    <input data-role="none" type="text" name="tts_lang" value="<?php echo oc_e($oc_tts['lang']); ?>" placeholder="de">
  </div>
</div>
<div class="sm-feld">
  <label><?php echo oc_t('EINST.TTS_TEMPLATE'); ?></label>
  <input data-role="none" type="text" name="tts_template" value="<?php echo oc_e($oc_tts['template']); ?>"
         placeholder="http://{ip}:{port}/tts?text={text}&amp;zone={zones}&amp;vol={vol}">
  <div class="sm-hilfe"><?php echo oc_t('EINST.TTS_TEMPLATE_HILFE'); ?></div>
</div>
<?php /* Ansage-2: Ausgabeweg Alexa-NG. Das Token kommt nie in die Seite -
         das Feld ist immer leer, der Platzhalter sagt, ob eines hinterlegt ist. */ ?>
<div class="sm-reihe">
  <div>
    <label><?php echo oc_t('EINST.TTS_ALEXA_GERAET'); ?></label>
    <input data-role="none" type="text" name="tts_alexa_geraet" value="<?php echo oc_e($oc_tts['alexa_geraet']); ?>" placeholder="kueche">
    <div class="sm-hilfe"><?php echo oc_t('EINST.TTS_ALEXA_GERAET_HILFE'); ?></div>
  </div>
  <div>
    <label><?php echo oc_t('EINST.TTS_ALEXA_TOKEN'); ?></label>
    <input data-role="none" type="password" name="tts_alexa_token" value="" autocomplete="new-password" placeholder="<?php echo oc_e(oc_t((string) $oc_tts['alexa_token'] !== '' ? 'EINST.TTS_ALEXA_TOKEN_DA' : 'EINST.TTS_ALEXA_TOKEN_LEER')); ?>">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-top:4px;">
      <input data-role="none" type="checkbox" name="tts_alexa_token_weg" value="1">
      <?php echo oc_t('EINST.TTS_ALEXA_TOKEN_WEG'); ?>
    </label>
  </div>
</div>
<div class="sm-hilfe"><?php echo oc_t('EINST.TTS_ALEXA_HILFE'); ?></div>
<?php /* Ansage-3: Google-Lautsprecher ueber Chromecast 4 Lox NG. Ein eigenes
         Sprechtoken, wie das von Alexa-NG nie in der Seite. */ ?>
<div class="sm-reihe">
  <div>
    <label><?php echo oc_t('EINST.TTS_GOOGLE_GERAET'); ?></label>
    <input data-role="none" type="text" name="tts_google_geraet" value="<?php echo oc_e($oc_tts['google_geraet']); ?>" placeholder="Wohnzimmer">
    <div class="sm-hilfe"><?php echo oc_t('EINST.TTS_GOOGLE_GERAET_HILFE'); ?></div>
  </div>
  <div>
    <label><?php echo oc_t('EINST.TTS_GOOGLE_LAUT'); ?></label>
    <input data-role="none" type="text" name="tts_google_laut" value="<?php echo (int) $oc_tts['google_laut'] >= 0 ? (int) $oc_tts['google_laut'] : ''; ?>" placeholder="0-100">
    <div class="sm-hilfe"><?php echo oc_t('EINST.TTS_GOOGLE_LAUT_HILFE'); ?></div>
  </div>
  <div>
    <label><?php echo oc_t('EINST.TTS_GOOGLE_TOKEN'); ?></label>
    <input data-role="none" type="password" name="tts_google_token" value="" autocomplete="new-password" placeholder="<?php echo oc_e(oc_t((string) $oc_tts['google_token'] !== '' ? 'EINST.TTS_ALEXA_TOKEN_DA' : 'EINST.TTS_ALEXA_TOKEN_LEER')); ?>">
    <label style="display:inline-flex;align-items:center;gap:6px;margin-top:4px;">
      <input data-role="none" type="checkbox" name="tts_google_token_weg" value="1">
      <?php echo oc_t('EINST.TTS_GOOGLE_TOKEN_WEG'); ?>
    </label>
  </div>
</div>
<div class="sm-hilfe"><?php echo oc_t('EINST.TTS_GOOGLE_HILFE'); ?></div>

<?php /* MQTT stand hier bis zu dieser Fassung. Es wohnt jetzt
         vollstaendig im Reiter MQTT - eine Sache, eine Stelle. */ ?>

<h2><?php echo oc_t('EINST.H_TOKEN'); ?></h2>
<div class="sm-hilfe"><?php echo oc_t('EINST.TOKEN_HILFE'); ?></div>
<div class="sm-pre"><?php echo oc_e($oc_endpunkt); ?>?token=<?php
    echo oc_e($oc_cfg['aktionstoken'] !== '' ? $oc_cfg['aktionstoken'] : oc_t('EINST.TOKEN_LEER'));
?>&amp;aktion=status</div>
<label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
  <input data-role="none" type="checkbox" name="token_neu" value="1">
  <?php echo oc_t('EINST.TOKEN_NEU'); ?>
</label>

<h2><?php echo oc_t('EINST.H_VERBRAUCH'); ?></h2>
<div class="sm-hilfe"><?php echo oc_t('EINST.VERBRAUCH_HILFE'); ?></div>
<div class="sm-reihe">
  <div>
    <label><?php echo oc_t('EINST.CONSUMPTION'); ?></label>
    <input data-role="none" type="text" name="consumption" value="<?php echo (int) $oc_cfg['consumption']; ?>" placeholder="3500">
  </div>
  <div>
    <label><?php echo oc_t('EINST.SHIFT_KWH'); ?></label>
    <input data-role="none" type="text" name="shift_kwh" value="<?php echo oc_e($oc_cfg['shift_kwh']); ?>" placeholder="3.0">
    <div class="sm-hilfe"><?php echo oc_t('EINST.SHIFT_HILFE'); ?></div>
  </div>
  <div></div>
</div>
<h3><?php echo oc_t('EINST.MONATE'); ?></h3>
<div class="sm-hilfe"><?php echo oc_t('EINST.MONATE_HILFE'); ?></div>
<div class="sm-monate">
<?php
$oc_mnamen = array('MON.JAN', 'MON.FEB', 'MON.MAR', 'MON.APR', 'MON.MAI', 'MON.JUN',
                   'MON.JUL', 'MON.AUG', 'MON.SEP', 'MON.OKT', 'MON.NOV', 'MON.DEZ');
for ($oc_i = 0; $oc_i < 12; $oc_i++) { ?>
  <div><label><?php echo oc_t($oc_mnamen[$oc_i]); ?></label>
  <input data-role="none" type="text" name="months[<?php echo $oc_i; ?>]"
         value="<?php echo $oc_mon['kwh'][$oc_i] > 0 ? oc_e($oc_mon['kwh'][$oc_i]) : ''; ?>" placeholder="0"></div>
<?php } ?>
</div>

<h2><?php echo oc_t('EINST.H_VERGLEICH'); ?></h2>
<div class="sm-hilfe"><?php echo oc_t('EINST.VERGLEICH_HILFE'); ?></div>
<div class="sm-reihe">
  <div>
    <label><?php echo oc_t('EINST.FIXED_PRICE'); ?></label>
    <input data-role="none" type="text" name="fixed_price" value="<?php echo oc_e($oc_cfg['fixed_price']); ?>" placeholder="30.90">
  </div>
  <div>
    <label><?php echo oc_t('EINST.FIX_GRUND'); ?></label>
    <input data-role="none" type="text" name="fix_grund" value="<?php echo oc_e($oc_cfg['fix_grund']); ?>" placeholder="12.90">
  </div>
  <div>
    <label><?php echo oc_t('EINST.DYN_GRUND'); ?></label>
    <input data-role="none" type="text" name="dyn_grund" value="<?php echo oc_e($oc_cfg['dyn_grund']); ?>" placeholder="0">
    <div class="sm-hilfe"><?php echo oc_t('EINST.DYN_GRUND_HILFE'); ?></div>
  </div>
</div>
<div class="sm-reihe">
  <div>
    <label><?php echo oc_t('EINST.SOFORTBONUS'); ?></label>
    <input data-role="none" type="text" name="fix_sofortbonus" value="<?php echo oc_e($oc_cfg['fix_sofortbonus']); ?>" placeholder="0">
  </div>
  <div>
    <label><?php echo oc_t('EINST.NEUBONUS'); ?></label>
    <input data-role="none" type="text" name="fix_neubonus" value="<?php echo oc_e($oc_cfg['fix_neubonus']); ?>" placeholder="0">
  </div>
  <div>
    <label><?php echo oc_t('EINST.NEUBONUS_PCT'); ?></label>
    <input data-role="none" type="text" name="fix_neubonus_pct" value="<?php echo oc_e($oc_cfg['fix_neubonus_pct']); ?>" placeholder="0">
  </div>
  <div>
    <label><?php echo oc_t('EINST.RABATT'); ?></label>
    <input data-role="none" type="text" name="fix_rabatt" value="<?php echo oc_e($oc_cfg['fix_rabatt']); ?>" placeholder="0">
  </div>
</div>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo oc_t('ALLGEMEIN.SPEICHERN'); ?></button>
</div>
</form>
<?php echo oc_eingaben_einsetzen(ob_get_clean(), 'save', $oc_eingaben); ?>

<h2><?= oc_t('EINST.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= oc_t('EINST.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= oc_t('EINST.SICH_WARNUNG') ?></div>
<?php
/* X-3 (Verbesserungsbau 30.09.2026): Wuerde ein gespeicherter Wert das
 * Zurueckspielen der eigenen Sicherung nicht bestehen, steht es HIER - am
 * Knopf, denn der Download selbst kann keine Seitenmeldung tragen. Gelb: die
 * Sicherung wird trotzdem geliefert. Dieselbe Pruefung wie beim
 * Zurueckspielen (oc_sicherung_altwerte() ruft oc_sicherung_lesen()). Mit
 * dem Haken "Zugangsdaten mitsichern" kommen deren Werte dazu - gezeigt wird,
 * was NUR dann zutrifft, als eigene Zeile. */
$oc_x3_ohne = oc_sicherung_altwerte(false);
$oc_x3_mit = ($oc_zug['email'] !== '' || $oc_zug['passwort'] !== '' || $oc_zug['konto'] !== '')
    ? array_values(array_diff(oc_sicherung_altwerte(true), $oc_x3_ohne)) : array();
if ($oc_x3_ohne || $oc_x3_mit) { ?>
<div class="sm-warnung"><?php echo oc_t('EINST.SICH_ALTWERT'); ?>
<?php if ($oc_x3_ohne) { ?><ul><?php foreach ($oc_x3_ohne as $oc_x3) { ?><li><?php echo oc_e($oc_x3); ?></li><?php } ?></ul><?php } ?>
<?php if ($oc_x3_mit) { ?><?php echo oc_t('EINST.SICH_ALTWERT_MIT'); ?><ul><?php foreach ($oc_x3_mit as $oc_x3) { ?><li><?php echo oc_e($oc_x3); ?></li><?php } ?></ul><?php } ?>
<?php echo oc_t('EINST.SICH_ALTWERT_HILFE'); ?></div>
<?php } ?>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?php echo oc_e($oc_fmt); ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <label style="display:inline-flex;align-items:center;gap:6px;margin:0 10px 0 0;font-weight:600;font-size:0.9em;">
      <input data-role="none" type="checkbox" name="mit_zugang" value="1">
      <?= oc_t('EINST.SICH_MIT_ZUGANG') ?>
    </label>
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="oc_sichern" value="1"><?= oc_t('EINST.K_SICHERN') ?></button>
  </form>
</div>
<!-- ZWEITE REIHE, und das ist Absicht.
     Lesende und schaltende Knoepfe kommen nie in dieselbe Reihe.
     Hier waren 'Einstellungen sichern' (gruen, liest nur) und
     'Zurueckspielen' (orange, ueberschreibt die komplette
     Konfiguration samt Aktionstoken) nebeneinander - ausgerechnet
     dort, wo der Fehlgriff am teuersten ist. -->
<div class="sm-knopfreihe">
  <form action="index.php" method="post" enctype="multipart/form-data">
<input data-role="none" type="hidden" name="fmt" value="<?php echo oc_e($oc_fmt); ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="file" name="oc_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="oc_zurueck" value="1"><?= oc_t('EINST.K_ZURUECK') ?></button>
  </form>
</div>
<div class="sm-hilfe"><?= oc_t('EINST.SICH_MIT_ZUGANG_HILFE') ?></div>
</div><!-- /tab-settings -->

<!-- ==================== Reiter: MQTT ==================== -->
<div class="sm-seite<?php echo $oc_tab === 'tab-mqtt' ? ' sm-active' : ''; ?>" id="tab-mqtt">

<h2>MQTT</h2>
<?php ob_start(); /* X-2 */ ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?php echo oc_e($oc_fmt); ?>">
<input data-role="none" type="hidden" name="save_mqtt" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<h2><?php echo oc_t('EINST.H_MQTT'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;font-weight:600;">
  <input data-role="none" type="checkbox" name="mqtt_enabled" <?php echo !empty($oc_cfg['mqtt_enabled']) ? 'checked' : ''; ?>>
  <?php echo oc_t('EINST.MQTT_AN'); ?>
</label>
<div class="sm-reihe">
  <div>
    <label><?php echo oc_t('EINST.MQTT_TOPIC'); ?></label>
    <input data-role="none" type="text" name="mqtt_topic" value="<?php echo oc_e($oc_cfg['mqtt_topic']); ?>" placeholder="octopus">
    <div class="sm-hilfe"><?php echo oc_t('EINST.MQTT_TOPIC_HILFE'); ?></div>
  </div>
  <div></div><div></div>
</div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?php echo oc_t('LEGENDE.AKTION'); ?></span></div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo oc_t('ALLGEMEIN.SPEICHERN'); ?></button>
</div>
</form>
<?php echo oc_eingaben_einsetzen(ob_get_clean(), 'save_mqtt', $oc_eingaben); ?>
<h2><?php echo oc_t('MQTT.H_ZUSTAND'); ?></h2>
<div class="sm-hinweis"><?php echo oc_t('MQTT.GATEWAY_ERKLAERUNG'); ?></div>
<table class="sm-tbl">
<tr><th><?php echo oc_t('MQTT.SP_WAS'); ?></th><th><?php echo oc_t('MQTT.SP_WERT'); ?></th></tr>
<tr><td><?php echo oc_t('MQTT.AUTOSTART'); ?></td>
    <td><?php
    /* O9 (seit 1.1.16): ohne Mqtt-Abschnitt in der general.json ist der
     * Autostart nicht feststellbar - nicht "nein" (Regeln/04). */
    echo !$oc_gw['vorhanden'] ? oc_t('MQTT.NICHT_FESTSTELLBAR')
        : ($oc_gw['autostart']
        ? '<span class="sm-an">' . oc_t('ALLGEMEIN.JA') . '</span>'
        : '<span class="sm-aus">' . oc_t('ALLGEMEIN.NEIN') . '</span> &mdash; ' . oc_t('MQTT.AUTOSTART_AUS')); ?></td></tr>
<tr><td><?php echo oc_t('MQTT.BROKER'); ?></td>
    <td><span class="sm-mono"><?php echo oc_e($oc_gw['broker'] . ':' . $oc_gw['port']); ?></span></td></tr>
<tr><td><?php echo oc_t('MQTT.UDP'); ?></td>
    <td><span class="sm-mono"><?php echo (int) $oc_gw['udpport']; ?></span></td></tr>
<tr><td><?php echo oc_t('MQTT.LOKAL'); ?></td>
    <td><?php echo (int) $oc_gw['lokal'] === 1 ? oc_t('ALLGEMEIN.JA') : oc_t('ALLGEMEIN.NEIN'); ?></td></tr>
<tr><td><?php echo oc_t('MQTT.PLUGIN_AN'); ?></td>
    <td><?php echo !empty($oc_cfg['mqtt_enabled'])
        ? '<span class="sm-an">' . oc_t('ALLGEMEIN.JA') . '</span>'
        : '<span class="sm-aus">' . oc_t('ALLGEMEIN.NEIN') . '</span>'; ?></td></tr>
</table>

<h2><?php echo oc_t('MQTT.H_ABO'); ?></h2>
<div class="sm-step">
<b><?php echo oc_t('MQTT.ABO_TITEL'); ?></b><br>
<?php echo oc_t('MQTT.ABO_WEG'); ?>
<div class="sm-pre"><?php echo oc_e($oc_cfg['mqtt_topic']); ?>/#</div>
<b><?php echo oc_abo_text(); ?></b>
</div>

<h2><?php echo oc_t('MQTT.H_THEMEN'); ?></h2>
<div class="sm-hilfe"><?php echo str_replace('%P%',
    '<span class="sm-mono">' . oc_e($oc_cfg['mqtt_topic']) . '</span>', oc_t('MQTT.THEMEN_HILFE')); ?></div>
<div class="sm-hilfe"><?php echo oc_t('MQTT.RETAIN_TEXT'); ?></div>
<!-- Die Tabelle hat mehr als sechs Spalten nicht - sie bleibt schmal.
     Die Retain-Spalte kommt aus oc_retain_fuer(), also aus derselben
     Quelle, die auch sendet: zwei Listen liefen sonst auseinander. -->
<table class="sm-tbl">
<tr><th><?php echo oc_t('MQTT.SP_THEMA'); ?></th><th><?php echo oc_t('MQTT.SP_BEDEUTUNG'); ?></th>
    <th><?php echo oc_t('MQTT.SP_EINHEIT'); ?></th><th><?php echo oc_t('MQTT.SP_RETAIN'); ?></th>
    <th><?php echo oc_t('MQTT.SP_AKTUELL'); ?></th></tr>
<?php $oc_werte = oc_werte($oc_st); foreach (oc_themen() as $oc_k => $oc_info) { ?>
<tr><td><span class="sm-mono"><?php echo oc_e($oc_cfg['mqtt_topic'] . '/' . $oc_k); ?></span></td>
    <td><?php echo oc_e(oc_thema_text($oc_info)); ?></td>
    <td><?php echo oc_e($oc_info[1]); ?></td>
    <td><?php echo oc_e(oc_t(oc_retain_fuer($oc_k) ? 'MQTT.RETAIN_JA' : 'MQTT.RETAIN_NEIN')); ?></td>
    <td><?php echo oc_e(isset($oc_werte[$oc_k]) ? $oc_werte[$oc_k] : ''); ?></td></tr>
<?php } ?>
</table>
</div><!-- /tab-mqtt -->

<!-- ==================== Reiter: Einbindung in Loxone ==================== -->
<div class="sm-seite<?php echo $oc_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" id="tab-loxone">
<h2><?php echo oc_t('EM.H_TITEL'); ?></h2>
<div class="sm-hinweis"><?php echo oc_t('EM.EINLEITUNG'); ?></div>

<div class="sm-step"><b><?php echo oc_t('EM.H_SPOTOPT'); ?></b><br>
<?php echo oc_t('EM.SPOTOPT_TEXT'); ?>
<table class="sm-tbl">
<tr><th><?php echo oc_t('EM.T_VONHIER'); ?></th><th><?php echo oc_t('EM.T_ANSCHLUSS'); ?></th><th><?php echo oc_t('EM.T_BEDEUTUNG'); ?></th></tr>
<tr><td><span class="sm-mono">ph00 &hellip; ph23</span></td><td><span class="sm-mono">00:00 &hellip; 23:00</span></td><td><?php echo oc_t('EM.Z_ABSOLUT'); ?></td></tr>
<tr><td><span class="sm-mono">pr00 &hellip; pr23</span></td><td><span class="sm-mono">+0 &hellip; +23</span></td><td><?php echo oc_t('EM.Z_RELATIV'); ?></td></tr>
<tr><td><span class="sm-mono">&ndash;</span></td><td><span class="sm-mono">Tr</span></td><td><?php echo oc_t('EM.Z_TRIGGER'); ?></td></tr>
</table>
<div class="sm-warnung"><?php echo oc_t('EM.SPOTOPT_WARNUNG'); ?></div>
</div>

<div class="sm-step"><b><?php echo oc_t('EM.H_EM'); ?></b><br>
<?php echo oc_t('EM.EM_TEXT'); ?>
<table class="sm-tbl">
<tr><th><?php echo oc_t('EM.T_VONHIER'); ?></th><th><?php echo oc_t('EM.T_ANSCHLUSS'); ?></th><th><?php echo oc_t('EM.T_BEDEUTUNG'); ?></th></tr>
<tr><td><span class="sm-mono">regel1_aktiv &hellip;</span></td><td><span class="sm-mono">Prio</span></td><td><?php echo oc_t('EM.Z_PRIO'); ?></td></tr>
<tr><td><span class="sm-mono">regel1_aktiv &hellip;</span></td><td><span class="sm-mono">O</span></td><td><?php echo oc_t('EM.Z_OFFSET'); ?></td></tr>
<tr><td><span class="sm-mono">regel1_aktiv &hellip;</span></td><td><span class="sm-mono">MinSoc</span></td><td><?php echo oc_t('EM.Z_MINSOC'); ?></td></tr>
<tr><td><span class="sm-mono">regel1_aktiv &hellip;</span></td><td><span class="sm-mono">Off</span></td><td><?php echo oc_t('EM.Z_OFF'); ?></td></tr>
</table>
<div class="sm-hinweis"><?php echo oc_t('EM.EM_HINWEIS'); ?></div>
</div>

<h2><?php echo oc_t('LOX.H_SCHRITTE'); ?></h2>

<div class="sm-step"><b><?php echo oc_t('LOX.S1_T'); ?></b><br><?php echo oc_t('LOX.S1'); ?></div>

<div class="sm-step"><b><?php echo oc_t('LOX.S2_T'); ?></b><br><?php echo oc_t('LOX.S2'); ?>
<div class="sm-pre"><?php echo oc_e($oc_cfg['mqtt_topic']); ?>/#</div>
<b><?php echo oc_abo_text(); ?></b></div>

<div class="sm-step"><b><?php echo oc_t('LOX.S3_T'); ?></b><br><?php echo oc_t('LOX.S3'); ?>
<table class="sm-tbl">
<tr><th><?php echo oc_t('LOX.SP_TITEL'); ?></th><th><?php echo oc_t('LOX.SP_EINHEIT'); ?></th>
    <th><?php echo oc_t('LOX.SP_BEDEUTUNG'); ?></th></tr>
<?php foreach (oc_themen() as $oc_k => $oc_info) { ?>
<tr><td><span class="sm-mono"><?php
    // M6 (seit 1.1.16): wie der Gateway den Namen bildet - auch der
    // Schraegstrich IM Praefix wird zum Unterstrich.
    echo oc_e(oc_thema_flach($oc_cfg['mqtt_topic'] . '/' . $oc_k)); ?></span></td>
    <td><?php echo oc_e($oc_info[1] !== '' ? $oc_info[1] : '-'); ?></td>
    <td><?php echo oc_e(oc_thema_text($oc_info)); ?></td></tr>
<?php } ?>
</table>
<form action="index.php" method="post" style="margin-top:8px;">
<input data-role="none" type="hidden" name="fmt" value="<?php echo oc_e($oc_fmt); ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i><?php echo oc_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i><?php echo oc_t('PLAN.LEGENDE_TECHNIK'); ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="download" value="mqtt_in"><?php echo oc_t('LOX.DL_MQTT'); ?></button>
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="download" value="http_in"><?php echo oc_t('LOX.DL_HTTP'); ?></button>
</div>
</form>
<div class="sm-hilfe"><?php echo oc_t('LOX.DL_HILFE'); ?></div>
<!-- Hausstandard: der Satz zum zweimaligen Import gehoert SICHTBAR in
     den Reiter, nicht nur in die Hilfe. Er fehlte bis 1.1.3 ganz -
     gemessen mit einer Suche ueber Sprachdateien und Hilfetext. -->
<div class="sm-warnung"><?php echo oc_t('LOX.DL_DOPPELT'); ?></div>
</div>

<div class="sm-step"><b><?php echo oc_t('LOX.S4_T'); ?></b><br><?php echo oc_t('LOX.S4'); ?>
<div class="sm-pre"><?php echo oc_e($oc_endpunkt); ?>?token=<?php echo oc_e($oc_cfg['aktionstoken']); ?>&amp;aktion=say
<?php echo oc_e($oc_endpunkt); ?>?token=<?php echo oc_e($oc_cfg['aktionstoken']); ?>&amp;aktion=ptest
<?php echo oc_e($oc_endpunkt); ?>?token=<?php echo oc_e($oc_cfg['aktionstoken']); ?>&amp;aktion=refresh</div>
</div>

<div class="sm-step"><b><?php echo oc_t('LOX.S5_T'); ?></b><br><?php echo oc_t('LOX.S5'); ?></div>

<div class="sm-step"><b><?php echo oc_t('LOX.S6_T'); ?></b><br><?php echo oc_t('LOX.S6'); ?>
<table class="sm-tbl">
<tr><th>#</th><th><?php echo oc_t('LOX.B_TYP'); ?></th><th><?php echo oc_t('LOX.B_NAME'); ?></th>
    <th><?php echo oc_t('LOX.B_PARAM'); ?></th><th><?php echo oc_t('LOX.B_EIN'); ?></th></tr>
<?php
// M6 (seit 1.1.16): der Name des Eingangs, wie der Gateway ihn bildet.
$oc_pf = oc_thema_flach($oc_cfg['mqtt_topic']);
$oc_bausteine = array(
    array(1,  'LOX.T_VE',        'VE_' . $oc_pf . '_cur',        'LOX.P_VE_CUR',    'LOX.E_MQTT'),
    array(2,  'LOX.T_VE',        'VE_' . $oc_pf . '_cur_h',      'LOX.P_VE_CURH',   'LOX.E_MQTT'),
    array(3,  'LOX.T_VE',        'VE_' . $oc_pf . '_rank',       'LOX.P_VE_RANK',   'LOX.E_MQTT'),
    array(4,  'LOX.T_VE',        'VE_' . $oc_pf . '_level',      'LOX.P_VE_LEVEL',  'LOX.E_MQTT'),
    array(5,  'LOX.T_VE',        'VE_' . $oc_pf . '_ok',         'LOX.P_VE_OK',     'LOX.E_MQTT'),
    array(6,  'LOX.T_VE',        'VE_' . $oc_pf . '_alter',      'LOX.P_VE_ALTER',  'LOX.E_MQTT'),
    array(7,  'LOX.T_VE',        'VE_' . $oc_pf . '_fenster_in', 'LOX.P_VE_FIN',    'LOX.E_MQTT'),
    array(8,  'LOX.T_VE',        'VE_' . $oc_pf . '_neg',        'LOX.P_VE_NEG',    'LOX.E_MQTT'),
    array(9,  'LOX.T_VE',        'VE_' . $oc_pf . '_co2_clean',  'LOX.P_VE_CO2',    'LOX.E_MQTT'),
    array(10, 'LOX.T_VE',        'VE_' . $oc_pf . '_ann',        'LOX.P_VE_ANN',    'LOX.E_MQTT'),
    array(11, 'LOX.T_VE',        'VE_' . $oc_pf . '_ptest',      'LOX.P_VE_PTEST',  'LOX.E_MQTT'),
    array(12, 'LOX.T_STATUS',    'ST_Strompreis',                'LOX.P_STATUS',    'LOX.E_1'),
    array(13, 'LOX.T_SCHWELLE',  'SW_Guenstig',                  'LOX.P_SW_G',      'LOX.E_1'),
    array(14, 'LOX.T_SCHWELLE',  'SW_Teuer',                     'LOX.P_SW_T',      'LOX.E_1'),
    array(15, 'LOX.T_VERGLEICH', 'VG_Rang_Guenstig',             'LOX.P_VG_RANG',   'LOX.E_3'),
    array(16, 'LOX.T_ODER',      'OD_Laden_frei',                'LOX.P_OD',        'LOX.E_ODER'),
    array(17, 'LOX.T_UND',       'UN_Laden',                     'LOX.P_UN',        'LOX.E_UND'),
    array(18, 'LOX.T_MERKER',    'MK_Daten_alt',                 'LOX.P_MK_ALT',    'LOX.E_6'),
    array(19, 'LOX.T_ODER',      'OD_Meldung',                   'LOX.P_OD_MELD',   'LOX.E_MELD'),
    array(20, 'LOX.T_BENACHR',   'BN_Strompreis',                'LOX.P_BN',        'LOX.E_19'),
    array(21, 'LOX.T_TEXTGEN',   'TG_Ansage',                    'LOX.P_TG',        'LOX.E_10'),
    array(22, 'LOX.T_STATISTIK', 'SG_Preisverlauf',              'LOX.P_SG',        'LOX.E_1'),
);
foreach ($oc_bausteine as $oc_b) { ?>
<tr><td><?php echo $oc_b[0]; ?></td><td><?php echo oc_t($oc_b[1]); ?></td>
    <td><span class="sm-mono"><?php echo oc_e($oc_b[2]); ?></span></td>
    <td><?php echo oc_t($oc_b[3]); ?></td><td><?php echo oc_t($oc_b[4]); ?></td></tr>
<?php } ?>
</table>
<div class="sm-hilfe"><?php echo oc_t('LOX.B_ERKLAERUNG'); ?></div>
</div>

<div class="sm-step"><b><?php echo oc_t('LOX.S7_T'); ?></b><br><?php echo oc_t('LOX.S7'); ?></div>
</div><!-- /tab-loxone -->

<!-- ==================== Reiter: Kostenvergleich ==================== -->
<div class="sm-seite<?php echo $oc_tab === 'tab-costs' ? ' sm-active' : ''; ?>" id="tab-costs">
<h2><?php echo oc_t('KOST.H_JAHR'); ?></h2>
<div class="sm-hilfe"><?php echo str_replace(array('%N%', '%S%'),
    array((int) $oc_kos['monate_gemessen'], oc_n($oc_kos['schnitt'], 2)),
    oc_t('KOST.GRUNDLAGE')); ?></div>
<table class="sm-tbl">
<tr><th><?php echo oc_t('KOST.SP_POSTEN'); ?></th><th><?php echo oc_t('KOST.SP_DYN'); ?></th>
    <th><?php echo oc_t('KOST.SP_FIX'); ?></th></tr>
<tr><td><?php echo oc_t('KOST.ARBEIT'); ?></td>
    <td><?php echo oc_n($oc_kos['dyn_arbeit'], 2); ?> &euro;</td>
    <td><?php echo oc_n($oc_kos['fix_arbeit'], 2); ?> &euro;</td></tr>
<tr><td><?php echo oc_t('KOST.GRUND'); ?></td>
    <td><?php echo oc_n($oc_kos['dyn_grund'], 2); ?> &euro;</td>
    <td><?php echo oc_n($oc_kos['fix_grund'], 2); ?> &euro;</td></tr>
<tr><td><?php echo oc_t('KOST.RABATT'); ?> (<?php echo oc_n($oc_kos['rabatt_pct'], 1); ?> %)</td>
    <td>&ndash;</td><td>&minus; <?php echo oc_n($oc_kos['rabatt'], 2); ?> &euro;</td></tr>
<tr><td><?php echo oc_t('KOST.BONI'); ?></td>
    <td>&ndash;</td><td>&minus; <?php echo oc_n($oc_kos['boni'], 2); ?> &euro;</td></tr>
<tr><th><?php echo oc_t('KOST.JAHR1'); ?></th>
    <th><?php echo oc_n($oc_kos['dyn_jahr'], 2); ?> &euro;</th>
    <th><?php echo oc_n($oc_kos['fix_jahr1'], 2); ?> &euro;</th></tr>
<tr><th><?php echo oc_t('KOST.FOLGE'); ?></th>
    <th><?php echo oc_n($oc_kos['dyn_jahr'], 2); ?> &euro;</th>
    <th><?php echo oc_n($oc_kos['fix_folge'], 2); ?> &euro;</th></tr>
</table>
<div class="sm-hinweis">
<?php echo str_replace(array('%K%', '%V1%', '%VF%'),
    array(oc_n($oc_kos['kwh'], 0), oc_n(abs($oc_kos['vorteil1']), 2), oc_n(abs($oc_kos['vorteilf']), 2)),
    oc_t($oc_kos['vorteilf'] >= 0 ? 'KOST.FAZIT_DYN' : 'KOST.FAZIT_FIX')); ?>
</div>

<h2><?php echo oc_t('KOST.H_MONATE'); ?></h2>
<table class="sm-tbl">
<tr><th><?php echo oc_t('KOST.SP_MONAT'); ?></th><th><?php echo oc_t('KOST.SP_TAGE'); ?></th>
    <th><?php echo oc_t('KOST.SP_DYNP'); ?></th><th><?php echo oc_t('KOST.SP_FIXP'); ?></th>
    <th><?php echo oc_t('KOST.SP_DIFF'); ?></th><th><?php echo oc_t('KOST.SP_EURO'); ?></th></tr>
<?php $oc_mc = oc_month_compare(24); if (!$oc_mc) { ?>
<tr><td colspan="6"><?php echo oc_t('KOST.KEINE_HISTORIE'); ?></td></tr>
<?php } foreach ($oc_mc as $oc_m) { ?>
<tr><td><?php echo oc_e(substr($oc_m['monat'], 4, 2) . '/' . substr($oc_m['monat'], 0, 4)); ?></td>
    <td><?php echo (int) $oc_m['tage']; ?></td>
    <td><?php echo oc_n($oc_m['dynp'], 2); ?></td>
    <td><?php echo oc_n($oc_m['fix'], 2); ?></td>
    <td><?php echo ($oc_m['diff'] >= 0 ? '+' : '&minus;') . oc_n(abs($oc_m['diff']), 2); ?></td>
    <td><?php echo ($oc_m['euro'] >= 0 ? '+' : '&minus;') . oc_n(abs($oc_m['euro']), 2); ?> &euro;</td></tr>
<?php } ?>
</table>

<h2><?php echo oc_t('KOST.H_SHIFT'); ?></h2>
<?php $oc_sh = oc_shift_saving(7); ?>
<div class="sm-hinweis"><?php echo str_replace(
    array('%T%', '%C%', '%K%', '%E%', '%J%'),
    array((int) $oc_sh['tage'], oc_n($oc_sh['ct'], 2), oc_n($oc_sh['kwh'], 1),
          oc_n($oc_sh['euro'], 2), oc_n($oc_sh['euro_jahr'], 2)),
    oc_t('KOST.SHIFT_TEXT')); ?></div>

<h2><?php echo oc_t('KOST.H_HISTORIE'); ?></h2>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?php echo oc_t('KOST.SP_TAG'); ?></th><th><?php echo oc_t('KOST.SP_AVG'); ?></th>
    <th><?php echo oc_t('KOST.SP_MIN'); ?></th><th><?php echo oc_t('KOST.SP_MAX'); ?></th>
    <th><?php echo oc_t('KOST.SP_GEW'); ?></th><th>CO&#8322;</th><th><?php echo oc_t('KOST.SP_QUELLE'); ?></th></tr>
<?php if (!$oc_hist) { ?><tr><td colspan="7"><?php echo oc_t('KOST.KEINE_HISTORIE'); ?></td></tr><?php }
foreach (array_reverse($oc_hist) as $oc_r) { ?>
<tr><td><?php echo oc_e(substr($oc_r[0], 6, 2) . '.' . substr($oc_r[0], 4, 2) . '.' . substr($oc_r[0], 0, 4)); ?></td>
    <td><?php echo oc_n($oc_r[1], 2); ?></td><td><?php echo oc_n($oc_r[2], 2); ?></td>
    <td><?php echo oc_n($oc_r[3], 2); ?></td><td><?php echo oc_n($oc_r[4], 2); ?></td>
    <td><?php echo (int) $oc_r[5]; ?></td>
    <td><?php echo $oc_r[6] ? oc_t('KOST.Q_DEMO') : oc_t('KOST.Q_ECHT'); ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-hilfe"><?php echo oc_t('KOST.CSV_HILFE'); ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?php echo oc_e($oc_fmt); ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-costs">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i><?php echo oc_t('LEGENDE.LESEN'); ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="oc_csv" value="1"><?php echo oc_t('KOST.K_CSV'); ?></button>
</div>
</form>
<?php
/* Woher das Gewicht kommt, mit dem der Vergleich rechnet. Ohne diese
 * Zeile sieht eine Schaetzung genauso aus wie eine Messung. */
list($oc_pv2, $oc_pherkunft) = oc_profil_aktiv();
?>
<div class="sm-hilfe"><?php echo oc_t($oc_pherkunft === 'echt'
    ? 'KOST.PROFIL_ECHT' : 'KOST.PROFIL_GESCHAETZT'); ?></div>
</div><!-- /tab-costs -->

<!-- ==================== Reiter: Test ==================== -->
<div class="sm-seite<?php echo $oc_tab === 'tab-test' ? ' sm-active' : ''; ?>" id="tab-test">
<h2><?php echo oc_t('TEST.H_PRUEFUNG'); ?></h2>
<!-- EINE gesammelte Legende oben im Reiter, nicht je Knopfreihe eine
     eigene: dieselbe Zeile mehrfach untereinander stiftet mehr Unruhe
     als Nutzen (Hausstandard, Beschluss 01.08.2026). Hier standen bis
     1.1.3 zwei Legenden in diesem Reiter. -->
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo oc_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo oc_t('LEGENDE.TECHNIK'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo oc_t('LEGENDE.AKTION'); ?></span>
</div>


<h3 class="sm-h3"><?php echo oc_t('PLAN.H_FAHRPLAN'); ?></h3>
<p class="sm-small"><?php echo oc_t('PLAN.FAHRPLAN_TEXT'); ?></p>
<?php
$oc_fp = oc_fahrplan();
$oc_bel = $oc_fp['belegung'];
$oc_sl = (int) $oc_fp['slotlen'];
$oc_aktiv = array();
foreach ($oc_fp['plan'] as $oc_pz) {
    if (!empty($oc_pz['slots'])) { $oc_aktiv[] = $oc_pz; }
}
/* Nur die Scheiben zeigen, in denen ueberhaupt etwas geplant ist - eine
 * Tabelle mit 96 Zeilen, von denen 90 leer sind, liest niemand. Gedeckelt
 * bei 60 Zeilen; mehr passt auf keinen Bildschirm. */
$oc_zeiten = array_keys($oc_bel);
foreach ($oc_aktiv as $oc_pz) {
    foreach ($oc_pz['slots'] as $oc_ts) { $oc_zeiten[] = $oc_ts; }
}
$oc_zeiten = array_values(array_unique($oc_zeiten));
sort($oc_zeiten);
$oc_zeiten = array_slice($oc_zeiten, 0, 60);
$oc_budget = (float) $oc_cfg['budget_kw'];
?>
<?php if (!$oc_zeiten) { ?>
<div class="sm-hinweis"><?php echo oc_t('PLAN.FAHRPLAN_LEER'); ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th><?php echo oc_t('PLAN.T_ZEIT'); ?></th><th><?php echo oc_t('PLAN.T_PREIS'); ?></th>
<?php foreach ($oc_aktiv as $oc_pz) { ?>
    <th><?php echo oc_e($oc_pz['name']); ?></th>
<?php } ?>
    <th><?php echo oc_t('PLAN.T_SUMME'); ?></th></tr>
<?php foreach ($oc_zeiten as $oc_ts) {
    $oc_kw = isset($oc_bel[$oc_ts]) ? (float) $oc_bel[$oc_ts] : 0.0;
    $oc_voll = ($oc_budget > 0 && round($oc_kw, 4) >= round($oc_budget, 4));
?>
<tr<?php echo $oc_voll ? ' style="background:#fdf4ec;"' : ''; ?>>
    <td><span class="sm-mono"><?php echo date($oc_sl >= 3600 ? 'd.m. H:i' : 'd.m. H:i', $oc_ts); ?></span></td>
    <td><?php echo isset($oc_fp['preise'][$oc_ts])
        ? oc_num($oc_fp['preise'][$oc_ts], 2) : '&ndash;'; ?></td>
<?php foreach ($oc_aktiv as $oc_pz) { ?>
    <td style="text-align:center;"><?php echo in_array($oc_ts, $oc_pz['slots'], true)
        ? '<span class="sm-an">&#9632;</span>' : '&middot;'; ?></td>
<?php } ?>
    <td><?php echo $oc_kw > 0 ? oc_num($oc_kw, 2) . ' kW' : '&ndash;'; ?></td></tr>
<?php } ?>
</table>
<?php if ($oc_budget > 0) { ?>
<p class="sm-small"><?php echo oc_t('PLAN.FAHRPLAN_BUDGET'); ?></p>
<?php } } ?>

<h3 class="sm-h3"><?php echo oc_t('PLAN.H_UEBERSICHT'); ?></h3>
<p class="sm-small"><?php echo oc_t('PLAN.UEBERSICHT_TEXT'); ?></p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?php echo oc_t('PLAN.U_REGEL'); ?></th><th><?php echo oc_t('PLAN.U_GRUND'); ?></th>
    <th><?php echo oc_t('PLAN.U_NOETIG'); ?></th><th><?php echo oc_t('PLAN.U_GEPLANT'); ?></th>
    <th><?php echo oc_t('PLAN.U_FEHLT'); ?></th><th><?php echo oc_t('PLAN.U_CT'); ?></th>
    <th><?php echo oc_t('PLAN.U_SOFORT'); ?></th><th><?php echo oc_t('PLAN.U_SPART'); ?></th></tr>
<?php foreach ($oc_fp['plan'] as $oc_pz) {
    $oc_rg = isset($oc_pz['grund']) ? (string) $oc_pz['grund'] : 'aus';
    /* Der Grund kommt als Kuerzel aus dem Planer. Ist fuer eines kein Text
     * hinterlegt, gibt oc_t() den Schluessel zurueck - dann faellt beim
     * Durchsehen sofort auf, was fehlt, statt dass die Zelle leer bleibt. */
?>
<tr><td><?php echo oc_e($oc_pz['name']); ?></td>
    <td><?php echo oc_e(oc_t('PLANGRUND.' . strtoupper($oc_rg))); ?><?php
      if (!empty($oc_pz['mangel'])) {
          foreach (explode(',', (string) $oc_pz['mangel']) as $oc_mg) {
              echo '<br><span class="sm-aus">'
                 . oc_e(oc_t('PLANMANGEL.' . strtoupper(trim($oc_mg)))) . '</span>';
          }
      } ?></td>
    <td><?php echo (int) $oc_pz['noetig']; ?></td>
    <td><?php echo (int) $oc_pz['anzahl']; ?></td>
    <td><?php echo ((int) $oc_pz['fehlt'] > 0)
        ? '<span class="sm-aus">' . (int) $oc_pz['fehlt'] . '</span>' : '0'; ?></td>
    <td><?php echo $oc_pz['anzahl'] > 0 ? oc_n($oc_pz['ct'], 2) : '&ndash;'; ?></td>
    <td><?php echo $oc_pz['anzahl'] > 0 ? oc_n($oc_pz['ct_sofort'], 2) : '&ndash;'; ?></td>
    <td><?php if ($oc_pz['anzahl'] > 0) {
          echo '<b>' . oc_n($oc_pz['spart_ct'], 2) . '</b> ct/kWh';
          if ((float) $oc_pz['kwh'] > 0) {
              echo '<br>' . oc_n($oc_pz['spart_eur'], 2) . ' &euro; ('
                 . oc_n($oc_pz['kwh'], 1) . ' kWh)';
          }
        } else { echo '&ndash;'; } ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-hilfe"><?php echo oc_t('PLAN.U_HILFE'); ?></div>

<?php
/* ===================================================================
 * Reiterleiste, Positivliste und Flaechen gegeneinander halten
 * ===================================================================
 *
 * Drei Stellen muessen deckungsgleich sein, und keine von ihnen meldet
 * sich, wenn sie es nicht mehr ist:
 *
 *   $oc_muster    die Positivliste - fehlt ein Reiter darin, springt die
 *                 Seite nach jedem Absenden zurueck auf Einstellungen
 *   $oc_reiter    die Beschriftungen, aus denen die Leiste entsteht
 *   id="tab-..."  die Flaechen
 *
 * Gezaehlt wird an der eigenen Datei, und die Zahl der ANGESEHENEN Stellen
 * steht dabei: eine Null ist kein "in Ordnung", sondern der Hinweis, dass
 * nichts gemessen wurde. Findet sich die Datei nicht, gibt es einen Strich
 * und keinen Haken.
 */
/* Gezaehlt wird an __FILE__ - das ist die Datei, die gerade laeuft, in
 * jeder Lage. Bis 1.1.11 stand davor ein Kandidat aus LBHOMEDIR: installiert
 * zeigte er nie auf diese Datei (basename(dirname(__DIR__, 2)) ist dort
 * 'htmlauth'), und ohne LBHOMEDIR begann er an der Laufwerkswurzel - was dort
 * lag, wurde statt dieser Seite gezaehlt (in WSL gemessen,
 * Pruefung-Spotpreis-Octopus-1.1.12, Fall C10). */
$oc_rp_datei = is_file(__FILE__) ? __FILE__ : '';
$oc_rp_ok = 2;
$oc_rp_text = oc_t('TEST.REITER_UNKLAR');
if ($oc_rp_datei !== '') {
    $oc_rp_q = (string) @file_get_contents($oc_rp_datei);
    $oc_rp_liste = preg_match('/\$oc_muster\s*=\s*.\/\^tab-\(([a-z0-9_|]+)\)\$/', $oc_rp_q, $oc_rp_m)
        ? explode('|', $oc_rp_m[1]) : array();
    $oc_rp_leiste = preg_match_all('/data-ziel="tab-([a-z0-9_]+)"/', $oc_rp_q, $oc_rp_l)
        ? $oc_rp_l[1] : array();
    $oc_rp_flaechen = preg_match_all('/id="tab-([a-z0-9_]+)"/', $oc_rp_q, $oc_rp_f)
        ? $oc_rp_f[1] : array();
    if (!$oc_rp_liste) {
        $oc_rp_text = oc_t('TEST.REITER_UNKLAR');
    } else {
        $oc_rp_fehlt = array_unique(array_merge(
            array_diff($oc_rp_liste, $oc_rp_leiste), array_diff($oc_rp_liste, $oc_rp_flaechen)));
        $oc_rp_zuviel = array_unique(array_merge(
            array_diff($oc_rp_leiste, $oc_rp_liste), array_diff($oc_rp_flaechen, $oc_rp_liste)));
        $oc_rp_ok = (!$oc_rp_fehlt && !$oc_rp_zuviel) ? 1 : 0;
        $oc_rp_text = $oc_rp_ok
            ? sprintf(oc_t('TEST.REITER_OK'), count($oc_rp_liste),
                      count($oc_rp_leiste), count($oc_rp_flaechen))
            : sprintf(oc_t('TEST.REITER_FEHLT'),
                      $oc_rp_fehlt ? implode(', ', $oc_rp_fehlt) : '-',
                      $oc_rp_zuviel ? implode(', ', $oc_rp_zuviel) : '-');
    }
}
?>
<h3 class="sm-h3"><?php echo oc_t('TEST.H_REITER'); ?></h3>
<div class="sm-alert <?php echo $oc_rp_ok === 1 ? 'sm-ok' : ($oc_rp_ok === 0 ? 'sm-err' : 'sm-warn'); ?>">
<b><?php echo $oc_rp_ok === 1 ? '&#10003;' : ($oc_rp_ok === 0 ? '&#10007;' : '&ndash;'); ?></b>
<?php echo oc_e($oc_rp_text); ?>
</div>

<?php
/* ===================================================================
 * Tragen alle Formulare das Merkmal? (O11, seit 1.1.16; Pflichtzeile,
 * Regeln/04 "Pflichtzeilen jedes Plugins")
 * ===================================================================
 *
 * Gezaehlt im Quelltext der Oberflaeche: jedes Formular-Tag bis zu seinem
 * Ende muss ein verstecktes Merkmalfeld (fmt) tragen. Ein neues Formular ohne Merkmal
 * liefe in den Wachposten und fiele erst beim Anwender auf. Die leere Menge
 * ist kein Haken: ohne gefundenes Formular gibt es ein Kreuz. Bauform
 * fb_probe_formulare() (Fensterbilanz 0.12.11). */
$oc_fm_q = '';
$oc_fm_dateien = 0;
foreach (array(__FILE__, __DIR__ . '/oc_test.php') as $oc_fm_d) {
    if (!is_file($oc_fm_d)) { continue; }
    $oc_fm_q .= (string) @file_get_contents($oc_fm_d) . "\n";
    $oc_fm_dateien++;
}
$oc_fm_gesamt = 0;
$oc_fm_ohne = 0;
if (preg_match_all('/<form\s/', $oc_fm_q, $oc_fm_y, PREG_OFFSET_CAPTURE)) {
    foreach ($oc_fm_y[0] as $oc_fm_f) {
        $oc_fm_gesamt++;
        $oc_fm_ende = strpos($oc_fm_q, '</form>', $oc_fm_f[1]);
        $oc_fm_blk = substr($oc_fm_q, $oc_fm_f[1], $oc_fm_ende === false ? 400 : $oc_fm_ende - $oc_fm_f[1]);
        if (strpos($oc_fm_blk, 'name="fmt"') === false) { $oc_fm_ohne++; }
    }
}
$oc_fm_ok = ($oc_fm_gesamt > 0 && $oc_fm_ohne === 0);
?>
<h3 class="sm-h3"><?php echo oc_t('TEST.H_FORMULARE'); ?></h3>
<div class="sm-alert <?php echo $oc_fm_ok ? 'sm-ok' : 'sm-err'; ?>">
<b><?php echo $oc_fm_ok ? '&#10003;' : '&#10007;'; ?></b>
<?php echo oc_e($oc_fm_gesamt === 0 ? oc_t('TEST.FORMULARE_KEINE')
    : ($oc_fm_ok ? sprintf(oc_t('TEST.FORMULARE_OK'), $oc_fm_gesamt, $oc_fm_dateien)
                 : sprintf(oc_t('TEST.FORMULARE_OHNE'), $oc_fm_ohne, $oc_fm_gesamt))); ?>
</div>

<h3 class="sm-h3"><?php echo oc_t('PLAN.H_SELBSTTEST'); ?></h3>
<p class="sm-small"><?php echo oc_t('PLAN.SELBSTTEST_TEXT'); ?></p>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?php echo oc_e($oc_fmt); ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="tab" value="test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="plantest" value="1"><?php echo oc_t('PLAN.K_SELBSTTEST'); ?></button>
  </form>
</div>
<?php if (!empty($oc_plantest)) { ?>
<div class="sm-pre"><?php echo oc_e($oc_plantest); ?></div>
<?php } ?>

<h3 class="sm-h3"><?php echo oc_t('PLAN.H_KOPIEN'); ?></h3>
<p class="sm-small"><?php echo oc_t('PLAN.KOPIEN_TEXT'); ?></p>
<?php
/* Die Rechnung steht in planer.php (ab 1.1.6), nicht hier - sie ist die
 * Pruefung zu der Regel, die im Kopf derselben Datei steht, und sie darf
 * nicht in drei Kopien gefuehrt werden.
 *
 * Drei Ausgaenge, und der dritte ist Pflicht: eine Schwesterlinie, die auf
 * diesem LoxBerry nicht installiert ist, ist kein Befund und kein Haken.
 * Wer sie zu einem der beiden anderen zaehlt, bekommt eine Zeile, die bei
 * jeder Einzelinstallation gruen leuchtet, ohne je etwas verglichen zu
 * haben. */
$pl_summen = plan_pruefsummen(oc_paths()['home']);
$pl_vorhanden = 0;
$pl_ungleich = 0;
foreach (array_slice($pl_summen, 1) as $pl_e) {
    if ($pl_e['lage'] === 'fehlt') { continue; }
    $pl_vorhanden++;
    if ($pl_e['lage'] === 'verschieden') { $pl_ungleich++; }
}
?>
<table class="sm-tbl">
<tr><th><?php echo oc_t('PLAN.K_LINIE'); ?></th><th><?php echo oc_t('PLAN.K_LAGE'); ?></th></tr>
<?php foreach ($pl_summen as $pl_e) { ?>
<tr><td><span class="sm-mono"><?php echo oc_e($pl_e['ordner']); ?></span></td>
    <td class="<?php echo $pl_e['lage'] === 'verschieden' ? 'sm-aus' : ($pl_e['lage'] === 'gleich' ? 'sm-an' : ''); ?>"><?php
      echo oc_e(oc_t('PLAN.LAGE_' . strtoupper($pl_e['lage']))); ?></td></tr>
<?php } ?>
</table>
<?php if ($pl_vorhanden === 0) { ?>
<div class="sm-hinweis"><?php echo oc_t('PLAN.KOPIEN_KEINE'); ?></div>
<?php } elseif ($pl_ungleich > 0) { ?>
<div class="sm-warnung"><?php echo oc_t('PLAN.KOPIEN_UNGLEICH'); ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?php echo sprintf(oc_t('PLAN.KOPIEN_GLEICH'), (int) $pl_vorhanden); ?></div>
<?php } ?>

<div class="sm-knopfreihe">
<?php foreach (array('selbst' => 'TEST.K_SELBST', 'abruf' => 'TEST.K_ABRUF',
                     'anmeldung' => 'TEST.K_ANMELDUNG') as $oc_a => $oc_l) { ?>
  <form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?php echo oc_e($oc_fmt); ?>"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="<?php echo $oc_a; ?>"><?php echo oc_t($oc_l); ?></button></form>
<?php } ?>
</div>
<div class="sm-knopfreihe">
<?php foreach (array('gateway' => 'TEST.K_GATEWAY', 'endpunkt' => 'TEST.K_ENDPUNKT') as $oc_a => $oc_l) { ?>
  <form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?php echo oc_e($oc_fmt); ?>"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="<?php echo $oc_a; ?>"><?php echo oc_t($oc_l); ?></button></form>
<?php } ?>
</div>

<h3><?php echo oc_t('TEST.H_SCHALTEN'); ?></h3>
<div class="sm-warnung"><?php echo oc_t('TEST.SCHALTEN_HINWEIS'); ?></div>
<div class="sm-knopfreihe">
<?php foreach (array('mqtt' => 'TEST.K_MQTT', 'say' => 'TEST.K_SAY',
                     'saytomorrow' => 'TEST.K_SAYTOMORROW', 'ptest' => 'TEST.K_PTEST') as $oc_a => $oc_l) { ?>
  <form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?php echo oc_e($oc_fmt); ?>"><input data-role="none" type="hidden" name="activetab" value="tab-test">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="<?php echo $oc_a; ?>"><?php echo oc_t($oc_l); ?></button></form>
<?php } ?>
</div>

<?php if ($oc_test_titel !== '') { ?>
<h2><?php echo $oc_test_titel; ?></h2>
<div class="sm-step"><?php echo $oc_test_text; ?></div>
<?php } ?>
<?php
/* b1 (Verbesserungsbau 30.09.2026): die gekuerzte Rohantwort der letzten
 * Preisabfrage. Liest nur die Datei, die oc_kraken_preise() beim Abruf des
 * Takts oder eines Knopfs ablegt - diese Anzeige fragt Octopus nie. */
echo oc_rohantwort_html($oc_cfg);
?>
</div><!-- /tab-test -->

<!-- ==================== Reiter: Logdateien ==================== -->
<div class="sm-seite<?php echo $oc_tab === 'tab-log' ? ' sm-active' : ''; ?>" id="tab-log">
<h2><?php echo oc_t('LOG.H'); ?></h2>
<div class="sm-hilfe"><?php echo str_replace('%F%',
    '<span class="sm-mono">' . oc_e($oc_p['log']) . '</span>', oc_t('LOG.DATEI')); ?></div>
<?php /* O10 (seit 1.1.16, Regeln/04): log/plugins liegt auf einer Ramdisk. */ ?>
<div class="sm-hinweis"><?php echo oc_t('LOG.RAMDISK'); ?></div>
<?php
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo LBWeb::loglist_html();
}
?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?php echo oc_e($oc_fmt); ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-log">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i><?php echo oc_t('LEGENDE.AKTION'); ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="clearlog" value="1"><?php echo oc_t('LOG.LEEREN'); ?></button>
</div>
</form>
<div class="sm-pre" style="max-height:520px;"><?php
foreach ($oc_loglines as $oc_l) { echo oc_e($oc_l) . "\n"; }
if (!$oc_loglines) { echo oc_e(oc_t('LOG.LEER')); }
?></div>
</div><!-- /tab-log -->

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
	zeige(<?php echo json_encode($oc_tab); ?>);
})();
</script>
<?php
if ($oc_rahmen) { LBWeb::lbfooter(); }
