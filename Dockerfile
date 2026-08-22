FROM php:8.2-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip curl \
    libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    libzip-dev libonig-dev libicu-dev libxml2-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) pdo_mysql gd zip bcmath intl mbstring exif pcntl opcache \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# Debian's nodejs is 18; Vite 7 / Tailwind oxide need Node 20.19+ or 22.12+.
COPY --from=node:22-bookworm /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && ln -sf /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

WORKDIR /app
COPY . .

ENV COMPOSER_ALLOW_SUPERUSER=1

# These dirs are gitignored, so they are missing in the image until we create
# them. Composer runs `php artisan package:discover`, which needs a view cache path.
RUN mkdir -p \
      storage/framework/cache/data \
      storage/framework/sessions \
      storage/framework/views \
      storage/logs \
      bootstrap/cache \
 && chmod -R ug+rwx storage bootstrap/cache \
 && chmod +x railway-start.sh

RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist \
 && npm ci \
 && npm run build \
 && rm -rf node_modules

ENV PORT=8080
EXPOSE 8080

CMD ["sh", "railway-start.sh"]
