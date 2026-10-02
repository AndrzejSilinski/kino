# Obraz aplikacji PHP (Etap 10, blok D): jeden plik, cztery etapy.
#
#   base    PHP-FPM z rozszerzeniami i TYLKO bibliotekami uruchomieniowymi, entrypoint
#   dev     base + composer, git, unzip i narzędzia testowe (zbar, imagemagick); kod z bind mountu
#   vendor  etap budowania: composer install --no-dev i autoloader klasowy (nie trafia do wyniku)
#   prod    base + kod i vendor wpieczone w obraz, php.ini produkcyjne, OPcache bez sprawdzania plików
#
# Budowa (kontekst = katalog główny repozytorium; filtr: Dockerfile.dockerignore obok tego pliku):
#   docker compose build php                                        # dev, jak w docker-compose.yml
#   docker build -f docker/php/Dockerfile --target prod -t cinema/php:prod .
#
# Obrazy bazowe przypięte po digeście (pułapka W: tag bez digestu zmienia treść pod tą samą nazwą).
# Digest to indeks wieloplatformowy z rozpoznania Etapu 10 (docker buildx imagetools inspect).
# Aktualizacja: nowy digest z imagetools, przebudowa, pełny przebieg testów — świadoma zmiana w gicie.

FROM php:8.4-fpm-alpine@sha256:78cd8de9970a9cd6ff4d98860a94eb5bf37dd2d5776b4785562dbfad01c29d5a AS base

# Rozszerzenia budujemy z pakietami -dev w wirtualnej grupie, którą potem usuwamy w TEJ SAMEJ
# warstwie. Biblioteki potrzebne w czasie działania wylicza scanelf z gotowych plików .so
# (ten sam sposób co oficjalne obrazy PHP) — zamiast ręcznej listy, która rozjedzie się
# z wersją Alpine. Rozpoznanie bloku D: libpq, libzip, libpng, libjpeg-turbo, freetype,
# icu-libs, libgcc, libstdc++ (i biblioteki obecne już w bazie).
#   redis z PECL przypięty do wersji (wcześniej "pecl install redis" = najnowsza w chwili budowy).
#   su-exec — entrypoint wykonuje artisan jako www-data, a composer jako właściciel repozytorium
#   (pułapka ES: polecenie jako root w kontenerze zostawia pliki, do których aplikacja nie zapisze).
ARG PHPREDIS_VERSION=6.3.0
RUN set -eux; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS pax-utils \
        postgresql-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev icu-dev oniguruma-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql bcmath zip gd intl pcntl; \
    pecl install "redis-${PHPREDIS_VERSION}"; \
    docker-php-ext-enable redis; \
    runDeps="$(scanelf --needed --nobanner --format '%n#p' --recursive /usr/local/lib/php/extensions \
        | tr ',' '\n' | sort -u \
        | awk 'system("[ -e /usr/local/lib/" $1 " ]") == 0 { next } { print "so:" $1 }')"; \
    apk add --no-cache --virtual .php-ext-rundeps $runDeps su-exec; \
    apk del --no-network .build-deps; \
    rm -rf /tmp/pear ~/.pearrc; \
    php -m | grep -qx redis; php -m | grep -qx pdo_pgsql; php -m | grep -qx intl

# Limity wysyłania plików (Etap 7, blok F) — w obrazie zamiast montażu pojedynczego pliku.
# Montaż pliku z WSL to jedno z miejsc, w których start kontenera kończył się kodem 127
# (pułapka ET), a konfiguracja PHP i tak należy do obrazu, nie do środowiska.
COPY docker/php/conf.d/uploads.ini /usr/local/etc/php/conf.d/zz-uploads.ini
COPY --chmod=0755 docker/php/entrypoint.sh /usr/local/bin/cinema-entrypoint

WORKDIR /var/www/html
ENTRYPOINT ["cinema-entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------------------------
FROM base AS dev

COPY --from=composer:2@sha256:9715c7f69044da2a212a5fbde29ee7da24e364d426560ae6367b060236f847d7 /usr/bin/composer /usr/bin/composer
# git, unzip — composer install z archiwów i repozytoriów.
# Narzędzia TESTOWE (tylko dev): zbarimg odczytuje kody QR z obrazów — test renderera dekoduje
# bilet z logo; imagemagick, bo zbarimg czyta PNG przez ImageMagick (pakiet zbar ciągnie tylko
# jego biblioteki, bez modułu PNG). W obrazie produkcyjnym ich nie ma (zapowiedź z Etapu 5).
RUN apk add --no-cache git unzip zbar imagemagick
ENV CINEMA_IMAGE=dev

# ---------------------------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer:2@sha256:9715c7f69044da2a212a5fbde29ee7da24e364d426560ae6367b060236f847d7 /usr/bin/composer /usr/bin/composer
WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1
# Najpierw same pliki zależności: warstwa vendor przebudowuje się tylko po zmianie composer.lock.
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader \
    && composer check-platform-reqs --no-dev
COPY backend/ ./
# Autoloader klasowy bez skanowania dysku; package:discover zapisuje bootstrap/cache/packages.php
# z listą dostawców BEZ pakietów deweloperskich (pail, collision) — bez tego aplikacja szukałaby
# klas, których w obrazie nie ma. Discover nie łączy się z bazą i nie potrzebuje .env.
# Testów, .env i danych z storage/ tu nie ma — odfiltrował je Dockerfile.dockerignore.
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && php artisan package:discover \
    && test ! -e .env && test ! -e tests

# ---------------------------------------------------------------------------------------------
FROM base AS prod

RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini
COPY docker/php/prod/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/prod/security.ini /usr/local/etc/php/conf.d/zz-security.ini
COPY docker/php/prod/fpm-env.conf /usr/local/etc/php-fpm.d/zz-env.conf
# Kod należy do roota i jest tylko do odczytu dla procesów aplikacji; zapis tylko do storage/
# i bootstrap/cache (cache konfiguracji z entrypointu). W produkcji storage/ to wolumen.
COPY --from=vendor --chown=root:root /app /var/www/html
RUN set -eux; \
    mkdir -p storage/app/private/tickets storage/app/public storage/fonts \
             storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs; \
    chown -R www-data:www-data storage bootstrap/cache; \
    ln -sfn ../storage/app/public public/storage; \
    test ! -e .env
ENV CINEMA_IMAGE=prod
