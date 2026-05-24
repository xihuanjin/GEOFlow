#!/usr/bin/env bash
# build-single.sh — 构建 GEOFlow 单容器镜像
# 在 1Panel 服务器上运行，或在本地构建后推送到 registry
set -eu

IMAGE_NAME="${IMAGE_NAME:-geoflow:latest}"
DOCKERFILE="${DOCKERFILE:-docker/Dockerfile.single}"

echo "==> Building single-container image: ${IMAGE_NAME}"
echo "==> Dockerfile: ${DOCKERFILE}"

docker build \
  -t "${IMAGE_NAME}" \
  -f "${DOCKERFILE}" \
  --build-arg COMPOSER_PACKAGIST_MIRROR=https://mirrors.aliyun.com/composer/ \
  .

echo ""
echo "==> Build complete!"
echo ""
echo "Run with:"
echo "  docker run -d \\"
echo "    --name geoflow \\"
echo "    -p 18080:18080 \\"
echo "    -v /path/to/.env.single:/var/www/html/.env \\"
echo "    -v /path/to/storage:/var/www/html/storage \\"
echo "    ${IMAGE_NAME}"
echo ""
echo "Or deploy via 1Panel → 容器 → 创建容器"
echo "  Port mapping: 18080:18080"
echo "  Volumes: .env + storage"
