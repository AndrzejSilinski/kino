#!/bin/sh
# Entrypoint obrazu PHP (Etap 10, blok D): kroki, które README od Etapu 2 kazało wykonać ręcznie
# po "docker compose up", wykonują się przy starcie kontenera.
#
# Jeden obraz, kilka ról — rolę wybierają zmienne środowiskowe usługi w docker-compose.yml:
#   CINEMA_SETUP=1           tylko php: przygotowanie (dev: .env, klucze, composer, storage),
#                            migracje, dane demonstracyjne, sygnał restartu dla workerów i Reverba;
#                            na końcu znacznik /tmp/cinema-ready, który czyta healthcheck — worker,
#                            scheduler i reverb startują dopiero po nim (depends_on: service_healthy).
#   CINEMA_SEED_IF_EMPTY=1   z CINEMA_SETUP: dane demonstracyjne do pustej bazy (nigdy w produkcji).
#   CINEMA_CHECK_PUSH=1      worker: ostrzeżenie, gdy włączony push nie może przeczytać pliku konta.
#   CINEMA_IMAGE=dev|prod    ustawia Dockerfile; dev = kod z bind mountu, prod = kod w obrazie.
#
# Zasady:
#   - artisan zawsze jako www-data (uid 82), composer i pliki repozytorium jako właściciel katalogu
#     z kodem — nigdy jako root (pułapka ES: katalog roota, do którego aplikacja nie zapisze),
#   - tylko UZUPEŁNIANIE: istniejący .env i niepuste klucze zostają nietknięte,
#   - błąd przerywa start (set -e): kontener z niezmigrowaną bazą nie udaje, że działa.
#
# "docker compose exec" omija entrypoint — polecenia ręczne działają jak dotąd.
# Katalog roboczy wywołującego (docker run -w …, CI, narzędzia) zostaje zachowany: kroki
# przygotowania działają w /var/www/html, ale polecenie końcowe startuje tam, gdzie je uruchomiono
# (pułapka EW).
set -eu
KATALOG_WYWOLANIA="$PWD"
cd /var/www/html

IMAGE="${CINEMA_IMAGE:-prod}"
READY=/tmp/cinema-ready

log() { printf '[cinema-entrypoint] %s\n' "$*" >&2; }

# jako <użytkownik[:grupa]> polecenie... — zmiana użytkownika tylko wtedy, gdy jesteśmy rootem
# (worker, scheduler i reverb działają już jako 82:82 i wykonują polecenia bezpośrednio).
jako() {
  kto="$1"; shift
  if [ "$(id -u)" = 0 ]; then su-exec "$kto" "$@"; else "$@"; fi
}
artisan() { jako www-data php artisan "$@"; }

# Tylko dev: wpisuje wygenerowaną wartość, gdy w .env klucz istnieje i jest PUSTY.
# Wartości: cyfry, hex i base64 — bez znaku "|", więc sed z tym separatorem jest bezpieczny.
uzupelnij() {
  if grep -qE "^$1=\$" .env; then
    jako "$WLASCICIEL" sed -i "s|^$1=\$|$1=$2|" .env
    log "wygenerowano $1 w backend/.env"
  fi
}
losowe() { php -r "$1"; }

