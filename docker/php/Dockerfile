FROM php:8.4-fpm-alpine

# System libraries required to build the PHP extensions below.
#   postgresql-dev  -> pdo_pgsql (database driver)
#   libzip / png / jpeg / freetype -> gd + zip (QR codes with logo, PDFs)
#   icu-dev         -> intl (timezones, date formatting)
#   oniguruma-dev   -> mbstring
#   $PHPIZE_DEPS    -> build toolchain, required by pecl (redis)
RUN apk add --no-cache \
        postgresql-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        icu-dev \
        oniguruma-dev \
        git \
        unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_pgsql \
        bcmath \
        zip \
        gd \
        intl \
        pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Composer - PHP dependency manager, copied from the official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Narzędzie TESTOWE: zbarimg odczytuje kody QR z obrazów. Test renderera
# dekoduje wygenerowany bilet z logo, żeby udowodnić, że kod jest czytelny.
# Osobna warstwa na końcu pliku: dodanie jej nie przebudowuje rozszerzeń PHP.
# Etap 10: przeniesienie do etapu "dev" w wieloetapowym buildzie.
# imagemagick: zbarimg czyta obrazy przez ImageMagick, a pakiet zbar ciągnie tylko jego biblioteki, bez modułu PNG.
RUN apk add --no-cache zbar imagemagick
