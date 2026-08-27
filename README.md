# LoxBerry-Plugin Octopus Dynamic

Holt die **Viertelstundenpreise** des Tarifs *dynamicOctopus* über die
Kraken-Schnittstelle von Octopus Energy und stellt sie dem Loxone Miniserver
zur Verfügung — über MQTT als Regelweg, über einen tokengeschützten
HTTP-Endpunkt als Rückfallebene.

---

## Was 1.1.0 bringt — und was es behebt

Diese Fassung ist aus einer Zeile-für-Zeile-Durchsicht des ganzen Plugins
entstanden. Sie behebt **zwölf Befunde** und ergänzt **elf Funktionen**.
Alles, was hier steht, ist unter PHP 7.4.33 *und* 8.4.24 nachgemessen.

### Der schwerste Befund: der Miniserver bekam die Schaltregeln praktisch nie

`oc_state()` schrieb seinen Zwischenspeicher **mitten in der Funktion**.
Alles, was danach noch entstand — die Stundenprofile `ph`/`pm`/`pr`, die
PV-Summe, der Speicherstand, **die Regeln** und `planlast` —, fehlte darin.
Am Endpunkt gemessen, zwei Aufrufe hintereinander:

| | Felder | fehlten |
|---|---|---|
| 1. Aufruf, frisch gerechnet | 146 | – |
| 2. Aufruf, aus dem Zwischenspeicher | **118** | `REGEL1_AKTIV` … `REGEL4_RANG`, `PH/PM/PR00–23` |

Und weil der minütliche Cron den Zwischenspeicher selbst füllt, war das der
Regelfall und nicht die Ausnahme. Nebenwirkung: die MQTT-Sendebremse „nur
bei Änderung senden" war wirkungslos, weil ihre Signatur im Minutentakt
zwischen 145 und 117 Schlüsseln sprang.

*Jetzt*: der Zwischenspeicher wird geschrieben, wenn der Wert fertig ist.
Drei Aufrufe hintereinander liefern **163 / 163 / 163** Felder und sind
zeichenweise gleich — bis auf den Zeitstempel des Lebenszeichens.

### Der Knopf „Vorlage herunterladen" war unter PHP 8 tot

Drei Thementexte trugen zwei Platzhalter (`%d%s`), `oc_thema_text()` reicht
aber nur einen an `sprintf()`. Unter PHP 7.4 gab das eine Warnung und eine
Vorlage mit kaputten Kommentaren, unter **PHP 8 einen Fatalfehler** — auf
LoxBerry 4 lieferte der Knopf also eine leere Seite.

Gefunden wurde das erst, als die Themenliste auch auf der Seite über
`oc_thema_text()` lief. Vorher wurde die Funktion **ausschließlich** im
Vorlagen-Knopf benutzt, und den hatte kein Prüfstand je gedrückt.

### Weitere behobene Befunde

* **Die Sicherungsdatei prüfte nur die Schlüssel, nie die Werte.** Von zehn
  von Hand gebauten Dateien wurden **neun angenommen** — darunter ein
  MQTT-Präfix mit Zeilenumbruch, das die UDP-Zeile an den Gateway in zwei
  zerlegt und ein erfundenes Thema erzeugt. Jetzt wird jeder Wert gegen
  dieselbe Positivliste geprüft, die auch die Konfiguration benutzt; von
  denselben zehn Dateien gehen noch **drei** durch, und alle drei mit
  Absicht (siehe unten).
* **Ein erfolgreiches Zurückspielen meldete gar nichts.** Die
  Meldungsablage `$oc_meldungen` wurde beschrieben, aber nirgends angelegt
  und nirgends ausgegeben — genau ein Vorkommen in der ganzen Datei.
* **Nach dem Zurückspielen zeigte die Seite den alten Stand.** Der Block
  „Anzeige vorbereiten" lief vor dem Handler. Er steht jetzt hinter *allen*
  Handlern, damit es kein künftiger vergessen kann.
* **Der Warntext am Sicherungsknopf war unwahr.** Er behauptete, die Datei
  enthalte die Zugangsdaten; gemessen enthielt sie keine.
* **56 rohe `%d` standen auf der Seite** — die Themenliste und die
  Baustein-Liste gaben den Sprachtext ungefüllt aus, samt Verlust des vom
  Anwender vergebenen Regelnamens.
* **Der Rückfallpfad des Cron endete im Fatalfehler.** `lb_wurzel_ermitteln()`
  wurde in Zeile 25 gerufen und erst am Dateiende *bedingt* definiert —
  PHP hebt das nicht vor. Der Rückfall war nie eine Absicherung, sondern
  eine Erzählung; `cron.01min` leitet nach `/dev/null` um.
* **Keine Cron-Sperre**, obwohl ein Lauf aus den Zeitschranken bis 151 s
  dauern kann — bei einem Takt von 60 s.
