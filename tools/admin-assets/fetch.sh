#!/bin/sh
# Zasoby statyczne panelu administracyjnego (Etap 7, blok B2).
#
# Uruchomienie z katalogu głównego repozytorium:  sh tools/admin-assets/fetch.sh
# Pierwsze uruchomienie (brak package-lock.json):  sh tools/admin-assets/fetch.sh --init-lock
#
# Na hoście skrypt tylko uruchamia SIEBIE w kontenerze Node (w WSL nie ma Node).
# Obraz przypięty po digeście: pływający tag node:22-alpine pobrał się na nowo
# w rozpoznaniu Etapu 7 (pułapka W). --user: pliki należą do użytkownika WSL.
# npm ci instaluje DOKŁADNIE wersje z package-lock.json i sprawdza sumy integrity.
set -eu

NODE_IMAGE='node:22-alpine@sha256:c610fcdfb1d5b4740dd70c284ed3cb16bb857e0f7166196e36a5501df7a3aa32'

if [ "${ADMIN_ASSETS_IN_CONTAINER:-}" != 1 ]; then
    ROOT=$(cd "$(dirname "$0")/../.." && pwd)
    exec docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -e ADMIN_ASSETS_IN_CONTAINER=1 \
        -v "$ROOT":/work -w /work/tools/admin-assets "$NODE_IMAGE" sh fetch.sh "$@"
fi

if [ ! -f package-lock.json ]; then
    [ "${1:-}" = --init-lock ] || { echo "STOP: brak package-lock.json (uruchom z --init-lock)" >&2; exit 1; }
    npm install --package-lock-only --ignore-scripts --no-audit --no-fund --no-update-notifier
fi

npm ci --ignore-scripts --no-audit --no-fund --no-update-notifier

DEST=/work/backend/public/vendor/admin
mkdir -p "$DEST"
cp node_modules/@picocss/pico/css/pico.min.css "$DEST/pico.min.css"
cp node_modules/laravel-echo/dist/echo.iife.js "$DEST/echo.iife.js"
cp node_modules/pusher-js/dist/web/pusher.min.js "$DEST/pusher.min.js"

# Teksty licencji, jeśli pakiet je dołącza (pusher-js nie ma pliku LICENSE),
# oraz spis wersji i licencji z package.json każdego pakietu.
for pkg in @picocss/pico laravel-echo pusher-js; do
    lic=$(find "node_modules/$pkg" -maxdepth 1 -iname 'license*' | head -n 1)
    if [ -n "$lic" ]; then cp "$lic" "$DEST/LICENSE.$(basename "$pkg")"; fi
done
node -e 'for (const p of ["@picocss/pico", "laravel-echo", "pusher-js"]) {
    const j = require("./node_modules/" + p + "/package.json");
    console.log(j.name + "@" + j.version + " (licencja: " + j.license + ")");
}' > "$DEST/LICENSES.txt"

cd "$DEST"
sha256sum pico.min.css echo.iife.js pusher.min.js > SHA256SUMS
cat LICENSES.txt SHA256SUMS
