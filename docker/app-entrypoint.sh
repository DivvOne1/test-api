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
php artisan serve --host=0.0.0.0 --port=8000
