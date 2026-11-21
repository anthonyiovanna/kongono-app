FROM dunglas/frankenphp:php8.5

WORKDIR /app

COPY composer.json ./
RUN composer install --no-dev --no-interaction --optimize-autoloader --no-scripts

COPY . /app
RUN php vendor/bin/kongo optimize

ENV APP_ENV=production
ENV APP_DEBUG=false

EXPOSE 8080
CMD ["frankenphp", "run", "--config", "/app/Caddyfile"]