* **Die Frist war an zwei Tagen im Jahr falsch.** `plan_frist_ende()`
  rechnete im ersten Zweig „Tagesbeginn + Stunde × 3600" — genau der
  Fehler, vor dem der Kommentar im zweiten Zweig warnt. Am 29.03. war die
  Wäsche eine Stunde **nach** der Frist fertig, am 25.10. eine Stunde davor.
* **Die Belegungstabelle unterschlug Geräte, die der Negativpreis
  eingeschaltet hatte.** Zwei Regeln liefen mit zusammen 8 kW, die Tabelle
  zeigte 0 kW.
* **`mittel` rechnete bei negativem Tagesmittel in die falsche Richtung**
  (Grenze −8 statt −12) und hielt ein echtes negatives Mittel für „nicht
  bekannt".
* **Ein Tag Historie ging verloren**, wenn der LoxBerry zwischen 23:50 und
  23:59 aus war — und die Historie ist die Grundlage des Kostenvergleichs.

### Der Selbsttest des Planers: von 53 über 101 auf 133 Fälle

Der alte Selbsttest meldete 53 grüne Fälle — der Kommentar daneben sprach
von „dreißig". Ein Mutationslauf (28 absichtliche Verfälschungen im
Quelltext) zeigte: **11 überlebten**, der Test prüfte diese Stellen also
gar nicht. Ungeprüft waren unter anderem die Regelarten `stunden` und
`mittel`, jedes Zeitfenster, der Rang-Gleichstand, ein Loch in der
Preisreihe — und die beiden Sommerzeit-Umstellungstage, wo der Fehler saß.

Jetzt: **101 Fälle, 0 Fehlschläge**, unter beiden PHP-Fassungen. Die Zahl
steht nicht mehr im Fließtext, sondern wird gezählt.

**Nachtrag 27.08.2026 — und er zeigt, warum eine Zahl allein nichts sagt.**
Der Selbsttest stand inzwischen bei 115 Fällen, der Mutationslauf meldete
18 von 18 erkannt. Beides beruhigt. Nachgemessen wurde zweierlei:

* **Alle 18 Mutationsanker standen schon in der Vorfassung.** Die zwei
  Funktionen, die mit 1.1.0 dazugekommen sind, rührte keine einzige an —
  eine Liste, die nicht mitwächst, wird mit jeder Erweiterung beruhigender
  und aussageärmer.
* **Zwei von achtzehn Rückgabefeldern prüfte kein Fall**: `rest` und
  `startmin`. `rest` geht als Restlaufzeit nach Loxone. Dazu 45 von 176
  Verzweigungen, die sich auf `true` oder `false` zwingen ließen, ohne dass
  ein Fall rot wurde.

Geschlossen mit **18 neuen Fällen** (jetzt 133) und **8 neuen Mutationen**
(jetzt 26, alle erkannt). Jeder neue Fall ist einzeln geeicht: die Stelle,
die er prüfen soll, wurde zurückgebaut, und er wurde rot. Ein Fall, der das
nicht tut, hebt nur die Fallzahl.

### Die Reiterleiste steht jetzt ausgeschrieben

Sie entstand aus einer `foreach`-Schleife. Das liest sich besser und hatte
einen Preis, den man nicht sieht: `hausstandard_pruefen.py` findet die
Reiter dann nicht mehr und meldete in der Spalte `tab` **seit jeher einen
Strich** — niemandem war es aufgefallen, weil ein Strich wie eine
Kleinigkeit aussieht.

Drei Stellen müssen deckungsgleich bleiben, und keine meldet sich, wenn sie
es nicht mehr ist: die Positivliste `$oc_muster`, die Leiste selbst und die
`id` der Flächen. Fehlt ein Reiter in der Positivliste, ist er anklickbar —
aber nach jedem Absenden springt die Seite zurück auf Einstellungen, und
man sucht den Grund an der falschen Stelle.

Die Auflösung ist nicht „Schleife oder Hand", sondern beides: **ausschreiben
und nachrechnen lassen.** Der Reiter Test hat dafür eine neue Zeile, die die
drei Stellen an der eigenen Datei gegeneinander zählt und die Zahl der
angesehenen Stellen mitnennt — eine Null ist dort kein „in Ordnung".

Geeicht in beide Richtungen: unverändert grün, und jede der drei Stellen
einzeln zerbrochen (Reiter aus der Positivliste, aus der Leiste, Fläche
umbenannt) macht die Zeile **rot** — unter PHP 7.4 und 8.4. Die Spalte `tab`
steht damit auf `TAB` statt auf einem Strich.

---

## Neu in 1.1.0

### Sichern und Zurückspielen: jetzt vollständig

Die zwei Knöpfe gab es schon. Neu ist der Haken **Zugangsdaten
mitsichern**. Ohne ihn enthält die Datei alle Einstellungen und das
Aktionstoken; mit ihm zusätzlich E-Mail, Passwort und Kundennummer — und
erst damit ist der erklärte Zweck erfüllt, der **Umzug auf einen zweiten
LoxBerry**. Ohne die Zugangsdaten stünden dort alle Felder richtig, und es
kämen trotzdem keine Preise.

