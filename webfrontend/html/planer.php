<?php
/**
 * Fahrplaner fuer preisgesteuerte Verbraucher
 *
 * DIESE DATEI IST IN MEHREREN PLUGINS BYTEWEISE GLEICH.
 * Sie liegt in LoxBerry-Plugin-Spotpreis-aWATTar, in
 * LoxBerry-Plugin-Spotpreis-Octopus und seit dem 04.09.2026 auch in
 * LoxBerry-Plugin-Spotpreis-Tibber. Wer sie aendert, aendert sie in ALLEN
 * DREIEN - und prueft danach mit sha256sum ueber alle drei Ablageorte, dass
 * die Pruefsumme wieder uebereinstimmt.
 *
 * Dieselbe Frage beantwortet jede der drei Linien im Reiter Test von sich
 * aus: sie nennt PLAN_FASSUNG und haelt die Pruefsumme dieser Datei gegen
 * die der Schwesterlinien, soweit sie auf demselben LoxBerry installiert
 * sind. Drei Ausgaenge - gleich, verschieden, nicht installiert. Damit hat
 * die Regel 'drei Kopien byteweise gleich' ein Werkzeug, das sie findet.
 *
 * Der Ordnername stand hier bis 1.1.0 falsch ("LoxBerry-Plugin-Octopus"). Wer
 * danach gesucht hat, fand nichts - deshalb steht er jetzt vollstaendig da.
 *
 * Deshalb traegt sie das neutrale Kuerzel 'plan_' statt des Plugin-Kuerzels.
 * Das ist die einzige Ausnahme von der Regel "Funktionen tragen das Kuerzel
 * der Bibliothek", und sie ist bewusst gemacht: zwei auseinanderlaufende
 * Kopien derselben Rechnung waeren schlimmer als ein zweites Kuerzel. Die
 * drei Plugins laufen nie im selben Prozess; Namenskollisionen kann es
 * also nicht geben.
 *
 * ------------------------------------------------------------------
 * Was der Planer kann - und warum
 * ------------------------------------------------------------------
 *
 * Die Schaltregeln der Plugins beantworteten bis dahin jede fuer sich
 * die Frage "laeuft es gerade guenstig?". Drei Dinge fehlten, und alle drei
 * treten erst auf, wenn mehr als ein Geraet mitspielt:
 *
 *   1. FRIST.  "Die Waschmaschine braucht 2,5 h und muss um 7 Uhr fertig
 *      sein" liess sich nicht ausdruecken. Es gab ein Zeitfenster und einen
 *      Horizont, aber keinen Endtermin und keine Energiemenge.
 *
 *   2. RANGFOLGE UND LEISTUNGSBUDGET.  Jede Regel rechnete fuer sich.
 *      Waermepumpe, Wallbox und Waschmaschine fanden dieselbe guenstigste
 *      Stunde und schalteten gleichzeitig. Niemand hielt ein Budget.
 *
 *   3. PV-PROGNOSE UND SPEICHERSTAND.  Der Preis allein sagt nicht, ob es
 *      sich lohnt: wer nachts auf Vorrat laedt, obwohl morgen die Sonne
 *      scheint, zahlt zweimal.
 *
 * ------------------------------------------------------------------
 * Der Ablauf in vier Schritten
 * ------------------------------------------------------------------
 *
 *   1. Aus dem Preis je Zeitscheibe wird ein EFFEKTIVPREIS: liegt fuer die
 *      Scheibe eine PV-Prognose vor, wird eine Gutschrift abgezogen. Damit
 *      gewinnt die sonnige Mittagsstunde gegen die billige Nachtstunde,
 *      ohne dass jemand Schwellen von Hand pflegt.
 *
 *   2. Die Regeln werden nach RANG sortiert. Rang 1 waehlt zuerst.
 *
 *   3. Jede Regel waehlt ihre Zeitscheiben aus dem, was Frist, Zeitfenster,
 *      Horizont UND das noch freie Leistungsbudget uebrig lassen. Was eine
 *      hoeher gereihte Regel belegt hat, steht nicht mehr zur Verfuegung.
 *
 *   4. Die gewaehlten Scheiben werden gebucht, dann kommt die naechste Regel.
 *
 * Das ist ein gieriges Verfahren, kein optimales. Es ist dafuer in einem
 * Satz erklaerbar - "wer vorne steht, sucht sich zuerst aus" - und genau
 * das braucht jemand, der um drei Uhr nachts wissen will, warum die
 * Wallbox nicht laedt. Ein Verfahren, dessen Ergebnis niemand nachvollzieht,
 * waere im Hausgebrauch schlechter, auch wenn es ein paar Cent findet.
 *
 * ------------------------------------------------------------------
 * Einheiten - einmal festgelegt, damit nichts durcheinandergeraet
 * ------------------------------------------------------------------
 *
 *   Zeitscheibe   $slotlen Sekunden (3600 bei aWATTar, 900 bei Octopus)
 *   Preis         ct/kWh, brutto, so wie ihn das aufrufende Plugin liefert
 *   Leistung      kW
 *   Energie       kWh
 *   PV-Prognose   Wh JE ZEITSCHEIBE
 *   Speicherstand Prozent
 *
 * Alles hier ist reine Rechnung: kein Netz, keine Dateien, keine Uhr ausser
 * dem uebergebenen Zeitpunkt. Deshalb laesst sich der Planer vollstaendig
 * durchpruefen - plan_selbsttest() rechnet die Faelle nach und nennt ihre
 * Zahl selbst.
 *
 * KEINE ZAHL IM FLIESSTEXT. Bis 1.0.0 stand hier und weiter unten
 * "dreissig Faelle". Gemessen waren es 53 - der Satz war um 23 Faelle
 * veraltet, und niemand hatte es bemerkt, weil ihn nichts nachzaehlt.
 * Eine Zahl, die neben einer erzeugten Liste steht, ist eine Zeitbombe.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 */

/** Fassung dieser Datei. Der Reiter Test jeder der drei Linien zeigt sie an.
 *
 * 1.1.1: Aufrundung gegen Gleitkommarauschen in plan_slots_noetig(), und
 *        der Lueckenschluss in plan_takt() haelt sich jetzt an die
 *        Kandidatenliste - beides mit eigenen Prueffaellen unten.
 *
 * 1.1.3: Fuenf Befunde, jeder mit eigenem Prueffall und in BEIDE
 *        Richtungen geeicht - der Prueffall wurde jeweils zurueckgebaut
 *        und musste rot werden:
 *
 *        - plan_runde() ersetzt die eingebaute Rundung. Die entscheidet
 *          an der Haelfte je nach PHP-Fassung verschieden; die
 *          Schaltschwelle der Regelart "mittel" lag unter 7.4.33 bei
 *          4,293 und unter 8.4.24 bei 4,292 ct. Der Umstieg auf Debian 13
 *          haette die Schwelle von selbst verstellt.
 *        - plan_frist_ende() rechnet ueber strtotime statt ueber die
 *          Zeitfunktion mit Einzelargumenten. Die loeste die doppelte
 *          Stunde am 25.10. fassungsabhaengig auf - eine Stunde
 *          Unterschied bei der Frist, allein durch den Interpreter.
 *        - plan_takt() macht Luecken jetzt ZWEIMAL zu. Das Verlaengern
 *          riss neue auf, die niemand mehr ansah; gemessen ueber 3815
 *          Faelle hielten 171 Ergebnisse die Mindestpause nicht ein.
 *        - plan_waehlen() rundet das Fenstermittel, bevor es vergleicht.
 *          Bei rechnerischem Gleichstand gewann sonst das SPAETERE
 *          Fenster - dieselbe Vorkehrung hatten 'stunden' und 'scheiben'
 *          schon.
 *        - plan_kennzahlen() rechnet die abgeleiteten Zahlen nach dem
 *          Negativpreis-Zweig noch einmal. Eine Regel zu 4 kW, die allein
 *          wegen des Negativpreises lief, meldete sonst kwh=0 und
 *          fehlt=2 statt kwh=4,0 und fehlt=1.
 *
 *        Dazu zwei Prueffaelle FUER EINE ALTE KORREKTUR: dass die
 *        Hysterese sich an die Kandidatenliste haelt, stand seit 1.1.1 im
 *        Kommentar, war aber von keinem Fall gedeckt - der Rueckbau blieb
 *        gruen. Jetzt geht er rot.
 *
 * 1.1.4: Nur der Kopf und eine Zusicherung - an der Rechnung aendert sich
 *        nichts, plan_selbsttest() rechnet unveraendert dieselben Faelle
 *        nach.
 *
 *        Zwei Berichtigungen. Erstens liegt die Datei seit dem 04.09.2026
 *        in DREI Linien, der Kopf nannte zwei. Zweitens war die Zusicherung
 *        "Steht in beiden Plugins im Reiter Test" gemessen falsch:
 *        PLAN_FASSUNG kam in aWATTar 1.2.20 ausserhalb dieser Datei
 *        ueberhaupt nicht vor, und in Octopus 1.1.6 stand sie in der
 *        Antwortzeile des Loxone-Endpunkts, nicht im Reiter Test. Genau
 *        die Fehlerklasse, vor der spot_lib.php warnt: ein Kommentar, der
 *        eine Benutzung behauptet, ist kein Beleg fuer sie. Jetzt zeigen
 *        alle drei Linien die Fassung im Reiter Test an.
 *
 * 1.1.7: plan_pruefsummen() sucht die Schwesterlinien, statt sie
 *        aufzuzaehlen.
 *
 *        Die Liste aus 1.1.6 nannte 'spotpreisawattar' und
 *        'spotpreisoctopus'. Installiert wird aber nach FOLDER, und das
 *        heisst 'spotpreis' und 'octopus' - die Pruefung meldete auf jeder
 *        Anlage zweimal 'fehlt' und verglich nie etwas. Genau die
 *        Divergenz, wegen der es sie gibt, haette sie nicht gefunden.
 *
 *        Gemessen am 04.09.2026 an den drei plugin.cfg. Umbenennen war kein
 *        Weg: FOLDER geht in die Plugin-Kennung ein.
 *
 * 1.1.6: plan_pruefsummen() kommt dazu - die Pruefung zu der Regel, die
 *        ganz oben in diesem Kopf steht.
 *
 *        Sie lag zuerst in tb_lib.php, also in EINER der drei Linien. Damit
 *        haetten die beiden anderen entweder keine Pruefung bekommen oder
 *        eine zweite Kopie davon. Eine Pruefung, die Kopien vergleicht, in
 *        drei Kopien zu fuehren waere der Witz gewesen - also steht sie
 *        hier, in der Datei, um die es geht.
 *
 *        Sie liest Dateien und gehoert deshalb NICHT in plan_selbsttest():
 *        der rechnet nur, ohne Netz und ohne Platte, und genau das ist seine
 *        Zusage.
 *
 * 1.1.5: Der Selbsttest misst in einer FESTEN Zeitzone.
 *
 *        Sieben seiner Faelle pruefen die Zeitumstellung und stehen als
 *        feste Unix-Zeitpunkte da - der 25.10. mit seiner doppelten
 *        Stunde, der 29.03. mit seiner uebersprungenen, der Silvester-
 *        abend. Auf einem Geraet ohne Sommerzeit ergeben dieselben
 *        Zeitpunkte andere Ortszeiten, und die sieben Zeilen gehen rot,
 *        ohne dass am Plugin etwas falsch waere.
 *
 *        Gemessen am 04.09.2026 unter PHP 8.4.21, derselbe Quelltext:
 *
 *            date.timezone=UTC            7 Fehlschlaege von 170
 *            date.timezone=Europe/Berlin  0 Fehlschlaege von 170
 *
 *        Ein Selbsttest, dessen Ergebnis an der Einstellung des Geraets
 *        haengt, misst das Geraet und nicht die Rechnung. Er setzt die
 *        Zeitzone jetzt selbst und stellt sie am Ende zurueck; die
 *        Kopfzeile nennt beide, damit niemand seinen LoxBerry fuer
 *        umgestellt haelt. An der Rechnung aendert sich nichts. */
define('PLAN_FASSUNG', '1.1.7');

/* ==================================================================
 * Runden, das in jeder PHP-Fassung dasselbe ergibt
 * ================================================================== */

/**
 * Kaufmaennisch runden, von der Null weg, nach einer Vorrundung auf 15
 * signifikante Stellen.
 *
 * WARUM DIESE FUNKTION UEBERHAUPT: die eingebaute Rundung von PHP nahm bis
 * 7.4 diese Vorrundung selbst vor und laesst sie seit 8.x weg. Gemessen an
 * der Rechnung aus plan_waehlen(), Mittelwert 5,05 ct minus 15 Prozent:
 *
 *     PHP 7.4.33 -> 4.293
 *     PHP 8.4.24 -> 4.292
 *
 * Das ist die SCHALTSCHWELLE der Regelart "mittel": eine Stunde zu
 * 4,2925 ct laeuft unter der einen Fassung und unter der anderen nicht.
 * LoxBerry 3.0-4.0 fahren 7.4, mit Debian 13 kommt 8.x - der Wechsel
 * verstellte die Schwelle von selbst, ohne dass jemand etwas eingestellt
 * haette.
 *
 * Diese Funktion bildet die 7.4-Antwort nach, die Zahl 15 aber FEST statt
 * aus der ini-Einstellung "precision" - damit haengt das Ergebnis weder an
 * der PHP-Fassung noch an der Einstellung des Geraets. 4,2925 wird zu
 * 4,293, so wie es ein Mensch auch rechnet.
 *
 * Geeicht ueber 72042 Werte, je Fassung: gegen 7.4 kein Unterschied, gegen
 * 8.4 genau 629 - eben die Faelle, die 8.4 geaendert hat. Das Gitter steht
 * als PR-RUNDEN in plan_selbsttest().
 *
 * Deshalb geht in dieser Datei keine eingebaute Rundung mehr in eine
 * Entscheidung oder in einen Wert, den Loxone bekommt.
 */
function plan_runde($x, $stellen = 0)
{
    $x = (float) $x;
    // NAN und INF bleiben, was sie sind - eine Zahl daraus zu machen waere
    // ein Messwert, den es nicht gibt.
    if (!is_finite($x)) {
        return $x;
    }
    $stellen = (int) $stellen;
    if ($x == 0.0) {
        return 0.0;
    }
    $neg = ($x < 0);
    $b = $neg ? -$x : $x;

    /* 15 signifikante Stellen als reine Ziffernkette. Der Umweg ueber
     * sprintf ist Absicht: es rechnet in Dezimalstellen statt in
     * Bitmustern und tut das in beiden Fassungen gleich. Der naheliegende
     * Weg floor($y + 0.5) faellt bei 0.49999999999999994 um, und ein
     * Rueckweg ueber (float) holt die Darstellungsluecke sofort wieder
     * herein - deshalb wird bis zum Schluss auf der Zeichenkette
     * gearbeitet. */
    $s = sprintf('%.14E', $b);
    $p = strpos($s, 'E');
    $e = (int) substr($s, $p + 1);
    $z = str_replace('.', '', substr($s, 0, $p));   // 15 Ziffern
    // Wert = 0.$z * 10^($e+1); der Dezimalpunkt steht nach Stelle $e+1.
    $k = $e + 1 + $stellen;                          // so viele bleiben stehen

    if ($k >= 15) {
        // Nichts abzuschneiden - die Vorrundung ist schon die Antwort.
        $r = (float) $s;
        return $neg ? -$r : $r;
    }
    if ($k < 0) {
        // Kleiner als die halbe letzte Stelle.
        return 0.0;
    }
    // $k liegt jetzt zwischen 0 und 14, die behaltenen Ziffern passen also
    // sicher in eine Ganzzahl.
    $kern = ($k === 0) ? 0 : (int) substr($z, 0, $k);
    if ($z[$k] >= '5') {
        $kern++;                                     // von der Null weg
    }
    // Gleitkomma, nicht Ganzzahl: sonst schreibt json_encode "3" statt
    // "3.0", und state.json saehe je nach Wert anders aus.
    $r = (float) $kern / pow(10, $stellen);
    return $neg ? -$r : $r;
}

/* ==================================================================
 * Vorgaben
 * ================================================================== */

/**
 * Die Felder, die eine Regel ZUSAETZLICH zu den bisherigen bekommt.
 * Das aufrufende Plugin mischt sie in seine eigene Regelvorgabe.
 *
 * Jede Vorgabe ist so gewaehlt, dass sich fuer eine bestehende Regel
 * NICHTS aendert: Rang 50 fuer alle, kein Leistungsanteil, keine Frist,
 * keine Sperre. Wer nichts einstellt, bekommt das Verhalten von vorher.
 */
function plan_regel_vorgabe()
{
    return array(
        'rang'      => 50,    // 1 = waehlt zuerst, 99 = zuletzt
        'leistung'  => 0.0,   // kW; 0 = nimmt am Leistungsbudget nicht teil
        'energie'   => 0.0,   // kWh; 0 = Anzahl kommt weiter aus 'n'
        'frist'     => -1,    // Stunde, bis zu der es fertig sein muss; -1 = keine
        'pv_sperre' => 0.0,   // kWh Tagesprognose, ab der die Regel schweigt; 0 = aus
        'soc_min'   => 0,     // Prozent; Regel erst ab diesem Speicherstand
        'soc_max'   => 0,     // Prozent; Regel nur bis zu diesem Stand; 0 = aus
        // ---- ab 1.1.0: Taktschutz ----
        // Beide in MINUTEN, 0 = aus. Bei Viertelstundenpreisen ist kurzes
        // Takten der Normalfall und nicht die Ausnahme: eine Schwellenregel
        // schaltet sonst alle 15 Minuten. Waermepumpe und Kompressor nehmen
        // davon Schaden.
        'min_lauf'  => 0,     // Mindestlaufzeit eines zusammenhaengenden Blocks
        'min_pause' => 0,     // Mindestpause zwischen zwei Bloecken
    );
}

/** Die Felder, die die Plugin-Konfiguration zusaetzlich bekommt. */
function plan_global_vorgabe()
{
    return array(
        'budget_kw'    => 0.0,   // gleichzeitige Leistung; 0 = kein Budget
        'pv_bonus'     => 0.0,   // ct/kWh Gutschrift bei voller PV-Scheibe; 0 = aus
        'pv_schwelle'  => 500,   // Wh je Zeitscheibe fuer die volle Gutschrift
        /* ---- ab 1.1.0: zweites, zeitlich begrenztes Budget (Paragraf 14a) ----
         *
         * Wer eine steuerbare Verbrauchseinrichtung angemeldet hat, darf zu
         * bestimmten Zeiten nur eine verminderte Leistung ziehen. Das ist ein
         * ZWEITES Budget neben dem ersten, kein Ersatz: es gilt zusaetzlich
         * und nur innerhalb seines Zeitfensters. Beide werden geprueft, die
         * kleinere Schranke gewinnt.
         *
         * budget2_kw = 0 schaltet es ab. von == bis heisst "ganzer Tag" -
         * dieselbe Lesart wie beim Zeitfenster einer Regel, damit niemand
         * zwei Bedeutungen fuer dieselbe Schreibweise lernen muss. */
        'budget2_kw'   => 0.0,   // kW waehrend der Sperrzeit; 0 = aus
        'budget2_von'  => 0,     // Stunde, ab der es gilt (einschliesslich)
        'budget2_bis'  => 0,     // Stunde, bis zu der es gilt (ausschliesslich)
    );
}

/* ==================================================================
 * Kleine Helfer
 * ================================================================== */

/** Liegt die Stunde $h im Zeitfenster? von == bis bedeutet: ganzer Tag. */
function plan_in_zeitfenster($h, $von, $bis)
{
    $h = (int) $h; $von = (int) $von; $bis = (int) $bis;
    if ($von === $bis) { return true; }
    if ($von < $bis) { return $h >= $von && $h < $bis; }
    return $h >= $von || $h < $bis;   // ueber Mitternacht, z. B. 22 bis 6
}

