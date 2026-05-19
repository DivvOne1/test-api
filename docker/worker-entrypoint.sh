#!/bin/sh
set -e

if [ ! -f .env ]; then
  cp .env.example .env
fi

until php artisan migrate --force; do
  echo "Waiting for database..."
  sleep 3
done

php artisan config:clear
php artisan package:discover --ansi
php artisan notifications:consume