Die Datei trägt jetzt einen lesbaren Kopf (`_hinweis`, `_plugin`,
`_fassung`, `_stand`), den die Leseseite überspringt statt ihn als fremd
abzuweisen. Beim Zurückspielen gilt weiterhin **alles oder nichts**, und
alle Beanstandungen kommen auf einmal.

Drei Dinge gehen absichtlich durch: eine Adresse auf `127.0.0.1` (der
dokumentierte Fall ist ein Zähler-Plugin auf demselben LoxBerry), eine
eigene Ansage-Vorlage (der Modus *custom* ist eine freie Adresse), und eine
erfundene Regelart (die Konfiguration setzt sie nachvollziehbar auf
`fenster`). Ein leeres Aktionstoken in der Datei wird durch ein frisches
ersetzt — und das steht dann als Meldung auf der Seite.

### Ein Wachposten am Eingang

Das Plugin hatte **keinen** Formularschutz: kein `formtoken`, kein `fmt`,
kein `hash_hmac` im ganzen `webfrontend`-Zweig. Eine fremde Seite konnte im
angemeldeten Browser einen Preisabruf, ein MQTT-Senden oder eine
Sprachansage auslösen.

Jetzt prüft **eine** Stelle am Eingang jeden POST; fällt die Prüfung durch,
wird `$_POST` geleert und danach läuft kein Zweig mehr an. Das Merkmal wird
aus dem Aktionstoken abgeleitet — es gibt also kein zweites Geheimnis, und
die Sicherungsdatei trägt beides mit dem einen Wert. Alle elf Formulare der
Seite führen das Feld.

### `?selftest=1` am Endpunkt

Hausstandard, und er fehlte. Er prüft das Token genauso wie jeder andere
Aufruf, löst aber nichts aus:

    ?selftest=1&token=<TOKEN>   SELFTEST;OK=1;TOKEN=OK;AKTIV=1;FASSUNG=…;PLANER=1.1.0
    falsches oder kein Token    SELFTEST;OK=0;ERR=TOKEN            (HTTP 403)
    kein Token eingerichtet     SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET

### Lebenszeichen

Drei Themen gehen bei **jedem** Durchgang hinaus, auch wenn sich sonst
nichts geändert hat: `status/ok`, `status/ts` und `status/zaehler`. Sonst
schweigt das Plugin an einem Tag mit gleichbleibenden Preisen stundenlang,
und niemand kann „läuft noch" von „hängt" unterscheiden. Sie stehen
ausdrücklich **nicht** in der Sendebremse — sonst wäre die wirkungslos.

### Wiederholsperren am Endpunkt

`refresh` verwirft das Kraken-Token und meldet sich neu an — das ist der
teuerste Aufruf überhaupt. Ein Loxone-Baustein, der versehentlich in einer
Schleife hängt, hat so schon einmal 21 Anmeldeversuche gekostet. Jetzt:
`refresh` höchstens alle 60 s, `say` alle 20 s, `ptest` alle 10 s.
Gesperrt heißt nicht gescheitert — es kommt der letzte Stand, und die
Antwort sagt `GESPERRT=1`.

### Ersparnis je Regel — die Zahl, die die Frage beantwortet

Der Reiter Test zeigt unter dem Fahrplan eine Zeile je Regel: was der
geplante Zeitraum kostet, was es gekostet hätte, **jetzt sofort**
einzuschalten und durchlaufen zu lassen, und die Differenz in ct/kWh und in
Euro. Über MQTT: `regelN_spart` und `regelN_spart_eur`, dazu `plan_spart`
als Summe.

Das ist der einzige Wert, an dem sich ablesen lässt, ob der ganze Aufwand
sich lohnt — und er beantwortet nachts um drei die Frage „warum lädt die
Wallbox nicht?" mit einer Zahl statt mit einer Regel.

### „Frist nicht erfüllbar" ist jetzt ein eigener Ausgang

Eine Regel mit `n=5` und einer Frist, die nur zwei Stunden zulässt, bekam
stillschweigend zwei Stunden: `verdraengt=0`, kein Hinweis. Jetzt zählen
`noetig` und `fehlt`, und `grund` unterscheidet **frist**, **budget**,
**keine** und **gesperrt**. Über MQTT: `regelN_fehlt`.

### Taktschutz und Hysterese

Bei Viertelstundenpreisen ist kurzes Takten der Normalfall, nicht die
Ausnahme. Zwei Mittel:

* je Regel eine **Mindestlaufzeit** und eine **Mindestpause** in Minuten.
  Erst werden zu kurze Pausen zugemacht, dann zu kurze Blöcke nach hinten
  verlängert; was dann noch zu kurz ist, entfällt. Die Reihenfolge ist der
  Trick — umgekehrt würde ein Block verworfen, den das Zumachen gerettet
  hätte.
* **Begonnene Blöcke laufen zu Ende** (ab Werk an). Ohne das kann ein Gerät
  mitten im Betrieb abschalten, weil die neue Preisreihe drei Stunden
  später etwas Billigeres kennt.

### Die fünfte Regelart: `scheiben`

