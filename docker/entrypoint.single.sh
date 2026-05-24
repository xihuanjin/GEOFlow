#!/usr/bin/env sh
# docker/entrypoint.single.sh — GEOFlow 单容器入口
#
# 职责：
#   1. 校验 APP_KEY，自动生成
#   2. 创建存储目录 + storage:link
#   3. 等待远程 PostgreSQL（1Panel 管理）
#   4. 执行 migrate
#   5. 执行 optimize
#   6. 交给 supervisor 启动全部进程
set -eu

cd /var/www/html

# ------------------------------------------------------------------
# .env 必须存在（1Panel 挂载进来）
# ------------------------------------------------------------------
if [ ! -f .env ]; then
  echo "[entrypoint] FATAL: .env not found at /var/www/html/.env"
  echo "[entrypoint] Please mount it via 1Panel volume:  /path/to/.env:/var/www/html/.env"
  exit 1
fi

# ------------------------------------------------------------------
# APP_KEY：移除无效的环境变量，避免覆盖 .env 中的有效密钥
# ------------------------------------------------------------------
if [ -z "${APP_KEY:-}" ] || ! printf '%s' "${APP_KEY:-}" | grep -q '^base64:'; then
  unset APP_KEY
fi

if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
  echo "[entrypoint] php artisan key:generate --force"
  php artisan key:generate --force --no-interaction
fi

# ------------------------------------------------------------------
# 存储目录 + symbolic link
# ------------------------------------------------------------------
mkdir -p \
  bootstrap/cache \
  storage/app/public \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs

if [ ! -e public/storage ]; then
  php artisan storage:link --force --no-interaction
fi

# 权限：为 www-data（PHP-FPM/Horizon/Schedule/Reverb）开放 storage/bootstrap-cache
chown -R www-data:www-data bootstrap/cache storage

# ------------------------------------------------------------------
# 等待远程 PostgreSQL（1Panel 上已有的数据库服务）
# ------------------------------------------------------------------
if [ "${AUTO_WAIT_FOR_DB:-true}" = "true" ] && [ "${DB_CONNECTION:-pgsql}" = "pgsql" ]; then
  echo "[entrypoint] waiting for PostgreSQL at ${DB_HOST}:${DB_PORT} (user=${DB_USERNAME}, db=${DB_DATABASE})"
  until pg_isready \
    -h "${DB_HOST}" \
    -p "${DB_PORT:-5432}" \
    -U "${DB_USERNAME:-geo_user}" \
    -d "${DB_DATABASE:-geo_flow}" >/dev/null 2>&1; do
    echo "[entrypoint] still waiting for PostgreSQL..."
    sleep 3
  done
  echo "[entrypoint] PostgreSQL is ready"
fi

# ------------------------------------------------------------------
# 数据库迁移（每次启动执行，与旧 init 容器行为一致）
# ------------------------------------------------------------------
if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
  echo "[entrypoint] php artisan migrate --force"
  php artisan migrate --force --no-interaction
fi

# ------------------------------------------------------------------
# 首次部署时 seed（默认关，首次可设置 AUTO_SEED=true）
# ------------------------------------------------------------------
if [ "${AUTO_SEED:-false}" = "true" ]; then
  echo "[entrypoint] php artisan db:seed --force"
  php artisan db:seed --force --no-interaction
fi

# ------------------------------------------------------------------
# 优化（config/events/routes/views 缓存）
# ------------------------------------------------------------------
if [ "${AUTO_OPTIMIZE:-true}" = "true" ]; then
  echo "[entrypoint] php artisan optimize"
  php artisan optimize --no-interaction || echo "[entrypoint] warning: optimize failed, continuing"
fi

# ------------------------------------------------------------------
# 交给 Supervisor
# ------------------------------------------------------------------
echo "[entrypoint] starting supervisord..."
exec "$@"
