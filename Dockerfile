# syntax=docker/dockerfile:1.7
#
# SuperNative Messenger — server role image.
# Runs nginx + php-fpm + Reverb + queue worker + scheduler under supervisor.
#
#   docker build -t supernative .
#   docker run --env-file .env.docker -p 8080:8080 -p 8081:8081 supernative
#
# The mobile (NativePHP) build is NOT produced here — see docs/BACKEND.md.

# ----------------------------------------------------------------------------
# Stage 1 — Composer dependencies (PHP 8.4 so nativephp/mobile's platform
# requirement is satisfied during install).
#
# Some nativephp/* packages resolve to GitHub zipballs that hit the anonymous
# API rate limit. Provide a token so the build stays reliable:
#
#   DOCKER_BUILDKIT=1 docker build \
#     --secret id=github_token,env=GITHUB_TOKEN -t supernative .
#
# Without it, git is installed so `--prefer-source` can clone as a fallback.
# ----------------------------------------------------------------------------
FROM php:8.4-cli-bookworm AS vendor

RUN apt-get update \
    && apt-get install -y --no-install-recommends git openssh-client unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions zip intl

WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock ./
RUN --mount=type=secret,id=github_token \
    if [ -f /run/secrets/github_token ]; then \
        composer config -g github-oauth.github.com "$(cat /run/secrets/github_token)"; \
    fi \
    && composer install --no-dev --no-scripts --no-autoloader --no-interaction \
        --prefer-dist --no-progress \
    || composer install --no-dev --no-scripts --no-autoloader --no-interaction \
        --prefer-source --no-progress

COPY . .
RUN composer dump-autoload --optimize --no-dev

# ----------------------------------------------------------------------------
# Stage 2 — Runtime
# ----------------------------------------------------------------------------
FROM php:8.4-fpm-bookworm AS app

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx supervisor tini curl \
    && install-php-extensions \
        pcntl pdo_mysql pdo_pgsql bcmath intl zip gd exif opcache redis sockets \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# --- config ---
COPY docker/php.ini          /usr/local/etc/php/conf.d/zzz-app.ini
COPY docker/opcache.ini      /usr/local/etc/php/conf.d/zzz-opcache.ini
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zzz-app.conf
COPY docker/nginx.conf       /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/app.conf

WORKDIR /var/www/html

# --- application ---
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor

# writable dirs + a place for a runtime sqlite db if DB_CONNECTION=sqlite
RUN rm -f database/database.sqlite \
    && mkdir -p storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache database \
    && chmod -R ug+rwX storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# 8080 = HTTP API (nginx) · 8081 = Reverb websockets
EXPOSE 8080 8081

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/app.conf", "-n"]
