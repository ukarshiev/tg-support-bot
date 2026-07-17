FROM php:8.3-fpm-bookworm@sha256:2a397791f5ee422190bb673d79332be53ff545205f6df19e2664bd664ebbd739 AS php-base

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

RUN read -r -a phpize_deps <<< "$(printenv PHPIZE_DEPS)" && \
    apt-get update && \
    apt-get install -y --no-install-recommends \
        libfreetype6 libicu72 libjpeg62-turbo libpng16-16 libpq5 libzip4 \
        libfreetype6-dev libicu-dev libjpeg62-turbo-dev libpng-dev libpq-dev libzip-dev "${phpize_deps[@]}" && \
    docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install pdo pdo_pgsql pgsql intl zip gd pcntl && \
    pecl install redis && docker-php-ext-enable redis && \
    apt-get purge -y --auto-remove \
        libfreetype6-dev libicu-dev libjpeg62-turbo-dev libpng-dev libpq-dev libzip-dev "${phpize_deps[@]}" && \
    rm -rf /var/lib/apt/lists/* /tmp/pear

COPY ./docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY ./docker/php-fpm/zz-relaxa-pool.conf /usr/local/etc/php-fpm.d/zz-relaxa-pool.conf

WORKDIR /var/www

FROM php-base AS composer-build

COPY --from=composer:2.8@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c /usr/bin/composer /usr/local/bin/composer
COPY . .
RUN rm -f bootstrap/cache/*.php && \
    composer install --no-dev --no-interaction --prefer-dist \
        --classmap-authoritative --no-progress

FROM node:20-bookworm-slim@sha256:2cf067cfed83d5ea958367df9f966191a942351a2df77d6f0193e162b5febfc0 AS frontend-build

WORKDIR /build
COPY package.json package-lock.json vite.app.config.js ./
COPY resources ./resources
RUN npm ci && npm run build

FROM php-base AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false \
    LARAVEL_GIT_COMMIT=false

COPY . .
COPY --from=composer-build /var/www/vendor ./vendor
COPY --from=frontend-build /build/public/build ./public/build

RUN rm -rf node_modules tests .git .github && \
    rm -f bootstrap/cache/*.php && \
    mkdir -p storage/logs storage/framework/sessions storage/framework/views \
        storage/framework/cache bootstrap/cache && \
    cp -a public /var/www_public_image && \
    chown -R www-data:www-data storage bootstrap/cache public /var/www_public_image && \
    find storage bootstrap/cache -type d -exec chmod 775 {} + && \
    find storage bootstrap/cache -type f -exec chmod 664 {} +

USER www-data

EXPOSE 9000
CMD ["php-fpm"]
