#!/usr/bin/env bash
# build-single.sh — 构建 GEOFlow 单容器镜像（Docker BuildKit 加速版）
set -eu

IMAGE_NAME="${IMAGE_NAME:-geoflow:latest}"
DOCKERFILE="${DOCKERFILE:-docker/Dockerfile.single}"

# 启用 BuildKit（支持 apt cache mount，二次构建免下载）
export DOCKER_BUILDKIT=1

echo "==> Building single-container image: ${IMAGE_NAME}"
echo "==> Dockerfile: ${DOCKERFILE}"
echo "==> BuildKit: enabled"

docker build \
  -t "${IMAGE_NAME}" \
  -f "${DOCKERFILE}" \
  --build-arg COMPOSER_PACKAGIST_MIRROR=https://mirrors.aliyun.com/composer/ \
  .

echo ""
echo "==> Build complete!"
echo ""
echo "=== 部署方式 ==="
echo ""
echo "方式 A — docker run:"
echo "  docker run -d \\"
echo "    --name geoflow \\"
echo "    --restart always \\"
echo "    -p 18080:18080 \\"
echo "    -v /path/to/.env.single:/var/www/html/.env \\"
echo "    -v /path/to/storage:/var/www/html/storage \\"
echo "    ${IMAGE_NAME}"
echo ""
echo "方式 B — 1Panel → 容器 → 创建容器:"
echo "  镜像:    ${IMAGE_NAME}"
echo "  端口:    18080:18080"
echo "  挂载:   /path/to/.env.single → /var/www/html/.env"
echo "  挂载:   /path/to/storage    → /var/www/html/storage"
echo "  重启:   always"
echo ""
echo "初次构建慢正常（下载依赖），二次构建利用缓存会快很多。"
echo "如需 Docker Hub 国内镜像加速，在 /etc/docker/daemon.json 添加:"
echo '  { "registry-mirrors": ["https://docker.1panel.live"] }'
