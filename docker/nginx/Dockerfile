# Obraz nginx stosu deweloperskiego (Etap 10, blok E).
#
# Konfiguracja jest w obrazie, a nie montowana jako pojedyncze pliki: montaż pliku z WSL to miejsce,
# w którym start kontenera kończył się kodem 127 po restarcie Dockera (pułapka ET). Po zmianie
# default.conf, cinema.conf albo tls.sh:  docker compose up -d --build --force-recreate nginx
#
# openssl — tylko dla tls.sh (samopodpisany certyfikat, gdy w docker/nginx/certs/ nie ma własnego).
FROM nginx:1.27-alpine@sha256:65645c7bb6a0661892a8b03b89d0743208a18dd2f3f17a54ef4b76fb8e2f2a10 AS dev

RUN apk add --no-cache openssl
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/cinema.conf /etc/nginx/cinema/app.conf
COPY --chmod=0755 docker/nginx/tls.sh /docker-entrypoint.d/05-cinema-tls.sh