/**
 * Wie viele Zeitscheiben ergeben eine Stunde?
 * Bei stuendlichen Preisen 1, bei Viertelstunden 4.
 */
function plan_pro_stunde($slotlen)
{
    $slotlen = max(1, (int) $slotlen);
    return $slotlen >= 3600 ? 1 : (int) max(1, plan_runde(3600 / $slotlen));
}

/**
 * Der Zeitpunkt, zu dem eine Frist ablaeuft.
 *
 * Die Frist ist eine Uhrzeit, kein Datum: "bis 7 Uhr" heisst das naechste
 * Mal, dass es 7 Uhr ist. Ist es jetzt 5 Uhr, ist das heute; ist es 9 Uhr,
 * ist es morgen. Genau so denkt jemand, der die Waschmaschine abends
 * einstellt.
 *
 * Rueckgabe: Zeitstempel, oder 0 wenn keine Frist gesetzt ist.
 */
function plan_frist_ende($jetzt, $frist)
{
    $frist = (int) $frist;
    if ($frist < 0 || $frist > 23) { return 0; }
    /* $jetzt ganzzahlig, bevor er in date() geht. Ein Float loest unter
     * PHP 8 "Implicit conversion ... loses precision" aus; unter 7.4
     * schweigt es. Beide Aufrufer rastern heute sauber, aber der Schutz
     * kostet nichts. */
    $jetzt = (int) $jetzt;

    /* BEIDE Zweige ueber die STUNDE, nicht ueber Sekunden.
     *
     * Bis 1.0.0 rechnete der erste Zweig "Tagesbeginn + Frist * 3600". An
     * den beiden Umstellungstagen hat ein Tag 23 oder 25 Stunden, und eine
     * Stunde ist dann nicht 3600 Sekunden vom Tagesbeginn entfernt.
     * Gemessen, unter 7.4 wie unter 8.4:
     *     29.03.2026, Frist 7 -> 08:00 statt 07:00
     *     25.10.2026, Frist 7 -> 06:00 statt 07:00
     * Im Fruehjahrsfall war die Waesche eine Stunde NACH der Frist fertig.
     *
     * SEIT 1.1.3 UEBER strtotime STATT ueber die Zeitfunktion mit
     * Einzelargumenten. Die loest naemlich die DOPPELTE Stunde am Ende der
     * Sommerzeit fassungsabhaengig auf - gemessen am 25.10.2026, Frist 2:
     *     7.4.33 -> 02:00 CET   (die zweite der beiden)
     *     8.4.24 -> 02:00 CEST  (die erste)
     * Eine Stunde Unterschied bei der Frist, allein durch den Interpreter.
     * strtotime auf eine Datumszeichenkette antwortet in beiden Fassungen
     * 02:00 CET und loest die UEBERSPRUNGENE Stunde am 29.03. genauso auf
     * wie zuvor: Frist 2 gibt es dort nicht, die Antwort ist 03:00 CEST -
     * die naechste Uhrzeit, die es wirklich gibt. Fuenf Faelle gemessen,
     * beide Fassungen gleich. Geeicht in plan_selbsttest(). */
    $tag = date('Y-m-d', $jetzt);
    $ziel = (int) strtotime(sprintf('%s %02d:00:00', $tag, $frist));
    if ($ziel <= $jetzt) {
        /* Schon vorbei - dann ist morgen gemeint. Ueber die Datumsrechnung
         * und nicht mit +86400: an den Umstellungstagen ist ein Tag nicht
         * 86400 Sekunden lang. '+1 day' traegt auch ueber Monats- und
         * Jahreswechsel (31.12.2026 -> 01.01.2027, gemessen). */
        $ziel = (int) strtotime(sprintf('%s %02d:00:00 +1 day', $tag, $frist));
    }
    return $ziel;
}

/**
 * Wie viele Zeitscheiben braucht die Regel?
 *
 * Steht eine Energiemenge und eine Leistung, ergibt sich die Zahl daraus -
 * das ist die Angabe, die ein Mensch kennt ("7 kWh, 3,7 kW"). Sonst bleibt
 * es bei der bisherigen Angabe in Stunden.
 *
 * Aufgerundet wird immer: eine halbe Zeitscheibe gibt es nicht, und zu kurz
 * laden ist schlechter als eine Scheibe zu viel.
 *
 * AUFGERUNDET WIRD ABER GEGEN EIN EPSILON, NICHT BLANK. Ein glattes
 * kWh/kW-Paar ergibt in Gleitkomma nicht immer eine glatte Stundenzahl:
 * 6,9 / 2,3 ist rechnerisch genau 3, gemessen aber 3,00000000000000044409.
 * ceil() machte daraus vier Scheiben statt drei - ein Drittel zu viel
 * gebuchte Energie und eine Stunde Leistung, die den anderen Regeln im
 * Budget fehlt. Gemessen unter 7.4 wie unter 8.4; 4,2/1,4 verhaelt sich
 * genauso, 7,4/3,7 und 11,0/2,2 sind unauffaellig. Ob es zuschlaegt,
 * entscheidet allein die Darstellung des Paares - deshalb ist es keine
 * Frage von "gaengigen" Werten.
 *
 * Das Epsilon ist relativ: bei grossen Scheibenzahlen waere ein absolutes
 * wirkungslos, bei kleinen zu grob. Aufgerundet wird nach wie vor - nur
 * eben nicht wegen der siebzehnten Nachkommastelle.
 */
function plan_slots_noetig($r, $slotlen)
{
    $pro = plan_pro_stunde($slotlen);
    $energie = isset($r['energie']) ? (float) $r['energie'] : 0.0;
    $leistung = isset($r['leistung']) ? (float) $r['leistung'] : 0.0;
    if ($energie > 0 && $leistung > 0) {
        $stunden = $energie / $leistung;
        $scheiben = $stunden * $pro;
        $glatt = plan_runde($scheiben);
        if (abs($scheiben - $glatt) <= 1e-9 * max(1.0, abs($scheiben))) {
            $scheiben = $glatt;
        }
        return max(1, (int) ceil($scheiben));
    }
    // isset auf 'n': die Regelvorgabe des Planers kennt das Feld nicht, es
    // kommt aus der Plugin-Vorgabe. Eine Regel ohne 'n' loeste unter PHP 8
    // eine Warnung aus und rechnete mit 0.
    return max(1, isset($r['n']) ? (int) $r['n'] : 1) * $pro;
}

/**
 * Traegt die Regel eine Energiemenge, aber keine Leistung?
 *
 * Dann faellt plan_slots_noetig() stillschweigend auf 'n' zurueck: wer
 * "7 kWh" eintraegt und die Leistung vergisst, bekommt ein ganz anderes
 * Ergebnis und kein Wort dazu. Der Planer kann das nicht selbst beheben -
 * ohne Leistung gibt es keine Laufzeit -, aber er kann es SAGEN. Die
 * Oberflaeche weist es beim Speichern ab; hier steht es fuer den Fall,
 * dass die Werte aus einer zurueckgespielten Sicherung kommen.
 */
function plan_energie_ohne_leistung($r)
{
    $energie = isset($r['energie']) ? (float) $r['energie'] : 0.0;
    $leistung = isset($r['leistung']) ? (float) $r['leistung'] : 0.0;
    return ($energie > 0 && $leistung <= 0);
}

/* ==================================================================
 * Effektivpreis
 * ================================================================== */

/**
 * Preis je Zeitscheibe abzueglich PV-Gutschrift.
 *
 * Die Gutschrift steigt linear bis zur Schwelle und bleibt dann stehen:
 *
 *     Gutschrift = pv_bonus * min(1, Wh_der_Scheibe / pv_schwelle)
 *
 * Eine reine Ja/Nein-Schwelle waere eine Klippe - eine Scheibe mit 499 Wh
 * bekaeme nichts, eine mit 501 Wh alles, und der Fahrplan spraenge bei
 * einer minimal geaenderten Prognose um Stunden. Der lineare Anstieg
 * vermeidet das und bleibt in einer Zeile erklaerbar.
 *
 * Der Effektivpreis darf negativ werden. Das ist gewollt: eine Stunde mit
 * viel Eigenstrom ist tatsaechlich billiger als der Boersenpreis sagt.
 *
 * Rueckgabe: array(ts => Effektivpreis)
 */
function plan_effektivpreise($preise, $pv, $bonus, $schwelle)
{
    $bonus = (float) $bonus;
    $schwelle = max(1.0, (float) $schwelle);
    $out = array();
    foreach ($preise as $ts => $ct) {
        $eff = (float) $ct;
        if ($bonus > 0 && is_array($pv) && isset($pv[$ts]) && (float) $pv[$ts] > 0) {
            $anteil = min(1.0, (float) $pv[$ts] / $schwelle);
            $eff -= $bonus * $anteil;
        }
        $out[$ts] = plan_runde($eff, 4);
    }
    return $out;
}

/* ==================================================================
 * Auswahl je Regel
 * ================================================================== */

/**
 * Die Zeitscheiben, die fuer eine Regel ueberhaupt in Frage kommen.
 *
 * Vier Filter, in dieser Reihenfolge:
 *   1. Vergangenheit               - was vorbei ist, laesst sich nicht schalten
 *   2. Horizont oder Frist         - die Frist gewinnt, wenn sie frueher endet
 *   3. Zeitfenster (Uhrzeit)       - "nur zwischen 22 und 6"
 *   4. freies Leistungsbudget      - was hoeher gereihte Regeln belegt haben,
 *                                    ist weg
 *
 * Rueckgabe: array(ts => Effektivpreis)
 */
function plan_kandidaten($r, $eff, $jetzt, $slotlen, $belegt, $budget, $b2 = null)
{
    $jetzt = (int) $jetzt;
    $ende = $jetzt + max(1, isset($r['horizont']) ? (int) $r['horizont'] : 24) * 3600;
    $frist = plan_frist_ende($jetzt, isset($r['frist']) ? $r['frist'] : -1);
    if ($frist > 0 && $frist < $ende) { $ende = $frist; }

    $leistung = isset($r['leistung']) ? (float) $r['leistung'] : 0.0;
    $budget = (float) $budget;

    /* Das zweite Budget gilt nur in seinem Zeitfenster. $b2 ist entweder
     * null (aus) oder array(kw, von, bis). */
    $b2kw  = (is_array($b2) && isset($b2['kw']))  ? (float) $b2['kw']  : 0.0;
    $b2von = (is_array($b2) && isset($b2['von'])) ? (int) $b2['von'] : 0;
    $b2bis = (is_array($b2) && isset($b2['bis'])) ? (int) $b2['bis'] : 0;

    $von = isset($r['von']) ? $r['von'] : 0;
    $bis = isset($r['bis']) ? $r['bis'] : 0;

    $out = array();
    foreach ($eff as $ts => $ct) {
        if ($ts < $jetzt || $ts >= $ende) { continue; }
        $stunde = (int) date('G', $ts);
        if (!plan_in_zeitfenster($stunde, $von, $bis)) { continue; }
        if ($leistung > 0) {
            $schon = isset($belegt[$ts]) ? (float) $belegt[$ts] : 0.0;
            /* Beide Schranken pruefen, die kleinere gewinnt. Rundung auf
             * vier Stellen, damit 3.7 + 3.7 <= 7.4 nicht an der
             * Gleitkommadarstellung scheitert. */
            if ($budget > 0 && plan_runde($schon + $leistung, 4) > plan_runde($budget, 4)) { continue; }
            if ($b2kw > 0 && plan_in_zeitfenster($stunde, $b2von, $b2bis)
                && plan_runde($schon + $leistung, 4) > plan_runde($b2kw, 4)) { continue; }
        }
        $out[$ts] = (float) $ct;
    }
    ksort($out);
    return $out;
}

/**
 * Aus den Kandidaten die Treffer waehlen.
 *
 * Fuenf Arten:
 *   fenster   die guenstigsten $anzahl Scheiben AM STUECK
 *   stunden   die guenstigsten VOLLEN Stunden, ueber den Tag verstreut
 *   scheiben  die guenstigsten EINZELNEN Scheiben, ohne Stundenraster (1.1.0)
 *   schwelle  alles unter einem festen Preis
 *   mittel    alles X Prozent unter dem Tagesmittel
 *
 * Bei 'stunden' wird bewusst auf volle Stunden gemittelt statt die
 * guenstigsten Viertelstunden zu picken - sonst schaltet die Wallbox im
 * Viertelstundentakt an und aus. Bei stuendlichen Preisen ist das
 * dieselbe Rechnung, nur mit Bloecken der Laenge eins.
 *
 * WARUM 'scheiben' DAZUKOMMT (1.1.0)
 * Bisher gab es nur "am Stueck" oder "volle Stunden". Wer eine Wallbox mit
 * Zeitpuffer hat, will aber die guenstigsten Viertelstunden EINZELN - das
 * ist bares Geld, und der Zeitpuffer macht das Takten unschaedlich. Wer
 * takten nicht vertraegt, nimmt 'fenster' oder setzt min_lauf. Deshalb eine
 * eigene Art und kein Schalter an einer bestehenden: eine Art beschreibt,
 * WAS gewaehlt wird, ein Schalter haette dieselbe Art zwei Dinge bedeuten
 * lassen.
 *
 * $mittel darf null sein - das heisst "nicht bekannt" und wird aus den
 * Kandidaten ersetzt. Ein echtes negatives Tagesmittel ist NICHT dasselbe
 * wie "nicht bekannt"; bis 1.0.0 wurden beide gleich behandelt.
 *
 * Rueckgabe: sortierte Liste von Zeitstempeln.
 */
function plan_waehlen($r, $kand, $slotlen, $anzahl, $mittel)
{
    $art = isset($r['art']) ? (string) $r['art'] : 'fenster';
    $ks = array_keys($kand);
    $treffer = array();

    if ($art === 'fenster') {
        $len = min(max(1, (int) $anzahl), count($ks));
        $best = null;
        for ($i = 0; $len > 0 && $i + $len <= count($ks); $i++) {
            // Zusammenhaengend heisst luekenlos: ueber eine fehlende Scheibe
            // hinweg wird nicht geklebt, sonst stuende die Wallbox mittendrin.
            if ($ks[$i + $len - 1] - $ks[$i] !== ($len - 1) * $slotlen) { continue; }
            $s = 0.0;
            for ($j = 0; $j < $len; $j++) { $s += $kand[$ks[$i + $j]]; }
            /* GERUNDET, aus demselben Grund wie beim Zweig 'stunden': zwei
             * rechnerisch gleich teure Fenster sind in Gleitkomma fast nie
             * identisch. Gemessen mit 0,1/0,2/0,1/0,2 gegen viermal 0,15 -
             * beide Mittel 0,15, verglichen 0.15000000000000002 gegen
             * 0.14999999999999999, und der Planer nahm das SPAETERE
             * Fenster. Mit der Rundung gewinnt bei Gleichstand das
             * fruehere, weil der Vergleich echt kleiner verlangt. */
            $m = plan_runde($s / $len, 6);
            if ($best === null || $m < $best[1]) { $best = array($i, $m); }
        }
        if ($best !== null) {
            for ($j = 0; $j < $len; $j++) { $treffer[] = $ks[$best[0] + $j]; }
        }

    } elseif ($art === 'stunden') {
        $pro = plan_pro_stunde($slotlen);
        $bloecke = array();
        foreach ($kand as $ts => $ct) {
            $h = $ts - ($ts % 3600);
            if (!isset($bloecke[$h])) { $bloecke[$h] = array(0.0, 0); }
            $bloecke[$h][0] += $ct;
            $bloecke[$h][1]++;
        }
        $mittelwerte = array();
        foreach ($bloecke as $h => $v) {
            // Angebrochene Stunden nicht bewerten - sie waeren kuenstlich
            // guenstig oder teuer, je nachdem welche Viertel fehlen.
            /* GERUNDET, sonst greift der Zweitschluessel unten nie: zwei
             * rechnerisch gleiche Stundenmittel sind in Gleitkomma fast
             * nie identisch. Gemessen mit Viertelstunden 0,1/0,2/0,1/0,2
             * gegen 0,15/0,15/0,15/0,15 - beide Mittel 0,15, verglichen
             * 0.15000000000000002 gegen 0.14999999999999999, und der
             * Planer nahm die SPAETERE Stunde. Dieselbe Vorkehrung wie
             * das Epsilon in plan_slots_noetig(). */
            if ($v[1] === $pro) { $mittelwerte[$h] = plan_runde($v[0] / $pro, 6); }
        }
        /* Stabile Reihenfolge bei gleichem Stundenmittel: asort() ist zwar
         * seit PHP 8.0 stabil, unter 7.4 aber nicht. Ohne den Zweitschluessel
         * kann derselbe Fahrplan unter zwei PHP-Fassungen anders aussehen. */
        $stunden = array_keys($mittelwerte);
        usort($stunden, function ($a, $b) use ($mittelwerte) {
            if ($mittelwerte[$a] !== $mittelwerte[$b]) {
                return $mittelwerte[$a] < $mittelwerte[$b] ? -1 : 1;
            }
            return $a < $b ? -1 : ($a > $b ? 1 : 0);
        });
        $wieviele = max(1, (int) ceil($anzahl / $pro));
        $gewaehlt = array_slice($stunden, 0, $wieviele);
        foreach ($kand as $ts => $ct) {
            if (in_array($ts - ($ts % 3600), $gewaehlt, true)) { $treffer[] = $ts; }
        }

    } elseif ($art === 'scheiben') {
        /* Die guenstigsten Einzelscheiben, ohne Stundenraster und ohne
         * Zusammenhang. Bei Preisgleichstand entscheidet die fruehere
         * Scheibe - sonst haengt das Ergebnis an der Sortierfunktion. */
        $sortiert = $ks;
        usort($sortiert, function ($a, $b) use ($kand) {
            if ($kand[$a] !== $kand[$b]) { return $kand[$a] < $kand[$b] ? -1 : 1; }
            return $a < $b ? -1 : ($a > $b ? 1 : 0);
        });
        $treffer = array_slice($sortiert, 0, max(1, (int) $anzahl));

    } else {
        if ($art === 'schwelle') {
            $grenze = isset($r['schwelle']) ? (float) $r['schwelle'] : 0.0;
        } else {
            /* null heisst "Tagesmittel nicht bekannt". Bis 1.0.0 stand hier
             * "$m <= 0", und damit galt ein ECHTES negatives Tagesmittel als
             * unbekannt - an genau dem Tag, an dem die Regel am meisten
             * bewirkt. */
            $m = ($mittel === null || !is_numeric($mittel)) ? null : (float) $mittel;
            if ($m === null) { $m = $kand ? array_sum($kand) / count($kand) : 0.0; }
            $prozent = max(0, min(90, isset($r['prozent']) ? (int) $r['prozent'] : 0));
            /* "X Prozent UNTER dem Mittel" - gemessen am Betrag des Mittels,
             * nicht als Faktor. Bei einem Mittel von -10 ct und 20 Prozent
             * ergab "$m * (1 - 0.2)" die Grenze -8, also OBERHALB des
             * Mittels: die Regel wurde bei Negativpreisen weiter statt
             * enger. Richtig ist -12. */
            $grenze = plan_runde($m - abs($m) * $prozent / 100, 3);
        }
        foreach ($kand as $ts => $ct) {
            if ($ct <= $grenze) { $treffer[] = $ts; }
        }
    }

    sort($treffer);
    return $treffer;
}

/* ==================================================================
 * Taktschutz
 * ================================================================== */

/**
 * Eine Trefferliste in zusammenhaengende Bloecke zerlegen.
 * Rueckgabe: Liste von array(erster_ts, letzter_ts, anzahl).
 */
