#!/bin/sh
set -eu

php artisan migrate --force --isolated
php artisan db:seed --class=DatabaseSeeder --force
