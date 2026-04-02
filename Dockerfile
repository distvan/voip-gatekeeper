FROM php:8.2-cli-alpine AS vendor

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --classmap-authoritative

FROM php:8.2-cli-alpine

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY composer.json composer.lock ./
COPY public ./public
COPY src ./src

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
	&& rm /usr/bin/composer \
	&& docker-php-ext-install opcache \
	&& addgroup -S app \
	&& adduser -S -G app app \
	&& chown -R app:app /app

ENV APP_ENV=production
ENV PORT=8080
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 CMD php -r 'exit(@file_get_contents("http://127.0.0.1:" . getenv("PORT") . "/health") === "ok" ? 0 : 1);'

USER app

CMD ["sh", "-c", "php -d variables_order=EGPCS -d opcache.enable_cli=1 -d opcache.validate_timestamps=0 -S 0.0.0.0:${PORT} -t public public/router.php"]