function plan_bloecke($treffer, $slotlen)
{
    $slotlen = max(1, (int) $slotlen);
    $out = array();
    $vorher = null;
    foreach ($treffer as $ts) {
        if ($vorher !== null && $ts - $vorher === $slotlen) {
            $out[count($out) - 1][1] = $ts;
            $out[count($out) - 1][2]++;
        } else {
            $out[] = array($ts, $ts, 1);
        }
        $vorher = $ts;
    }
    return $out;
}


/**
 * Luecken unter der Mindestpause zumachen - alles oder nichts.
 *
 * Steht als eigene Funktion da, weil plan_takt() sie ZWEIMAL braucht:
 * einmal vor dem Verlaengern, damit ein zu kurzer Block gerettet wird, und
 * einmal danach, weil das Verlaengern und das Werfen neue Luecken
 * aufreissen. Bis 1.1.2 gab es nur den ersten Durchgang; gemessen ueber
 * 3815 Faelle blieben dabei 171 Ergebnisse uebrig, die die Mindestpause
 * nicht einhielten.
 *
 * Zugemacht wird nur mit Scheiben, die Kandidaten sind - damit bleiben
 * Budget, Frist und Zeitfenster gewahrt. Laesst sich eine Luecke so nicht
 * ueberbruecken, bleibt sie offen.
 *
 * Rueckgabe: sortierte Liste von Zeitstempeln.
 */
function plan_luecken_zu($treffer, $kand, $slotlen, $min_pause)
{
    if (!$treffer || $min_pause <= 0) { return $treffer; }
    $gesetzt = array_flip($treffer);
    $bloecke = plan_bloecke($treffer, $slotlen);
    for ($i = 1; $i < count($bloecke); $i++) {
        $luecke = (int) plan_runde(($bloecke[$i][0] - $bloecke[$i - 1][1] - $slotlen) / 60);
        if ($luecke > 0 && $luecke < $min_pause) {
            // Erst sammeln und pruefen, dann setzen: alles oder nichts.
            $fuellung = array();
            $zulaessig = true;
            for ($ts = $bloecke[$i - 1][1] + $slotlen; $ts < $bloecke[$i][0]; $ts += $slotlen) {
                if (!isset($kand[$ts])) { $zulaessig = false; break; }
                $fuellung[] = $ts;
            }
            if ($zulaessig) {
                foreach ($fuellung as $ts) { $gesetzt[$ts] = 1; }
            }
        }
    }
    $treffer = array_keys($gesetzt);
    sort($treffer);
    return $treffer;
}

/**
 * Mindestlaufzeit und Mindestpause durchsetzen.
 *
 * Zwei Schritte, in dieser Reihenfolge - und die Reihenfolge ist der ganze
 * Trick:
 *
 *   1. LOECHER ZUMACHEN. Ist die Pause zwischen zwei Bloecken kuerzer als
 *      min_pause, laeuft das Geraet durch. Lieber ein paar teure Minuten
 *      mitnehmen als aus- und gleich wieder einschalten.
 *
 *   2. ZU KURZE BLOECKE VERLAENGERN. Was danach noch kuerzer ist als
 *      min_lauf, wird nach hinten aus den Kandidaten aufgefuellt. Reicht
 *      das nicht, faellt der Block weg - ein Block unter der
 *      Mindestlaufzeit ist genau das, was die Regel verhindern soll.
 *
 * Zuerst zumachen, dann verlaengern: umgekehrt wuerde ein zu kurzer Block
 * verworfen, den das Zumachen gerettet haette.
 *
 * BEIDE Schritte arbeiten nur mit Scheiben, die ohnehin Kandidaten sind -
 * damit bleiben Budget, Frist und Zeitfenster gewahrt.
 *
 * Bis 1.1.3 galt das nur fuer Schritt 2. Schritt 1 machte jede Luecke zu,
 * gleich ob die Scheiben darin zulaessig waren. Gemessen an zwei Regeln zu
 * je 2,0 kW mit budget_kw 2,0 und min_pause 30: in der Belegung standen um
 * 00:15 dann 4 kW - das Leistungsbudget war gerissen, und mit derselben
 * Anordnung auch das zweite Budget des Paragrafen 14a EnWG. Ebenso lief
 * eine Regel mit Fenster 20-10 Uhr und min_pause 720 zehn Stunden lang
 * ausserhalb ihres Fensters und buchte das Sechsfache ihrer Energiemenge.
 * Der Kopf dieser Funktion versprach die Wahrung schon damals; nur der
 * Code hielt sie an einer von zwei Stellen.
 *
 * Zugemacht wird ZWEIMAL: vor dem Verlaengern, damit ein zu kurzer Block
 * gerettet wird, und danach noch einmal, weil das Verlaengern und das
 * Werfen neue Luecken aufreissen. Bis 1.1.2 fehlte der zweite Durchgang;
 * gemessen ueber 3815 Faelle hielten dadurch 171 Ergebnisse die
 * Mindestpause nicht ein. Beide Male tut es plan_luecken_zu().
 *
 * Zugemacht wird ALLES ODER NICHTS. Eine Luecke halb zu schliessen liesse
 * eine kuerzere Luecke stehen, die immer noch unter der Mindestpause
 * liegt - der Taktschutz haette dann Leistung gebucht, ohne sein Ziel zu
 * erreichen. Laesst sich eine Luecke nicht zulaessig ueberbruecken, bleibt
 * sie offen: eine gerissene Budgetgrenze ist teurer als ein Takt zu viel.
 *
 * $kand darf leer sein; dann wird weder zugemacht noch verlaengert,
 * sondern nur geworfen.
 */
function plan_takt($treffer, $kand, $slotlen, $min_lauf, $min_pause)
{
    $slotlen = max(1, (int) $slotlen);
    $min_lauf = max(0, (int) $min_lauf);
    $min_pause = max(0, (int) $min_pause);
    if (!$treffer || ($min_lauf <= 0 && $min_pause <= 0)) { return $treffer; }

    $je = max(1, (int) plan_runde($slotlen / 60));     // Minuten je Scheibe
    $gesetzt = array_flip($treffer);

    // ---- 1. Loecher zumachen ----
    $treffer = plan_luecken_zu($treffer, $kand, $slotlen, $min_pause);
    $gesetzt = array_flip($treffer);

    // ---- 2. Zu kurze Bloecke verlaengern, sonst werfen ----
    if ($min_lauf > 0) {
        $bloecke = plan_bloecke($treffer, $slotlen);
        foreach ($bloecke as $b) {
            $laenge = $b[2] * $je;
            if ($laenge >= $min_lauf) { continue; }
            $ts = $b[1] + $slotlen;
            while ($laenge < $min_lauf && isset($kand[$ts]) && !isset($gesetzt[$ts])) {
                $gesetzt[$ts] = 1;
                $laenge += $je;
                $ts += $slotlen;
            }
            if ($laenge < $min_lauf) {
                // Immer noch zu kurz: der ganze Block faellt weg, samt dem,
                // was gerade angehaengt wurde.
                for ($x = $b[0]; $x <= $b[1]; $x += $slotlen) { unset($gesetzt[$x]); }
                for ($x = $b[1] + $slotlen; $x < $ts; $x += $slotlen) { unset($gesetzt[$x]); }
            }
        }
        $treffer = array_keys($gesetzt);
        sort($treffer);

        /* ---- 3. Noch einmal zumachen ----
         *
         * Schritt 2 fuegt Scheiben an und wirft ganze Bloecke weg; beides
         * reisst Luecken auf, die Schritt 1 noch nicht sehen konnte.
         * Gemessen ueber 3815 Faelle: ohne diesen Durchgang blieben 171
         * Ergebnisse uebrig, die die Mindestpause nicht einhielten, mit ihm
         * keines.
         *
         * Ein zweiter Durchgang genuegt, und zwar nachweisbar: Zumachen
         * fuegt nur Scheiben HINZU. Es kann also keinen Block verkuerzen -
         * die Mindestlaufzeit aus Schritt 2 bleibt erhalten - und es laesst
         * Bloecke nur verschmelzen, reisst also selbst keine Luecke auf.
         * Nach ihm ist nichts mehr zu tun. */
        $treffer = plan_luecken_zu($treffer, $kand, $slotlen, $min_pause);
    }

    return $treffer;
}

/* ==================================================================
 * Rangfolge
 * ================================================================== */

/**
 * Zwei Regeln vergleichen: erst nach Rang, bei GLEICHEM Rang nach der
 * Nummer. Rueckgabe wie bei usort: -1, 0 oder 1.
 *
 * WARUM DAS EINE EIGENE FUNKTION IST
 *
 * Der zweite Teil - die Nummer als Zweitschluessel - stand bis 1.1.0 in
 * einer anonymen Funktion mitten in plan_rechnen(). Ein Mutationslauf hat
 * gezeigt, dass ihn kein Prueffall anfasst: ersetzt man ihn durch
 * "return 0", bleibt der Selbsttest gruen.
 *
 * Und das laesst sich am ERGEBNIS auch nicht aendern. Seit PHP 8.0 ist
 * usort() STABIL: bei "return 0" bleibt die urspruengliche Reihenfolge
 * ohnehin stehen, das Ergebnis ist dasselbe. Unter PHP 7.4 ist die
 * Sortierung nicht stabil, dort KANN es abweichen - genau davor schuetzt
 * der Zweitschluessel. Ein Prueffall, der das ueber das Ergebnis messen
 * wollte, muesste sich auf die Unstetigkeit einer fremden Sortierfunktion
 * verlassen; er waere launisch und pruefte nicht uns, sondern PHP.
 *
 * Deshalb steht der Vergleich hier als benannte Funktion und wird
 * UNMITTELBAR geprueft. Der Prueffall misst die Zusage selbst - dass bei
 * gleichem Rang die kleinere Nummer vorn liegt - und nicht ihren Schatten
 * im Fahrplan.
 */
function plan_rang_vergleich($a, $b)
{
    if ($a['rang'] !== $b['rang']) { return $a['rang'] < $b['rang'] ? -1 : 1; }
    return $a['i'] < $b['i'] ? -1 : ($a['i'] > $b['i'] ? 1 : 0);
}

/**
 * In welcher Reihenfolge waehlen die Regeln?
 * Rueckgabe: Liste von array('i' => Index, 'rang' => Rang).
 */
function plan_reihenfolge($regeln)
{
    $reihe = array();
    foreach ($regeln as $i => $r) {
        $reihe[] = array('i' => $i, 'rang' => isset($r['rang']) ? (int) $r['rang'] : 50);
    }
    usort($reihe, 'plan_rang_vergleich');
    return $reihe;
}

/**
 * Sperrt eine Umweltbedingung diese Regel?
 * Rueckgabe: '' wenn nicht, sonst der Grund als Kuerzel.
 */
function plan_gesperrt($r, $umwelt)
{
    $pvs = isset($r['pv_sperre']) ? (float) $r['pv_sperre'] : 0.0;
    if ($pvs > 0 && isset($umwelt['pv_summe']) && $umwelt['pv_summe'] !== null
        && (float) $umwelt['pv_summe'] >= $pvs) {
        // Morgen kommt genug vom Dach - heute nacht nicht auf Vorrat laden.
        return 'pv';
    }
    $soc = isset($umwelt['soc']) ? $umwelt['soc'] : null;
    if ($soc !== null) {
        $min = isset($r['soc_min']) ? (int) $r['soc_min'] : 0;
        $max = isset($r['soc_max']) ? (int) $r['soc_max'] : 0;
        if ($min > 0 && (float) $soc < $min) { return 'soc_min'; }
        if ($max > 0 && (float) $soc > $max) { return 'soc_max'; }
    }
    return '';
}

/* ==================================================================
 * Fremde Auskuenfte: PV-Prognose und Speicherstand
 *
 * BEWUSST NUR AUSWERTUNG, KEIN ABRUF. Das Holen macht das aufrufende
 * Plugin mit seiner eigenen HTTP-Funktion - die kennt schon Zeitgrenzen,
 * Kopfzeilen und Fehlermeldungen. Hier steht nur, wie man aus der Antwort
 * Zahlen macht. So bleibt der Planer ohne Netz vollstaendig pruefbar.
 *
 * Drei Formen, weil es keine gemeinsame Sprache fuer PV-Prognosen gibt:
 *
 *   forecast_solar  der feste Pfad result.watt_hours_period, Werte in Wh
 *                   je Zeitabschnitt. forecast.solar ist kostenlos und
 *                   ohne Konto benutzbar - deshalb als Vorgabe.
 *   objekt          ein frei angegebener Pfad auf ein Objekt, dessen
 *                   SCHLUESSEL Zeitangaben sind und dessen Werte Zahlen.
 *   liste           ein frei angegebener Pfad auf eine Liste von Objekten,
 *                   dazu der Name des Zeit- und des Wertfeldes. Das ist die
 *                   Form von Solcast und den meisten anderen.
 * ================================================================== */

/** Einen Punktpfad in einem verschachtelten Feld aufloesen. */
function plan_pfad($daten, $pfad)
{
    $pfad = trim((string) $pfad);
    if ($pfad === '') { return $daten; }
    foreach (explode('.', $pfad) as $teil) {
        if (!is_array($daten) || !isset($daten[$teil])) { return null; }
        $daten = $daten[$teil];
    }
    return $daten;
}

/**
 * Einen Wert in Wh je Zeitscheibe umrechnen.
 *
 * 'wh' ist die Energie der Scheibe selbst. 'w' und 'kw' sind eine
 * mittlere Leistung - daraus wird die Energie erst mit der Scheibenlaenge.
 * Wer das verwechselt, bekommt bei Viertelstunden den vierfachen Wert,
 * und die PV-Gutschrift greift viel zu frueh.
 */
function plan_nach_wh($wert, $einheit, $slotlen)
{
    $w = (float) $wert;
    $std = max(1, (int) $slotlen) / 3600.0;
    /* Klein schreiben, bevor verglichen wird. Ohne das fiel 'KW' oder 'kW'
     * stillschweigend auf 'wh' zurueck - Faktor 1000 daneben, ohne eine
     * Meldung. Gemessen: plan_nach_wh(1, 'KW', 3600) gab 1 Wh statt 1000.
     * Genau davor warnt der Kommentar zwei Zeilen darueber. */
    $einheit = strtolower(trim((string) $einheit));
    if ($einheit === 'kw') { return $w * 1000.0 * $std; }
    if ($einheit === 'w')  { return $w * $std; }
    return $w;   // 'wh'
}

/**
 * Eine PV-Prognose auswerten.
 *
 * Rueckgabe: array(ts => Wh), auf die Zeitscheiben gerundet. Liefert die
 * Quelle feinere Abschnitte als eine Zeitscheibe, werden sie aufaddiert;
 * liefert sie groebere, steht der Wert nur in der ersten Scheibe. Beides
 * ist besser als eine erfundene Verteilung - und beides steht so in der
 * Oberflaeche.
 *
 * Rueckgabe: array(Werte, Meldung). Bei einem Fehler ist Werte leer und
 * die Meldung sagt, woran es lag.
 */
function plan_pv_lesen($daten, $art, $pfad, $zeitfeld, $wertfeld, $einheit, $slotlen)
{
    if (!is_array($daten)) {
        return array(array(), 'KEINE_ANTWORT');
    }
    $slotlen = max(1, (int) $slotlen);
    $paare = array();

    if ($art === 'forecast_solar') {
        $k = plan_pfad($daten, 'result.watt_hours_period');
        if (!is_array($k)) { return array(array(), 'PFAD_LEER'); }
        foreach ($k as $zeit => $wert) { $paare[] = array($zeit, $wert); }
        $einheit = 'wh';

    } elseif ($art === 'objekt') {
        $k = plan_pfad($daten, $pfad);
        if (!is_array($k)) { return array(array(), 'PFAD_LEER'); }
        foreach ($k as $zeit => $wert) { $paare[] = array($zeit, $wert); }

    } elseif ($art === 'liste') {
        $k = plan_pfad($daten, $pfad);
        if (!is_array($k)) { return array(array(), 'PFAD_LEER'); }
        $zf = trim((string) $zeitfeld);
        $wf = trim((string) $wertfeld);
        if ($zf === '' || $wf === '') { return array(array(), 'FELDNAMEN_FEHLEN'); }
        foreach ($k as $eintrag) {
            if (!is_array($eintrag) || !isset($eintrag[$zf], $eintrag[$wf])) { continue; }
            $paare[] = array($eintrag[$zf], $eintrag[$wf]);
        }

    } else {
        return array(array(), 'KEINE_QUELLE');
    }

    $out = array();
    $unlesbar = 0;
    foreach ($paare as $p) {
        $ts = is_numeric($p[0]) ? (int) $p[0] : strtotime((string) $p[0]);
        if ($ts === false || $ts <= 0 || !is_numeric($p[1])) { $unlesbar++; continue; }
        $scheibe = $ts - ($ts % $slotlen);
        $wh = plan_nach_wh($p[1], $einheit, $slotlen);
        $out[$scheibe] = (isset($out[$scheibe]) ? $out[$scheibe] : 0.0) + $wh;
    }
    ksort($out);
    if (!$out) {
        return array(array(), $unlesbar > 0 ? 'ZEITEN_UNLESBAR' : 'PFAD_LEER');
    }
    return array($out, '');
}

/** Summe der Prognose ueber die naechsten $stunden Stunden, in kWh. */
function plan_pv_summe($pv, $jetzt, $stunden = 24)
{
    if (!is_array($pv) || !$pv) { return null; }
    $ende = (int) $jetzt + max(1, (int) $stunden) * 3600;
    $s = 0.0;
    foreach ($pv as $ts => $wh) {
        if ($ts >= $jetzt && $ts < $ende) { $s += (float) $wh; }
    }
    return plan_runde($s / 1000.0, 3);
}

/**
 * Einen Speicherstand auswerten.
 * Rueckgabe: array(Prozent|null, Meldung).
 */
function plan_soc_lesen($daten, $pfad)
{
    if (!is_array($daten)) { return array(null, 'KEINE_ANTWORT'); }
    $v = plan_pfad($daten, $pfad);
    if ($v === null || is_array($v) || !is_numeric($v)) { return array(null, 'PFAD_LEER'); }
    $p = (float) $v;
    if ($p < 0 || $p > 100) {
        // Eine Zahl ausserhalb 0 bis 100 ist kein Prozentwert. Sie
        // durchzulassen hiesse, jede Sperre unbrauchbar zu machen.
        return array(null, 'AUSSERHALB');
    }
    return array(plan_runde($p, 1), '');
}

/* ==================================================================
 * Der Fahrplan
 * ================================================================== */

/**
 * Alle Regeln in Rangfolge planen.
 *
 * $preise   array(ts => ct/kWh), aufsteigend
 * $slotlen  Sekunden je Zeitscheibe
 * $jetzt    Beginn der laufenden Zeitscheibe
 * $regeln   Liste der Regeln (mit den Feldern beider Vorgaben)
 * $umwelt   array('pv' => array(ts=>Wh)|null, 'pv_summe' => kWh|null,
 *                 'soc' => Prozent|null, 'neg' => 0/1, 'mittel' => ct|null,
 *                 'laufend' => array(regel_index => bis_ts))
 * $g        array('budget_kw','pv_bonus','pv_schwelle',
 *                 'budget2_kw','budget2_von','budget2_bis')
 *
 * Rueckgabe je Regel: nr, aktiv, in, rest, ct, start, startmin, grund,
 * slots, anzahl, noetig, fehlt, verdraengt, gesperrt, rang, leistung,
 * ct_sofort, spart_ct, spart_eur, kwh, mangel.
 *
 * 'in' und 'rest' zaehlen in MINUTEN, damit dieselbe Rechnung fuer
 * stuendliche und viertelstuendliche Preise passt. Das aufrufende Plugin
 * rechnet um, wenn es Stunden ausgeben will.
 *
 * ------------------------------------------------------------------
 * Die moeglichen Werte von 'grund' - vollstaendig, damit die Oberflaeche
 * keinen erfinden muss:
 *
 *   aus        die Regel ist abgeschaltet
 *   gesperrt   PV-Prognose oder Speicherstand sperren sie ('gesperrt' sagt
 *              welche - siehe Feld 'gesperrt')
 *   keine      es gibt ueberhaupt keine Zeitscheibe (keine Preise, oder
 *              Zeitfenster und Horizont lassen nichts uebrig)
 *   frist      die Frist hat das Fenster abgeschnitten; ohne sie waere
 *              etwas dagewesen
 *   budget     das Leistungsbudget hat alles weggenommen
 *   wartet     geplant, aber jetzt gerade nicht dran
 *   laeuft     laeuft nur noch, weil der Block schon begonnen hat
 *              (Hysterese)
 *   negativ    laeuft, weil der Preis negativ ist
 *   fenster | stunden | scheiben | schwelle | mittel
 *              laeuft aus dem Grund, den die Regelart nennt
 * ------------------------------------------------------------------
 */
