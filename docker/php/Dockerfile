# API PHP (backend/) em PHP-FPM 8.3. Build a partir da raiz do repositório:
#   docker build -f docker/php/Dockerfile .
FROM php:8.3-fpm-bookworm

# Extensões usadas pelo código: pdo_mysql (banco), gd com JPEG/PNG/WebP (fotos dos imóveis) e exif (orientação da câmera).
# curl, sodium, mbstring, iconv, zlib, fileinfo e openssl já vêm na imagem oficial.
# opcache acelera a API em produção. libfcgi-bin fornece o cgi-fcgi usado no healthcheck do PHP-FPM (definido no compose).
RUN apt-get update \
 && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev libfcgi-bin \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql gd exif opcache \
 && rm -rf /var/lib/apt/lists/* \
 && for ext in pdo_mysql gd exif curl sodium mbstring iconv zlib fileinfo 'Zend OPcache'; do php -m | grep -qix "$ext" || { echo "extensão ausente: $ext"; exit 1; }; done

# O backend não tem dependências Composer (não há backend/composer.json); por isso não há etapa de composer install.

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-imob-hub.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-imob-hub.conf

WORKDIR /var/www/html
COPY --chown=root:root backend/ backend/

# Diretórios graváveis (montados como volumes no compose): fotos públicas e armazenamento privado.
RUN mkdir -p backend/public/uploads/properties /var/www/private-storage \
 && chown -R www-data:www-data backend/public/uploads /var/www/private-storage \
 && chmod 0755 backend/public/uploads && chmod 0700 /var/www/private-storage

ENV PRIVATE_STORAGE_PATH=/var/www/private-storage \
    APP_TIMEZONE=America/Sao_Paulo

USER www-data
EXPOSE 9000

