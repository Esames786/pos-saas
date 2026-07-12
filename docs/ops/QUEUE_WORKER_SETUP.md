# Queue Worker Setup (Supervisor)

> `QUEUE_CONNECTION=database` — anything queued (future mail, notifications, jobs)
> is stored in the `jobs` table and **never runs** unless a worker process exists.
> The `jobs` table already exists (base migration).

## 1. Install Supervisor (Ubuntu)
```bash
sudo apt-get update && sudo apt-get install -y supervisor
```

## 2. Worker config
Create `/etc/supervisor/conf.d/pos-saas-worker.conf`:
```ini
[program:pos-saas-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/pos-saas/artisan queue:work database --sleep=3 --tries=3 --timeout=90
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/html/pos-saas/storage/logs/worker.log
stopwaitsecs=120
```

## 3. Activate
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
sudo supervisorctl restart pos-saas-worker:*
```

## 4. After every deploy
Workers keep the OLD code in memory. `deploy.sh` already runs:
```bash
php artisan queue:restart || true
```
(`|| true` keeps deploys green on boxes without a worker.)

## 5. Health checks
```bash
sudo supervisorctl status                 # RUNNING?
php artisan queue:monitor database:default --max=100   # backlog size
tail -50 storage/logs/worker.log
```
Failed jobs land in `failed_jobs`: `php artisan queue:failed` / `queue:retry all`.
