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
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    # Kein fest verdrahteter Systempfad: aus dem eigenen Ablageort ableiten.
    # Dieses Skript liegt im Wurzelverzeichnis des Plugin-Archivs bzw. unter
    # <home>/data/plugins/<ordner>/.
    BASE=$(cd "$(dirname "$(readlink -f "$0")")/../../.." 2>/dev/null && pwd)
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
chmod 640 "$CFGDIR/octopus.json" 2>/dev/null

# Zugangsdaten liegen in einer eigenen Datei mit Rechten 0600.
if [ ! -f "$CFGDIR/zugang.json" ]; then
    echo '{}' > "$CFGDIR/zugang.json"
fi
chmod 600 "$CFGDIR/zugang.json" 2>/dev/null

# Selbstheilung: bei einer Neuinstallation ueber eine alte Sicherung wird die
# Konfiguration zurueckgeholt, sofern die aktuelle leer ist.
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$CFGDIR/octopus.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        cp -p "$BK" "$CF" && echo "<OK> Konfiguration aus der Sicherung wiederhergestellt."
    fi
fi

chown -R loxberry:loxberry "$CFGDIR" "$DATADIR" "$LOGDIR" 2>/dev/null

# Das Arbeitsskript muss ausfuehrbar sein und PHP muss es finden.
if ! command -v php >/dev/null 2>&1; then
    echo "<WARNING> php wurde nicht gefunden. Ohne PHP laeuft weder die Oberflaeche noch der Cron."
fi

echo "<OK> Installation abgeschlossen."
echo "<INFO> Naechster Schritt: Plugin oeffnen, im Reiter Einstellungen die Octopus-Zugangsdaten"
echo "<INFO> hinterlegen (oder den Demo-Modus einschalten) und einmal speichern - dabei wird das"
echo "<INFO> Token fuer den Loxone-Endpunkt erzeugt."
exit 0
