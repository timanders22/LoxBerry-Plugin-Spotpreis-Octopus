<?php
/**
 * Octopus Dynamic - die Aktionen des Reiters Test
 *
 * Jede Funktion beantwortet eine Frage, die man sonst nur ueber Loxone
 * beantworten koennte - und dann prueft man zwei Dinge auf einmal.
 *
 * Rueckgabe immer: array(Titel, HTML-Text)
 */

/** Ein Haken oder ein Kreuz mit Beschriftung. */
function oc_zeile($ok, $text, $zusatz = '')
{
    $z = $ok ? '<span class="sm-an">&#10004;</span>' : '<span class="sm-aus">&#10008;</span>';
    return '<div>' . $z . ' ' . $text . ($zusatz !== '' ? ' &mdash; ' . $zusatz : '') . '</div>';
}

function oc_test_ausfuehren($was)
{
    switch ($was) {
        case 'selbst':      return oc_test_selbst();
        case 'anmeldung':   return oc_test_anmeldung();
        case 'abruf':       return oc_test_abruf();
        case 'gateway':     return oc_test_gateway();
        case 'endpunkt':    return oc_test_endpunkt();
        case 'mqtt':        return oc_test_mqtt();
        case 'say':         return oc_test_say(false);
        case 'saytomorrow': return oc_test_say(true);
        case 'ptest':       return oc_test_ptest();
    }
    return array(oc_t('TEST.UNBEKANNT'), '');
}

/* ---------------- Selbstpruefung ---------------- */

