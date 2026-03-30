#!/usr/bin/env bash
# Ubuntu/WSL: elimina PHP 8.3 (paquetes listados) e instala PHP 8.4 + extensiones típicas para Laravel.
# Uso: bash scripts/install-php84-ubuntu.sh

set -euo pipefail

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
  echo "Ejecuta con sudo: sudo bash scripts/install-php84-ubuntu.sh" >&2
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive

apt-get update -y

# Paquetes que tenías (8.3 + metapaquetes php)
apt-get remove --purge -y \
  php \
  php-common \
  php-mbstring \
  php-zip \
  php8.3-cli \
  php8.3-curl \
  php8.3-opcache \
  php8.3-xml \
  php-cli \
  php-curl \
  php-xml \
  php8.3 \
  php8.3-common \
  php8.3-mbstring \
  php8.3-readline \
  php8.3-zip \
  || true

apt-get autoremove -y --purge

apt-get install -y software-properties-common
add-apt-repository -y ppa:ondrej/php
apt-get update -y

apt-get install -y \
  php8.4 \
  php8.4-cli \
  php8.4-common \
  php8.4-opcache \
  php8.4-readline \
  php8.4-mbstring \
  php8.4-xml \
  php8.4-curl \
  php8.4-zip \
  php8.4-pgsql \
  php8.4-sqlite3 \
  php8.4-mysql \
  php8.4-gd \
  php8.4-intl \
  php8.4-bcmath \
  php8.4-redis \
  composer

update-alternatives --set php /usr/bin/php8.4 2>/dev/null || update-alternatives --install /usr/bin/php php /usr/bin/php8.4 100

php -v
command -v composer >/dev/null && composer --version || true
