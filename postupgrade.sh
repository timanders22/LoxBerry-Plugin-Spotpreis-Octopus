#!/bin/bash
# Octopus Dynamic - postupgrade: Gesichertes zurueckspielen
# Aufruf: command <TEMPFOLDER-KENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>
#
# $1 ist eine ZUFALLSKENNUNG, kein Pfad - die ausfuehrliche Begruendung
# steht in preupgrade.sh. Gesucht wird in dieser Reihenfolge:
#   1. der Arbeitsordner aus dem sechsten Argument
#   2. der bisherige Weg ueber die Kennung (relativ zum cwd des Installers)
#
# Ein dritter Weg - ein Merker .upgrade_pfad im Konfigurationsordner - stand
# hier an erster Stelle und ist ausgebaut: purge_installation entfernt genau
# dieses Verzeichnis, bevor dieses Skript laeuft. Der Merker konnte nie
# ankommen, der Zweig war tot, und das rm -f darauf ebenfalls. Beide Skripte
# rechnen den Pfad aus DEMSELBEN sechsten Argument aus.

ARGV1=$1
ARGV6=$6
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
mkdir -p "$CFGDIR" "$DATADIR" "$LOGDIR" 2>/dev/null

if [ -n "$ARGV6" ] && [ -d "$ARGV6/octopus_upgrade" ]; then
    SICHERUNG="$ARGV6/octopus_upgrade"
else
    SICHERUNG="${ARGV1:-octopus}_upgrade"
fi
echo "<INFO> Sicherungsordner: $SICHERUNG"

[ -f "$SICHERUNG/octopus.json" ] && cp -p "$SICHERUNG/octopus.json" "$CFGDIR/octopus.json"
[ -f "$SICHERUNG/zugang.json" ]  && cp -p "$SICHERUNG/zugang.json"  "$CFGDIR/zugang.json"
[ -f "$SICHERUNG/history.csv" ]  && cp -p "$SICHERUNG/history.csv"  "$DATADIR/history.csv"

# Selbstheilung wie in postinstall.sh
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$CFGDIR/octopus.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        cp -p "$BK" "$CF"
    fi
fi

# Das Token steckt in den Adressen im Miniserver - die Rechte muessen stimmen,
# der Inhalt bleibt unberuehrt.
chmod 600 "$CFGDIR/octopus.json" 2>/dev/null
chmod 600 "$CFGDIR/zugang.json" 2>/dev/null

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

# Der Zustandsspeicher wird verworfen, damit nach dem Update sofort mit der
# neuen Fassung gerechnet wird.
rm -f /tmp/"$PFOLDER"/state.json 2>/dev/null

echo "<OK> Aktualisierung abgeschlossen. Konfiguration, Zugangsdaten und Historie wurden uebernommen."
exit 0
