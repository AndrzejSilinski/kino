#!/usr/bin/env bash
# Wdrożenie na serwerze (Etap 10, blok F2) — wywołuje je CI przez SSH po zielonych testach
# (.github/workflows/ci.yml, zadania deploy-dev i deploy-prod), można też ręcznie.
#
#   bash tools/deploy/deploy.sh dev|prod        z katalogu głównego klonu repozytorium na serwerze
#
# Kroki z zadania (5.3) w tym stosie:
#   git pull                   — robi go polecenie SSH z CI tuż przed tym skryptem (skrypt zawsze z nowej rewizji)
#   composer install --no-dev  — etap "vendor" obrazu docker/php/Dockerfile przy budowie poniżej
#   [prod] artisan down        — przed migracją, na DZIAŁAJĄCEJ jeszcze starej wersji
#   migrate --force            — entrypoint nowego kontenera php (cinema:boot --migrate, z blokadą)
#   cache:clear                — po starcie nowej wersji (cache konfiguracji i tras buduje entrypoint: optimize)
#   restart workerów           — nowe kontenery worker/scheduler/reverb + queue:restart i reverb:restart z entrypointu
#   [prod] artisan up          — po migracji i gotowości wszystkich usług
#
# Zmienne (opcjonalne): CINEMA_TAG (domyślnie skrót bieżącego commita) — tag obrazów kino-php i kino-nginx.
set -euo pipefail

TRYB="${1:?podaj tryb: dev albo prod}"
case "$TRYB" in dev|prod) ;; *) echo "deploy.sh: nieznany tryb $TRYB (dev albo prod)" >&2; exit 2 ;; esac
cd "$(dirname "$0")/../.."

[ -f docker/prod/prod.env ] || { echo "deploy.sh: STOP — brak docker/prod/prod.env (wzór: docker/prod/prod.env.example)" >&2; exit 1; }
[ -f docker/prod/certs/cert.pem ] && [ -f docker/prod/certs/key.pem ] \
  || { echo "deploy.sh: STOP — brak certyfikatu TLS w docker/prod/certs/ (cert.pem, key.pem)" >&2; exit 1; }

CINEMA_TAG="${CINEMA_TAG:-$(git rev-parse --short HEAD)}"
export CINEMA_TAG
dc() { docker compose --env-file docker/prod/prod.env -f docker/prod/compose.yml "$@"; }
artisan() { dc exec -T -u www-data php php artisan "$@"; }
krok() { printf '\n[deploy %s %s] %s\n' "$TRYB" "$(date -u +%H:%M:%S)" "$*"; }

krok "obrazy kino-php:$CINEMA_TAG i kino-nginx:$CINEMA_TAG (composer install --no-dev, build SPA)"
dc build php nginx

DZIALA=0
if [ -n "$(dc ps --status running -q php 2> /dev/null)" ]; then DZIALA=1; fi

if [ "$TRYB" = prod ] && [ "$DZIALA" = 1 ]; then
  krok "artisan down (stara wersja, przed migracją)"
  artisan down --retry=15 --refresh=15
fi

# Gdyby cokolwiek dalej zawiodło w trybie prod, serwis zostaje w trybie konserwacji — to celowe:
# lepiej 503 z "Retry-After" niż nowy kod na niezmigrowanej bazie. Wyjście: napraw i uruchom ponownie.
krok "nowa wersja: migracje, optimize, restart workerów (entrypoint), czekam na gotowość"
dc up -d --remove-orphans --wait --wait-timeout 600

krok "cache:clear"
artisan cache:clear

if [ "$TRYB" = prod ]; then
  krok "artisan up"
  artisan up
fi

krok "gotowe: $(dc ps --format '{{.Service}} {{.Status}}' | tr '\n' ';')"
