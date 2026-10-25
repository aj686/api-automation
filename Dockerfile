# One image for the web app (php-fpm) and the queue worker that runs Postman
# CLI, plus a small nginx image that serves public/. Built by docker-compose.yml.

FROM php:8.3-fpm-bookworm AS app

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl git gnupg libicu-dev libzip-dev unzip \
    && rm -rf /var/lib/apt/lists/*

# pcntl lets queue:work handle SIGTERM, so `docker stop` does not orphan a run.
RUN docker-php-ext-configure pdo_mysql --with-pdo-mysql=mysqlnd \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pcntl bcmath zip intl opcache

# Node: Postman CLI ships as an npm package, and Vite builds the assets.
ARG NODE_MAJOR=22
RUN curl -fsSL https://deb.nodesource.com/setup_${NODE_MAJOR}.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# Pinned: the report parser is tested against this version's output.
ARG POSTMAN_CLI_VERSION=1.71.0
RUN npm install -g postman-cli@${POSTMAN_CLI_VERSION} \
    && npm cache clean --force \
    && postman --version

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-api-automation.ini

# Everything runs as www-data, fpm included, so the pool's user/group lines
# would only print a warning.
RUN sed -i '/^user = /d; /^group = /d' /usr/local/etc/php-fpm.d/www.conf \
    && chown www-data:www-data /var/www

WORKDIR /var/www/html
USER www-data

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY --chown=www-data:www-data package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

# Composer runs before the Vite build: Tailwind scans views inside vendor/.
COPY --chown=www-data:www-data . .
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && npm run build \
    && rm -rf node_modules \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && ln -s storage/app/private/.env .env

ENTRYPOINT ["sh", "docker/entrypoint.sh"]
CMD ["php-fpm"]


FROM nginx:1.27-alpine AS web

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
