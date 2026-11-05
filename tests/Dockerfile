FROM php:8.2-cli
RUN apt-get update \
 && apt-get install -y --no-install-recommends default-mysql-client curl ca-certificates \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install mysqli
