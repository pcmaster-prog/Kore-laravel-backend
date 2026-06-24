web: php artisan serve --host=0.0.0.0 --port=$PORT
worker: php artisan queue:work --sleep=3 --tries=3 --max-jobs=1000 --max-time=3600
scheduler: php artisan schedule:work