Die *N* günstigsten **einzelnen** Viertelstunden, ohne Stundenraster und
ohne Zusammenhang. Bisher gab es nur „am Stück" oder „volle Stunden". Für
eine Wallbox mit Zeitpuffer ist das bares Geld; wer Takten nicht verträgt,
nimmt `fenster` oder setzt `min_lauf`.

### Zweites Leistungsbudget (§ 14a EnWG)

Neben dem Budget ein zweites mit eigenem Zeitfenster, für steuerbare
Verbrauchseinrichtungen. Es gilt **zusätzlich**; die kleinere der beiden
Schranken gewinnt. Über MQTT: `plan_budget2`.

### Echter Verbrauch statt geschätztem Profil

Der Kostenvergleich gewichtet die Stunden mit einem vereinfachten
Haushaltsprofil — einer ehrlichen, aber geratenen Abschätzung. Wer eine
Adresse hinterlegt, die den Verbrauch je Stunde liefert, bekommt statt der
Schätzung eine Messung. Dieselben drei Formen wie bei der PV-Prognose,
ausgewertet mit derselben Funktion. Unter der Tabelle steht, welches
Profil gerechnet hat.

### Benachrichtigung des LoxBerry

Die Glocke in der Kopfzeile meldet, wenn seit einer einstellbaren Zahl von
Stunden kein Preisabruf mehr gelungen ist, und wenn die Preise für morgen
da sind. Bis 1.0.9 gab es dafür nur MQTT-Themen, die erst in Loxone
verdrahtet werden mussten — wer das nicht getan hat, merkte einen Ausfall
gar nicht.

### Historie: Nachtrag und CSV

Der Tageswert wird ab 23:40 beiseitegelegt und ab 00:05 nachgetragen, falls
der Lauf um 23:50 ausgefallen ist. Dazu ein Knopf, der die ganze Historie
als CSV herunterlädt — nach 400 Tagen fällt der älteste Tag heraus.

### MQTT-Gateway V1 gegen V2

Das war schon richtig gebaut: `Mqtt.Gatewayversion` wird gelesen, und der
Hinweis hat drei Ausgänge (V1, V2, nicht feststellbar — dann werden beide
Fälle genannt). Neu ist, dass die **Autostart-Warnung über allen Reitern**
steht statt nur als Zeile in einer Tabelle, die man erst aufschlagen muss.
Sie erscheint nur, wenn der Wert wirklich *aus* ist — nicht, wenn er sich
nicht feststellen lässt.

---
---

## Was 0.9.2 behebt

Zwei Meldungen eines Mitlesers. Eine trifft zu, aber aus einem anderen Grund
als angegeben; die andere hat er selbst schon richtig eingeordnet.

### Der Monatsbericht hatte nur einen Versuch — zutreffend

Gemeldet als „Cronjobs können sich bei hoher Systemlast um einige Sekunden
verschieben". Nachgestellt mit 1000 simulierten Monaten und verschieden
grossem Verzug:

| Cron-Verzug bis | Monate ohne Bericht |
|---|---|
| 59 s | **0 %** |
| 65 s | 6,5 % |
| 90 s | 22 % |

Ein Verzug von ein paar Sekunden schadet also **nicht** — der um fünf
Sekunden verspätete Lauf liegt immer noch bei 08:05:05, und `date('H:i')`
liefert weiterhin `08:05`. Erst ein Verzug über eine volle Minute lässt das
Fenster ausfallen.

Der Befund stimmt trotzdem, nur ist der Weg dorthin ein anderer: Es gibt
keinen zweiten Versuch. Fällt der Lauf um 08:05 am Ersten **ganz** aus, ist
der Bericht für den Monat verloren. Das passiert, wenn der LoxBerry gerade
neu startet oder aus ist, wenn ein Update läuft — oder wenn das Plugin in
genau dieser Minute auf „aus" stand: Die Prüfung auf `enabled` beendet das
Skript, bevor es zum Bericht kommt.

*Jetzt*: 1. des Monats, ab 8 Uhr, mit einem Erledigt-Marker. Nachgewiesen an
vier Fällen — Normalbetrieb, LoxBerry bis 11 Uhr aus, verschluckter Lauf um
08:05, zweiter Tag des Monats: einmal, einmal, einmal, keinmal.

**Der vorgeschlagene Ort für den Marker war allerdings falsch.**
`/tmp/octopus_month_report_YYYYMM.done` — `oc_paths()['tmp']` zeigt auf
`/tmp/<ordner>`, und `/tmp` ist auf dem LoxBerry eine Ramdisk. Startet der
Rechner am Ersten nach dem Bericht neu, wäre der Marker fort und der nächste
Lauf meldete den Monatsbericht ein zweites Mal — samt Sprachansage. Der
Marker liegt deshalb im Datenordner, der den Neustart übersteht. Gesetzt wird
er **vor** der Auswertung: Bricht die ab, ist der Bericht für diesen Monat
verloren — eine Endlosschleife aus Fehlversuchen mit Ansage wäre schlimmer.

