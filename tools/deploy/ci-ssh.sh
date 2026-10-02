#!/usr/bin/env bash
# Krok wdrożenia w GitHub Actions (Etap 10, blok F2): SSH na serwer DEV albo PROD, git pull i
# tools/deploy/deploy.sh. Dane serwera wyłącznie z sekretów środowiska GitHuba (dev, prod) — w workflow
# są tylko ich nazwy (zadanie 5.3: "w workflow tylko placeholdery, serwery mogą być fikcyjne").
#
#   bash tools/deploy/ci-ssh.sh dev|prod
#
# Sekrety środowiska: DEPLOY_HOST, DEPLOY_USER, DEPLOY_PATH (klon repozytorium na serwerze),
# DEPLOY_SSH_KEY (klucz prywatny tylko do wdrożeń), DEPLOY_KNOWN_HOSTS (wpis ssh-keyscan serwera —
# klucz hosta przypięty, bez StrictHostKeyChecking=no). Bez kompletu: wdrożenie pominięte z adnotacją.
set -euo pipefail

TRYB="${1:?podaj tryb: dev albo prod}"
case "$TRYB" in
  dev) GALAZ=dev ;;
  prod) GALAZ=main ;;
  *) echo "ci-ssh.sh: nieznany tryb $TRYB" >&2; exit 2 ;;
esac
TESTOWANY="${GITHUB_SHA:?brak GITHUB_SHA — skrypt działa w GitHub Actions}"

BRAK=()
for v in DEPLOY_HOST DEPLOY_USER DEPLOY_PATH DEPLOY_SSH_KEY DEPLOY_KNOWN_HOSTS; do
  [ -n "${!v:-}" ] || BRAK+=("$v")
done
if [ "${#BRAK[@]}" -gt 0 ]; then
  echo "::notice title=Wdrożenie $TRYB pominięte::brak sekretów środowiska $TRYB: ${BRAK[*]}. Testy przeszły — z kompletem sekretów ten krok wdroży $TESTOWANY."
  exit 0
fi

umask 077
mkdir -p "$HOME/.ssh"
printf '%s\n' "$DEPLOY_SSH_KEY" > "$HOME/.ssh/deploy"
printf '%s\n' "$DEPLOY_KNOWN_HOSTS" > "$HOME/.ssh/known_hosts_deploy"
trap 'rm -f "$HOME/.ssh/deploy"' EXIT

# Na serwerze: git pull gałęzi, a potem wdrożenie TYLKO jeśli po pull jest dokładnie ta rewizja, którą
# sprawdziło CI. Nowsze wypchnięcie w międzyczasie wdroży jego własny przebieg (kolejka w concurrency).
# shellcheck disable=SC2029  # zmienne celowo rozwijane po stronie CI
ssh -i "$HOME/.ssh/deploy" -o BatchMode=yes -o IdentitiesOnly=yes \
  -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts_deploy" \
  "$DEPLOY_USER@$DEPLOY_HOST" \
  "set -e; cd '$DEPLOY_PATH'; git fetch --prune origin; git checkout -q '$GALAZ'; git pull --ff-only origin '$GALAZ';
   if [ \"\$(git rev-parse HEAD)\" != '$TESTOWANY' ]; then echo 'Na $GALAZ jest już nowsza rewizja — wdroży ją jej własny przebieg CI.'; exit 0; fi;
   bash tools/deploy/deploy.sh '$TRYB'"
