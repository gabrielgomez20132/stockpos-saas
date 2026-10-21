# StokPOS (Laravel 13) — imagen de PHP para produccion.
# El codigo NO va adentro de la imagen: se monta desde el host (docker-compose.yml),
# igual que en control-soft. Asi un rebuild nunca toca public/uploads ni el .env.
FROM php:8.3-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
        libonig-dev libxml2-dev libzip-dev libicu-dev mariadb-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring exif pcntl bcmath gd soap zip intl opcache \
    && rm -rf /var/lib/apt/lists/*

# PHP de produccion + OPcache (codigo compilado en memoria; detecta archivos nuevos del deploy)
RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini \
    && { \
      echo 'opcache.enable=1'; \
      echo 'opcache.enable_cli=0'; \
      echo 'opcache.memory_consumption=192'; \
      echo 'opcache.interned_strings_buffer=16'; \
      echo 'opcache.max_accelerated_files=20000'; \
      echo 'opcache.validate_timestamps=1'; \
      echo 'opcache.revalidate_freq=2'; \
    } > /usr/local/etc/php/conf.d/opcache.ini \
    && { \
      echo 'upload_max_filesize=32M'; \
      echo 'post_max_size=40M'; \
      echo 'memory_limit=512M'; \
      echo 'max_execution_time=120'; \
      echo 'expose_php=Off'; \
      echo 'date.timezone=America/Argentina/Buenos_Aires'; \
    } > /usr/local/etc/php/conf.d/stokpos.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/stokpos

COPY docker/entrypoint.sh /usr/local/bin/stokpos-entrypoint
RUN chmod +x /usr/local/bin/stokpos-entrypoint

ENTRYPOINT ["stokpos-entrypoint"]
CMD ["php-fpm"]
