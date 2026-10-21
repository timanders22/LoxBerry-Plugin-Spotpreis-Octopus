<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Octopus Dynamic (Spotpreis Octopus).
 *
 * Gruppe 5 / MQTT (1.1.26; Bauvorschrift wz_bild/GRUPPE5_BAUVORSCHRIFT.md, Entwurf Musterprojekt/entwurf_gruppe5_oc.txt
 * Abschnitt 3, ENTSCHEIDUNGEN_GRUPPE5_MQTT.md; Muster vb_g1_ab_bau/bin/befehle_zuordnung.php - harmlose Pulse ohne
 * Dauerdienst der Linie). Die Bruecke selbst (bin/befehlsbruecke.php und .sh) ist byte-gleich die Stammfassung
 * Werkzeuge/gemeinsam/ - linieneigen ist nur diese Datei.
 *
 * Regeln (Entwurf gemeinsam 2.5): die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts.
 * 'tun' ruft DIESELBE Funktion wie der HTTP-Endpunkt webfrontend/html/index.php - oc_befehl_ausfuehren() - mit Quelle
 * 'mqtt' und gibt array(bool ok, string grund) zurueck (oc_befehl_bruecke()).
 *
 * Drei Pulse (Entwurf oc E1): ansage, ansage_morgen, testpush - alle harmlos, deshalb kein zweiter Haken (wie
 * Abfuhrkalender, Bewaesserung-Messwerte, Fensterbilanz). Sperrminute: die Vorgabe der Bruecke, 60 s je Name (ein Puls
 * traegt immer "1"); dazu bremst das Plugin selbst (say 20 s gemeinsam fuer beide Ansagen, ptest 10 s). 'refresh'
 * (erzwungener Abruf, Kraken-Neuanmeldung) steht bewusst NICHT hier (Entwurf oc E2) - der Minutenlauf holt ohnehin
 * alle 15 Minuten; ueber HTTP bleibt er.
 */
if (!function_exists('oc_befehl_ausfuehren')) {
    $oc_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $oc_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/oc_lib.php';
    } else {
        $oc_kand[] = dirname(__DIR__) . '/webfrontend/html/oc_lib.php';
    }
    foreach ($oc_kand as $oc_k) {
        if (is_file($oc_k)) { require_once $oc_k; break; }
    }
}
return array(
    'fassung' => 1,
    'linie'   => 'octopus',
    'plugin'  => 'Octopus Dynamic',
    'geladen' => function_exists('oc_config') && function_exists('oc_befehl_ausfuehren') && function_exists('oc_befehl_bruecke'),
    'einstellungen' => function () {
        return oc_befehle_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        oc_log('Befehlsbruecke ' . ($stufe === 'INFO' ? '' : $stufe . ': ') . $text);
    },
    'befehle' => array(
        'ansage'        => array('art' => 'puls', 'eingreifend' => false,
                                 'titel' => 'Octopus Dynamic Preisansage (MQTT)', 'kachel' => 'BEFEHL.K_ANSAGE',
                                 'quittung' => 'ausgefuehrt',
                                 'tun' => function ($w, $t) {
                                     return oc_befehl_bruecke(oc_befehl_ausfuehren('say', 'mqtt'));
                                 }),
        'ansage_morgen' => array('art' => 'puls', 'eingreifend' => false,
                                 'titel' => 'Octopus Dynamic Ansage Preise morgen (MQTT)', 'kachel' => 'BEFEHL.K_ANSAGE_MORGEN',
                                 'quittung' => 'ausgefuehrt',
                                 'tun' => function ($w, $t) {
                                     return oc_befehl_bruecke(oc_befehl_ausfuehren('saytomorrow', 'mqtt'));
                                 }),
        'testpush'      => array('art' => 'puls', 'eingreifend' => false,
                                 'titel' => 'Octopus Dynamic Test-Pushnachricht (MQTT)', 'kachel' => 'BEFEHL.K_TESTPUSH',
                                 'quittung' => 'ausgefuehrt',
                                 'tun' => function ($w, $t) {
                                     return oc_befehl_bruecke(oc_befehl_ausfuehren('ptest', 'mqtt'));
                                 }),
    ),
);
