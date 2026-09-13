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
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    # Kein fest verdrahteter Systempfad: aus dem eigenen Ablageort ableiten.
    # Dieses Skript liegt im Wurzelverzeichnis des Plugin-Archivs bzw. unter
    # <home>/data/plugins/<ordner>/.
    BASE=$(cd "$(dirname "$(readlink -f "$0")")/../../.." 2>/dev/null && pwd)
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
