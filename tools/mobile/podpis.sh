#!/usr/bin/env bash
# Klucz podpisu wydania APK (Etap 10, blok G, decyzja 425) — zawsze POZA repozytorium.
#
#   bash tools/mobile/podpis.sh nowy      magazyn PKCS12 i podpis.properties w ~/.kino-podpis;
#                                         hasło wpisujesz sam (dwa razy, nie pojawia się na ekranie)
#   bash tools/mobile/podpis.sh odcisk    odcisk SHA-256 certyfikatu — dane publiczne, każdy może
#                                         go odczytać z APK; po nim poznasz, czym podpisano APK
#   bash tools/mobile/podpis.sh schowek   magazyn w base64 do schowka Windows (sekret GitHuba
#                                         KINO_PODPIS_MAGAZYN_BASE64) — bez wypisywania na ekran
#   bash tools/mobile/podpis.sh ci        tylko w GitHub Actions: katalog podpisu z sekretów
#                                         w $RUNNER_TEMP; bez sekretów — adnotacja i klucz debug
#
# Katalog: KINO_PODPIS_KATALOG (domyślnie ~/.kino-podpis) — ten sam, który tools/flutter/flutter.sh
# montuje przy "build". keytool działa w przypiętym obrazie Fluttera (w WSL nie ma Javy).
#
# Dlaczego jedno hasło: keytool zakłada magazyn PKCS12, a ten nie obsługuje osobnego hasła klucza
# (keytool ignoruje -keypass z ostrzeżeniem). Hasło przekazujemy do kontenera zmienną środowiskową
# (-storepass:env), więc nie widać go w liście procesów ani w historii powłoki.
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
KATALOG="${KINO_PODPIS_KATALOG:-$HOME/.kino-podpis}"
MAGAZYN=kino-wydanie.jks
WLASCIWOSCI=podpis.properties

stop() { echo "podpis.sh: STOP — $*" >&2; exit 1; }

obraz() {
  sh "$ROOT/tools/flutter/flutter.sh" --przygotuj >&2
  sh "$ROOT/tools/flutter/flutter.sh" --obraz
}

# keytool w kontenerze; katalog podpisu jako /podpis (do zapisu tylko przy "nowy").
keytool_k() {
  local tryb="$1"; shift
  docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -e KINO_PODPIS_HASLO \
    -v "$KATALOG:/podpis:$tryb" --entrypoint keytool "$(obraz)" "$@"
}

wartosc() { sed -n "s/^$1=//p" "$KATALOG/$WLASCIWOSCI" | head -1; }

# Hasło trafia do pliku .properties (Java czyta go jako ISO-8859-1, a "\" jest tam znakiem
# ucieczki) — więc tylko drukowalne ASCII bez "\" i bez spacji na brzegach; min. 12 znaków.
sprawdz_haslo() {
  local h="$1" LC_ALL=C
  [ "${#h}" -ge 12 ] || stop "hasło krótsze niż 12 znaków"
  case "$h" in *\\*) stop "hasło nie może zawierać znaku \\" ;; esac
  case "$h" in *[![:print:]]*) stop "hasło: tylko drukowalne znaki ASCII (bez polskich liter)" ;; esac
  case "$h" in " "*|*" ") stop "hasło nie może zaczynać się ani kończyć spacją" ;; esac
}

odcisk() {
  [ -f "$KATALOG/$WLASCIWOSCI" ] || stop "brak $KATALOG/$WLASCIWOSCI (najpierw: bash tools/mobile/podpis.sh nowy)"
  local wynik
  wynik=$(KINO_PODPIS_HASLO="$(wartosc haslo)" keytool_k ro -list -v -keystore "/podpis/$(wartosc magazyn)" \
    -storepass:env KINO_PODPIS_HASLO -alias "$(wartosc alias)" 2>&1) || stop "keytool -list: $(printf '%s' "$wynik" | grep -i 'error' | head -1)"
  printf '%s\n' "$wynik" | sed -n 's/^[[:space:]]*SHA256: //p' | tr -d ':' | tr 'A-F' 'a-f' | grep -xE '[0-9a-f]{64}' \
    || stop "keytool nie wypisał odcisku SHA256"
}

