FROM php:8.3-cli-alpine

RUN apk add --no-cache libpq libpq-dev
# We needd libpq-dev for installing the PDO extension for Postgres
RUN docker-php-ext-install pdo_pgsql && docker-php-ext-enable pdo_pgsql
RUN docker-php-ext-install pcntl && docker-php-ext-enable pcntl
RUN docker-php-ext-install opcache && docker-php-ext-enable opcache
COPY ./php.ini "$PHP_INI_DIR/php.ini"

RUN apk del libpq-dev 