#!/usr/bin/env bash
# Sonda WebSocket jednym poleceniem (Etap 10, blok B2; zapowiedź z Etapu 6).
#
# Uruchomienie z katalogu głównego repozytorium, przy działającym stosie (docker compose up):
#   bash tools/realtime-probe/run.sh
# Wynik: raport sondy i kod wyjścia 0 tylko przy komplecie PASS. Ten sam skrypt uruchamia CI.
#
# Przebieg (sonda sama sygnalizuje etapy na standardowym wyjściu):
#   1. sonda.php prepare w kontenerze php: seans w sprzedaży, rezerwacja techniczna, tokeny Sanctum
#      -> plik JSON z prawami 600 w katalogu tymczasowym; tokeny nigdy nie trafiają na ekran,
#   2. wolne miejsce z planu sali (REST, tak jak widzi je klient),
#   3. probe.mjs (pusher-js) w przypiętym kontenerze Node, w sieci stosu, przez nginx /app/,
#   4. "PROBE READY"       -> blokada miejsca przez API (curl, X-Session-Id),
#   5. "PROBE RECONNECTED" -> zwolnienie miejsca przez API i sonda.php event (zdarzenie rezerwacji),
#   6. sprzątanie ZAWSZE (trap): sonda.php cleanup usuwa rezerwację i tokeny, katalog tymczasowy znika.
set -euo pipefail

# Ten sam obraz co tools/frontend/npm.sh i wykonawca paczek (pułapka W: tag bez digestu się zmienia).
NODE_IMAGE='node:22-alpine@sha256:c610fcdfb1d5b4740dd70c284ed3cb16bb857e0f7166196e36a5501df7a3aa32'
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
DIR="$ROOT/tools/realtime-probe"
API=${PROBE_API:-http://localhost:8080/api/v1}
LIMIT=${PROBE_LIMIT:-90}
# Kontrola ujemna (test dymny bloku B2): PROBE_BEZ_KROKOW=1 pomija blokadę i zdarzenie — sonda MUSI
# wtedy skończyć się FAIL i kodem 1. Inaczej zielony wynik nie odróżniałby działania od ślepoty.
BEZ_KROKOW=${PROBE_BEZ_KROKOW:-0}
cd "$ROOT"

for narzedzie in docker curl jq openssl; do
  command -v "$narzedzie" > /dev/null || { echo "STOP: brak $narzedzie" >&2; exit 2; }
done
[ "$(curl -s -o /dev/null -w '%{http_code}' "${API%/api/v1}/up")" = 200 ] \
  || { echo "STOP: aplikacja nie odpowiada na ${API%/api/v1}/up (docker compose up -d)" >&2; exit 2; }

TMP=$(mktemp -d)
chmod 700 "$TMP"
LOG="$TMP/sonda.log"
KONTENER="cinema_sonda_$$"
php_sonda() { docker compose exec -T php php -- "$@" < "$DIR/sonda.php"; }
posprzataj() {
  docker rm -f "$KONTENER" > /dev/null 2>&1 || true
  php_sonda cleanup || echo "UWAGA: sprzątanie danych sondy nie powiodło się (uruchom: docker compose exec -T php php -- cleanup < tools/realtime-probe/sonda.php)" >&2
  rm -rf -- "$TMP"
}
trap posprzataj EXIT

# 1. Dane i tokeny — prosto do pliku.
php_sonda prepare > "$TMP/probe.json"
chmod 600 "$TMP/probe.json"   # kontener Node działa jako ten sam uid
SCREENING=$(jq -r .screening_id "$TMP/probe.json")
BOOKING=$(jq -r .booking_id "$TMP/probe.json")

# 2. Wolne miejsce według API.
SEAT=$(curl -sf -H 'Accept: application/json' "$API/screenings/$SCREENING/seat-map" \
  | jq -r '[.data.seats[] | select(.status == "free") | .id][0] // empty')
[ -n "$SEAT" ] || { echo "STOP: brak wolnego miejsca na seansie $SCREENING" >&2; exit 1; }
echo "sonda: seans $SCREENING, miejsce $SEAT, rezerwacja techniczna $BOOKING"

# Klucz publiczny Reverba (jawny, trafia do każdego klienta) i sieć, w której stoi nginx.
KEY=$(grep -E '^REVERB_APP_KEY=' backend/.env | tail -1 | cut -d= -f2- | tr -d '"')
[ -n "$KEY" ] || { echo "STOP: brak REVERB_APP_KEY w backend/.env" >&2; exit 2; }
SIEC=$(docker inspect "$(docker compose ps -q nginx)" --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' | awk '{print $1}')

# 3. Sonda w tle. node_modules w katalogu sondy (ignorowany przez gita), instalacja z package-lock.json.
docker run -d --name "$KONTENER" --network "$SIEC" --user "$(id -u):$(id -g)" -e HOME=/tmp \
  -e REVERB_APP_KEY="$KEY" -e SEAT_ID="$SEAT" -e PROBE_FILE=/secrets/probe.json \
  -v "$TMP":/secrets:ro -v "$DIR":/probe -w /probe "$NODE_IMAGE" \
  sh -c 'npm ci --ignore-scripts --no-fund --no-audit --no-update-notifier > /tmp/npm.log 2>&1 || { cat /tmp/npm.log; exit 3; }; exec node probe.mjs' > /dev/null

czekaj() {  # czekaj <znacznik> -> 0 gdy sonda go wypisała, 1 gdy skończyła się wcześniej albo minął limit
  local i
  for i in $(seq 1 "$LIMIT"); do
    docker logs "$KONTENER" > "$LOG" 2>&1 || true
    grep -qx "$1" "$LOG" && return 0
    [ "$(docker inspect -f '{{.State.Running}}' "$KONTENER" 2>/dev/null)" = true ] || return 1
    sleep 1
  done
  return 1
}

# Identyfikator sesji zakupowej: 32 znaki [A-Za-z0-9] (ResolveBookingSession). Token właściciela
# w pliku nagłówków (curl -H @plik), a nie w argumentach — te widać w liście procesów.
SESJA=$(openssl rand -hex 16)
printf 'Authorization: Bearer %s\n' "$(jq -r .tokens.owner "$TMP/probe.json")" > "$TMP/auth.h"
lock() { curl -s -o /dev/null -w '%{http_code}' -X "$1" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H @"$TMP/auth.h" -H "X-Session-Id: $SESJA" "$API/screenings/$SCREENING/seat-locks$2" ${3:+-d "$3"}; }

# 4-5. Kroki powłoki w momentach wskazanych przez sondę.
if [ "$BEZ_KROKOW" = 1 ]; then
  echo "sonda: PROBE_BEZ_KROKOW=1 — bez blokady i zdarzenia (kontrola ujemna)"
elif czekaj 'PROBE READY'; then
  echo "sonda: blokada miejsca $SEAT -> HTTP $(lock POST '' "{\"seat_ids\":[$SEAT]}")"
  if czekaj 'PROBE RECONNECTED'; then
    echo "sonda: zwolnienie miejsca $SEAT -> HTTP $(lock DELETE "/$SEAT")"
    php_sonda event "$BOOKING" || true
  fi
fi

KOD=$(timeout 180 docker wait "$KONTENER" 2>/dev/null || echo 1)
docker logs "$KONTENER" > "$LOG" 2>&1 || true
cat "$LOG"
exit "$KOD"
