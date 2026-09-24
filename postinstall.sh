#!/bin/bash
# Octopus Dynamic - postinstall
# Aufruf: command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Achtung: die Umgebungsvariablen des Installers sind NICHT dasselbe wie die
# Perl-Variablen. $LBPDATA zeigt auf <home>/data/plugins, $lbpdatadir dagegen
# auf <home>/data/plugins/<ordner>. Wer beide mischt, landet eine Ebene
# daneben. Deshalb wird hier ausschliesslich aus den Argumenten abgeleitet.
#
# LoxBerry::System taugt hier nicht: es leitet den Pluginordner aus dem
# Aufrufort ab, und postinstall.sh ruft von woanders auf.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-octopus}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Die Wurzel: $5 (vom Installer) oder $LBHOMEDIR, wenn dort config/plugins
# und data/plugins liegen - sonst vom eigenen Ablageort AUFWAERTS SUCHEN, bis
# ein Verzeichnis config/plugins, data/plugins UND config/system/general.json
# traegt. Keine feste Ebenenzahl und kein fest verdrahteter Systempfad danach.
#
# Bis 1.1.11 stand hier der Rueckfall "drei Ebenen ueber dem eigenen
# Ablageort", ohne jede Pruefung. Aus einem ausgepackten Archiv drei Ebenen
# unter einem fremden Baum legte postinstall.sh dort an, preupgrade.sh
# sicherte dessen Konfiguration, und postupgrade.sh spielte in ihn zurueck (in
# WSL gemessen, Pruefung-Spotpreis-Octopus-1.1.12, Faelle H8, H10, H12).
# general.json ist die Bedingung aus dem Raumklima-Vorfall (Regeln/06): ein
# LoxBerry hat die Datei immer, ein Pruefstandsrest nie. Findet sich nichts,
# wird GEWARNT statt vollzogen. Bauart Spotpreis-Tibber 0.9.18.
oc_wurzel_suchen() {
    oc_v=$(cd "$1" 2>/dev/null && pwd -P) || return 1
    oc_i=0
    while [ -n "$oc_v" ] && [ "$oc_v" != "/" ] && [ "$oc_i" -lt 8 ]; do
        if [ -d "$oc_v/config/plugins" ] && [ -d "$oc_v/data/plugins" ] \
           && [ -f "$oc_v/config/system/general.json" ]; then
            echo "$oc_v"
            return 0
        fi
        oc_v=$(dirname "$oc_v")
        oc_i=$((oc_i + 1))
    done
    return 1
}
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ]; then
    BASE=$(oc_wurzel_suchen "$(dirname "$(readlink -f "$0")")") || BASE=""
fi
if [ -z "$BASE" ]; then
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden: weder als"
    echo "<WARNING> fuenftes Argument noch in \$LBHOMEDIR, und oberhalb von"
    echo "<WARNING> $(dirname "$(readlink -f "$0")") traegt kein Verzeichnis"
    echo "<WARNING> config/plugins, data/plugins und config/system/general.json."
    echo "<WARNING> Es wurde nichts angelegt, gesichert oder zurueckgespielt."
    exit 1
fi

CFGDIR="$BASE/config/plugins/$PFOLDER"
DATADIR="$BASE/data/plugins/$PFOLDER"
LOGDIR="$BASE/log/plugins/$PFOLDER"

mkdir -p "$CFGDIR" "$DATADIR" "$LOGDIR" || {
    echo "<FAIL> Verzeichnisse konnten nicht angelegt werden."
    exit 1
}

# Leere Grundkonfiguration, damit die Oberflaeche beim ersten Aufruf nicht
# auf eine fehlende Datei laeuft.
if [ ! -f "$CFGDIR/octopus.json" ]; then
    echo '{}' > "$CFGDIR/octopus.json"
fi
chmod 600 "$CFGDIR/octopus.json" 2>/dev/null

# Zugangsdaten liegen in einer eigenen Datei mit Rechten 0600.
if [ ! -f "$CFGDIR/zugang.json" ]; then
    echo '{}' > "$CFGDIR/zugang.json"
fi
chmod 600 "$CFGDIR/zugang.json" 2>/dev/null