przygotuj_dev() {
  WLASCICIEL="$(stat -c %u:%g .)"
  if [ ! -f .env ]; then
    jako "$WLASCICIEL" cp .env.example .env
    log "utworzono backend/.env z .env.example (klucze Stripe'a uzupełnij ręcznie)"
  fi
  uzupelnij APP_KEY "$(losowe 'echo "base64:".base64_encode(random_bytes(32));')"
  uzupelnij TICKET_QR_KEY "$(losowe 'echo bin2hex(random_bytes(32));')"
  uzupelnij REVERB_APP_ID "$(losowe 'echo random_int(100000, 999999);')"
  uzupelnij REVERB_APP_KEY "$(losowe 'echo bin2hex(random_bytes(10));')"
  uzupelnij REVERB_APP_SECRET "$(losowe 'echo bin2hex(random_bytes(20));')"

  # Zależności: przy pierwszym starcie i po zmianie composer.lock (git pull, zmiana gałęzi).
  if [ ! -f vendor/autoload.php ] || [ composer.lock -nt vendor/composer/installed.json ]; then
    log "composer install (pierwszy start albo nowszy composer.lock)"
    jako "$WLASCICIEL" env HOME=/tmp COMPOSER_HOME=/tmp/composer composer install --no-interaction --no-progress
  fi

  # Katalogi zapisu: worker, scheduler i PHP-FPM działają jako www-data, artisan z WSL jako
  # użytkownik WSL — katalogi muszą być zapisywalne dla obu (README, "Uruchomienie od zera").
  # Tylko KATALOGI bez prawa zapisu dla innych: pliki i katalogi już poprawne zostają nietknięte.
  for d in storage/app/private/tickets storage/app/public storage/fonts storage/framework/cache/data \
           storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; do
    [ -d "$d" ] || jako "$WLASCICIEL" mkdir -p "$d"
  done
  find storage bootstrap/cache -type d ! -perm -0007 -exec chmod a+rwx {} +
  [ -L public/storage ] || { jako "$WLASCICIEL" ln -s ../storage/app/public public/storage; log "utworzono public/storage"; }
}

if [ "$IMAGE" = prod ]; then
  # W produkcji konfiguracja przychodzi ze środowiska, a nie z pliku .env, którego w obrazie nie ma.
  [ -n "${APP_KEY:-}" ] || { log "STOP: brak APP_KEY w środowisku kontenera (obraz prod nie czyta .env)"; exit 1; }
  # storage/ to w produkcji wolumen (docker/prod/compose.yml). Jego zawartość i właściciel nie zależą
  # od obrazu: podkatalog dla nginx (subpath app/public) Docker zakłada jako root, zanim kontener php
  # skopiuje do pustego wolumenu katalogi z obrazu (pułapka FB). Kontener przygotowania (root) sam
  # zakłada brakujące katalogi i oddaje www-data te, które do niego nie należą — tylko KATALOGI,
  # płytko, bez przechodzenia po tysiącach biletów PDF.
  if [ "${CINEMA_SETUP:-0}" = 1 ] && [ "$(id -u)" = 0 ]; then
    for d in storage/app/private/tickets storage/app/public storage/fonts storage/framework/cache/data \
             storage/framework/sessions storage/framework/views storage/logs; do
      mkdir -p "$d"
    done
    find storage -maxdepth 3 -type d ! -user www-data -exec chown www-data:www-data {} +
  fi
  # Każdy kontener ma własny system plików obrazu, więc każdy buduje własny cache konfiguracji,
  # tras, widoków i zdarzeń — ze swoich zmiennych środowiskowych.
  artisan optimize > /dev/null
fi

if [ "${CINEMA_SETUP:-0}" = 1 ]; then
  rm -f "$READY"   # znacznik z poprzedniego uruchomienia tego samego kontenera
  [ "$IMAGE" = prod ] || przygotuj_dev
  if [ "${CINEMA_SEED_IF_EMPTY:-0}" = 1 ]; then
    artisan cinema:boot --migrate --seed-if-empty
  else
    artisan cinema:boot --migrate
  fi
  # Procesy z poprzedniej wersji kodu kończą bieżącą pracę i wstają od nowa (restart: unless-stopped).
  artisan queue:restart > /dev/null
  artisan reverb:restart > /dev/null
  touch "$READY"
  log "gotowe"
fi

if [ "${CINEMA_CHECK_PUSH:-0}" = 1 ]; then
  artisan cinema:boot --check-push
fi

# docker-php-entrypoint z obrazu bazowego: argumenty zaczynające się od "-" przekazuje php-fpm.
cd "$KATALOG_WYWOLANIA"
exec docker-php-entrypoint "$@"