### Protokoll leeren ohne Sperre — kosmetisch, wie vermutet

Der Melder ordnet es selbst als „Meckern auf hohem Niveau" ein, und die
Messung gibt ihm recht: vier Sekunden gleichzeitiges Anhängen und Leeren,
**0 unbrauchbare Zeilen**, in beiden Varianten. Zerreissen kann eine Zeile
auch nicht — `FILE_APPEND` bedeutet `O_APPEND`, der Kern setzt vor jedem
Schreiben ans tatsächliche Dateiende.

*Verlieren* kann man eine Zeile aber sehr wohl, und das nicht beim Leeren
über die Oberfläche, sondern beim **Kürzen im minütlichen Lauf**: `oc_log()`
liest bei 512 kB das Endstück ein und schreibt es zurück. Wer in diesem
Fenster anhängt, schreibt in eine Datei, die gleich überschrieben wird. Beide
Stellen laufen jetzt über `oc_log_setzen()` mit `flock` und `ftruncate` —
dieselbe Datei, dieselbe Inode, wer sie offen hat, schreibt weiter hinein.

### Hausstandard

Die Reiter waren schon echte Verweise, aber `sm-active` vergab
ausschliesslich das JavaScript — im ausgelieferten HTML kam die Klasse gar
nicht vor, und ohne JavaScript standen Kopfzeile und Reiterleiste da,
darunter nichts. Jetzt setzt der Server sie; alle sechs Reiter sind über
`?form=…` geprüft. Dazu 17 fehlende `data-role="none"` ergänzt (jetzt 67 von
67) und das Symbol auf das neue Hausmuster gebracht (Kreisscheibe mit zweitem
Ring).

Beide PHP-Fassungen liefern in beiden Sprachen zeichengleiche Ausgabe ohne
eine Meldung. Eine Abweichung von 20 Zeichen, die zwischenzeitlich auftrat,
lag am Prüfstand und nicht am Plugin: Der erste Lauf legt Zustandsdateien an,
die der zweite dann vorfindet. Mit jeweils frischem Datenordner sind die
Ausgaben identisch.

## Neu in 0.9.1: Schaltregeln

Bis 0.9.0 lieferte das Plugin nur **Zahlen** — Startzeit, Minuten bis dahin,
Durchschnittspreis. Daraus „jetzt laden" zu machen war Arbeit im Miniserver.

Eine **Schaltregel** beantwortet die Frage hier und gibt ein fertiges
0/1-Signal aus. Vier Arten stehen zur Wahl:

| Art | Bedeutung |
|---|---|
| Fenster | die N günstigsten Stunden **am Stück** |
| Stunden | die N günstigsten **vollen** Stunden |
| Mittel | Preis X % unter dem Tagesmittel |
| Schwelle | Preis unter einem festen Wert |

Dazu je Regel ein Zeitfenster, ein Horizont und wahlweise ein absolutes,
relatives oder kombiniertes Profil.

**Und eine Anleitung, welcher Loxone-Baustein wozu passt.** Zwei kommen in
Frage, und sie tun *nicht* dasselbe: der **Spot Price Optimizer** rechnet mit
Preisen und schaltet in den günstigsten Stunden; der **Energiemanager**
verteilt Überschuss und kennt überhaupt keinen Preis — dort wirken die Regeln
nur mittelbar. Beim Optimizer ist außerdem zu beachten, dass er Werte aus einem
virtuellen HTTP-Eingang nur benutzt, wenn sie **innerhalb der laufenden
Stunde** aktualisiert wurden.

---

## Was noch nicht geprüft ist

**Dieses Plugin wurde nie gegen einen echten Octopus-Vertrag gefahren.** Der
Autor hat keinen. Geprüft sind:

* PHP-Syntax aller Dateien
* Rendern der Oberfläche per GET und per POST gegen eine Attrappe
* der Endpunkt ohne Token, mit falschem Token, mit unbekannter Aktion und mit
  eingeschleustem Shell-Befehl im Parameter
* die Abdeckung aller Sprachschlüssel in beiden `.ini`-Dateien
* der komplette Datenpfad im **Demo-Modus** (echte Netzabfrage gegen die
  offene aWATTar-Schnittstelle, Auswertung, MQTT, Ansage, Historie)

**Nicht geprüft** sind die beiden Abfragen gegen `api.oeg-kraken.energy`:
die Anmelde-Mutation und die Preisabfrage. Sie sind wortgleich aus der
Anleitung von Octopus übernommen. Wer einen Vertrag hat, prüft sie im Reiter
*Test* mit **Anmeldung prüfen** und **Preise jetzt abrufen** — im Protokoll
steht dann die Antwort im Wortlaut.

Octopus weist ausdrücklich darauf hin, dass sich die Struktur der
Preisinformationen (`TimeOfUseProductUnitRateInformation` innerhalb von
`unitRateInformation`) ändern kann. Das Plugin liest die Antwort deshalb
**nicht auf einem festen Pfad**, sondern sucht rekursiv nach Einträgen mit
`validFrom`/`validTo` und den beiden Preisfeldern. Eine Umbenennung der
Zwischenebenen überlebt es damit; eine Umbenennung der Preisfelder nicht —
in dem Fall meldet der Reiter Test, dass kein Bruttopreis gefunden wurde.

