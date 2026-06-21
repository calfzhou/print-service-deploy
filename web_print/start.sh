#!/bin/bash
# 网页打印服务启动脚本

PORT=${1:-8080}
DIR="$(cd "$(dirname "$0")" && pwd)"

echo "=========================================="
echo "       网页打印服务"
echo "=========================================="
echo ""
echo "启动目录: $DIR"
echo "监听端口: $PORT"
echo ""

# 检查 PHP
if ! command -v php &> /dev/null; then
    echo "[错误] PHP 未安装"
    exit 1
fi

# 检查 CUPS
if ! command -v lpstat &> /dev/null; then
    echo "[警告] CUPS 未安装，打印功能可能不可用"
fi

# 获取本机IP
LOCAL_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
if [ -z "$LOCAL_IP" ]; then
    LOCAL_IP="localhost"
fi

echo "访问地址:"
echo "  本地: http://localhost:$PORT"
echo "  局域网: http://$LOCAL_IP:$PORT"
echo ""
echo "按 Ctrl+C 停止服务"
echo "=========================================="
echo ""

# 启动 PHP 内置服务器（设置上传限制）
cd "$DIR"
php -d upload_max_filesize=500M -d post_max_size=510M -d max_execution_time=300 -d memory_limit=512M -S 0.0.0.0:$PORT
