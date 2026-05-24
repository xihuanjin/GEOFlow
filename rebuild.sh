#!/usr/bin/env bash
# rebuild.sh — 仅重构建应用层（不改系统依赖时秒级完成）
set -euo pipefail

export DOCKER_BUILDKIT=1

BASE_TAG="${BASE_TAG:-geoflow-base}"
APP_TAG="${APP_TAG:-geoflow:latest}"

echo "==> Quick rebuild: ${APP_TAG} (FROM ${BASE_TAG})"
docker build \
  -t "${APP_TAG}" \
  -f docker/Dockerfile.single \
  --build-arg BASE_IMAGE="${BASE_TAG}" \
  --build-arg COMPOSER_PACKAGIST_MIRROR=https://mirrors.aliyun.com/composer/ \
  .

echo ""
echo "✅ Done. Run:"
echo "  docker rm -f geoflow 2>/dev/null; docker run -d --name geoflow --restart always \\"
echo "    -p 18080:18080 \\"
echo "    -v /path/to/.env.single:/var/www/html/.env \\"
echo "    -v /path/to/storage:/var/www/html/storage \\"
echo "    ${APP_TAG}"