function oc_test_selbst()
{
    $cfg = oc_config();
    $z   = oc_zugang();
    $p   = oc_paths();
    $g   = oc_gateway();
    $st  = oc_state();
    $h   = '';

    $h .= oc_zeile(function_exists('curl_init'), oc_t('TEST.CURL'),
        function_exists('curl_init') ? oc_t('TEST.CURL_JA') : oc_t('TEST.CURL_NEIN'));
    $h .= oc_zeile(function_exists('socket_create'), oc_t('TEST.SOCKETS'),
        function_exists('socket_create') ? '' : oc_t('TEST.SOCKETS_NEIN'));
    $h .= oc_zeile(is_writable(dirname($p['config'])) || is_writable($p['config']),
        oc_t('TEST.CONFIG_SCHREIBBAR'), oc_e($p['config']));
    $h .= oc_zeile(is_dir($p['data']) || @mkdir($p['data'], 0775, true),
        oc_t('TEST.DATENORDNER'), oc_e($p['data']));

    $demo = !empty($cfg['demo']);
    if ($demo) {
        $h .= oc_zeile(true, oc_t('TEST.DEMO_AN'), oc_t('TEST.DEMO_HINWEIS'));
    } else {
        $h .= oc_zeile($z['email'] !== '', oc_t('TEST.EMAIL'),
            $z['email'] !== '' ? oc_e($z['email']) : oc_t('TEST.FEHLT'));
        // Der Wert eines Geheimnisses wird nie angezeigt - nur seine Form.
        $h .= oc_zeile($z['passwort'] !== '', oc_t('TEST.PASSWORT'),
            $z['passwort'] !== ''
                ? str_replace('%N%', strlen($z['passwort']), oc_t('TEST.PASSWORT_LAENGE'))
                : oc_t('TEST.FEHLT'));
        $h .= oc_zeile(oc_konto_gueltig($z['konto']), oc_t('TEST.KONTO'),
            $z['konto'] === '' ? oc_t('TEST.FEHLT')
                : (oc_konto_gueltig($z['konto'])
                    ? oc_e(oc_maske_konto($z['konto']))
                    : oc_t('TEST.KONTO_FORM')));
        // Ein Passwort ohne Benutzernamen ist von aussen nicht zu sehen und
        // hat schon einmal 21 Anmeldeversuche gekostet.
        if ($z['passwort'] !== '' && $z['email'] === '') {
            $h .= '<div class="sm-warnung">' . oc_t('TEST.WARN_PW_OHNE_MAIL') . '</div>';
        }
    }

    $h .= oc_zeile($st['ok'] === 1, oc_t('TEST.PREISE'),
        $st['ok']
            ? str_replace(array('%N%', '%M%'), array($st['heute']['n'], $st['morgen']['n']),
                          oc_t('TEST.PREISE_DA'))
            : ($st['fehler'] !== '' ? oc_e(oc_fehlertext($st['fehler'])) : oc_t('TEST.PREISE_KEINE')));
    if ($st['ok'] && !$st['brutto_ok']) {
        $h .= '<div class="sm-warnung">' . oc_t('TEST.WARN_KEIN_BRUTTO') . '</div>';
    }
    if (!empty($st['veraltet'])) {
        $h .= '<div class="sm-warnung">' . oc_t('TEST.WARN_VERALTET') . '</div>';
    }
    $h .= oc_zeile($st['tomorrow_ok'] === 1, oc_t('TEST.MORGEN'),
        $st['tomorrow_ok'] ? '' : oc_t('TEST.MORGEN_NOCH_NICHT'));
    $h .= oc_zeile((string) $cfg['aktionstoken'] !== '', oc_t('TEST.TOKEN'),
        (string) $cfg['aktionstoken'] !== '' ? '' : oc_t('TEST.TOKEN_FEHLT'));

    if (!empty($cfg['mqtt_enabled'])) {
        $h .= oc_zeile($g['autostart'] === 1, oc_t('TEST.GATEWAY'),
            $g['autostart'] ? oc_t('TEST.GATEWAY_AN') : oc_t('TEST.GATEWAY_AUS'));
        $h .= oc_zeile($g['udpport'] > 0, oc_t('TEST.UDP'),
            $g['udpport'] > 0 ? oc_e($g['udpport']) : oc_t('TEST.UDP_FEHLT'));
    }
    if (!empty($cfg['co2_enabled'])) {
        $h .= oc_zeile(!empty($st['co2_ok']), oc_t('TEST.CO2'),
            !empty($st['co2_ok']) ? $st['co2'] . ' g/kWh' : oc_t('TEST.CO2_KEIN'));
    }
    if (!empty($cfg['notify']['audio'])) {
        $url = oc_tts_url('Test');
        $h .= oc_zeile($url !== '' && $url !== null, oc_t('TEST.TTS'),
            $url === null ? oc_t('TEST.TTS_AUDIOSERVER')
                : ($url === '' ? oc_t('TEST.TTS_KEINE_IP') : oc_t('TEST.TTS_OK')));
    }

    /* ---- Die Schranken gegen die Vorgaben ----
     *
     * oc_schranken() ist die Positivliste, an der die Sicherungspruefung
     * jeden Wert misst. Kommt ein neues Feld in oc_vorgaben() dazu und
     * vergisst jemand den Eintrag hier, rutscht es UNGEPRUEFT durch das
     * Zurueckspielen - und niemand merkt es, weil alles gruen aussieht.
     *
     * Deshalb zaehlt diese Zeile beides gegeneinander. Sie prueft nicht,
     * ob die Grenzen richtig GEWAEHLT sind - nur, dass es welche gibt. */
    $ohne = array_diff(array_keys(oc_vorgaben()), array_keys(oc_schranken()));
    $zuviel = array_diff(array_keys(oc_schranken()), array_keys(oc_vorgaben()));
    $h .= oc_zeile(!$ohne && !$zuviel, oc_t('TEST.SCHRANKEN'),
        (!$ohne && !$zuviel)
            ? str_replace('%N%', count(oc_schranken()), oc_t('TEST.SCHRANKEN_OK'))
            : oc_e(trim(($ohne ? 'ohne Schranke: ' . implode(', ', $ohne) . '  ' : '')
                      . ($zuviel ? 'ohne Vorgabe: ' . implode(', ', $zuviel) : ''))));

    /* ---- Der Rundlauf der Sicherung ----
     *
     * Bauen, wieder einlesen, und es muss durchgehen. Das ist der eine
     * Fall, den die Wirkungspruefung nicht abdeckt: sie fuettert eine
     * ERZEUGTE Liste, nicht die Datei, die der Knopf wirklich ausgibt.
     * Ein Kopfschluessel, den die Leseseite nicht kennt, faellt genau
     * hier auf und sonst nirgends. */
    $rund = oc_sicherung_lesen(oc_sicherung_bauen(false));
    $h .= oc_zeile($rund[0] !== null, oc_t('TEST.SICH_RUNDLAUF'),
        $rund[0] !== null
            ? str_replace('%N%', (int) $rund[2], oc_t('TEST.SICH_RUNDLAUF_OK'))
            : oc_e(implode(' ', $rund[1])));

    return array(oc_t('TEST.T_SELBST'), $h);
}

/* ---------------- Anmeldung ---------------- */

function oc_test_anmeldung()
{
    $cfg = oc_config();
    if (!empty($cfg['demo'])) {
        return array(oc_t('TEST.T_ANMELDUNG'),
            '<div class="sm-hinweis">' . oc_t('TEST.DEMO_KEINE_ANMELDUNG') . '</div>');
    }
    $z = oc_zugang();
    if ($z['email'] === '' || $z['passwort'] === '') {
        return array(oc_t('TEST.T_ANMELDUNG'),
            '<div class="sm-warnung">' . oc_t('TEST.FEHLT_ZUGANG') . '</div>');
    }
    $t0 = microtime(true);
    $token = oc_kraken_token(true, $f);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    if ($token === '') {
        return array(oc_t('TEST.T_ANMELDUNG'),
            '<div class="sm-warnung">' . oc_e(oc_fehlertext($f)) . '</div>'
            . '<div class="sm-hilfe">' . str_replace('%MS%', $ms, oc_t('TEST.DAUER')) . '</div>');
    }
    // Nie den Wert eines Geheimnisses anzeigen - Laenge genuegt.
    return array(oc_t('TEST.T_ANMELDUNG'),
        oc_zeile(true, oc_t('TEST.ANMELDUNG_OK'),
            str_replace(array('%N%', '%MS%'), array(strlen($token), $ms), oc_t('TEST.TOKEN_INFO'))));
}