nowy() {
  [ ! -e "$KATALOG/$MAGAZYN" ] || stop "$KATALOG/$MAGAZYN już istnieje — klucza wydania nie nadpisuję (utrata klucza = brak aktualizacji aplikacji)"
  mkdir -p "$KATALOG"
  chmod 700 "$KATALOG"
  local alias="${KINO_PODPIS_ALIAS:-kino}" powtorz
  if [ -z "${KINO_PODPIS_HASLO:-}" ]; then
    read -rsp "Hasło magazynu kluczy (min. 12 znaków ASCII): " KINO_PODPIS_HASLO; echo >&2
    read -rsp "Powtórz hasło: " powtorz; echo >&2
    [ "$KINO_PODPIS_HASLO" = "$powtorz" ] || stop "hasła się różnią"
  fi
  sprawdz_haslo "$KINO_PODPIS_HASLO"
  export KINO_PODPIS_HASLO
  # Ważność 10000 dni (ok. 27 lat): sklep Google Play wymaga certyfikatu ważnego po 2033 roku.
  # W nazwie certyfikatu tylko nazwa aplikacji — certyfikat jest publiczny (każdy odczyta go z APK).
  local wynik
  wynik=$(keytool_k rw -genkeypair -keystore "/podpis/$MAGAZYN" -storetype PKCS12 -keyalg RSA -keysize 4096 \
    -validity 10000 -alias "$alias" -dname "CN=Kino, C=PL" \
    -storepass:env KINO_PODPIS_HASLO -keypass:env KINO_PODPIS_HASLO 2>&1) \
    || stop "keytool -genkeypair: $(printf '%s' "$wynik" | grep -i 'error' | head -1)"
  (umask 077; printf 'magazyn=%s\nalias=%s\nhaslo=%s\n' "$MAGAZYN" "$alias" "$KINO_PODPIS_HASLO" > "$KATALOG/$WLASCIWOSCI")
  chmod 600 "$KATALOG/$MAGAZYN" "$KATALOG/$WLASCIWOSCI"
  echo "podpis.sh: klucz wydania w $KATALOG ($MAGAZYN, $WLASCIWOSCI; prawa 600). Zrób kopię zapasową" >&2
  echo "podpis.sh: tego katalogu poza komputerem — bez niego nie wydasz aktualizacji aplikacji." >&2
  echo "podpis.sh: odcisk SHA-256 certyfikatu:" >&2
  odcisk
}

schowek() {
  [ -f "$KATALOG/$MAGAZYN" ] || stop "brak $KATALOG/$MAGAZYN"
  command -v clip.exe > /dev/null || stop "brak clip.exe (polecenie dla WSL na Windowsie)"
  base64 -w0 "$KATALOG/$MAGAZYN" | clip.exe
  echo "podpis.sh: magazyn w base64 jest w schowku — wklej jako sekret KINO_PODPIS_MAGAZYN_BASE64." >&2
  echo "podpis.sh: KINO_PODPIS_ALIAS i KINO_PODPIS_HASLO wpisz w GitHubie sam." >&2
}

ci() {
  : "${RUNNER_TEMP:?tylko w GitHub Actions}" "${GITHUB_ENV:?tylko w GitHub Actions}"
  if [ -z "${KINO_PODPIS_MAGAZYN_BASE64:-}" ] || [ -z "${KINO_PODPIS_HASLO:-}" ] || [ -z "${KINO_PODPIS_ALIAS:-}" ]; then
    echo "::notice title=APK z kluczem debug::brak sekretów KINO_PODPIS_MAGAZYN_BASE64, KINO_PODPIS_ALIAS, KINO_PODPIS_HASLO — APK wydania podpisany kluczem debug (do prób, nie do dystrybucji)."
    { echo "KINO_PODPIS_KATALOG="; echo "KINO_ODCISK="; } >> "$GITHUB_ENV"
    return 0
  fi
  sprawdz_haslo "$KINO_PODPIS_HASLO"
  KATALOG="$RUNNER_TEMP/kino-podpis"
  mkdir -p "$KATALOG"
  chmod 700 "$KATALOG"
  (umask 077
   printf '%s' "$KINO_PODPIS_MAGAZYN_BASE64" | base64 -d > "$KATALOG/$MAGAZYN"
   printf 'magazyn=%s\nalias=%s\nhaslo=%s\n' "$MAGAZYN" "$KINO_PODPIS_ALIAS" "$KINO_PODPIS_HASLO" > "$KATALOG/$WLASCIWOSCI")
  local o
  o=$(odcisk)
  { echo "KINO_PODPIS_KATALOG=$KATALOG"; echo "KINO_ODCISK=$o"; } >> "$GITHUB_ENV"
  echo "podpis.sh: podpis wydania z sekretów, odcisk certyfikatu $o"
}

case "${1:-}" in
  nowy) nowy ;;
  odcisk) odcisk ;;
  schowek) schowek ;;
  ci) ci ;;
  *) stop "użycie: bash tools/mobile/podpis.sh nowy|odcisk|schowek|ci" ;;
esac
