#!/usr/bin/env bash
# build-single.sh — GEOFlow 单容器构建（分层构建版）
#
# 步骤:
#   1. docker/Dockerfile.base  → geoflow-base    （只跑一次，固话系统依赖）
#   2. docker/Dockerfile.single → geoflow:latest  （秒级，只 COPY 应用代码）
set -euo pipefail

export DOCKER_BUILDKIT=1

BASE_TAG="${BASE_TAG:-geoflow-base}"
APP_TAG="${APP_TAG:-geoflow:latest}"

# ==============================
# Step 1: 基础镜像（系统层）
# ==============================
echo "╔════════════════════════════════════════╗"
echo "║  Step 1/2: Base image (system layer)  ║"
echo "╚════════════════════════════════════════╝"
echo "  → ${BASE_TAG}"

docker build \
  -t "${BASE_TAG}" \
  -f docker/Dockerfile.base \
  --build-arg COMPOSER_PACKAGIST_MIRROR=https://mirrors.aliyun.com/composer/ \
  .

echo ""
echo "  ✅ Base image built: ${BASE_TAG}"
echo ""

# ==============================
# Step 2: 应用镜像（代码层）
# ==============================
echo "╔═════════════════════════════════════════╗"
echo "║  Step 2/2: App image (application code) ║"
echo "╚═════════════════════════════════════════╝"
echo "  → ${APP_TAG} (FROM ${BASE_TAG})"

docker build \
  -t "${APP_TAG}" \
  -f docker/Dockerfile.single \
  --build-arg BASE_IMAGE="${BASE_TAG}" \
  --build-arg COMPOSER_PACKAGIST_MIRROR=https://mirrors.aliyun.com/composer/ \
  .

echo ""
echo "  ✅ App image built: ${APP_TAG}"
echo ""
echo "══════════════════════════════════════════"
echo "  Deploy with:"
echo "    docker run -d --name geoflow --restart always \\"
echo "      -p 18080:18080 \\"
echo "      -v /path/to/.env.single:/var/www/html/.env \\"
echo "      -v /path/to/storage:/var/www/html/storage \\"
echo "      ${APP_TAG}"
echo ""
echo "  Or use 1Panel → 容器 → 创建容器"
echo "══════════════════════════════════════════"
echo ""
echo "💡 Tips:"
echo "  - 基础镜像只改系统依赖时才需重跑 Step 1"
echo "  - 日常改代码只需 Step 2（秒级）"
echo "  - docker.rebuild.sh 快捷脚本: 只跑 Step 2"