function plan_rechnen($preise, $slotlen, $jetzt, $regeln, $umwelt, $g)
{
    $slotlen = max(1, (int) $slotlen);
    $jetzt = (int) $jetzt;
    $budget = isset($g['budget_kw']) ? (float) $g['budget_kw'] : 0.0;
    $b2 = null;
    if (isset($g['budget2_kw']) && (float) $g['budget2_kw'] > 0) {
        $b2 = array(
            'kw'  => (float) $g['budget2_kw'],
            'von' => isset($g['budget2_von']) ? (int) $g['budget2_von'] : 0,
            'bis' => isset($g['budget2_bis']) ? (int) $g['budget2_bis'] : 0,
        );
    }
    $eff = plan_effektivpreise($preise,
        isset($umwelt['pv']) ? $umwelt['pv'] : null,
        isset($g['pv_bonus']) ? $g['pv_bonus'] : 0,
        isset($g['pv_schwelle']) ? $g['pv_schwelle'] : 500);
    /* null heisst "nicht bekannt" und wird in plan_waehlen() aus den
     * Kandidaten ersetzt. 0.0 waere ein Wert und kein Nichtwissen. */
    $mittel = (isset($umwelt['mittel']) && is_numeric($umwelt['mittel']))
        ? (float) $umwelt['mittel'] : null;
    $neg = !empty($umwelt['neg']);
    $laufend = (isset($umwelt['laufend']) && is_array($umwelt['laufend']))
        ? $umwelt['laufend'] : array();

    $reihe = plan_reihenfolge($regeln);

    $belegt = array();
    $erg = array();

    foreach ($reihe as $z) {
        $i = $z['i'];
        $r = $regeln[$i];
        $leistung = isset($r['leistung']) ? (float) $r['leistung'] : 0.0;

        $e = array(
            'nr' => $i + 1, 'aktiv' => 0, 'in' => -1, 'rest' => 0, 'ct' => 0.0,
            'start' => -1, 'startmin' => 0, 'grund' => 'aus', 'slots' => array(),
            'anzahl' => 0, 'noetig' => 0, 'fehlt' => 0,
            'verdraengt' => 0, 'gesperrt' => '',
            'rang' => isset($r['rang']) ? (int) $r['rang'] : 50, 'leistung' => $leistung,
            // ---- ab 1.1.0 ----
            'ct_sofort' => 0.0, 'spart_ct' => 0.0, 'spart_eur' => 0.0, 'kwh' => 0.0,
            'mangel' => '',
        );

        /* ---- Maengel an der Regel selbst ----
         *
         * Nicht behoben, sondern GENANNT. Die Oberflaeche weist beides beim
         * Speichern ab; hierher kommen sie, wenn die Werte aus einer
         * zurueckgespielten Sicherung oder einer von Hand bearbeiteten
         * Konfigurationsdatei stammen. Eine Regel, die aus einem Zahlendreher
         * nie laufen kann, soll das sagen und nicht schweigen. */
        $maengel = array();
        if (plan_energie_ohne_leistung($r)) {
            // Ohne Leistung gibt es keine Laufzeit; die Zahl kommt weiter
            // aus 'n', und die eingetragene Energiemenge blieb ungenutzt.
            $maengel[] = 'energie_ohne_leistung';
        }
        $smin = isset($r['soc_min']) ? (int) $r['soc_min'] : 0;
        $smax = isset($r['soc_max']) ? (int) $r['soc_max'] : 0;
        if ($smin > 0 && $smax > 0 && $smin >= $smax) {
            // "erst ab 80 Prozent, aber nur bis 20" - die Regel kann nie
            // laufen, und ohne diesen Hinweis sieht man nur eine Sperre.
            $maengel[] = 'soc_reihe';
        }
        $e['mangel'] = implode(',', $maengel);

        if (empty($r['aktiv'])) { $erg[$i] = $e; continue; }

        $sperre = plan_gesperrt($r, $umwelt);
        if ($sperre !== '') {
            $e['gesperrt'] = $sperre;
            $e['grund'] = 'gesperrt';
            $erg[$i] = $e;
            continue;
        }

        $noetig = plan_slots_noetig($r, $slotlen);
        $e['noetig'] = $noetig;

        /* Wie viele Scheiben faenden ohne Budget Platz? Die Differenz zu dem,
         * was uebrig bleibt, ist die Verdraengung - sie steht in der
         * Oberflaeche und beantwortet die Frage "warum laedt es nicht?". */
        $ohne = plan_kandidaten($r, $eff, $jetzt, $slotlen, array(), 0, null);
        $mit  = plan_kandidaten($r, $eff, $jetzt, $slotlen, $belegt, $budget, $b2);
        $e['verdraengt'] = max(0, count($ohne) - count($mit));

        /* Und wie viele waeren es OHNE die Frist? Nur so laesst sich
         * "die Frist war zu knapp" von "es gab ohnehin nichts"
         * unterscheiden - und genau das ist die Frage, die der Anwender
         * am naechsten Morgen stellt. */
        $ohne_frist = count($ohne);
        if (isset($r['frist']) && (int) $r['frist'] >= 0) {
            $rf = $r;
            $rf['frist'] = -1;
            $ohne_frist = count(plan_kandidaten($rf, $eff, $jetzt, $slotlen, array(), 0, null));
        }

        $treffer = plan_waehlen($r, $mit, $slotlen, $noetig, $mittel);

        /* Taktschutz auf die gewaehlten Scheiben, bevor irgendetwas gebucht
         * wird - sonst stuende in der Belegung eine andere Leistung als im
         * Fahrplan. */
        $treffer = plan_takt($treffer, $mit, $slotlen,
            isset($r['min_lauf']) ? $r['min_lauf'] : 0,
            isset($r['min_pause']) ? $r['min_pause'] : 0);

        /* Hysterese: ein Block, der schon laeuft, bleibt bis zu seinem Ende
         * stehen - auch wenn die neue Preisreihe inzwischen eine billigere
         * Scheibe kennt. Ohne das schaltet die Wallbox bei jedem Abruf um,
         * und der Fahrplan von vor fuenf Minuten war eine Luege. */
        $bis = isset($laufend[$i]) ? (int) $laufend[$i] : 0;
        /* Vor der Hysterese merken, ob die laufende Scheibe ohnehin gewaehlt
         * war. Nur wenn NICHT, laeuft die Regel allein wegen des begonnenen
         * Blocks - und nur dann sagt 'laeuft' mehr als der Name der
         * Regelart. Die Frage laesst sich hinterher nicht mehr stellen. */
        $lief_ohnehin = in_array($jetzt, $treffer, true);
        /* NUR Scheiben, die ohnehin Kandidaten sind - wie in plan_takt().
         * Bis 1.1.1 stand hier $preise, also JEDE Scheibe mit einem Preis.
         * Gemessen an zwei Regeln zu je 3,0 kW mit budget_kw 3,0 und einem
         * laufenden Block: in der Belegung standen 6 kW - das
         * Leistungsbudget war gerissen, und mit derselben Anordnung auch
         * das zweite Budget des Paragrafen 14a EnWG. Ebenso lief eine
         * Regel mit Fenster 02-03 Uhr drei Stunden vor ihrem Fenster.
         * Das ist dieselbe Klasse wie der Lueckenschluss in plan_takt(),
         * die dort in 1.1.3 behoben wurde und hier stehenblieb.
         * Eine gerissene Budgetgrenze ist teurer als eine verlorene
         * Hysterese: der Hausanschluss ist eine harte Grenze. */
        if ($bis > $jetzt) {
            $vorhanden = array_flip($treffer);
            for ($ts = $jetzt; $ts < $bis; $ts += $slotlen) {
                if (isset($mit[$ts])) { $vorhanden[$ts] = 1; }
            }
            $treffer = array_keys($vorhanden);
            sort($treffer);
        }

        if ($treffer) {
            $e['slots'] = $treffer;
            $e['anzahl'] = count($treffer);
            $summe = 0.0;
            foreach ($treffer as $ts) { $summe += isset($preise[$ts]) ? (float) $preise[$ts] : 0.0; }
            $e['ct'] = plan_runde($summe / count($treffer), 3);
            $e['aktiv'] = in_array($jetzt, $treffer, true) ? 1 : 0;
            foreach ($treffer as $ts) {
                if ($ts >= $jetzt) {
                    $e['start'] = (int) date('G', $ts);
                    $e['startmin'] = (int) date('i', $ts);
                    $e['in'] = (int) plan_runde(($ts - $jetzt) / 60);
                    break;
                }
            }
            if ($e['aktiv']) {
                $rest = 0;
                for ($ts = $jetzt; in_array($ts, $treffer, true); $ts += $slotlen) {
                    $rest += (int) plan_runde($slotlen / 60);
                }
                $e['rest'] = $rest;
            }
            $art = isset($r['art']) ? (string) $r['art'] : 'fenster';
            $e['grund'] = $e['aktiv'] ? $art : 'wartet';
            /* Laeuft die Scheibe nur, weil der Block schon begonnen hat?
             * Dann sagt 'laeuft' mehr als der Name der Regelart. */
            if ($e['aktiv'] && $bis > $jetzt && !$lief_ohnehin) {
                $e['grund'] = 'laeuft';
            }

            // Buchen - erst jetzt, und nur was wirklich gewaehlt wurde.
            if ($leistung > 0) {
                foreach ($treffer as $ts) {
                    $belegt[$ts] = (isset($belegt[$ts]) ? (float) $belegt[$ts] : 0.0) + $leistung;
                }
            }
        } elseif ($e['verdraengt'] > 0) {
            // Nichts gefunden, aber es waere etwas dagewesen: das war das
            // Budget. Das gehoert unterschieden von "es gibt keine Stunde".
            $e['grund'] = 'budget';
        } elseif ($ohne_frist > count($ohne)) {
            // Ohne die Frist waere etwas dagewesen - dann war sie es.
            $e['grund'] = 'frist';
        } else {
            $e['grund'] = 'keine';
        }

        // ---- Die abgeleiteten Zahlen ----
        $art = isset($r['art']) ? (string) $r['art'] : 'fenster';
        $e = plan_kennzahlen($e, $r, $preise, $jetzt, $slotlen, $noetig);
        if ($e['fehlt'] > 0 && $e['anzahl'] > 0 && $ohne_frist > count($ohne)
            && in_array($art, array('fenster', 'stunden', 'scheiben'), true)) {
            // Teilweise geplant, und die Frist ist schuld.
            $e['grund'] = $e['aktiv'] ? $art : 'frist';
        }

        /* Negativer Preis sticht - wer dann nicht laedt, verschenkt Geld.
         * ABER: das Budget sticht zurueck. Sonst waere die Verdraengung bei
         * negativem Preis wirkungslos, und genau dann laufen alle Geraete
         * gleichzeitig los. Deshalb greift die Ausnahme nur, wenn fuer die
         * laufende Scheibe noch Leistung frei ist. */
        if (!empty($r['neg']) && $neg) {
            $schon = isset($belegt[$jetzt]) ? (float) $belegt[$jetzt] : 0.0;
            $kuenftig = plan_runde($schon + ($e['aktiv'] ? 0 : $leistung), 4);
            $passt = true;
            if ($leistung > 0) {
                if ($budget > 0 && $kuenftig > plan_runde($budget, 4)) { $passt = false; }
                if ($b2 !== null && $b2['kw'] > 0
                    && plan_in_zeitfenster((int) date('G', $jetzt), $b2['von'], $b2['bis'])
                    && $kuenftig > plan_runde($b2['kw'], 4)) { $passt = false; }
            }
            if ($passt) {
                if (!$e['aktiv'] && $leistung > 0) {
                    $belegt[$jetzt] = $schon + $leistung;
                }
                /* Die laufende Scheibe gehoert in die Trefferliste.
                 *
                 * Bis 1.0.0 wurde hier nur 'aktiv' gesetzt und intern
                 * gebucht - 'slots' blieb leer. plan_belegung() liest aber
                 * NUR 'slots'. Gemessen: drei Regeln zu 4 kW, Budget 9 kW,
                 * Negativpreis, kein Treffer aus der Regelart - zwei Regeln
                 * liefen mit zusammen 8 kW, und die Belegungstabelle zeigte
                 * 0 kW. Wer dort nachsieht, warum die dritte nicht laeuft,
                 * findet eine leere Tabelle und keine Antwort. */
                if (!in_array($jetzt, $e['slots'], true)) {
                    $e['slots'][] = $jetzt;
                    sort($e['slots']);
                    $e['anzahl'] = count($e['slots']);
                    $summe = 0.0;
                    foreach ($e['slots'] as $ts) {
                        $summe += isset($preise[$ts]) ? (float) $preise[$ts] : 0.0;
                    }
                    $e['ct'] = plan_runde($summe / max(1, count($e['slots'])), 3);
                    $e['start'] = (int) date('G', $jetzt);
                    $e['startmin'] = (int) date('i', $jetzt);
                }
                $e['aktiv'] = 1;
                $e['in'] = 0;
                $e['rest'] = max((int) plan_runde($slotlen / 60), (int) $e['rest']);
                /* Die abgeleiteten Zahlen NOCH EINMAL: dieser Zweig hat
                 * 'slots', 'anzahl' und 'ct' veraendert, nachdem sie
                 * gerechnet waren. Bis 1.1.2 blieben sie stehen - eine
                 * Regel zu 4 kW, die allein wegen des Negativpreises lief,
                 * meldete kwh=0 und fehlt=2 statt kwh=4,0 und fehlt=1. */
                $e = plan_kennzahlen($e, $r, $preise, $jetzt, $slotlen, $noetig);
                $e['grund'] = 'negativ';
            }
        }

        $erg[$i] = $e;
    }

    ksort($erg);
    return array_values($erg);
}


/**
 * Die abgeleiteten Zahlen eines Fahrplaneintrags rechnen: was fehlt, was
 * es sofort gekostet haette, was das Warten bringt, wie viel Energie
 * verschoben wird.
 *
 * WARUM DAS EINE EIGENE FUNKTION IST: plan_rechnen() braucht sie ZWEIMAL.
 * Der Negativpreis-Zweig traegt die laufende Scheibe nachtraeglich nach
 * und veraendert dabei 'slots', 'anzahl' und 'ct'. Bis 1.1.2 stand die
 * Rechnung nur davor; gemessen an einer Regel zu 4 kW, die allein wegen
 * des Negativpreises lief, meldete der Fahrplan danach kwh=0 und fehlt=2
 * statt kwh=4,0 und fehlt=1.
 *
 * Die Funktion haengt nur von $e['anzahl'], $e['ct'] und den Preisen ab -
 * sie darf deshalb beliebig oft laufen, das Ergebnis bleibt dasselbe.
 *
 * 'grund' fasst sie NICHT an. Wer den Grund setzt, weiss mehr ueber den
 * Zusammenhang als diese Rechnung.
 *
 * Gerechnet wird mit den ECHTEN Preisen, nicht mit dem Effektivpreis: die
 * PV-Gutschrift ist eine Lenkungsgroesse, kein Geld auf der Rechnung.
 */
function plan_kennzahlen($e, $r, $preise, $jetzt, $slotlen, $noetig)
{
    $leistung = isset($r['leistung']) ? (float) $r['leistung'] : 0.0;
    $art = isset($r['art']) ? (string) $r['art'] : 'fenster';

    /* ---- Fehlt etwas an der Laufzeit? ----
     *
     * Bis 1.0.0 bekam eine Regel mit n=5 und einer Frist, die nur zwei
     * Stunden zulaesst, stillschweigend zwei Stunden: verdraengt=0,
     * grund='fenster', und nichts im Rueckgabefeld sagte, dass drei
     * Stunden fehlen. Genau die Frage "warum ist die Waesche nicht
     * fertig?" blieb unbeantwortet.
     *
     * Bei den Arten 'schwelle' und 'mittel' gibt es keine Sollmenge -
     * sie nehmen, was unter der Grenze liegt. Dort waere 'fehlt' eine
     * Falschaussage. */
    if (in_array($art, array('fenster', 'stunden', 'scheiben'), true)) {
        $e['fehlt'] = max(0, $noetig - $e['anzahl']);
    }

    /* ---- Was es sofort gekostet haette, und was das Warten bringt ----
     *
     * Die Gegenrechnung ist "jetzt einschalten und durchlaufen lassen":
     * die naechsten $noetig Scheiben ab jetzt, der Reihe nach, egal was
     * sie kosten. Nur so ist die Zahl ehrlich - ein Vergleich gegen den
     * Tagesdurchschnitt waere geschmeichelt. */
    if ($e['anzahl'] > 0) {
        $summe = 0.0; $n = 0;
        for ($k = 0; $k < $noetig; $k++) {
            $ts = $jetzt + $k * $slotlen;
            if (!isset($preise[$ts])) { break; }
            $summe += (float) $preise[$ts];
            $n++;
        }
        if ($n > 0) {
            $e['ct_sofort'] = plan_runde($summe / $n, 3);
            $e['spart_ct'] = plan_runde($e['ct_sofort'] - $e['ct'], 3);
        }
        /* Die verschobene Energiemenge: entweder eingetragen, oder aus
         * Leistung mal geplanter Laufzeit. Ohne beides bleibt sie 0 -
         * dann gibt es einen Preisvorteil je kWh, aber keine Summe. */
        $energie = isset($r['energie']) ? (float) $r['energie'] : 0.0;
        if ($energie <= 0 && $leistung > 0) {
            $energie = $leistung * $e['anzahl'] * $slotlen / 3600.0;
        }
        $e['kwh'] = plan_runde($energie, 3);
        $e['spart_eur'] = plan_runde($e['spart_ct'] / 100.0 * $energie, 2);
    }
    return $e;
}

/**
 * Die Belegung je Zeitscheibe aus einem fertigen Fahrplan.
 * Fuer die Anzeige: eine Zeile je Scheibe, wer wann wie viel zieht.
 */
function plan_belegung($fahrplan)
{
    $out = array();
    foreach ($fahrplan as $e) {
        if ((float) $e['leistung'] <= 0) { continue; }
        foreach ($e['slots'] as $ts) {
            if (!isset($out[$ts])) { $out[$ts] = 0.0; }
            $out[$ts] = plan_runde($out[$ts] + (float) $e['leistung'], 4);
        }
    }
    ksort($out);
    return $out;
}