---

## Voraussetzungen

* LoxBerry ab 3.0.0 (der MQTT-Gateway ist seit LoxBerry 3 Bestandteil des
  Systems und muss **nicht** nachinstalliert werden)
* PHP 7.4 oder neuer
* Für den Echtbetrieb: ein Octopus-Kundenkonto mit dem Tarif **dynamicOctopus**
  und einem Smart Meter. Ohne diesen Tarif liefert die Schnittstelle eine
  leere Liste — das ist kein Fehler des Plugins.

Ohne Vertrag lässt sich alles über den **Demo-Modus** durchspielen.

---

## Die Preisrechnung

Octopus liefert mit `latestGrossUnitRateCentsPerKwh` bereits den fertigen
**Brutto-Arbeitspreis in ct/kWh**. Das Plugin schlägt deshalb **nichts** auf:
keine Netzentgelte, keine Umlagen, keine Umsatzsteuer. Ein eigener
Aufschlagsrechner wäre hier eine Fehlerquelle ohne Nutzen — anders als bei
einem Plugin, das den nackten Börsenpreis holt.

Der Nettoanteil (`netUnitRateCentsPerKwh`) wird zusätzlich geführt. Er
entscheidet darüber, ob der Preis als *negativ* gilt.

### Auflösung

Octopus liefert 15-Minuten-Werte (Intraday-Auktion IDA 1). Das Plugin gibt
beides aus:

* `cur`, `next`, `rank`, `fenster_*` — im **Viertelstundenraster**
* `cur_h`, `next_h`, `rank_h` — als **Stundenmittel**, für alle
  Loxone-Bausteine, die auf Stunden ausgelegt sind

Das günstigste zusammenhängende Fenster wird im Viertelstundenraster gesucht
und kann deshalb auch um 13:45 beginnen.

---

## Demo-Modus

Ohne Octopus-Zugang rechnet das Plugin aus den frei verfügbaren
Börsenpreisen (aWATTar, EPEX SPOT) eine Preisliste **derselben Form**:
Börsenpreis zuzüglich eines einstellbaren Aufschlags und der Umsatzsteuer.

**Diese Werte sind simuliert.** Der Aufschlag ist frei gewählt und entspricht
keinem realen Tarif. Der Demo-Modus ist überall gekennzeichnet:

* violetter Kasten über der Oberfläche
* MQTT-Thema `demo` steht auf 1
* Spalte *Quelle* in der Tagesstatistik zeigt „Demo"
* jede Sprachansage beginnt mit dem Hinweis

Da die Börsendaten stündlich sind, bleibt der Wert innerhalb einer Stunde
gleich. Erfundene Schwankungen innerhalb der Stunde wären eine
Falschaussage — deshalb gibt es sie nicht.

---

## Aufbau

    bin/oc_cron.php                    minuetlicher Lauf (Preise, Ansage, MQTT, Historie)
    cron/cron.01min                    ruft oc_cron.php auf
    webfrontend/html/oc_lib.php        gemeinsame Bibliothek
    webfrontend/html/index.php         Endpunkt fuer den Miniserver (Token)
    webfrontend/htmlauth/index.php     Bedienoberflaeche
    webfrontend/htmlauth/oc_test.php   die Aktionen des Reiters Test
    templates/lang/language_de.ini     Sprachdatei Deutsch
    templates/lang/language_en.ini     Sprachdatei Englisch
    templates/help/help.html           Hilfetext hinter dem Fragezeichen

Drei Aufgaben, drei Dateien — nie vermischt: die Oberfläche bedient nur der
Mensch, der Datenabruf läuft über den Cron, und der Endpunkt gehört Loxone.
Ein Klick auf das Plugin löst **keinen** API-Abruf aus.

Die Bibliothek liegt im unangemeldeten Webbereich, weil der Loxone-Endpunkt
sie ebenfalls braucht. Das Arbeitsskript des Cron liegt bewusst **nicht**
dort — es wäre sonst ohne Token über HTTP erreichbar.

---

## Sicherheit

* **Zugangsdaten** stehen in `config/plugins/octopus/zugang.json` mit Rechten
  **0600** — nicht in der Konfiguration, die die Oberfläche anzeigt. Sie
  werden nie über die Kommandozeile übergeben (Argumente stehen in der
  Prozessliste) und nie angezeigt; im Reiter Test steht nur die Länge.
* Das **Kraken-Token** ist eine Stunde gültig, wird 55 Minuten gehalten und
  liegt ebenfalls mit 0600 in `data/plugins/octopus/token.json`.
* Der **Endpunkt** vergleicht sein Token mit `hash_equals`, also in
  gleichbleibender Zeit. Ein einfaches `==` ließe sich über die Antwortzeit
  Zeichen für Zeichen erraten. Unbekannte Aktionen werden abgewiesen, nicht
  zurechtgebogen.
