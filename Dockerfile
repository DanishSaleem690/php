# PHP backend for container deploys (Railway, Fly.io, Render, etc.)
# Platform: set root directory to backend/ OR docker build path: docker build -f backend/Dockerfile backend/

FROM php:8.2-cli-bookworm

# MySQL contact form — HTTPS to Brevo uses allow_url_fopen or PHP’s curl extension if installed
RUN docker-php-ext-install -j"$(nproc)" pdo_mysql \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /var/www/html

# App files — database.php / email_notify.php are usually gitignored; add via platform secrets or mounts
COPY . .

RUN (test -f /var/www/html/lib/cors.php && test -f /var/www/html/lib/db.php) \
    || test -f /var/www/html/lib_bundle.php \
    || (echo "BUILD FAILED: upload lib/cors.php + lib/db.php OR lib_bundle.php" && exit 1)

RUN sed -i 's/\r$//' /var/www/html/docker-entrypoint.sh \
    && chmod +x /var/www/html/docker-entrypoint.sh

ENV PORT=8080
EXPOSE 8080

ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
