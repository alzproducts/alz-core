release: php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
worker: php artisan horizon
scheduler: php artisan schedule:work