* Ein leeres Passwortfeld **löscht nichts**. Zum Löschen gibt es einen
  eigenen Haken.
* Eine Kundennummer, die nicht zur bekannten Form passt (`A-` gefolgt von
  Ziffern und/oder Buchstaben), wird **abgewiesen und gemeldet** — nicht
  stillschweigend zurechtgeschnitten.

---

## Einbindung in Loxone

Der Reiter *Einbindung in Loxone* führt in sieben Schritten durch die
Einrichtung und enthält eine **komplette Baustein-Liste zum 1:1-Nachbauen**
(22 Zeilen mit Typ, Namensvorschlag, Parametern und Verdrahtung).

Kurzfassung:

1. **MQTT ist der Regelweg.** Im MQTT-Gateway unter *Subscriptions* das Abo
   `octopus/#` eintragen. **Ohne diesen Eintrag kommt am Miniserver nichts
   an** — das ist die häufigste Fehlerursache überhaupt.
2. Virtuelle Eingänge anlegen; die Titel bildet der Gateway selbst
   (`octopus_cur`, `octopus_rank`, …). Eine fertige Vorlage lässt sich im
   Reiter herunterladen.
3. Ausfallerkennung über `alter` (Minuten seit dem letzten erfolgreichen
   Abruf). Virtuelle Eingänge behalten ihren letzten Wert — ohne diese Größe
   sieht in der App alles normal aus, obwohl die Preise von gestern sind.
   Schwelle deutlich über den Abholtakt legen, Vorschlag 90 Minuten.

Wer den Gateway nicht nutzen will: der Endpunkt liefert alle Werte in einer
Zeile.

    http://<loxberry>/plugins/octopus/index.php?token=<TOKEN>&aktion=status

Weitere Aktionen: `json`, `debug`, `refresh`, `say`, `saytomorrow`, `ptest`.

---

## MQTT-Themen

Alle Themen unter dem einstellbaren Präfix (Vorgabe `octopus`). Die
vollständige Tabelle mit Bedeutung, Einheit und aktuellem Wert steht im
Reiter *MQTT*. Die wichtigsten:

| Thema | Bedeutung |
|---|---|
| `cur` | Endpreis der laufenden Viertelstunde (ct/kWh) |
| `cur_h` | Endpreis der laufenden Stunde |
| `rank` | Rang in den nächsten 24 h, 1 = günstigste Viertelstunde |
| `level` | 1 günstig, 2 normal, 3 teuer |
| `fenster_in` | in wie vielen Minuten das günstigste Fenster beginnt |
| `neg` | 1, wenn der Nettopreis negativ ist |
| `ok` | 1, sobald gültige Preise vorliegen |
| `alter` | Alter der Preisdaten in Minuten |
| `demo` | 1, wenn die Preise simuliert sind |

---

## Kostenvergleich

Das Plugin schreibt täglich kurz vor Mitternacht Tageswerte fort (Schnitt,
Minimum, Maximum, lastprofil-gewichteter Schnitt, CO₂). Daraus rechnet der
Reiter *Kostenvergleich* einen Vollkostenvergleich gegen einen festen Tarif —
mit Grundpreisen, Rabatt und Boni, erstes Jahr und Folgejahre getrennt.

Die Gewichtung erfolgt über ein vereinfachtes Haushalts-Lastprofil. Ohne
echte Verbrauchsdaten wäre ein glatter Mittelwert zu optimistisch: die teuren
Stunden sind gerade die, in denen ein Haushalt viel verbraucht.

**Ohne Historie ist das Ergebnis eine Momentaufnahme.** Es wird mit jedem
erfassten Tag belastbarer. Die Anzahl der zugrunde liegenden Monate steht
über der Tabelle.

---

## Release

Das Auto-Update ist eingeschaltet und zeigt auf **dieses** Repository — nicht
auf einen fremden Stand, denn sonst böte LoxBerry irgendwann ein Downgrade an.

Die Fassung bleibt bewusst **unter 1.0.0**, solange die beiden Cloud-Abfragen
nicht an einem echten Vertrag erprobt sind. Wer 0.9.0 installiert hat, bekommt
0.9.1 angeboten; sobald `1.0.0` erscheint, greift die Aktualisierung ebenso.

Bei jedem Release müssen **drei Stellen** zusammenpassen, sonst greift das
Auto-Update nicht:

1. `plugin.cfg` → `VERSION`
2. `release.cfg` → `VERSION` **und beide Adressen** auf den neuen Tag
3. auf GitHub ein Release mit genau diesem Tag (`vX.Y.Z`)

Die `prerelease.cfg` wird mitgezogen.

---

## Quellen

* Octopus Energy, *Dynamisch sparen leicht gemacht — so holst du dir die
  Preise für deine Geräte direkt per API*,
  <https://octopusenergy.de/blog/tipps-tricks/dynamisch-sparen-per-api>
* Kraken GraphQL: <https://api.oeg-kraken.energy/v1/graphql/>
* CO₂-Intensität: Fraunhofer ISE, Energy-Charts,
  <https://api.energy-charts.info/co2eq> (frei, ohne Konto)
