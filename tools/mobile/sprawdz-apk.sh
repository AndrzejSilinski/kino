#!/bin/sh
# Sprawdzenie APK wydania (Etap 10, blok G, decyzja 427) — ten sam skrypt w teście dymnym i w CI.
# Działa W KONTENERZE Fluttera (apksigner i aapt2 są w build-tools, poza PATH):
#
#   sh tools/flutter/flutter.sh --powloka /work/tools/mobile/sprawdz-apk.sh <apk> [oczekiwany-odcisk]
#
# <apk> względem mobile/ (np. build/app/outputs/flutter-apk/app-release.apk). Najpierw wypisuje
# FAKTY (KLUCZ=wartość — test dymny porównuje je też dla APK debug jako kontroli dodatniej),
# potem werdykt. Kod wyjścia 0 tylko dla APK wydania:
#   - podpis v2 lub v3 poprawny (apksigner verify), a przy podanym odcisku — ten certyfikat,
#   - bez android:debuggable i bez kernel_blob.bin (ten jest tylko w buildzie JIT/debug),
#   - kod Darta skompilowany AOT (lib/arm64-v8a/libapp.so), pakiet pl.silinski.cinema,
#   - ikona powiadomień w tabeli zasobów mimo isShrinkResources (res/raw/keep.xml).
# Odcisk certyfikatu to dana publiczna (każdy odczyta go z APK), więc wolno go wypisać.
set -eu

APK="${1:?podaj ścieżkę APK}"
OCZEKIWANY="${2:-}"
[ -f "$APK" ] || { echo "sprawdz-apk.sh: brak pliku $APK" >&2; exit 2; }

# Najnowsze build-tools (nazwy katalogów to numery wersji; w obrazie 36.0.0).
# shellcheck disable=SC2012
BT="$ANDROID_HOME/build-tools/$(ls "$ANDROID_HOME/build-tools" | sort -V | tail -1)"
JAR=$(command -v jar || echo "$JAVA_HOME/bin/jar")
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT

"$BT/apksigner" verify --verbose --print-certs "$APK" > "$T/podpis.txt" 2>&1 || true
"$BT/aapt2" dump badging "$APK" > "$T/badging.txt" 2>&1 || true
"$BT/aapt2" dump resources "$APK" > "$T/zasoby.txt" 2>&1 || true
"$JAR" tf "$APK" > "$T/pliki.txt" 2>&1 || true

tak() { if grep -qE -- "$1" "$2"; then echo 1; else echo 0; fi; }
ODCISK=$(sed -n 's/^Signer #1 certificate SHA-256 digest: //p' "$T/podpis.txt" | head -1)
ODCISK_DEBUG=brak
if [ -f "$HOME/.android/debug.keystore" ]; then
  ODCISK_DEBUG=$(keytool -list -v -keystore "$HOME/.android/debug.keystore" -storepass android \
    -alias androiddebugkey 2> /dev/null | sed -n 's/^[[:space:]]*SHA256: //p' | tr -d ':' | tr 'A-F' 'a-f')
fi
PODPIS=inny
if [ -n "$OCZEKIWANY" ] && [ "$ODCISK" = "$OCZEKIWANY" ]; then PODPIS=wydanie
elif [ "$ODCISK" = "$ODCISK_DEBUG" ]; then PODPIS=debug
fi

PAKIET=$(sed -n "s/^package: name='\([^']*\)'.*/\1/p" "$T/badging.txt")
WERSJA=$(sed -n "s/^package: .* versionCode='\([^']*\)' versionName='\([^']*\)'.*/\2+\1/p" "$T/badging.txt")
TARGET_SDK=$(sed -n "s/^targetSdkVersion:'\([^']*\)'/\1/p" "$T/badging.txt")
WERYFIKACJA=$(tak '^Verifies$' "$T/podpis.txt")
SCHEMAT_V2_V3=$(tak '^Verified using v[23] scheme .*: true$' "$T/podpis.txt")
DEBUGGABLE=$(tak '^application-debuggable$' "$T/badging.txt")
KERNEL_BLOB=$(tak '^assets/flutter_assets/kernel_blob\.bin$' "$T/pliki.txt")
LIBAPP=$(tak '^lib/arm64-v8a/libapp\.so$' "$T/pliki.txt")
IKONA=$(tak 'drawable/ic_notification' "$T/zasoby.txt")
ROZMIAR_MB=$(( $(wc -c < "$APK") / 1048576 ))

echo "APK=$APK"
echo "ROZMIAR_MB=$ROZMIAR_MB"
echo "PAKIET=$PAKIET"
echo "WERSJA=$WERSJA"
echo "TARGET_SDK=$TARGET_SDK"
echo "WERYFIKACJA=$WERYFIKACJA"
echo "SCHEMAT_V2_V3=$SCHEMAT_V2_V3"
echo "ODCISK=$ODCISK"
echo "ODCISK_DEBUG=$ODCISK_DEBUG"
echo "PODPIS=$PODPIS"
echo "DEBUGGABLE=$DEBUGGABLE"
echo "KERNEL_BLOB=$KERNEL_BLOB"
echo "LIBAPP=$LIBAPP"
echo "IKONA_POWIADOMIEN=$IKONA"

ZLE=''
[ "$WERYFIKACJA" = 1 ] && [ "$SCHEMAT_V2_V3" = 1 ] || ZLE="$ZLE podpis-niepoprawny"
[ -z "$OCZEKIWANY" ] || [ "$PODPIS" = wydanie ] || ZLE="$ZLE inny-certyfikat"
[ "$DEBUGGABLE" = 0 ] || ZLE="$ZLE debuggable"
[ "$KERNEL_BLOB" = 0 ] || ZLE="$ZLE kernel_blob"
[ "$LIBAPP" = 1 ] || ZLE="$ZLE brak-libapp"
[ "$PAKIET" = pl.silinski.cinema ] || ZLE="$ZLE pakiet"
[ "$IKONA" = 1 ] || ZLE="$ZLE ikona-powiadomien"
if [ -z "$OCZEKIWANY" ] && [ "$PODPIS" = debug ]; then
  echo "UWAGA=APK podpisany kluczem debug (bez podpisu wydania) — do prób, nie do dystrybucji"
fi
if [ -n "$ZLE" ]; then
  echo "WERDYKT=ZLE:$ZLE"
  exit 1
fi
echo "WERDYKT=OK"
