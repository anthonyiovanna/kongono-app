FROM dunglas/frankenphp:1-php8.5-alpine

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN install-php-extensions dom sodium opcache \
    && adduser -D -u 10001 -H -s /sbin/nologin kongono \
    && printf 'expose_php=Off\nsession.upload_progress.enabled=Off\nzend.exception_ignore_args=On\nallow_url_include=Off\ndisplay_errors=Off\nlog_errors=On\n' > /usr/local/etc/php/conf.d/99-kongono-security.ini

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY --chown=kongono:kongono . /app
RUN mkdir -p /app/var/cache /app/var/storage /data /config \
    && php vendor/bin/kongo optimize \
    && chown -R kongono:kongono /app/var /data /config

ENV APP_ENV=production \
    APP_DEBUG=false \
    SESSION_SECURE=true

USER kongono

EXPOSE 8080
CMD ["frankenphp", "run", "--config", "/app/Caddyfile"]