/* ==================================================================
 * Selbsttest
 *
 * Jeder Fall von Hand nachgerechnet. Sie laufen ohne Netz, ohne Dateien
 * und mit einer festen Uhrzeit - deshalb ist das Ergebnis reproduzierbar
 * und taugt als Knopf im Reiter Test. Die Zahl der Faelle steht in der
 * ersten Ausgabezeile und wird gezaehlt, nicht behauptet.
 *
 * Die Preisreihe ist bewusst klein und von Hand gesetzt, damit sich jeder
 * erwartete Wert nachzaehlen laesst.
 *
 * ------------------------------------------------------------------
 * WAS 1.1.0 AN FAELLEN DAZUBEKOMMEN HAT - UND WARUM
 * ------------------------------------------------------------------
 * Der Selbsttest von 1.0.0 meldete 53 gruene Faelle. Ein Mutationslauf
 * (28 absichtliche Verfaelschungen im Quelltext, danach der Selbsttest)
 * zeigte, dass 11 davon UEBERLEBTEN - der Test prueft diese Stellen also
 * gar nicht. Eine gruene Zeile deckt nur, was sie misst.
 *
 * Ungeprueft waren unter anderem:
 *   - die Regelarten 'stunden' und 'mittel' wurden nie geplant
 *   - kein Fahrplanfall setzte ein Zeitfenster (von/bis)
 *   - keiner hatte zwei Regeln mit GLEICHEM Rang
 *   - keiner hatte ein Loch in der Preisreihe
 *   - keiner rechnete gegen die beiden Sommerzeit-Umstellungstage;
 *     genau dort steckte ein Fehler, der ein Jahr lang mitlief
 *   - der Vergangenheitsfilter, die Einheit 'w', eine inaktive Regel
 *
 * Diese Faelle stehen jetzt unten unter "Nachgezogen". Sie sind nicht
 * schoener als die anderen - sie sind die, die gefehlt haben.
 *
 * ------------------------------------------------------------------
 * UND DANN DER ZWEITE MUTATIONSLAUF
 * ------------------------------------------------------------------
 * Der erste Durchgang brachte 101 gruene Faelle. Das ist eine Zahl, keine
 * Deckung - also lief der Mutationslauf ein zweites Mal, ueber den bereits
 * erweiterten Selbsttest. Ergebnis: von achtzehn Verfaelschungen wurden
 * dreizehn erkannt und FUENF ueberlebten weiter.
 *
 * Alle fuenf sassen auf einem "genau gleich":
 *
 *     3,7 + 3,7 gegen ein Budget von genau 7,4
 *     eine angebrochene Stunde, die billiger ist als jede volle
 *     eine PV-Prognose von genau 25 gegen eine Sperre von 25
 *     ein Speicherstand von genau 20 gegen soc_min 20
 *     zwei Regeln mit genau demselben Rang
 *
 * Das ist kein Zufall. Gleichstaende entstehen beim Pruefen nie von
 * selbst - wer eine Reihe hinschreibt, waehlt unwillkuerlich Zahlen, die
 * sich unterscheiden. Man muss sie bauen.
 *
 * Sie stehen unten unter "Die Gleichstaende". Danach: 18 von 18 erkannt.
 * ================================================================== */

/** Eine Preisreihe: 24 Stunden ab $start, Preise aus der Liste. */
function plan_test_reihe($start, $werte, $slotlen = 3600)
{
    $out = array();
    $ts = $start;
    foreach ($werte as $w) {
        $out[$ts] = (float) $w;
        $ts += $slotlen;
    }
    return $out;
}

function plan_test_regel($feld = array())
{
    return array_merge(array(
        'aktiv' => 1, 'name' => '', 'art' => 'fenster', 'n' => 2,
        'von' => 0, 'bis' => 0, 'horizont' => 24,
        'schwelle' => 20.0, 'prozent' => 20, 'neg' => 0,
    ), plan_regel_vorgabe(), $feld);
}

/**
 * Liegen in den Schwesterlinien dieselben Kopien dieser Datei?
 *
 * Die Regel steht ganz oben im Kopf: wer diese Datei aendert, aendert sie in
 * allen drei Linien. Bis 1.1.5 stand sie dort als Bitte an den Menschen. Hier
 * ist das Werkzeug dazu, und es misst am INSTALLIERTEN Zustand - nicht am
 * Arbeitsordner, denn auf dem Geraet liegt, was zaehlt.
 *
 *   $home    die LoxBerry-Wurzel (LBHOMEDIR)
 *   $eigen   der volle Pfad der eigenen planer.php. Ueblicherweise NICHT
 *            angeben: __FILE__ ist diese Datei, und das ist immer richtig.
 *
 * Der Vorgabewert ist kein Bequemlichkeitsdienst, sondern eine Vorkehrung.
 * Der erste Entwurf verlangte den Pfad vom Aufrufer, und die erste
 * Aufrufstelle gab ihn falsch an: aus webfrontend/htmlauth/index.php ergab
 * dirname(__DIR__) . '/html/planer.php' den Pfad
 * .../htmlauth/plugins/html/planer.php. Auf dem installierten LoxBerry
 * liegen html/ und htmlauth/ in GETRENNTEN Baeumen - einen relativen Weg
 * vom einen in den anderen gibt es nicht. Die Tabelle im Reiter Test zeigte
 * daraufhin eine einzige Zeile mit dem Ordnernamen "html".
 *
 * Drei Aufrufer, drei Gelegenheiten fuer denselben Fehler - also nimmt die
 * Datei die Frage an sich.
 *
 * Rueckgabe: Liste aus array('ordner', 'lage', 'sha'), Lage ist eines von
 *
 *   'eigen'        die eigene Datei; ihre Pruefsumme ist der Massstab
 *   'gleich'       die Schwesterlinie traegt dieselbe Datei
 *   'verschieden'  sie traegt eine ANDERE - eine der beiden ist aelter
 *   'fehlt'        sie ist auf diesem LoxBerry nicht installiert
 *
 * 'fehlt' ist ausdruecklich KEIN Befund und kein Haken: ueber eine leere
 * Menge wird nicht geurteilt. Wer die drei Ausgaenge zu zweien zusammenzieht,
 * bekommt eine Zeile, die bei jedem Einzelplugin gruen leuchtet, ohne je
 * etwas verglichen zu haben.
 *
 * Gesucht wird NICHT nach einer festen Namensliste, sondern nach der Datei:
 * jeder Ordner unter webfrontend/html/plugins/, in dem eine planer.php
 * liegt, ist eine Schwesterlinie. Bis 1.1.6 stand hier
 *
 *     array('spotpreisawattar', 'spotpreisoctopus', 'spotpreistibber')
 *
 * und das war zweimal falsch: installiert wird nach FOLDER, und das ist
 * 'spotpreis' (aWATTar) beziehungsweise 'octopus'. Die Zeile meldete auf
 * jeder Anlage zweimal 'fehlt' und verglich nie etwas - eine Pruefung, die
 * nicht anschlagen kann. Umbenennen war kein Weg: FOLDER geht in die
 * Plugin-Kennung ein (PluginDB::_calculate_md5 aus author_name, author_email,
 * NAME und FOLDER), eine Aenderung macht aus dem Upgrade eine zweite,
 * leere Installation.
 *
 * Suchen statt aufzaehlen loest beides: es findet jede Schwesterlinie,
 * gleich wie ihr Ordner heisst, auch eine vierte, und auch eine
 * Zweitinstallation unter angehaengten MD5-Zeichen.
 */
function plan_pruefsummen($home, $eigen = null)
{
    if ($eigen === null) { $eigen = __FILE__; }
    $out = array();
    $meine = (is_string($eigen) && is_file($eigen)) ? hash_file('sha256', $eigen) : '';
    $eigener_ordner = '';
    if (is_string($eigen) && $eigen !== '') {
        $eigener_ordner = basename(dirname($eigen));
    }
    $out[] = array('ordner' => $eigener_ordner, 'lage' => 'eigen', 'sha' => (string) $meine);
    if (!is_string($home) || $home === '' || $meine === '') { return $out; }
    $treffer = glob($home . '/webfrontend/html/plugins/*/planer.php');
    if (!is_array($treffer)) { return $out; }
    sort($treffer);
    foreach ($treffer as $d) {
        $o = basename(dirname($d));
        /* Der eigene Ordner wird uebersprungen - sonst vergliche die Zeile
         * die Datei mit sich selbst und meldete stolz "gleich". Verglichen
         * wird ueber den ORDNERNAMEN und nicht ueber den Pfad: bei einer
         * Zweitinstallation heisst der Ordner spotpreistibber_01, und dann
         * ist die Datei dort eine echte zweite Ablage. */
        if ($o === $eigener_ordner) { continue; }
        if (!is_file($d)) { continue; }
        $s = (string) hash_file('sha256', $d);
        $out[] = array('ordner' => $o, 'sha' => $s,
                       'lage' => ($s === $meine) ? 'gleich' : 'verschieden');
    }
    return $out;
}