# Traegt eine Datei Inhalt? 0 = ja, 1 = nein, 2 = nicht pruefbar (kein php).
# "konfig": ein Aktionstoken in der Form, die webfrontend/html/oc_lib.php
# (Normalisierung der Konfiguration) als Token gelten laesst,
# [A-Za-z0-9]{8,64}; es entsteht beim ersten Speichern. "zugang": E-Mail UND
# Passwort als nicht leerer Text (die Felder aus oc_zugang_write()).
# Wortgleich in postinstall.sh und postupgrade.sh - die Hakenskripte laufen
# getrennt und binden keine gemeinsame Datei ein.
oc_inhalt() {   # $1 Datei, $2 Art: konfig | zugang
    [ -f "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 2
    php -r '
        $d = json_decode((string) @file_get_contents($argv[1]), true);
        if (!is_array($d)) { exit(1); }
        if ($argv[2] === "konfig") {
            $t = (isset($d["aktionstoken"]) && !is_array($d["aktionstoken"]))
                ? trim((string) $d["aktionstoken"]) : "";
            exit(preg_match("/^[A-Za-z0-9]{8,64}$/", $t) ? 0 : 1);
        }
        $da = function ($k) use ($d) {
            return isset($d[$k]) && is_string($d[$k]) && trim($d[$k]) !== "";
        };
        exit(($da("email") && $da("passwort")) ? 0 : 1);
    ' -- "$1" "$2" >/dev/null 2>&1
    oc_rc=$?
    [ "$oc_rc" = 0 ] || [ "$oc_rc" = 1 ] || return 2
    return "$oc_rc"
}

# Selbstheilung: bei einer Neuinstallation ueber eine alte Sicherung wird die
# Konfiguration zurueckgeholt, sofern die aktuelle leer ist - aber nur aus
# einer Zweitschrift MIT Inhalt. Bis 1.1.12 wurde auch "{}" kopiert und als
# "wiederhergestellt" gemeldet (gemessen 24.09.2026,
# Pruefung-Spotpreis-Octopus-1.1.13, Fall c). Ohne php wird wie bisher kopiert.
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$CFGDIR/octopus.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        oc_inhalt "$BK" konfig
        if [ "$?" = 1 ]; then
            echo "<INFO> Sicherung ohne Einstellungen - nichts zurueckgespielt."
        else
            cp -p "$BK" "$CF" && echo "<OK> Konfiguration aus der Sicherung wiederhergestellt."
        fi
    fi
fi

# Rechte NACH der Wiederherstellung, nicht davor: "cp -p" oben traegt die
# Rechte der Sicherung mit und dreht ein frueheres chmod zurueck. In dieser
# Konfiguration steht das Aktionstoken; wer es lesen kann, kann den Endpunkt
# abfragen und jedes Formular der Oberflaeche absenden. Hausstandard 0600
# (Regeln/05, 13.09.2026). Die Zweitschrift traegt dasselbe Geheimnis.
chmod 600 "$CF" 2>/dev/null
if [ -f "$BK" ]; then
    chmod 600 "$BK" 2>/dev/null
fi

chown -R loxberry:loxberry "$CFGDIR" "$DATADIR" "$LOGDIR" 2>/dev/null

# Das Arbeitsskript muss ausfuehrbar sein und PHP muss es finden.
if ! command -v php >/dev/null 2>&1; then
    echo "<WARNING> php wurde nicht gefunden. Ohne PHP laeuft weder die Oberflaeche noch der Cron."
fi

# ---------- Abschluss: Erstanleitung nur ohne gespeicherte Einstellungen ----------
# Dieses Skript laeuft bei der Erstinstallation UND bei jedem Upgrade. Bis
# 1.1.12 stand die Anleitung darunter deshalb auch nach jedem gelungenen
# Upgrade da (gemessen 24.09.2026 in WSL, Pruefung-Spotpreis-Octopus-1.1.13,
# Fall b) - und gleich danach meldete postupgrade.sh, alles sei uebernommen.
#
# Entschieden wird nach dem INHALT von octopus.json, wie er nach dem
# Zurueckspielen oben steht: ein Aktionstoken in der Form, die
# webfrontend/html/oc_lib.php (Normalisierung der Konfiguration) als
# Token gelten laesst ([A-Za-z0-9]{8,64}; oc_inhalt, oben). Es entsteht beim ersten
# Speichern - genau dem Schritt, zu dem die Anleitung auffordert. Die
# Zugangsdaten taugen hier NICHT als Merkmal: zugang.json kommt beim
# Upgrade erst in postupgrade.sh zurueck, also nach diesem Skript.
# Fehlt das Token nach einem Upgrade, ist die Rueckholung gescheitert, und
# die Anleitung ist richtig; ohne php ebenso (lieber einmal zu viel).
if oc_inhalt "$CF" konfig; then
    echo "<OK> Aktualisierung abgeschlossen, Einstellungen uebernommen."
else
    echo "<OK> Installation abgeschlossen."
    echo "<INFO> Naechster Schritt: Plugin oeffnen, im Reiter Einstellungen die Octopus-Zugangsdaten"
    echo "<INFO> hinterlegen (oder den Demo-Modus einschalten) und einmal speichern - dabei wird das"
    echo "<INFO> Token fuer den Loxone-Endpunkt erzeugt."
fi
exit 0