* Demo-Modus: aWATTar Marktdaten,
  <https://api.awattar.de/v1/marketdata> (frei, ohne Konto)

Das Plugin steht in keiner Verbindung zu Octopus Energy Germany GmbH und
verwendet keine fremden Wortmarken oder Logos.

---


## Fassung 1.0.0 — Fahrplaner

Bis 0.9.2 rechnete jede Schaltregel für sich. Wärmepumpe, Wallbox und
Waschmaschine fanden dieselbe günstigste Viertelstunde und schalteten gleichzeitig.
Drei Dinge kommen dazu — **alle drei ab Werk aus**, wer nichts einstellt,
bekommt das Verhalten der Fassung davor:

**Frist und Energiemenge.** Eine Regel kann jetzt sagen „7 kWh bei 3,7 kW,
fertig bis 7 Uhr". Daraus rechnet der Planer die nötige Laufzeit und sucht
nur bis zur Frist — auch wenn es danach billiger wäre. Ist die Uhrzeit heute
schon vorbei, ist morgen gemeint.

**Rangfolge und Leistungsbudget.** Jede Regel bekommt einen Rang und eine
Leistungsangabe, das Plugin ein Gesamtbudget in kW. Geplant wird in
Rangfolge: Rang 1 sucht sich die günstigen Viertelstunden zuerst aus, was er belegt
hat, steht den anderen nicht mehr zur Verfügung. Das ist ein gieriges
Verfahren, kein optimales — dafür in einem Satz erklärbar: *wer vorne steht,
sucht sich zuerst aus.* Wer um drei Uhr nachts wissen will, warum die Wallbox
nicht lädt, bekommt mit `VERD` die Zahl der weggenommenen Viertelstunden und sieht
es sofort.

**PV-Prognose und Speicherstand.** Für jede Viertelstunde mit Sonnenprognose wird
eine Gutschrift vom Preis abgezogen — damit gewinnt die sonnige
Mittagsstunde gegen die billige Nachtstunde. Die Gutschrift steigt linear bis
zu einer Schwelle; eine reine Ja/Nein-Grenze wäre eine Klippe, an der der
Fahrplan bei minimal geänderter Prognose um Stunden springt. Dazu zwei
Sperren je Regel: „nicht laden, wenn morgen mehr als X kWh vom Dach kommen"
und „nur zwischen diesen beiden Speicherständen".

Als Quelle taugt **forecast.solar** (kostenlos, ohne Konto) oder jede eigene
Adresse, die JSON liefert — als Objekt Zeit→Wert oder als Liste von Objekten
mit frei benennbaren Feldern. Für den Speicherstand genügt eine Adresse und
ein Pfad. Beides wird höchstens alle 15 Minuten geholt.

### Der Planer steckt in einer eigenen Datei

`webfrontend/html/planer.php` ist in **diesem und im Spotpreis-aWATTar-Plugin
byteweise gleich** — dieselbe Rechnung, dieselben Prüffälle. Deshalb trägt
sie das neutrale Kürzel `plan_` statt des Plugin-Kürzels; das ist die einzige
Ausnahme von der Kürzelregel und bewusst gemacht: zwei auseinanderlaufende
Kopien derselben Rechnung wären schlimmer als ein zweites Kürzel.

Sie ist reine Rechnung — kein Netz, keine Dateien, keine Uhr außer dem
übergebenen Zeitpunkt. Deshalb lässt sie sich vollständig durchprüfen:
**133 Fälle, jeder von Hand nachgerechnet**, unter PHP 7.4 und 8.4 alle grün
(53 waren es bei 1.2.0, 101 bei Planerfassung 1.1.0). Darunter die
Verdrängung durch das Budget, die Frist über Mitternacht, die
Einheitenumrechnung Wh/W/kW und der Fall „PV-Gutschrift lässt die
Sonnenstunde gegen die billigste Stunde gewinnen".

Dazu ein Mutationslauf mit **26 absichtlichen Verfälschungen**, alle
erkannt. Die Zahl der Fälle allein sagt nichts darüber, ob sie die
Rechnung anfassen — erst der Mutationslauf tut das.

**Was das nicht beweist:** dass die Prognosequelle so antwortet, wie sie
soll. Das entscheidet der Dienst am anderen Ende. Der Reiter Einstellungen
zeigt deshalb an, was zuletzt geholt wurde, und nennt den Grund, wenn nichts
ankam.

### Viertelstunden statt Stunden

Der Planer rechnet in Zeitscheiben, nicht in Stunden — deshalb passt dieselbe
Datei für beide Plugins. „2 Stunden am Stück" sind hier acht Zeitscheiben,
und `IN` und `REST` zählen wie bisher in Minuten. Bei der Regelart *die N
günstigsten Einzelstunden* wird weiterhin auf **volle** Stunden gemittelt:
sonst schaltete die Wallbox im Viertelstundentakt an und aus.

## Lizenz

MIT — siehe `LICENSE`.