/* ---------------- Preisabruf ---------------- */

function oc_test_abruf()
{
    $t0 = microtime(true);
    $st = oc_state(true);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $h = '';
    if (!$st['ok']) {
        $h .= '<div class="sm-warnung">' . oc_e(oc_fehlertext($st['fehler'])) . '</div>';
    } else {
        $h .= oc_zeile(true, str_replace(array('%N%', '%M%'),
                array($st['heute']['n'], $st['morgen']['n']), oc_t('TEST.PREISE_DA')),
            $st['demo'] ? oc_t('TEST.DEMO_KENNZEICHEN') : oc_t('TEST.QUELLE_OCTOPUS'));
        $h .= '<table class="sm-tbl"><tr><th>' . oc_t('TEST.SP_WAS') . '</th><th>'
            . oc_t('TEST.SP_WERT') . '</th></tr>';
        $r = function ($a, $b) { return '<tr><td>' . $a . '</td><td>' . $b . '</td></tr>'; };
        $h .= $r(oc_t('TEST.R_JETZT'), number_format($st['cur'], 3, ',', '.') . ' ct/kWh');
        $h .= $r(oc_t('TEST.R_STUNDE'), number_format($st['cur_h'], 3, ',', '.') . ' ct/kWh');
        $h .= $r(oc_t('TEST.R_RANG'), $st['rank'] . ' / ' . $st['n']);
        $h .= $r(oc_t('TEST.R_HEUTE'), sprintf('%.3f &hellip; %.3f, &Oslash; %.3f ct',
                    $st['heute']['minp'], $st['heute']['maxp'], $st['heute']['avg']));
        $h .= $r(oc_t('TEST.R_MORGEN'), $st['tomorrow_ok']
                    ? sprintf('%.3f &hellip; %.3f, &Oslash; %.3f ct',
                        $st['morgen']['minp'], $st['morgen']['maxp'], $st['morgen']['avg'])
                    : oc_t('TEST.MORGEN_NOCH_NICHT'));
        $h .= $r(oc_t('TEST.R_FENSTER'), sprintf('%02d:%02d &ndash; &Oslash; %.3f ct',
                    $st['fenster']['h'], $st['fenster']['m'], $st['fenster']['ct']));
        $h .= '</table>';
    }
    $h .= '<div class="sm-hilfe">' . str_replace('%MS%', $ms, oc_t('TEST.DAUER')) . '</div>';
    return array(oc_t('TEST.T_ABRUF'), $h);
}

/* ---------------- MQTT-Gateway ---------------- */

function oc_test_gateway()
{
    $g = oc_gateway();
    $h = '';
    // Achtung: "Brokerhost ist gesetzt" beantwortet NICHT die Frage, ob es
    // einen laufenden Gateway gibt - der Wert ist ab Werk gesetzt.
    $h .= oc_zeile($g['vorhanden'] === 1, oc_t('TEST.G_GEFUNDEN'),
        $g['vorhanden'] ? oc_e(oc_paths()['general']) : oc_t('TEST.G_KEINE_DATEI'));
    $h .= oc_zeile($g['autostart'] === 1, oc_t('TEST.G_AUTOSTART'),
        $g['autostart'] ? oc_t('ALLGEMEIN.JA') : oc_t('TEST.G_AUTOSTART_NEIN'));
    $h .= oc_zeile($g['broker'] !== '', oc_t('TEST.G_BROKER'),
        oc_e($g['broker'] . ':' . $g['port']));
    $h .= oc_zeile($g['udpport'] > 0, oc_t('TEST.G_UDP'), oc_e($g['udpport']));
    $h .= oc_zeile((int) $g['lokal'] === 1, oc_t('TEST.G_LOKAL'),
        (int) $g['lokal'] === 1 ? oc_t('ALLGEMEIN.JA') : oc_t('ALLGEMEIN.NEIN'));
    return array(oc_t('TEST.T_GATEWAY'), $h);
}

/* ---------------- MQTT senden ---------------- */

