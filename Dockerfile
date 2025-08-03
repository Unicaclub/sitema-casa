# ERP Sistema - Dockerfile para Produção
FROM php:8.2-fpm-alpine

# Instala dependências do sistema
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    zip \
    unzip \
    git \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    redis \
    mysql-client \
    imagemagick-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        mysqli \
        gd \
        zip \
        bcmath \
        exif \
        intl

# Instala Redis extension
RUN pecl install redis \
    && docker-php-ext-enable redis

# Instala ImageMagick extension
RUN pecl install imagick \
    && docker-php-ext-enable imagick

# Instala Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configura diretório de trabalho
WORKDIR /var/www/html

# Copia arquivos da aplicação
COPY . .

# Instala dependências PHP
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Configura permissões
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/storage \
    && chmod -R 777 /var/www/html/public/assets

# Copia configurações
COPY docker/php/php.ini /usr/local/etc/php/php.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

# Cria diretórios necessários
RUN mkdir -p /var/log/supervisor \
    && mkdir -p /var/run/supervisor \
    && mkdir -p /run/nginx

# Expõe porta
EXPOSE 9000

# Comando padrão
CMD ["php-fpm"]

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost:9000/health || exit 1
