#!/bin/bash
# Octopus Dynamic - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>
#
# Neu in 1.1.16 (I1, Entscheidung 1 vom 29.09.2026), Bauform AudiConnect
# 0.9.22 / Abfahrts-Assistent 1.6.16. Der Installer ruft dieses Skript bei
# JEDEM Einbau auf, nach dem Aufraeumen der alten Fassung und VOR dem Kopieren
# von Cron-Datei und Oberflaeche (sbin/plugininstall.pl: preupgrade :853,
# purge :874, preinstall :877, Cron :990, HTML :1068 -
# Geraet/2026-09-28/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: die Zweitschrift braucht
# postinstall.sh.
#
# Ohne Marke ist es eine NEUINSTALLATION. Eine liegengebliebene Zweitschrift
# config/plugins/<ordner>.backup.json einer frueheren Installation geht nach
# <name>.alt, gemeldet mit genau einer <WARNING>. Bis 1.1.15 spielte
# postinstall.sh sie ungefragt zurueck - samt altem Aktionstoken -, und fiel
# der Minutentakt in die Luecke vor postinstall.sh, holte schon die
# Selbstheilung sie zurueck und schickte die alten Einstellungen retained an
# den Broker (in WSL gemessen, Pruefbericht installer, Faelle I2 und I3).
# Die Selbstheilung der Bibliothek liest .alt nie; die Deinstallation raeumt
# es ab.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-octopus}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Wurzelsuche wie in den uebrigen Hakenskripten: ohne config/plugins,
# data/plugins UND config/system/general.json wird nichts angefasst.
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac
[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && exit 0

BEISEITE=""
FEST=""
ZIEL="$BASE/config/plugins/$PFOLDER.backup.json"
if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
    rm -f "${ZIEL:?}.alt" 2>/dev/null
    if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
        BEISEITE="$ZIEL.alt"
        [ -f "$ZIEL.alt" ] && [ ! -L "$ZIEL.alt" ] && chmod 600 "$ZIEL.alt" 2>/dev/null
    else
        FEST="$ZIEL"
    fi
fi
if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    T="<WARNING> Neuinstallation: Einstellungen und Aktionstoken einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && T="$T Beiseitegelegt: $BEISEITE (die Deinstallation raeumt es ab)."
    [ -n "$FEST" ] && T="$T Nicht zu verschieben, bitte von Hand entfernen: $FEST"
    echo "$T"
fi
exit 0
