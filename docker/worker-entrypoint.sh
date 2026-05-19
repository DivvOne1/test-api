#!/bin/sh
set -e

if [ ! -f .env ]; then
  cp .env.example .env
fi

until php artisan migrate:status >/dev/null 2>&1; do
  echo "Waiting for database and app migrations..."
  sleep 3
done

php artisan config:clear
php artisan package:discover --ansi
php artisan notifications:consume