/** Rueckgabe: array(anzahl, fehl, text) */
function plan_selbsttest()
{
    $z = array();
    $fehl = 0;
    $anzahl = 0;
    $pruefe = function ($name, $ist, $soll) use (&$z, &$fehl, &$anzahl) {
        $anzahl++;
        $ok = ($ist === $soll);
        if (!$ok) { $fehl++; }
        $z[] = ($ok ? '[ OK ] ' : '[FEHL] ') . $name;
        if (!$ok) {
            $z[] = '       erzeugt : ' . json_encode($ist);
            $z[] = '       erwartet: ' . json_encode($soll);
        }
    };

    /* GEMESSEN WIRD IN EINER FESTEN ZEITZONE, und zwar in Europe/Berlin.
     *
     * Der Grund steht oben bei 1.1.5: sieben Faelle pruefen die
     * Zeitumstellung und stehen als feste Unix-Zeitpunkte da. Auf einem
     * Geraet ohne Sommerzeit - UTC ist bei manchen Abbildern der
     * Auslieferungszustand - ergeben dieselben Zeitpunkte andere
     * Ortszeiten, und die sieben Zeilen gehen rot, obwohl die Rechnung
     * stimmt.
     *
     * Zurueckgestellt wird am Ende dieser Funktion, unmittelbar vor der
     * Kopfzeile. Dazwischen gibt es keinen Rueckspruch: die Funktion
     * rechnet nur und ruft nichts, was von aussen zurueckkommen koennte.
     *
     * Was das NICHT ist: eine Aussage darueber, wie das Geraet steht. Die
     * Kopfzeile nennt deshalb beide Zeitzonen. Wessen LoxBerry auf UTC
     * steht, dessen Fristen verschieben sich an den Umstellungstagen
     * wirklich - nur faellt das nicht mehr dem Selbsttest zur Last. */
    $tz_geraet = date_default_timezone_get();
    date_default_timezone_set('Europe/Berlin');

    /* Ein fester Zeitpunkt: 10.08.2026, 00:00 Uhr Ortszeit. Alle Fristen
     * und Zeitfenster rechnen dagegen. */
    $t0 = mktime(0, 0, 0, 8, 10, 2026);
    $g0 = array('budget_kw' => 0.0, 'pv_bonus' => 0.0, 'pv_schwelle' => 500);
    $u0 = array('pv' => null, 'pv_summe' => null, 'soc' => null, 'neg' => 0, 'mittel' => 20.0);

    /* ---------- Helfer ---------- */
    $pruefe('pro Stunde bei 3600 s', plan_pro_stunde(3600), 1);
    $pruefe('pro Stunde bei 900 s', plan_pro_stunde(900), 4);
    $pruefe('pro Stunde bei 1800 s', plan_pro_stunde(1800), 2);

    $pruefe('Zeitfenster 22 bis 6, Stunde 23', plan_in_zeitfenster(23, 22, 6), true);
    $pruefe('Zeitfenster 22 bis 6, Stunde 5', plan_in_zeitfenster(5, 22, 6), true);
    $pruefe('Zeitfenster 22 bis 6, Stunde 12', plan_in_zeitfenster(12, 22, 6), false);
    $pruefe('Zeitfenster 0 bis 0 = ganzer Tag', plan_in_zeitfenster(13, 0, 0), true);

    /* ---------- Frist ---------- */
    $pruefe('Frist 7 Uhr, jetzt 0 Uhr -> heute 7 Uhr',
        plan_frist_ende($t0, 7), mktime(7, 0, 0, 8, 10, 2026));
    $pruefe('Frist 7 Uhr, jetzt 9 Uhr -> morgen 7 Uhr',
        plan_frist_ende(mktime(9, 0, 0, 8, 10, 2026), 7), mktime(7, 0, 0, 8, 11, 2026));
    $pruefe('Frist -1 = keine', plan_frist_ende($t0, -1), 0);
    $pruefe('Frist 24 ist ungueltig', plan_frist_ende($t0, 24), 0);

    /* ---------- Gleichstand beim Fenster ----------
     *
     * EICHUNG: nimmt man die Rundung in plan_waehlen() wieder heraus, geht
     * der erste Fall rot - unter 7.4 wie unter 8.4. Die beiden anderen
     * sind die Kontrolle: ohne sie wuerde der erste Fall auch dann gruen,
     * wenn die Auswahl gar nichts mehr taete. */
    $kf = array();
    foreach (array(0.1, 0.2, 0.1, 0.2, 0.15, 0.15, 0.15, 0.15) as $i => $v) {
        $kf[$t0 + $i * 900] = $v;
    }
    $pruefe('Fenster bei Gleichstand: das fruehere gewinnt',
        plan_waehlen(array('art' => 'fenster'), $kf, 900, 4, null),
        array($t0, $t0 + 900, $t0 + 1800, $t0 + 2700));
    $kf2 = array();
    foreach (array(0.1, 0.1, 0.1, 0.1, 0.15, 0.15, 0.15, 0.15) as $i => $v) {
        $kf2[$t0 + $i * 900] = $v;
    }
    $pruefe('Fenster: das echt guenstigere frueher gewinnt',
        plan_waehlen(array('art' => 'fenster'), $kf2, 900, 4, null),
        array($t0, $t0 + 900, $t0 + 1800, $t0 + 2700));
    $kf3 = array();
    foreach (array(0.2, 0.2, 0.2, 0.2, 0.15, 0.15, 0.15, 0.15) as $i => $v) {
        $kf3[$t0 + $i * 900] = $v;
    }
    $pruefe('Fenster: das echt guenstigere spaeter gewinnt',
        plan_waehlen(array('art' => 'fenster'), $kf3, 900, 4, null),
        array($t0 + 3600, $t0 + 4500, $t0 + 5400, $t0 + 6300));

    /* Die beiden Umstellungstage. Die Zeitpunkte stehen als Zahl da und
     * sind unter 7.4.33 wie unter 8.4.24 gemessen - beide Fassungen sind
     * sich bei strtotime auf eine Datumszeichenkette einig.
     *
     * DIESE FAELLE SIND DIE EICHUNG DER KORREKTUR. Baut man
     * plan_frist_ende() auf die Zeitfunktion mit Einzelargumenten zurueck,
     * gehen die beiden Faelle zur doppelten Stunde UNTER PHP 8.x rot
     * (1792886400 statt 1792890000, eine Stunde frueher). Unter 7.4
     * bleiben sie gruen - genau deshalb fiel es vorher nicht auf. */
    $pruefe('Frist 2 am 25.10., doppelte Stunde -> 02:00 CET',
        plan_frist_ende(1792881000, 2), 1792890000);
    $pruefe('Frist 2 am Vorabend des 25.10. -> 02:00 CET am Folgetag',
        plan_frist_ende(1792877400, 2), 1792890000);
    $pruefe('Frist 7 am 25.10., dem Tag mit 25 Stunden',
        plan_frist_ende(1792881000, 7), 1792908000);
    $pruefe('Frist 2 am 29.03., uebersprungene Stunde -> 03:00 CEST',
        plan_frist_ende(1774740600, 2), 1774746000);
    $pruefe('Frist 7 am 29.03., dem Tag mit 23 Stunden',
        plan_frist_ende(1774740600, 7), 1774760400);
    $pruefe('Frist 6 am Silvesterabend -> 06:00 am Neujahrstag',
        plan_frist_ende(1798756200, 6), 1798779600);

    /* ---------- Runden, fassungsfest (PR-RUNDEN) ----------
     *
     * Die ersten beiden Faelle sind die EICHUNG: sie gehen rot, sobald
     * jemand plan_runde() wieder durch die eingebaute Rundung ersetzt -
     * unter PHP 8.x, denn dort ist die Vorrundung weggefallen. Gemessen
     * ueber 72042 Werte: gegen die eingebaute Rundung unter 7.4 kein
     * einziger Unterschied, unter 8.4 genau 629.
     *
     * Der Rest haelt das gewoehnliche Verhalten fest, damit eine spaetere
     * Aenderung an plan_runde() nicht unbemerkt daran vorbeigeht. */
    $pruefe('Schaltschwelle mittel: 5,05 ct minus 15 Prozent',
        plan_runde(5.05 - abs(5.05) * 15 / 100, 3), 4.293);
    $pruefe('0,03 minus 5 Prozent auf drei Stellen',
        plan_runde(0.03 - abs(0.03) * 5 / 100, 3), 0.029);
    $pruefe('2,5 kaufmaennisch -> 3', plan_runde(2.5, 0), 3.0);
    $pruefe('-2,5 kaufmaennisch -> -3', plan_runde(-2.5, 0), -3.0);
    $pruefe('1,005 auf zwei Stellen -> 1,01', plan_runde(1.005, 2), 1.01);
    $pruefe('0,285 auf zwei Stellen -> 0,29', plan_runde(0.285, 2), 0.29);
    $pruefe('-0,285 auf zwei Stellen -> -0,29', plan_runde(-0.285, 2), -0.29);
    $pruefe('77,775 auf zwei Stellen -> 77,78', plan_runde(77.775, 2), 77.78);
    $pruefe('winziger Wert faellt auf null', plan_runde(0.0000001, 3), 0.0);
    $pruefe('null bleibt null', plan_runde(0.0, 3), 0.0);
    $pruefe('Ergebnis ist Gleitkomma, nicht Ganzzahl',
        is_float(plan_runde(2.5, 0)), true);
    $pruefe('unendlich bleibt unendlich', plan_runde(INF, 2), INF);
    $pruefe('keine Zahl bleibt keine Zahl', is_nan(plan_runde(NAN, 2)), true);

    /* ---------- Energie zu Zeitscheiben ---------- */
    $pruefe('7 kWh bei 3,7 kW, Stundenscheiben -> 2 Scheiben',
        plan_slots_noetig(plan_test_regel(array('energie' => 7.0, 'leistung' => 3.7)), 3600), 2);
    $pruefe('7 kWh bei 3,7 kW, Viertelstunden -> 8 Scheiben',
        plan_slots_noetig(plan_test_regel(array('energie' => 7.0, 'leistung' => 3.7)), 900), 8);
    $pruefe('11 kWh bei 11 kW -> 1 Scheibe (Stunde)',
        plan_slots_noetig(plan_test_regel(array('energie' => 11.0, 'leistung' => 11.0)), 3600), 1);
    $pruefe('ohne Energie bleibt es bei n=2 Stunden',
        plan_slots_noetig(plan_test_regel(array('n' => 2)), 3600), 2);
    $pruefe('ohne Energie, n=2, Viertelstunden -> 8 Scheiben',
        plan_slots_noetig(plan_test_regel(array('n' => 2)), 900), 8);

    /* ---------- PV-Gutschrift ---------- */
    $p = array($t0 => 30.0, $t0 + 3600 => 30.0, $t0 + 7200 => 30.0);
    $pv = array($t0 => 0, $t0 + 3600 => 250, $t0 + 7200 => 1000);
    $e = plan_effektivpreise($p, $pv, 10.0, 500);
    $pruefe('PV 0 Wh: Preis unveraendert', $e[$t0], 30.0);
    $pruefe('PV 250 Wh von 500: halbe Gutschrift', $e[$t0 + 3600], 25.0);
    $pruefe('PV 1000 Wh von 500: volle Gutschrift, nicht mehr', $e[$t0 + 7200], 20.0);
    $e2 = plan_effektivpreise($p, $pv, 0.0, 500);
    $pruefe('Gutschrift 0 laesst alles unveraendert', $e2[$t0 + 7200], 30.0);

    /* ---------- Auswahl: guenstigstes Fenster ----------
     * Preise ab Mitternacht: 30 30 10 10 30 30 ...
     * Zwei Stunden am Stueck muessen 02:00 und 03:00 sein. */
    $preise = plan_test_reihe($t0, array(30, 30, 10, 10, 30, 30, 30, 30));
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 2))), $u0, $g0);
    $pruefe('Fenster: 2 Stunden am Stueck ab 02:00',
        array($fp[0]['start'], $fp[0]['anzahl'], $fp[0]['in']), array(2, 2, 120));
    $pruefe('Fenster: laeuft um Mitternacht noch nicht', $fp[0]['aktiv'], 0);

    /* ---------- Frist verkuerzt das Fenster ----------
     * Dieselbe Reihe, aber Frist 2 Uhr: dann sind nur 00:00 und 01:00
     * erreichbar, obwohl 02:00/03:00 billiger waeren. */
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 2, 'frist' => 2))), $u0, $g0);
    $pruefe('Frist 2 Uhr: nimmt 00:00, obwohl 02:00 billiger waere',
        array($fp[0]['start'], $fp[0]['anzahl'], $fp[0]['aktiv']), array(0, 2, 1));

    /* ---------- Rangfolge und Budget ----------
     * Budget 5 kW. Zwei Regeln zu je 3,7 kW, beide wollen die billigste
     * Stunde. Rang 1 bekommt sie, Rang 2 muss ausweichen.
     * Preise: 30 30 10 20 30 ... -> billigste Stunde ist 02:00, zweitbeste
     * 03:00 mit 20. */
    $preise2 = plan_test_reihe($t0, array(30, 30, 10, 20, 30, 30, 30, 30));
    $g1 = array('budget_kw' => 5.0, 'pv_bonus' => 0.0, 'pv_schwelle' => 500);
    $regeln = array(
        plan_test_regel(array('art' => 'fenster', 'n' => 1, 'leistung' => 3.7, 'rang' => 1, 'name' => 'A')),
        plan_test_regel(array('art' => 'fenster', 'n' => 1, 'leistung' => 3.7, 'rang' => 2, 'name' => 'B')),
    );
    $fp = plan_rechnen($preise2, 3600, $t0, $regeln, $u0, $g1);
    $pruefe('Budget: Rang 1 bekommt die billigste Stunde (02:00)', $fp[0]['start'], 2);
    $pruefe('Budget: Rang 2 weicht auf die zweitbeste aus (03:00)', $fp[1]['start'], 3);
    $pruefe('Budget: Rang 2 meldet Verdraengung', $fp[1]['verdraengt'] > 0, true);
    $pruefe('Budget: Rang 1 wird nicht verdraengt', $fp[0]['verdraengt'], 0);

    /* Dieselben zwei Regeln ohne Budget: beide nehmen dieselbe Stunde. */
    $fp = plan_rechnen($preise2, 3600, $t0, $regeln, $u0, $g0);
    $pruefe('Ohne Budget nehmen beide dieselbe Stunde',
        array($fp[0]['start'], $fp[1]['start']), array(2, 2));

    /* Rangfolge umgedreht - dann bekommt die andere Regel den Zuschlag. */
    $regeln2 = array(
        plan_test_regel(array('art' => 'fenster', 'n' => 1, 'leistung' => 3.7, 'rang' => 9, 'name' => 'A')),
        plan_test_regel(array('art' => 'fenster', 'n' => 1, 'leistung' => 3.7, 'rang' => 1, 'name' => 'B')),
    );
    $fp = plan_rechnen($preise2, 3600, $t0, $regeln2, $u0, $g1);
    $pruefe('Rang entscheidet, nicht die Reihenfolge in der Liste',
        array($fp[0]['start'], $fp[1]['start']), array(3, 2));

    /* Ergebnis bleibt an seinem Platz: Regel 1 ist immer $fp[0]. */
    $pruefe('Reihenfolge der Rueckgabe folgt der Regelnummer',
        array($fp[0]['nr'], $fp[1]['nr']), array(1, 2));

    /* ---------- Sperren ---------- */
    $u_pv = array_merge($u0, array('pv_summe' => 30.0));
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'schwelle', 'schwelle' => 99, 'pv_sperre' => 25.0))),
        $u_pv, $g0);
    $pruefe('PV-Sperre greift bei 30 kWh Prognose und Schwelle 25',
        array($fp[0]['aktiv'], $fp[0]['gesperrt']), array(0, 'pv'));

    $u_pv2 = array_merge($u0, array('pv_summe' => 5.0));
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'schwelle', 'schwelle' => 99, 'pv_sperre' => 25.0))),
        $u_pv2, $g0);
    $pruefe('PV-Sperre greift NICHT bei 5 kWh Prognose', $fp[0]['aktiv'], 1);

    $u_soc = array_merge($u0, array('soc' => 90.0));
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'schwelle', 'schwelle' => 99, 'soc_max' => 80))),
        $u_soc, $g0);
    $pruefe('Speicher voll: soc_max sperrt',
        array($fp[0]['aktiv'], $fp[0]['gesperrt']), array(0, 'soc_max'));

    $u_soc2 = array_merge($u0, array('soc' => 10.0));
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'schwelle', 'schwelle' => 99, 'soc_min' => 20))),
        $u_soc2, $g0);
    $pruefe('Speicher leer: soc_min sperrt', $fp[0]['gesperrt'], 'soc_min');

    /* Ohne Speicherwert wird nicht gesperrt - eine fehlende Auskunft ist
     * kein Grund, die Anlage stillzulegen. */
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'schwelle', 'schwelle' => 99,
                                    'soc_min' => 20, 'soc_max' => 80))), $u0, $g0);
    $pruefe('Ohne Speicherwert keine Sperre', $fp[0]['gesperrt'], '');

    /* ---------- Negativpreis gegen Budget ----------
     * Beide Regeln duerfen bei Negativpreis laufen, aber das Budget laesst
     * nur eine zu. */
    $u_neg = array_merge($u0, array('neg' => 1));
    $regeln3 = array(
        plan_test_regel(array('art' => 'schwelle', 'schwelle' => 0, 'leistung' => 3.7,
                              'rang' => 1, 'neg' => 1)),
        plan_test_regel(array('art' => 'schwelle', 'schwelle' => 0, 'leistung' => 3.7,
                              'rang' => 2, 'neg' => 1)),
    );
    $fp = plan_rechnen($preise, 3600, $t0, $regeln3, $u_neg,
        array('budget_kw' => 5.0, 'pv_bonus' => 0.0, 'pv_schwelle' => 500));
    $pruefe('Negativpreis: Rang 1 laeuft', $fp[0]['aktiv'], 1);
    $pruefe('Negativpreis: Rang 2 bleibt aus, weil das Budget voll ist', $fp[1]['aktiv'], 0);

    /* ---------- Viertelstunden ----------
     * Dieselbe Rechnung bei 900 s: 8 Scheiben = 2 Stunden am Stueck. */
    $vs = array();
    $w = array(30, 30, 30, 30, 30, 30, 30, 30, 10, 10, 10, 10, 10, 10, 10, 10, 30, 30, 30, 30);
    $vs = plan_test_reihe($t0, $w, 900);
    $fp = plan_rechnen($vs, 900, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 2, 'leistung' => 0))), $u0, $g0);
    $pruefe('Viertelstunden: 2 Stunden am Stueck sind 8 Scheiben ab 02:00',
        array($fp[0]['anzahl'], $fp[0]['start'], $fp[0]['in']), array(8, 2, 120));

    /* ---------- Belegung ---------- */
    $fp = plan_rechnen($preise2, 3600, $t0, $regeln, $u0, $g1);
    $bel = plan_belegung($fp);
    $pruefe('Belegung: je Stunde 3,7 kW, nicht 7,4',
        array(count($bel), array_values($bel)), array(2, array(3.7, 3.7)));

    /* ---------- Fremde Auskuenfte ---------- */
    $zeit1 = date('Y-m-d H:i:s', $t0);
    $zeit2 = date('Y-m-d H:i:s', $t0 + 3600);
    $fs = array('result' => array('watt_hours_period' => array(
        $zeit1 => 800, $zeit2 => 1500)));
    list($pvw, $m) = plan_pv_lesen($fs, 'forecast_solar', '', '', '', 'wh', 3600);
    $pruefe('forecast.solar: zwei Stunden in Wh',
        array($m, isset($pvw[$t0]) ? $pvw[$t0] : null, isset($pvw[$t0 + 3600]) ? $pvw[$t0 + 3600] : null),
        array('', 800.0, 1500.0));

    /* Zwei Halbstundenwerte fallen in dieselbe Stundenscheibe und werden
     * addiert - nicht ueberschrieben. */
    $fs2 = array('result' => array('watt_hours_period' => array(
        $zeit1 => 300, date('Y-m-d H:i:s', $t0 + 1800) => 500)));
    list($pvw2, $m2) = plan_pv_lesen($fs2, 'forecast_solar', '', '', '', 'wh', 3600);
    $pruefe('Feinere Abschnitte werden aufaddiert', $pvw2[$t0], 800.0);

    /* Liste mit eigenen Feldnamen, Werte in kW - bei Viertelstunden ist
     * 1 kW eine Viertelstunde lang gleich 250 Wh. */
    $liste = array('forecasts' => array(
        array('period_end' => $zeit1, 'pv_estimate' => 1.0),
        array('period_end' => date('Y-m-d H:i:s', $t0 + 900), 'pv_estimate' => 2.0),
    ));
    list($pvw3, $m3) = plan_pv_lesen($liste, 'liste', 'forecasts', 'period_end', 'pv_estimate', 'kw', 900);
    $pruefe('Liste in kW bei Viertelstunden: 1 kW -> 250 Wh',
        array($m3, $pvw3[$t0], $pvw3[$t0 + 900]), array('', 250.0, 500.0));

    list($pvw4, $m4) = plan_pv_lesen($liste, 'liste', 'gibtsnicht', 'period_end', 'pv_estimate', 'kw', 900);
    $pruefe('Falscher Pfad wird gemeldet, nicht verschwiegen', $m4, 'PFAD_LEER');
    list($pvw5, $m5) = plan_pv_lesen(array('a' => array('x' => 'keinedatum')), 'objekt', 'a', '', '', 'wh', 3600);
    $pruefe('Unlesbare Zeitangaben werden gemeldet', $m5, 'ZEITEN_UNLESBAR');
    list($pvw6, $m6) = plan_pv_lesen(null, 'forecast_solar', '', '', '', 'wh', 3600);
    $pruefe('Keine Antwort wird gemeldet', $m6, 'KEINE_ANTWORT');

    $pruefe('PV-Summe der naechsten 24 h in kWh',
        plan_pv_summe(array($t0 => 800, $t0 + 3600 => 1500, $t0 + 200000 => 9999), $t0, 24), 2.3);
    $pruefe('Ohne Prognose keine Summe', plan_pv_summe(array(), $t0, 24), null);

    list($soc, $ms) = plan_soc_lesen(array('bat' => array('soc' => 63.4)), 'bat.soc');
    $pruefe('Speicherstand aus einem Pfad', array($ms, $soc), array('', 63.4));
    list($soc2, $ms2) = plan_soc_lesen(array('bat' => array('soc' => 1234)), 'bat.soc');
    $pruefe('Speicherstand ausserhalb 0 bis 100 wird abgewiesen',
        array($ms2, $soc2), array('AUSSERHALB', null));
    list($soc3, $ms3) = plan_soc_lesen(array('bat' => array('soc' => 'viel')), 'bat.soc');
    $pruefe('Text statt Zahl wird abgewiesen', $ms3, 'PFAD_LEER');

    /* Und der Zweck des Ganzen: mit Gutschrift gewinnt die sonnige Stunde
     * gegen die billige Nachtstunde. Preise 10 20 20 20, PV nur um 02:00. */
    $preise3 = plan_test_reihe($t0, array(10, 20, 20, 20, 30, 30, 30, 30));
    $pv3 = array($t0 + 7200 => 1000);
    $u3 = array_merge($u0, array('pv' => $pv3));
    $gpv = array('budget_kw' => 0.0, 'pv_bonus' => 15.0, 'pv_schwelle' => 500);
    $fp = plan_rechnen($preise3, 3600, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 1))), $u3, $gpv);
    $pruefe('PV-Gutschrift laesst die Sonnenstunde gewinnen', $fp[0]['start'], 2);
    /* Der ausgewiesene Preis wird an DIESEM Lauf geprueft, nicht am
     * Lauf ohne Gutschrift.
     *
     * Bis 1.0.0 stand die Pruefung unter dem Lauf mit $g0 - also ohne
     * Bonus. Dort SIND Effektivpreis und Boersenpreis identisch, der Fall
     * konnte die beiden gar nicht unterscheiden und pruefte nichts.
     * Belegt durch eine Mutation: ersetzte man in plan_rechnen() den
     * Boersenpreis durch den Effektivpreis, blieb der Selbsttest gruen.
     * Hier ist der Unterschied sichtbar: die Sonnenstunde kostet echte
     * 20 ct, ihr Effektivpreis ist 5 ct. */
    $pruefe('Der ausgewiesene Preis ist der echte, nicht der Effektivpreis',
        $fp[0]['ct'], 20.0);
    $fp = plan_rechnen($preise3, 3600, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 1))), $u3, $g0);
    $pruefe('Ohne Gutschrift gewinnt die billigste Stunde', $fp[0]['start'], 0);
    $pruefe('Ohne Gutschrift stimmt der Preis ebenfalls', $fp[0]['ct'], 10.0);

    /* ==================================================================
     * Nachgezogen in 1.1.0 - die Faelle, die gefehlt haben
     * ================================================================== */

    /* ---- Sommerzeit: die beiden Umstellungstage ----
     * Der Fehler, den 1.0.0 ein Jahr lang mitgefuehrt hat. Am 29.03. hat
     * der Tag 23 Stunden, am 25.10. deren 25 - "Tagesbeginn + 7 * 3600"
     * trifft dann 08:00 bzw. 06:00 statt 07:00. */
    $pruefe('Frist 7 Uhr am 29.03. (Tag mit 23 Stunden)',
        plan_frist_ende(mktime(0, 0, 0, 3, 29, 2026), 7), mktime(7, 0, 0, 3, 29, 2026));
    $pruefe('Frist 7 Uhr am 25.10. (Tag mit 25 Stunden)',
        plan_frist_ende(mktime(0, 0, 0, 10, 25, 2026), 7), mktime(7, 0, 0, 10, 25, 2026));
    $pruefe('Frist ueber die Umstellung hinweg (24.10. 09 Uhr -> 25.10. 07 Uhr)',
        plan_frist_ende(mktime(9, 0, 0, 10, 24, 2026), 7), mktime(7, 0, 0, 10, 25, 2026));
    /* Die uebersprungene Stunde: 02:00 gibt es am 29.03. nicht. mktime
     * liefert dafuer 03:00 - die naechste Uhrzeit, die es wirklich gibt. */
    $pruefe('Frist 2 Uhr am 29.03. faellt in die uebersprungene Stunde',
        plan_frist_ende(mktime(0, 0, 0, 3, 29, 2026), 2), mktime(3, 0, 0, 3, 29, 2026));

    /* ---- Einheiten, klein und gross geschrieben ---- */
    $pruefe('Einheit w bei Stundenscheiben: 1000 W -> 1000 Wh',
        plan_nach_wh(1000, 'w', 3600), 1000.0);
    $pruefe('Einheit w bei Viertelstunden: 1000 W -> 250 Wh',
        plan_nach_wh(1000, 'w', 900), 250.0);
    $pruefe('Einheit KW gross geschrieben zaehlt wie kw',
        plan_nach_wh(1, 'KW', 3600), 1000.0);
    $pruefe('Unbekannte Einheit bleibt Wh', plan_nach_wh(7, 'furlong', 3600), 7.0);

    /* ---- Zeitfenster im Fahrplan ----
     * Preise 30 30 10 10 30 ... Das billige Fenster liegt 02:00-03:00,
     * das Zeitfenster laesst nur 04:00-06:00 zu. Die Regel muss auf 04:00
     * ausweichen, obwohl 02:00 billiger waere. */
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 1, 'von' => 4, 'bis' => 6))),
        $u0, $g0);
    $pruefe('Zeitfenster 4 bis 6 schliesst die billige Stunde aus',
        array($fp[0]['start'], $fp[0]['aktiv']), array(4, 0));

    /* ---- Vergangenheit wird nicht geplant ----
     * Dieselbe Reihe, aber jetzt ist es 03:00. Die billigen Stunden 02:00
     * und 03:00 sind angebrochen bzw. vorbei - 03:00 zaehlt noch. */
    $fp = plan_rechnen($preise, 3600, $t0 + 3 * 3600,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 1))), $u0, $g0);
    $pruefe('Was vorbei ist, wird nicht mehr geplant', $fp[0]['start'], 3);

    /* ---- Inaktive Regel ---- */
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('aktiv' => 0))), $u0, $g0);
    $pruefe('Inaktive Regel: aus, ohne Treffer',
        array($fp[0]['aktiv'], $fp[0]['grund'], $fp[0]['anzahl']), array(0, 'aus', 0));

    /* ---- Loch in der Preisreihe ----
     * Ueber eine fehlende Stunde darf nicht geklebt werden, sonst stuende
     * die Wallbox mittendrin.
     *
     * Die Reihe hat die Stunden 0, 1, [3, 4], [6] - zwei Loecher, und das
     * laengste zusammenhaengende Stueck ist deshalb ZWEI Stunden lang.
     * Drei am Stueck kann es nicht geben.
     *
     * (Beim ersten Anlauf stand hier eine Reihe mit nur einem Loch. Sie
     * enthielt sehr wohl drei zusammenhaengende Stunden - der Fall war
     * falsch gerechnet, nicht der Planer. Nachgezaehlt statt geglaubt.) */
    $loch = array($t0 => 30.0, $t0 + 3600 => 10.0,
                  $t0 + 10800 => 10.0, $t0 + 14400 => 10.0,
                  $t0 + 21600 => 30.0);
    $fp = plan_rechnen($loch, 3600, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 3))), $u0, $g0);
    $pruefe('Ueber ein Loch in der Preisreihe wird nicht geklebt',
        $fp[0]['anzahl'], 0);
    $fp = plan_rechnen($loch, 3600, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 2))), $u0, $g0);
    $pruefe('Zwei Stunden am Stueck gibt es trotz Loch: 03:00 und 04:00',
        array($fp[0]['start'], $fp[0]['anzahl']), array(3, 2));

    /* ---- Gleicher Rang: die Regelnummer entscheidet, immer gleich ---- */
    $regeln_gl = array(
        plan_test_regel(array('art' => 'fenster', 'n' => 1, 'leistung' => 3.7, 'rang' => 5)),
        plan_test_regel(array('art' => 'fenster', 'n' => 1, 'leistung' => 3.7, 'rang' => 5)),
    );
    $fp = plan_rechnen($preise2, 3600, $t0, $regeln_gl, $u0, $g1);
    $pruefe('Gleicher Rang: die kleinere Regelnummer waehlt zuerst',
        array($fp[0]['start'], $fp[1]['start']), array(2, 3));

    /* ---- Regelart 'stunden' ----
     * Viertelstundenreihe: 4x30, 4x10, 4x20, 4x30. Die guenstigste VOLLE
     * Stunde ist 01:00. Eine Stunde = 4 Scheiben. */
    $vs2 = plan_test_reihe($t0, array(30, 30, 30, 30, 10, 10, 10, 10,
                                      20, 20, 20, 20, 30, 30, 30, 30), 900);
    $fp = plan_rechnen($vs2, 900, $t0,
        array(plan_test_regel(array('art' => 'stunden', 'n' => 1))), $u0, $g0);
    $pruefe('Stunden: die guenstigste volle Stunde ist 01:00, vier Scheiben',
        array($fp[0]['start'], $fp[0]['anzahl']), array(1, 4));
    $fp = plan_rechnen($vs2, 900, $t0,
        array(plan_test_regel(array('art' => 'stunden', 'n' => 2))), $u0, $g0);
    $pruefe('Stunden: zwei Stunden sind acht Scheiben, verstreut erlaubt',
        $fp[0]['anzahl'], 8);
    /* Eine angebrochene Stunde wird nicht bewertet: ab 00:15 fehlt der
     * ersten Stunde eine Scheibe, sie faellt aus der Wertung. */
    $fp = plan_rechnen($vs2, 900, $t0 + 900,
        array(plan_test_regel(array('art' => 'stunden', 'n' => 1))), $u0, $g0);
    $pruefe('Stunden: angebrochene Stunden werden nicht bewertet',
        array($fp[0]['start'], $fp[0]['anzahl']), array(1, 4));

    /* ---- Regelart 'mittel' ---- */
    $preise_m = plan_test_reihe($t0, array(10, 20, 30, 20, 20, 20, 20, 20));
    $fp = plan_rechnen($preise_m, 3600, $t0,
        array(plan_test_regel(array('art' => 'mittel', 'prozent' => 20))),
        array_merge($u0, array('mittel' => 20.0)), $g0);
    $pruefe('Mittel: 20 Prozent unter 20 ct ist die Grenze 16 ct - nur die 10 ct',
        array($fp[0]['anzahl'], $fp[0]['aktiv']), array(1, 1));
    /* Und der Fall, an dem die alte Rechnung in die falsche Richtung lief:
     * bei einem Mittel von -10 ct war die Grenze -8 - also OBERHALB des
     * Mittels, die Regel wurde weiter statt enger. Richtig sind -12. */
    $preise_n = plan_test_reihe($t0, array(-30.0, -12.0, -10.0, -8.0, 0.0, 0.0, 0.0, 0.0));
    $fp = plan_rechnen($preise_n, 3600, $t0,
        array(plan_test_regel(array('art' => 'mittel', 'prozent' => 20))),
        array_merge($u0, array('mittel' => -10.0)), $g0);
    $pruefe('Mittel: bei negativem Tagesmittel ist die Grenze -12, nicht -8',
        $fp[0]['anzahl'], 2);
    /* Ein echtes negatives Mittel ist nicht dasselbe wie "nicht bekannt". */
    $fp = plan_rechnen($preise_n, 3600, $t0,
        array(plan_test_regel(array('art' => 'mittel', 'prozent' => 20))),
        array_merge($u0, array('mittel' => null)), $g0);
    $pruefe('Mittel: ohne Angabe wird aus den Kandidaten gemittelt',
        $fp[0]['anzahl'] > 0, true);

    /* ---- Regelart 'scheiben' (neu in 1.1.0) ----
     * Viertelstunden 30 10 30 10 30 10 ... Die vier guenstigsten
     * EINZELNEN Scheiben sind die vier Zehner - ohne Stundenraster und
     * ohne Zusammenhang. */
    $vs3 = plan_test_reihe($t0, array(30, 10, 30, 10, 30, 10, 30, 10), 900);
    $fp = plan_rechnen($vs3, 900, $t0,
        array(plan_test_regel(array('art' => 'scheiben', 'n' => 1))), $u0, $g0);
    $pruefe('Scheiben: die vier guenstigsten Einzelscheiben, verstreut',
        array($fp[0]['anzahl'], $fp[0]['ct']), array(4, 10.0));
    $pruefe('Scheiben: die erste liegt um 00:15',
        $fp[0]['slots'][0], $t0 + 900);

    /* ---- Taktschutz ----
     * Dieselbe Reihe: ohne Schutz vier Einzelscheiben mit Loechern.
     * Mit min_pause 30 werden die Luecken von je 15 Minuten zugemacht. */
    $fp = plan_rechnen($vs3, 900, $t0,
        array(plan_test_regel(array('art' => 'scheiben', 'n' => 1, 'min_pause' => 30))),
        $u0, $g0);
    $pruefe('Mindestpause 30 min macht die Loecher von 15 min zu',
        $fp[0]['anzahl'], 7);
    /* Mindestlaufzeit: ein einzelner Block von 15 Minuten wird auf 60
     * verlaengert, solange Kandidaten nachkommen. */
    $vs4 = plan_test_reihe($t0, array(30, 10, 30, 30, 30, 30, 30, 30), 900);
    $fp = plan_rechnen($vs4, 900, $t0,
        array(plan_test_regel(array('art' => 'scheiben', 'n' => 1, 'min_lauf' => 60))),
        $u0, $g0);
    $pruefe('Mindestlaufzeit 60 min verlaengert den Block auf vier Scheiben',
        $fp[0]['anzahl'], 4);
    /* Und wenn nichts nachkommt, faellt der Block weg - ein Block unter
     * der Mindestlaufzeit ist genau das, was die Regel verhindern soll. */
    $vs5 = array($t0 => 30.0, $t0 + 900 => 10.0);
    $fp = plan_rechnen($vs5, 900, $t0,
        array(plan_test_regel(array('art' => 'scheiben', 'n' => 1, 'min_lauf' => 60))),
        $u0, $g0);
    $pruefe('Zu kurzer Block ohne Nachschub faellt weg', $fp[0]['anzahl'], 0);
    $pruefe('Ohne Taktschutz bleibt die Auswahl unveraendert',
        count(plan_takt(array($t0, $t0 + 1800), array(), 900, 0, 0)), 2);

    /* ---- Hysterese ----
     * Preise 30 10 ...: ohne Hysterese laeuft die Regel um 00:00 nicht.
     * Mit einem laufenden Block bis 01:00 laeuft sie trotzdem weiter. */
    $preise_h = plan_test_reihe($t0, array(30, 10, 10, 30, 30, 30, 30, 30));
    $fp = plan_rechnen($preise_h, 3600, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 1))), $u0, $g0);
    $pruefe('Ohne Hysterese laeuft die Regel um 00:00 nicht', $fp[0]['aktiv'], 0);
    $u_h = array_merge($u0, array('laufend' => array(0 => $t0 + 3600)));
    $fp = plan_rechnen($preise_h, 3600, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 1))), $u_h, $g0);
    $pruefe('Hysterese: der begonnene Block laeuft bis zu seinem Ende',
        array($fp[0]['aktiv'], $fp[0]['grund']), array(1, 'laeuft'));
    /* Eine abgelaufene Hysterese haelt nichts mehr fest. */
    $u_h2 = array_merge($u0, array('laufend' => array(0 => $t0 - 3600)));
    $fp = plan_rechnen($preise_h, 3600, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 1))), $u_h2, $g0);
    $pruefe('Abgelaufene Hysterese haelt nichts fest', $fp[0]['aktiv'], 0);

    /* ---- Zweites Budget (Paragraf 14a) ----
     * Erstes Budget 10 kW, zweites 4 kW von 00 bis 04 Uhr. Eine Regel mit
     * 6 kW findet in der Sperrzeit nichts und weicht auf 04:00 aus. */
    $preise_b = plan_test_reihe($t0, array(10, 10, 10, 10, 20, 20, 20, 20));
    $g14 = array('budget_kw' => 10.0, 'pv_bonus' => 0.0, 'pv_schwelle' => 500,
                 'budget2_kw' => 4.0, 'budget2_von' => 0, 'budget2_bis' => 4);
    $fp = plan_rechnen($preise_b, 3600, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 1, 'leistung' => 6.0))),
        $u0, $g14);
    $pruefe('Paragraf 14a: 6 kW weichen aus der Sperrzeit auf 04:00 aus',
        $fp[0]['start'], 4);
    /* Ohne das zweite Budget nimmt dieselbe Regel die billige Stunde. */
    $fp = plan_rechnen($preise_b, 3600, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 1, 'leistung' => 6.0))),
        $u0, array('budget_kw' => 10.0, 'pv_bonus' => 0.0, 'pv_schwelle' => 500));
    $pruefe('Ohne zweites Budget nimmt sie 00:00', $fp[0]['start'], 0);
    /* 3 kW passen auch in der Sperrzeit. */
    $fp = plan_rechnen($preise_b, 3600, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 1, 'leistung' => 3.0))),
        $u0, $g14);
    $pruefe('Paragraf 14a: 3 kW passen in die Sperrzeit', $fp[0]['start'], 0);

    /* ---- Frist zu knapp: fehlt und grund ----
     * n=5, aber die Frist um 02:00 laesst nur zwei Stunden zu. */
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 5, 'frist' => 2))), $u0, $g0);
    $pruefe('Frist zu knapp: zwei von fuenf Stunden, drei fehlen',
        array($fp[0]['anzahl'], $fp[0]['noetig'], $fp[0]['fehlt']), array(2, 5, 3));
    /* Ohne Frist fehlt nichts. */
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 5))), $u0, $g0);
    $pruefe('Ohne Frist fehlt nichts', $fp[0]['fehlt'], 0);
    /* Bei 'schwelle' gibt es keine Sollmenge - dort waere 'fehlt' eine
     * Falschaussage. */
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'schwelle', 'schwelle' => 15))), $u0, $g0);
    $pruefe('Bei schwelle bleibt fehlt auf 0', $fp[0]['fehlt'], 0);
    /* Und wenn die Frist alles abschneidet: grund sagt, dass sie es war. */
    $fp = plan_rechnen($preise, 3600, $t0 + 3 * 3600,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 1, 'von' => 5, 'bis' => 7,
                                    'frist' => 4))), $u0, $g0);
    $pruefe('Frist schneidet alles ab: grund nennt die Frist',
        $fp[0]['grund'], 'frist');
    /* Gibt es ohnehin nichts, heisst der Grund 'keine' und nicht 'frist'. */
    $fp = plan_rechnen(array(), 3600, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 1))), $u0, $g0);
    $pruefe('Ohne Preise heisst der Grund keine', $fp[0]['grund'], 'keine');

    /* ---------- Hysterese haelt Fenster und Budget ein ----------
     *
     * EICHUNG: ersetzt man in plan_rechnen() das $mit der Hysterese wieder
     * durch $preise, gehen beide Faelle rot. Bis 1.1.1 war das so, und
     * BIS HEUTE deckte kein Prueffall die Korrektur ab - die Begruendung
     * stand im Kommentar, gemessen wurde sie nie. */
    $ph = array();
    for ($i = 0; $i < 8; $i++) { $ph[$t0 + $i * 3600] = 20.0 + $i; }

    /* A: Fenster 02-03 Uhr, laufender Block bis 04:00, jetzt ist 00:00.
     * Die Hysterese darf nur Scheiben nachtragen, die auch Kandidaten
     * sind - sonst laeuft das Geraet drei Stunden vor seinem Fenster. */
    $rh = plan_test_regel(array('art' => 'fenster', 'n' => 1, 'leistung' => 3.0,
        'von' => 2, 'bis' => 3));
    $fph = plan_rechnen($ph, 3600, $t0, array($rh),
        array_merge($u0, array('laufend' => array(0 => $t0 + 4 * 3600))), $g0);
    $pruefe('Hysterese traegt keine Scheibe ausserhalb des Fensters nach',
        $fph[0]['slots'], array($t0 + 7200));

    /* B: zwei Regeln zu je 3,0 kW, Budget 3,0 kW. Den laufenden Block hat
     * die ZWEITE - die erste waehlt also zuerst und belegt 00:00. Traegt
     * die Hysterese der zweiten dieselbe Scheibe nach, ohne das Budget zu
     * fragen, stehen dort 6,0 kW. */
    $rh1 = plan_test_regel(array('art' => 'fenster', 'n' => 1, 'leistung' => 3.0, 'rang' => 10));
    $rh2 = plan_test_regel(array('art' => 'fenster', 'n' => 1, 'leistung' => 3.0, 'rang' => 20));
    $fpb = plan_rechnen($ph, 3600, $t0, array($rh1, $rh2),
        array_merge($u0, array('laufend' => array(1 => $t0 + 3 * 3600))),
        array_merge($g0, array('budget_kw' => 3.0)));
    $pruefe('Hysterese reisst das Leistungsbudget nicht',
        plan_belegung($fpb),
        array($t0 => 3.0, $t0 + 3600 => 3.0, $t0 + 7200 => 3.0));

    /* ---------- Kennzahlen nach dem Negativpreis ----------
     *
     * EICHUNG: nimmt man den zweiten Aufruf von plan_kennzahlen() im
     * Negativzweig wieder heraus, gehen die beiden ersten Faelle rot.
     *
     * Aufbau: Regel der Art 'fenster' mit n=2 und 4 kW, Fenster 10-16 -
     * die laufende Stunde 00:00 liegt draussen, die Regelart findet also
     * nichts. Nur der Negativpreis schaltet sie ein. */
    $pn = array();
    for ($i = 0; $i < 6; $i++) { $pn[$t0 + $i * 3600] = 20.0 + $i; }
    $pn[$t0] = -5.0;
    $rn = plan_test_regel(array('art' => 'fenster', 'n' => 2, 'leistung' => 4.0,
        'neg' => 1, 'von' => 10, 'bis' => 16));
    $un = array_merge($u0, array('neg' => 1));
    $fpn = plan_rechnen($pn, 3600, $t0, array($rn), $un, $g0);
    $pruefe('Negativpreis: die verschobene Energie steht da',
        $fpn[0]['kwh'], 4.0);
    $pruefe('Negativpreis: fehlt zaehlt die nachgetragene Scheibe mit',
        $fpn[0]['fehlt'], 1);
    $pruefe('Negativpreis: der Grund bleibt negativ',
        array($fpn[0]['aktiv'], $fpn[0]['grund'], $fpn[0]['anzahl']),
        array(1, 'negativ', 1));
    /* Die Ersparnis ist die Zahl, die der Anwender sieht: sofort laden
     * haette (-5 + 21) / 2 = 8,0 ct gekostet, geladen wird zu -5,0 ct. */
    $pruefe('Negativpreis: die Ersparnis steht da',
        array($fpn[0]['ct'], $fpn[0]['ct_sofort'], $fpn[0]['spart_ct']),
        array(-5.0, 8.0, 13.0));
    /* Gegenprobe: dieselbe Lage ohne Negativpreis. Die Regel darf dann
     * nicht laufen, und die Zahlen bleiben bei null. */
    $pn2 = $pn;
    $pn2[$t0] = 20.0;
    $fpn2 = plan_rechnen($pn2, 3600, $t0, array($rn), array_merge($u0, array('neg' => 0)), $g0);
    $pruefe('Ohne Negativpreis laeuft dieselbe Regel nicht',
        array($fpn2[0]['aktiv'], $fpn2[0]['anzahl'], $fpn2[0]['kwh']),
        array(0, 0, 0.0));

    /* ---- Ersparnis ----
     * Preise 30 30 10 10 ...: sofort losfahren kostet zwei Stunden zu je
     * 30 ct, geplant sind zwei zu je 10 ct. Ersparnis 20 ct/kWh; bei
     * 3,7 kW mal zwei Stunden sind das 7,4 kWh und 1,48 Euro. */
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 2, 'leistung' => 3.7))),
        $u0, $g0);
    $pruefe('Ersparnis: sofort 30 ct, geplant 10 ct, Vorteil 20 ct/kWh',
        array($fp[0]['ct_sofort'], $fp[0]['ct'], $fp[0]['spart_ct']),
        array(30.0, 10.0, 20.0));
    $pruefe('Ersparnis in Euro: 7,4 kWh mal 20 ct sind 1,48 Euro',
        array($fp[0]['kwh'], $fp[0]['spart_eur']), array(7.4, 1.48));
    /* Eine eingetragene Energiemenge sticht die Rechnung aus Leistung mal
     * Laufzeit - sie ist die Angabe, die der Mensch kennt. */
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 2, 'leistung' => 3.7,
                                    'energie' => 5.0))), $u0, $g0);
    $pruefe('Eingetragene Energiemenge gilt fuer die Euro-Rechnung',
        array($fp[0]['kwh'], $fp[0]['spart_eur']), array(5.0, 1.0));

    /* ---- Maengel an der Regel ---- */
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('energie' => 7.0, 'leistung' => 0.0))), $u0, $g0);
    $pruefe('Energie ohne Leistung wird gemeldet',
        $fp[0]['mangel'], 'energie_ohne_leistung');
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('soc_min' => 80, 'soc_max' => 20))), $u0, $g0);
    $pruefe('soc_min ueber soc_max wird gemeldet', $fp[0]['mangel'], 'soc_reihe');
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel()), $u0, $g0);
    $pruefe('Eine saubere Regel meldet keinen Mangel', $fp[0]['mangel'], '');

    /* ---- Eine Regel ohne die Plugin-Felder darf nicht knallen ----
     * So sieht sie aus, wenn sie aus einer alten Sicherung kommt. Es darf
     * keine Warnung geben und der Grund darf nicht leer sein. */
    $fp = plan_rechnen($preise, 3600, $t0, array(array('aktiv' => 1)), $u0, $g0);
    $pruefe('Regel ohne Felder: kein Absturz, ein benannter Grund',
        $fp[0]['grund'] !== '', true);

    /* ---- Belegung: zwei Regeln in DERSELBEN Stunde werden addiert ----
     * Der alte Fall prueft nur eine Regel je Stunde und konnte die
     * Summierung gar nicht sehen. */
    $regeln_s = array(
        plan_test_regel(array('art' => 'fenster', 'n' => 1, 'leistung' => 3.7, 'rang' => 1)),
        plan_test_regel(array('art' => 'fenster', 'n' => 1, 'leistung' => 2.0, 'rang' => 2)),
    );
    $bel2 = plan_belegung(plan_rechnen($preise2, 3600, $t0, $regeln_s, $u0, $g0));
    $pruefe('Belegung: zwei Regeln in derselben Stunde ergeben 5,7 kW',
        array(count($bel2), array_values($bel2)), array(1, array(5.7)));

    /* ---- Negativpreis fuellt die Belegung ----
     * Zwei Regeln zu 4 kW, Budget 9 kW, Schwelle, die niemand erfuellt.
     * Zwei laufen, die Belegung muss 8 kW zeigen - bis 1.0.0 zeigte sie
     * eine leere Tabelle. */
    $regeln_n = array(
        plan_test_regel(array('art' => 'schwelle', 'schwelle' => -99, 'leistung' => 4.0,
                              'rang' => 1, 'neg' => 1)),
        plan_test_regel(array('art' => 'schwelle', 'schwelle' => -99, 'leistung' => 4.0,
                              'rang' => 2, 'neg' => 1)),
        plan_test_regel(array('art' => 'schwelle', 'schwelle' => -99, 'leistung' => 4.0,
                              'rang' => 3, 'neg' => 1)),
    );
    $fp = plan_rechnen($preise, 3600, $t0, $regeln_n, array_merge($u0, array('neg' => 1)),
        array('budget_kw' => 9.0, 'pv_bonus' => 0.0, 'pv_schwelle' => 500));
    $pruefe('Negativpreis: zwei laufen, die dritte nicht',
        array($fp[0]['aktiv'], $fp[1]['aktiv'], $fp[2]['aktiv']), array(1, 1, 0));
    $bel3 = plan_belegung($fp);
    $pruefe('Negativpreis: die Belegung zeigt die 8 kW, nicht nichts',
        array(count($bel3), array_values($bel3)), array(1, array(8.0)));

    /* ==================================================================
     * Die Gleichstaende
     * ==================================================================
     *
     * Nachgetragen, nachdem ein zweiter Mutationslauf ueber den bereits
     * erweiterten Selbsttest lief: von achtzehn absichtlichen
     * Verfaelschungen ueberlebten noch fuenf, und alle fuenf sassen auf
     * einem "genau gleich". Das ist kein Zufall - Gleichstaende entstehen
     * beim Pruefen nie von selbst, man muss sie bauen.
     *
     * Jeder Fall hier ist so gerechnet, dass die Zahl EXAKT auf der Kante
     * liegt: 3,7 + 3,7 gegen ein Budget von genau 7,4; eine Prognose von
     * genau 25 gegen eine Sperre von 25; ein Speicherstand von genau 20
     * gegen soc_min 20.
     */

    /* ---- Budget: die Summe trifft die Grenze GENAU ----
     * "> Budget" laesst 7,4 gegen 7,4 durch, ">= Budget" nicht. Beide
     * Regeln muessen also dieselbe billige Stunde bekommen. */
    $g74 = array('budget_kw' => 7.4, 'pv_bonus' => 0.0, 'pv_schwelle' => 500);
    $regeln_kante = array(
        plan_test_regel(array('art' => 'fenster', 'n' => 1, 'leistung' => 3.7, 'rang' => 1)),
        plan_test_regel(array('art' => 'fenster', 'n' => 1, 'leistung' => 3.7, 'rang' => 2)),
    );
    $fp = plan_rechnen($preise2, 3600, $t0, $regeln_kante, $u0, $g74);
    $pruefe('Budget genau ausgeschoepft: 3,7 + 3,7 passen in 7,4',
        array($fp[0]['start'], $fp[1]['start'], $fp[1]['verdraengt']), array(2, 2, 0));
    /* Und ein Haar darunter passt es nicht mehr. */
    $g739 = array('budget_kw' => 7.39, 'pv_bonus' => 0.0, 'pv_schwelle' => 500);
    $fp = plan_rechnen($preise2, 3600, $t0, $regeln_kante, $u0, $g739);
    $pruefe('Budget 7,39: die zweite Regel muss ausweichen',
        array($fp[0]['start'], $fp[1]['start']), array(2, 3));

    /* ---- Angebrochene Stunde: sie darf nicht gewinnen ----
     * Viertelstunden. Stunde 0 ist die billigste (10), aber ab 00:15 fehlt
     * ihr eine Scheibe - sie ist angebrochen und wird nicht bewertet. Die
     * guenstigste VOLLE Stunde ist Stunde 2 mit 20. Wer angebrochene
     * Stunden mitzaehlt, landet bei Stunde 0 mit drei Scheiben. */
    $vs_teil = plan_test_reihe($t0, array(10, 10, 10, 10, 30, 30, 30, 30,
                                          20, 20, 20, 20, 30, 30, 30, 30), 900);
    $fp = plan_rechnen($vs_teil, 900, $t0 + 900,
        array(plan_test_regel(array('art' => 'stunden', 'n' => 1))), $u0, $g0);
    $pruefe('Angebrochene Stunde gewinnt nicht - die volle Stunde 02:00 gewinnt',
        array($fp[0]['start'], $fp[0]['anzahl']), array(2, 4));

    /* ---- PV-Sperre: Prognose GENAU auf der Schwelle ----
     * ">= Schwelle" sperrt, "> Schwelle" nicht. */
    $u_kante = array_merge($u0, array('pv_summe' => 25.0));
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'schwelle', 'schwelle' => 99, 'pv_sperre' => 25.0))),
        $u_kante, $g0);
    $pruefe('PV-Sperre greift, wenn die Prognose die Schwelle genau trifft',
        array($fp[0]['aktiv'], $fp[0]['gesperrt']), array(0, 'pv'));
    $u_knapp = array_merge($u0, array('pv_summe' => 24.999));
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'schwelle', 'schwelle' => 99, 'pv_sperre' => 25.0))),
        $u_knapp, $g0);
    $pruefe('Ein Tausendstel darunter sperrt sie nicht', $fp[0]['gesperrt'], '');

    /* ---- Speicherstand GENAU auf der Grenze ----
     * soc_min sperrt UNTERHALB, nicht auf der Grenze: wer "erst ab 20 %"
     * sagt, meint bei 20 % lauft es. Dasselbe oben.
     *
     * Hier sind die beiden Grenzen bewusst NICHT gleich behandelt wie die
     * PV-Sperre - und das ist eine Entscheidung, keine Unachtsamkeit: die
     * PV-Sperre ist ein Verbot ("ab dieser Prognose nicht mehr"), die
     * Speichergrenzen sind ein Bereich ("von 20 bis 80"), und die Raender
     * eines Bereichs gehoeren dazu. */
    $u_soc_kante = array_merge($u0, array('soc' => 20.0));
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'schwelle', 'schwelle' => 99, 'soc_min' => 20))),
        $u_soc_kante, $g0);
    $pruefe('soc_min 20 bei Stand 20: laeuft, sperrt nicht', $fp[0]['gesperrt'], '');
    $u_soc_unter = array_merge($u0, array('soc' => 19.9));
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'schwelle', 'schwelle' => 99, 'soc_min' => 20))),
        $u_soc_unter, $g0);
    $pruefe('Ein Zehntel darunter sperrt', $fp[0]['gesperrt'], 'soc_min');
    $u_soc_oben = array_merge($u0, array('soc' => 80.0));
    $fp = plan_rechnen($preise, 3600, $t0,
        array(plan_test_regel(array('art' => 'schwelle', 'schwelle' => 99, 'soc_max' => 80))),
        $u_soc_oben, $g0);
    $pruefe('soc_max 80 bei Stand 80: laeuft, sperrt nicht', $fp[0]['gesperrt'], '');

    /* ---- Rangfolge: die Zusage selbst, nicht ihr Schatten ----
     * Warum unmittelbar und nicht ueber den Fahrplan, steht im Kopf von
     * plan_rang_vergleich(). */
    $pruefe('Kleinerer Rang kommt zuerst',
        plan_rang_vergleich(array('i' => 5, 'rang' => 1), array('i' => 0, 'rang' => 2)), -1);
    $pruefe('Groesserer Rang kommt spaeter',
        plan_rang_vergleich(array('i' => 0, 'rang' => 9), array('i' => 5, 'rang' => 2)), 1);
    $pruefe('Gleicher Rang: die kleinere Nummer kommt zuerst',
        plan_rang_vergleich(array('i' => 2, 'rang' => 5), array('i' => 7, 'rang' => 5)), -1);
    $pruefe('Gleicher Rang: die groessere Nummer kommt spaeter',
        plan_rang_vergleich(array('i' => 7, 'rang' => 5), array('i' => 2, 'rang' => 5)), 1);
    $pruefe('Dieselbe Regel mit sich selbst ergibt null',
        plan_rang_vergleich(array('i' => 3, 'rang' => 5), array('i' => 3, 'rang' => 5)), 0);
    /* Und die Reihenfolge, die daraus entsteht - vier Regeln, zwei Raenge. */
    $pruefe('Reihenfolge aus vier Regeln mit zwei Raengen',
        array_map(function ($z) { return $z['i']; }, plan_reihenfolge(array(
            array('rang' => 5), array('rang' => 1), array('rang' => 5), array('rang' => 1)))),
        array(1, 3, 0, 2));

    /* ================================================================
     * Luecken, die eine Deckungsmessung am 27.08.2026 gefunden hat
     * ================================================================
     *
     * Die 115 Faelle darueber waren gruen, und achtzehn Mutationen wurden
     * alle erkannt. Beides sagt nichts ueber Stellen, an die niemand
     * gedacht hat. Gemessen wurde deshalb zweierlei: welche Rueckgabefelder
     * ueberhaupt geprueft werden (zwei von achtzehn nicht), und welche
     * Bedingung im Produktivteil man auf true oder false zwingen kann, ohne
     * dass ein Fall rot wird (45 von 176).
     *
     * Die folgenden Faelle schliessen die Luecken, die dabei echte waren.
     * Jeder ist geeicht: die zugehoerige Stelle wurde zurueckgebaut, und
     * der Fall wurde rot. */

    /* ---- rest: wie lange laeuft es noch ----
     * Geht als R<n>REST nach Loxone. Eine falsche Zahl steht dort als
     * "laeuft noch X Stunden" in der Visualisierung - und niemand merkt es,
     * weil sie plausibel aussieht. */
    $p_rest = plan_test_reihe($t0, array(10, 10, 10, 50, 50, 50));
    $fp = plan_rechnen($p_rest, 3600, $t0,
        array(plan_test_regel(array('art' => 'schwelle', 'schwelle' => 20))), $u0, $g0);
    $pruefe('rest: drei zusammenhaengende Stunden ab jetzt sind 180 Minuten',
        array($fp[0]['aktiv'], $fp[0]['rest']), array(1, 180));
    /* Und der Gegenfall: wer jetzt nicht laeuft, hat keine Restlaufzeit.
     * Ohne diesen Fall wuerde ein rest, das immer gerechnet wird, nicht
     * auffallen. */
    $p_spaet = plan_test_reihe($t0, array(50, 50, 10, 10));
    $fp = plan_rechnen($p_spaet, 3600, $t0,
        array(plan_test_regel(array('art' => 'schwelle', 'schwelle' => 20))), $u0, $g0);
    $pruefe('rest bleibt 0, solange die Regel nicht laeuft',
        array($fp[0]['aktiv'], $fp[0]['rest']), array(0, 0));

    /* ---- startmin: die Minute des Beginns ----
     * Bei Stundenscheiben immer 0 - erst bei Viertelstunden traegt das Feld
     * eine Aussage. Genau deshalb war es ungeprueft: die Faelle rechnen
     * ueberwiegend in Stunden.
     *
     * n ist in STUNDEN gezaehlt, nicht in Scheiben: n=1 sind bei 900 s vier
     * Scheiben (siehe plan_slots_noetig weiter oben). Beim Schreiben dieses
     * Falls stand hier zuerst n=2 - das sind acht Scheiben und damit die
     * ganze Reihe, und startmin kam erwartungsgemaess als 0 heraus. Der
     * Fall war rot, und er hatte recht. */
    $p_min = plan_test_reihe($t0, array(50, 50, 10, 10, 10, 10, 50, 50), 900);
    $fp = plan_rechnen($p_min, 900, $t0,
        array(plan_test_regel(array('art' => 'fenster', 'n' => 1))), $u0, $g0);
    $pruefe('startmin: das guenstigste Viertelstundenfenster beginnt um :30',
        array($fp[0]['start'], $fp[0]['startmin'], $fp[0]['anzahl']), array(0, 30, 4));

    /* ---- grund = 'wartet' ----
     * Es ist etwas geplant, aber nicht jetzt. Der Unterschied zu 'keine'
     * ist der ganze Sinn des Feldes. */
    $pruefe('grund wartet, wenn erst spaeter etwas geplant ist',
        $fp[0]['grund'], 'wartet');

    /* ---- grund = 'budget' ----
     * Nichts gefunden, aber es waere etwas dagewesen - das Budget war
     * schuld. Zu unterscheiden von 'keine', sonst sucht der Anwender den
     * Fehler bei den Preisen statt bei seiner Leistungsgrenze. */
    $p_budget = plan_test_reihe($t0, array(10, 50, 50));
    $fp = plan_rechnen($p_budget, 3600, $t0, array(
        plan_test_regel(array('art' => 'schwelle', 'schwelle' => 20,
                              'leistung' => 3.7, 'rang' => 1)),
        plan_test_regel(array('art' => 'schwelle', 'schwelle' => 20,
                              'leistung' => 3.7, 'rang' => 2)),
    ), $u0, array('budget_kw' => 3.7, 'pv_bonus' => 0.0, 'pv_schwelle' => 500));
    $pruefe('Die zweite Regel bekommt nichts, und der Grund heisst budget',
        array($fp[0]['aktiv'], $fp[1]['anzahl'], $fp[1]['verdraengt'] > 0, $fp[1]['grund']),
        array(1, 0, true, 'budget'));

    /* ---- plan_nach_wh mit 'w' ----
     * 'w' ist eine mittlere LEISTUNG, keine Energie. Wer das verwechselt,
     * bekommt bei Viertelstunden den vierfachen Wert. Die Einheit kam in
     * der ganzen Datei kein einziges Mal vor. */
    $pruefe('2000 W ueber eine Viertelstunde sind 500 Wh',
        plan_nach_wh(2000, 'w', 900), 500.0);
    $pruefe('2000 W ueber eine Stunde sind 2000 Wh',
        plan_nach_wh(2000, 'w', 3600), 2000.0);
    $pruefe('Grossgeschriebenes W faellt nicht auf wh zurueck',
        plan_nach_wh(2000, 'W', 900), 500.0);

    /* ---- plan_pfad ----
     * Loest den Punktpfad in einer fremden JSON-Antwort auf. Fuenfmal
     * benutzt, nie unmittelbar geprueft. */
    $tief = array('a' => array('b' => array('c' => 42)));
    $pruefe('Punktpfad in die Tiefe', plan_pfad($tief, 'a.b.c'), 42);
    $pruefe('Leerer Pfad gibt alles zurueck', plan_pfad($tief, ''), $tief);
    $pruefe('Ein fehlender Zwischenschritt gibt null', plan_pfad($tief, 'a.x.c'), null);
    $pruefe('Ein Pfad in einen Skalar gibt null', plan_pfad($tief, 'a.b.c.d'), null);

    /* ---- Taktschutz ----
     * Sechs der ueberlebenden Mutationen sassen in plan_takt(), bei zwei
     * Faellen insgesamt. Geprueft wird die Funktion unmittelbar: sie ist
     * reine Listenarbeit und braucht keinen Fahrplan drumherum. */
    $pruefe('Ohne Vorgaben bleibt die Trefferliste unveraendert',
        plan_takt(array($t0, $t0 + 7200), array(), 3600, 0, 0),
        array($t0, $t0 + 7200));
    $pruefe('Eine Luecke unter der Mindestpause wird zugemacht',
        plan_takt(array($t0, $t0 + 7200),
            array($t0 => 1.0, $t0 + 3600 => 1.0, $t0 + 7200 => 1.0), 3600, 0, 120),
        array($t0, $t0 + 3600, $t0 + 7200));
    /* Die Gegenprobe dazu, und sie ist die wichtigere: ist die Scheibe in
     * der Luecke KEIN Kandidat, bleibt die Luecke offen. Sonst bucht der
     * Taktschutz Leistung ausserhalb von Budget, Frist und Zeitfenster -
     * der Befund, der 1.1.4 ausgeloest hat. */
    $pruefe('Eine Luecke aus Nicht-Kandidaten bleibt offen',
        plan_takt(array($t0, $t0 + 7200),
            array($t0 => 1.0, $t0 + 7200 => 1.0), 3600, 0, 120),
        array($t0, $t0 + 7200));
    $pruefe('Eine Luecke ueber der Mindestpause bleibt offen',
        plan_takt(array($t0, $t0 + 7200),
            array($t0 => 1.0, $t0 + 3600 => 1.0, $t0 + 7200 => 1.0), 3600, 0, 30),
        array($t0, $t0 + 7200));
    $pruefe('Ein zu kurzer Block wird aus den Kandidaten verlaengert',
        plan_takt(array($t0), array($t0 => 1.0, $t0 + 3600 => 1.0), 3600, 120, 0),
        array($t0, $t0 + 3600));
    $pruefe('Reicht der Kandidat nicht, faellt der ganze Block weg',
        plan_takt(array($t0), array(), 3600, 120, 0),
        array());
    /* Und die Reihenfolge, auf die der Kommentar von plan_takt() sich
     * beruft: erst zumachen, dann verlaengern. Umgekehrt waere der Block
     * verworfen, den das Zumachen gerettet hat. */
    $pruefe('Erst zumachen, dann verlaengern - der Block ueberlebt',
        plan_takt(array($t0, $t0 + 7200),
            array($t0 => 1.0, $t0 + 3600 => 1.0, $t0 + 7200 => 1.0), 3600, 180, 120),
        array($t0, $t0 + 3600, $t0 + 7200));

    /* ---------- Zweiter Durchgang beim Zumachen ----------
     *
     * EICHUNG: nimmt man den Aufruf von plan_luecken_zu() am Ende von
     * plan_takt() wieder heraus, geht der erste Fall rot.
     *
     * Der Aufbau ist der kleinste, den der Streifzug gefunden hat:
     * Viertelstunden, min_lauf 45, min_pause 45, getroffen sind 07:30,
     * 08:30 und 09:15. Schritt 1 sieht zwischen 07:30 und 08:30 genau 45
     * Minuten und laesst sie stehen. Schritt 2 verlaengert den Einzelblock
     * 07:30 auf 45 Minuten, also bis 08:00 - und laesst damit zwischen
     * 08:00 und 08:30 eine Luecke von 15 Minuten. */
    $tk = array();
    for ($i = 0; $i < 10; $i++) { $tk[$t0 + $i * 900] = 1.0; }
    $pruefe('Verlaengern reisst keine Luecke unter der Mindestpause auf',
        plan_takt(array($t0 + 1800, $t0 + 5400, $t0 + 8100), $tk, 900, 45, 45),
        array($t0 + 1800, $t0 + 2700, $t0 + 3600, $t0 + 4500, $t0 + 5400,
              $t0 + 6300, $t0 + 7200, $t0 + 8100));
    /* Gegenprobe: dieselbe Lage, aber 08:15 ist kein Kandidat. Dann laesst
     * sich die Luecke nicht zulaessig ueberbruecken und bleibt offen - so
     * steht es im Kopf von plan_takt, und so soll es bleiben. */
    $tk2 = $tk;
    unset($tk2[$t0 + 4500]);
    $pruefe('Eine nicht ueberbrueckbare Luecke bleibt offen',
        plan_takt(array($t0 + 1800, $t0 + 5400, $t0 + 8100), $tk2, 900, 45, 45),
        array($t0 + 1800, $t0 + 2700, $t0 + 3600,
              $t0 + 5400, $t0 + 6300, $t0 + 7200, $t0 + 8100));
    /* plan_luecken_zu() unmittelbar. */
    $pruefe('Zumachen ohne Mindestpause laesst alles stehen',
        plan_luecken_zu(array($t0, $t0 + 1800), $tk, 900, 0),
        array($t0, $t0 + 1800));
    $pruefe('Zumachen ueberbrueckt eine Luecke von einer Scheibe',
        plan_luecken_zu(array($t0, $t0 + 1800), $tk, 900, 45),
        array($t0, $t0 + 900, $t0 + 1800));
    /* Und die Aufrundung gegen das Gleitkommarauschen (Befund 1.1.4).
     * 6,9 kWh bei 2,3 kW sind genau drei Stunden - vorher wurden vier
     * Scheiben gebucht. Die beiden anderen Paare sind die Gegenprobe:
     * sie waren nie betroffen und duerfen sich nicht veraendern. */
    $pruefe('Ein glattes kWh/kW-Paar ergibt keine Scheibe zu viel',
        plan_slots_noetig(array('energie' => 6.9, 'leistung' => 2.3), 3600), 3);
    $pruefe('Dasselbe im Viertelstundenraster',
        plan_slots_noetig(array('energie' => 6.9, 'leistung' => 2.3), 900), 12);
    $pruefe('Ein krummes Paar wird weiterhin aufgerundet',
        plan_slots_noetig(array('energie' => 7.0, 'leistung' => 2.3), 3600), 4);

    /* Zurueckstellen, BEVOR die Kopfzeile gebaut wird - sonst traegt sie
     * die gesetzte Zeitzone als die des Geraets ein und behauptete genau
     * das, was diese Vorkehrung vermeiden soll. */
    date_default_timezone_set($tz_geraet);

    array_unshift($z, sprintf(
        'Planer %s: %d Faelle geprueft, %d Fehlschlaege.', PLAN_FASSUNG, $anzahl, $fehl),
        sprintf('Gerechnet in Europe/Berlin; dieses Geraet steht auf %s.', $tz_geraet), '');
    return array($anzahl, $fehl, implode("\n", $z));
}
