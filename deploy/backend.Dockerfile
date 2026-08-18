# Production Laravel image (PHP 8.4 FPM + all required extensions)
FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    git curl bash zip unzip \
    libpng-dev libzip-dev libxml2-dev \
    postgresql-dev postgresql-client oniguruma-dev icu-dev net-snmp-dev \
    linux-headers \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS pcre-dev \
 && docker-php-ext-install \
      pdo pdo_pgsql pgsql \
      mbstring zip exif pcntl bcmath gd intl soap opcache sockets snmp \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apk del .build-deps

# OPcache tuning for production
RUN { \
      echo "opcache.enable=1"; \
      echo "opcache.enable_cli=0"; \
      echo "opcache.memory_consumption=192"; \
      echo "opcache.interned_strings_buffer=16"; \
      echo "opcache.max_accelerated_files=20000"; \
      echo "opcache.validate_timestamps=0"; \
      echo "opcache.jit_buffer_size=64M"; \
      echo "opcache.jit=1255"; \
    } > /usr/local/etc/php/conf.d/opcache-prod.ini

# Upload / execution limits sized for ISP billing workloads
RUN { \
      echo "memory_limit=512M"; \
      echo "post_max_size=100M"; \
      echo "upload_max_filesize=100M"; \
      echo "max_execution_time=300"; \
      echo "expose_php=Off"; \
    } > /usr/local/etc/php/conf.d/app.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Non-root PHP-FPM user
RUN adduser -D -H -u 1000 -s /bin/sh appuser \
 && chown -R appuser:appuser /var/www

# php-fpm runs as www-data by default; keep that to match nginx
EXPOSE 9000
CMD ["php-fpm", "-F"]
