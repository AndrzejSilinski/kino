# Obrazy nginx (Etap 10): jeden plik, etapy dev (blok E), spa i prod (blok F2).
#
#   dev   stos deweloperski: konfiguracja w obrazie, kod i frontend/dist z bind mountów, TLS własny
#         albo samopodpisany; bez HSTS.
#   spa   etap budowania: npm ci (dokładnie z package-lock.json, bez skryptów) i vite build.
#   prod  serwer: SPA i statyczne pliki backend/public w obrazie, HTTP -> HTTPS, HSTS, certyfikat
#         wyłącznie z serwera (bez samopodpisanego), bez openssl.
#
# Konfiguracja jest w obrazie, a nie montowana jako pojedyncze pliki: montaż pliku z WSL to miejsce,
# w którym start kontenera kończył się kodem 127 po restarcie Dockera (pułapka ET). Po zmianie
# default.conf, cinema.conf albo tls.sh:  docker compose up -d --build --force-recreate nginx
# Budowa prod (kontekst = katalog główny, filtr Dockerfile.dockerignore):
#   docker build -f docker/nginx/Dockerfile --target prod -t kino-nginx .
FROM nginx:1.27-alpine@sha256:65645c7bb6a0661892a8b03b89d0743208a18dd2f3f17a54ef4b76fb8e2f2a10 AS dev

# openssl — tylko dla tls.sh (samopodpisany certyfikat, gdy w docker/nginx/certs/ nie ma własnego).
RUN apk add --no-cache openssl
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/cinema.conf /etc/nginx/cinema/app.conf
COPY docker/nginx/hsts-dev.conf /etc/nginx/cinema/hsts.conf
COPY --chmod=0755 docker/nginx/tls.sh /docker-entrypoint.d/05-cinema-tls.sh

# ---------------------------------------------------------------------------------------------
# Ten sam obraz Node co tools/frontend/npm.sh i CI — jeden build SPA dla każdego środowiska
# (konfiguracja w czasie działania przez GET /api/v1/client-config, decyzja z Etapu 8).
FROM node:22-alpine@sha256:c610fcdfb1d5b4740dd70c284ed3cb16bb857e0f7166196e36a5501df7a3aa32 AS spa

WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --ignore-scripts --no-fund --no-audit --no-update-notifier
COPY frontend/ ./
RUN npm run build && test -f dist/index.html

# ---------------------------------------------------------------------------------------------
FROM nginx:1.27-alpine@sha256:65645c7bb6a0661892a8b03b89d0743208a18dd2f3f17a54ef4b76fb8e2f2a10 AS prod

COPY docker/nginx/prod.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/cinema.conf /etc/nginx/cinema/app.conf
COPY docker/nginx/hsts-prod.conf /etc/nginx/cinema/hsts.conf
COPY --chmod=0755 docker/nginx/tls.sh /docker-entrypoint.d/05-cinema-tls.sh
COPY --from=spa /app/dist /var/www/frontend/dist
# Statyczne pliki panelu (/vendor/, /js/), favicon, robots. PHP-FPM ma własną kopię kodu w obrazie
# kino-php; nginx przekazuje mu tylko ścieżkę skryptu. /storage/ to wolumen z plakatami.
COPY backend/public /var/www/html/public
RUN mkdir -p /var/www/acme /var/www/html/public/storage
ENV CINEMA_TLS_WYMAGANY=1
