FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        $PHPIZE_DEPS \
        unzip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install \
        intl \
        pcntl \
        pdo_pgsql \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/development-entrypoint.sh /usr/local/bin/katra-server-entrypoint

RUN chmod +x /usr/local/bin/katra-server-entrypoint

WORKDIR /app

EXPOSE 8000

ENTRYPOINT ["katra-server-entrypoint"]
