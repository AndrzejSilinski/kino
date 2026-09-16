#!/bin/sh
# npm dla frontend/ w przypiętym kontenerze Node (Etap 8, blok C). W WSL nie ma Node.
#
# Uruchomienie z katalogu głównego repozytorium, np.:
#   sh tools/frontend/npm.sh ci --ignore-scripts      instalacja DOKŁADNIE z package-lock.json
#   sh tools/frontend/npm.sh run build                dist/ serwowany przez nginx pod localhost:8080
#   sh tools/frontend/npm.sh test                     Vitest
#   sh tools/frontend/npm.sh view <pakiet> version    sprawdzenie rejestru przed dodaniem pakietu
#   sh tools/frontend/npm.sh install --save-exact --ignore-scripts <pakiet>@<wersja>
#                                                     ŚWIADOMA zmiana zależności (zasada 31), osobny krok
#
# Obraz przypięty po digeście (pułapka W), ten sam co w tools/admin-assets/fetch.sh.
# --user: pliki (node_modules, dist, package-lock.json) należą do użytkownika WSL (zasada 6).
set -eu

NODE_IMAGE='node:22-alpine@sha256:c610fcdfb1d5b4740dd70c284ed3cb16bb857e0f7166196e36a5501df7a3aa32'
ROOT=$(cd "$(dirname "$0")/../.." && pwd)

exec docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -v "$ROOT/frontend":/app -w /app \
    "$NODE_IMAGE" npm --no-update-notifier --no-fund "$@"
