#!/bin/sh
# Certyfikat TLS dla nginx stosu deweloperskiego (Etap 10, blok E).
# W obrazie: /docker-entrypoint.d/05-cinema-tls.sh — oficjalny entrypoint nginx wykonuje go przed
# startem serwera (także przy "nginx -t" w kontenerze jednorazowym).
#
# Kolejność:
#   1. docker/nginx/certs/cert.pem + key.pem (montowane tylko do odczytu jako /etc/nginx/certs-host)
#      — własny certyfikat, np. z mkcert, którego CA przeglądarka ufa: wtedy działa service worker
#      i Web Push także pod adresem innym niż localhost (README, Etap 10),
#   2. inaczej samopodpisany, wygenerowany raz do wolumenu /etc/nginx/tls (przetrwa odtworzenie
#      kontenera): localhost, 127.0.0.1 i adresy z CINEMA_TLS_HOSTS (po przecinku).
#
# Nic nie trafia do katalogu repozytorium: kopia własnego certyfikatu i samopodpisany leżą w
# wolumenie. Klucz prywatny ma prawa 600 i nie jest nigdy wypisywany.
set -eu

TLS=/etc/nginx/tls
HOST=/etc/nginx/certs-host
ME="05-cinema-tls"
mkdir -p "$TLS"

if [ -f "$HOST/cert.pem" ] && [ -f "$HOST/key.pem" ]; then
  cp "$HOST/cert.pem" "$TLS/cert.pem"
  cp "$HOST/key.pem" "$TLS/key.pem"
  echo "$ME: certyfikat z docker/nginx/certs/"
elif [ ! -s "$TLS/cert.pem" ] || [ ! -s "$TLS/key.pem" ] || [ -f "$TLS/.z-certs-host" ]; then
  SAN="DNS:localhost,IP:127.0.0.1"
  for h in $(printf '%s' "${CINEMA_TLS_HOSTS:-}" | tr ',' ' '); do
    case "$h" in
      *[!0-9.]*) SAN="$SAN,DNS:$h" ;;
      *) SAN="$SAN,IP:$h" ;;
    esac
  done
  openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 397 \
    -subj "/CN=cinema-dev" -addext "subjectAltName=$SAN" \
    -addext "extendedKeyUsage=serverAuth" \
    -keyout "$TLS/key.pem" -out "$TLS/cert.pem" 2> /dev/null
  rm -f "$TLS/.z-certs-host"
  echo "$ME: wygenerowano samopodpisany ($SAN)"
else
  echo "$ME: samopodpisany z wolumenu"
fi

# Znacznik pochodzenia: gdy własny certyfikat zniknie z docker/nginx/certs/, następny start
# wygeneruje samopodpisany, zamiast używać po cichu kopii, której właściciel już nie chce.
if [ -f "$HOST/cert.pem" ] && [ -f "$HOST/key.pem" ]; then : > "$TLS/.z-certs-host"; fi
chmod 600 "$TLS/key.pem"
echo "$ME: $(openssl x509 -in "$TLS/cert.pem" -noout -enddate | sed 's/notAfter=/ważny do /')"