function oc_test_mqtt()
{
    $cfg = oc_config();
    if (empty($cfg['mqtt_enabled'])) {
        return array(oc_t('TEST.T_MQTT'),
            '<div class="sm-warnung">' . oc_t('TEST.MQTT_AUS') . '</div>');
    }
    $st = oc_state();
    $ok = oc_mqtt_publish($st);
    $anz = count(oc_werte($st));
    $h = oc_zeile($ok, $ok ? str_replace('%N%', $anz, oc_t('TEST.MQTT_GESENDET'))
                           : oc_t('TEST.MQTT_FEHLER'));
    $h .= '<div class="sm-hilfe">' . str_replace('%T%',
        '<span class="sm-mono">' . oc_e($cfg['mqtt_topic']) . '/#</span>',
        oc_t('TEST.MQTT_HINWEIS')) . '</div>';
    return array(oc_t('TEST.T_MQTT'), $h);
}

/* ---------------- Endpunkt ---------------- */

function oc_test_endpunkt()
{
    $cfg = oc_config();
    if ((string) $cfg['aktionstoken'] === '') {
        return array(oc_t('TEST.T_ENDPUNKT'),
            '<div class="sm-warnung">' . oc_t('TEST.TOKEN_FEHLT') . '</div>');
    }
    $basis = 'http://' . oc_eigene_ip() . '/plugins/' . oc_paths()['plugin'] . '/index.php';
    $h = '';

    // 1) ohne Token - muss abgewiesen werden
    $r = oc_http($basis . '?aktion=status', null, array(), 10);
    $h .= oc_zeile($r['code'] === 403, oc_t('TEST.E_OHNE_TOKEN'),
        'HTTP ' . ($r['code'] ?: '-'));
    // 2) mit falschem Token - muss abgewiesen werden
    $r = oc_http($basis . '?token=falsch&aktion=status', null, array(), 10);
    $h .= oc_zeile($r['code'] === 403, oc_t('TEST.E_FALSCHES_TOKEN'),
        'HTTP ' . ($r['code'] ?: '-'));
    // 3) unbekannte Aktion - muss abgewiesen werden
    $r = oc_http($basis . '?token=' . rawurlencode($cfg['aktionstoken']) . '&aktion=loeschen',
                 null, array(), 10);
    $h .= oc_zeile($r['code'] === 400, oc_t('TEST.E_UNBEKANNT'), 'HTTP ' . ($r['code'] ?: '-'));
    // 4) richtiger Aufruf
    $r = oc_http($basis . '?token=' . rawurlencode($cfg['aktionstoken']) . '&aktion=status',
                 null, array(), 15);
    $ok = $r['ok'] && strpos($r['body'], 'OCTOPUS;') === 0;
    $h .= oc_zeile($ok, oc_t('TEST.E_RICHTIG'), 'HTTP ' . ($r['code'] ?: '-'));
    if ($r['body'] !== '') {
        $h .= '<div class="sm-pre">' . oc_e(substr(trim($r['body']), 0, 1200)) . '</div>';
    }
    $h .= '<div class="sm-hilfe">' . str_replace('%U%',
        '<span class="sm-mono">' . oc_e($basis) . '</span>', oc_t('TEST.E_ADRESSE')) . '</div>';
    return array(oc_t('TEST.T_ENDPUNKT'), $h);
}

/* ---------------- Ansage / Push ---------------- */

function oc_test_say($morgen)
{
    $st = oc_state();
    $text = $morgen ? oc_tomorrow_text($st) : oc_announce_text($st);
    if ($text === '') { $text = oc_t('ANSAGE.TEST_LEER'); }
    $url = oc_tts_url($text);
    if ($url === null) {
        return array(oc_t('TEST.T_SAY'),
            '<div class="sm-hinweis">' . oc_t('TEST.TTS_AUDIOSERVER') . '</div>'
            . '<div class="sm-pre">' . oc_e($text) . '</div>');
    }
    if ($url === '') {
        return array(oc_t('TEST.T_SAY'),
            '<div class="sm-warnung">' . oc_t('TEST.TTS_KEINE_IP') . '</div>');
    }
    $ok = oc_say($text);
    return array(oc_t('TEST.T_SAY'),
        oc_zeile($ok, $ok ? oc_t('TEST.SAY_OK') : oc_t('TEST.SAY_FEHLER'))
        . '<div class="sm-pre">' . oc_e($text) . '</div>');
}

function oc_test_ptest()
{
    @file_put_contents(oc_tmpdir() . '/ptest', '1');
    oc_log('Test-Pushnachricht ueber die Oberflaeche angefordert');
    return array(oc_t('TEST.T_PTEST'),
        oc_zeile(true, oc_t('TEST.PTEST_GESETZT'))
        . '<div class="sm-hilfe">' . oc_t('TEST.PTEST_HINWEIS') . '</div>');
}
