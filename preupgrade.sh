#!/bin/bash
# Octopus Dynamic - preupgrade: Konfiguration, Zugangsdaten und Daten sichern
# Aufruf: command <TEMPFOLDER-KENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <WORKDIR>
#
# ---------------------------------------------------------------------------
# ZU DEN ARGUMENTEN - hier wird oft das Falsche angenommen
#
# Der LoxBerry-Installer ruft dieses Skript so auf (sbin/plugininstall.pl):
#
#   cd "$tempfolder" && "$script" "$tempfile" "$pname" "$pfolder" \n#                       "$pversion" "$lbhomedir" "$tempfolder"
#
# $1 ist $tempfile - eine ZUFALLSKENNUNG aus zehn Zeichen (generate(10)),
# KEIN Pfad. Der absolute Arbeitsordner kommt als SECHSTES Argument.
#
# Bis 1.1.3 stand hier mkdir -p "$ARGV1" und danach cp ... "$ARGV1/...".
# Das lief - aber nur, weil der Installer vorher in seinen Arbeitsordner
# wechselt: es entstand ein relativer Ordner mit dem Namen der Kennung
# darin, und postupgrade.sh fand ihn unter demselben Namen wieder. Ein
# Skript, das nur wegen des Arbeitsverzeichnisses seines Aufrufers
# funktioniert, ist eine Falle fuer den naechsten, der es anfasst: kippt
# einer der beiden Zufaelle, gehen Konfiguration, Zugangsdaten und
# Historie bei jedem Update still verloren - kein cp prueft hier seinen
# Rueckgabewert.
#
# Jetzt wird der Arbeitsordner ausdruecklich benutzt, mit Rueckfall auf den
# bisherigen Weg, falls eine aeltere LoxBerry-Fassung das sechste Argument
# nicht liefert. Das Muster ist woertlich das der Schwesterlinie
# Spotpreis-aWATTar, die es seit 1.1.2 traegt.
#
# DAS PROTOKOLL WIRD NICHT MEHR GESICHERT: log/plugins liegt auf dem
# LoxBerry fest auf einer Ramdisk und ist nach jedem Neustart ohnehin
# leer - eine Sicherung haette Fluechtiges von der Ramdisk in die Ramdisk
# kopiert.
# ---------------------------------------------------------------------------

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

if [ -n "$ARGV6" ] && [ -d "$ARGV6" ]; then
    SICHERUNG="$ARGV6/octopus_upgrade"
else
    echo "<INFO> Kein Arbeitsordner uebergeben - Rueckfall auf den bisherigen Weg"
    SICHERUNG="${ARGV1:-octopus}_upgrade"
fi

mkdir -p "$SICHERUNG" 2>/dev/null

# HIER STAND EIN MERKER .upgrade_pfad IM KONFIGURATIONSORDNER, den
# postupgrade.sh lesen sollte - mit der Begruendung, das sei "die eine
# Stelle, an der beide auseinanderlaufen". Er kann dort nie ankommen:
# purge_installation entfernt genau dieses Verzeichnis, bevor postupgrade
# laeuft (plugininstall.pl :886 im Upgrade-Zweig, der rm -rf in :1629).
# Nachgestellt: nach preupgrade da, nach dem Abraeumen weg, nach dem
# Neuanlegen durch den Installer weg.
#
# Der Merker war also eine falsche Faehrte, und die Zusicherung im Kommentar
# sagte das Gegenteil dessen, was der Code tut. Beide Skripte rechnen den
# Pfad ohnehin aus DEMSELBEN sechsten Argument aus - und das ist die eine
# Stelle, an der sie nicht auseinanderlaufen koennen.
#
# Die Schwesterlinie Smartmeter classic hat denselben Merker in 2.3.14 aus
# demselben Grund ausgebaut; dort steht die Fundstelle ausgeschrieben.

echo "<INFO> Sicherungsordner: $SICHERUNG"
cp -p "$BASE/config/plugins/$PFOLDER/octopus.json" "$SICHERUNG/octopus.json" 2>/dev/null
cp -p "$BASE/config/plugins/$PFOLDER/zugang.json"  "$SICHERUNG/zugang.json"  2>/dev/null
cp -p "$BASE/data/plugins/$PFOLDER/history.csv"    "$SICHERUNG/history.csv"  2>/dev/null
exit 0
