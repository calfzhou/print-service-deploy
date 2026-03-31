#!/bin/bash
set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

print_msg() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

print_header() {
    echo -e "\n${BLUE}========================================${NC}"
    echo -e "${GREEN}  远程打印客户端 Docker 版 v1.2.0${NC}"
    echo -e "${BLUE}========================================${NC}\n"
}

print_header

# 设备ID持久化处理
DEVICE_ID_VOL="/etc/printer-device-id-vol"
DEVICE_ID_FILE="/etc/printer-device-id"

# 确保持久化目录存在
mkdir -p "$DEVICE_ID_VOL"

# 设备ID文件路径（在持久化卷中）
PERSISTENT_DEVICE_ID="$DEVICE_ID_VOL/device-id"

if [ ! -f "$PERSISTENT_DEVICE_ID" ]; then
    print_msg "生成新设备ID..."
    if command -v openssl >/dev/null 2>&1; then
        DEVICE_ID=$(openssl rand -hex 15 2>/dev/null)
    else
        DEVICE_ID=$(cat /dev/urandom | tr -dc 'a-f0-9' | fold -w 30 | head -n 1)
    fi
    echo "$DEVICE_ID" > "$PERSISTENT_DEVICE_ID"
    chmod 644 "$PERSISTENT_DEVICE_ID"
    print_msg "设备ID: $DEVICE_ID"
else
    DEVICE_ID=$(cat "$PERSISTENT_DEVICE_ID")
    print_msg "设备ID: $DEVICE_ID"
fi

# 创建符号链接到标准位置，供printer_client.php使用
ln -sf "$PERSISTENT_DEVICE_ID" "$DEVICE_ID_FILE"

print_msg "初始化目录结构..."
mkdir -p /opt/websocket_printer \
    /var/log/printer-client \
    /var/log/supervisor \
    /tmp/print_jobs \
    /tmp/.libreoffice \
    /var/run/cups \
    /var/spool/cups \
    /var/cache/cups \
    /etc/cups/ppd \
    && chmod 777 /tmp/.libreoffice \
    && chmod 755 /tmp/print_jobs

# 检查并初始化应用文件（支持远程更新后持久化）
APP_DIR="/opt/websocket_printer"
APP_BACKUP_DIR="/opt/websocket_printer_default"

# 如果备份目录存在（镜像内置），检查应用文件
if [ -d "$APP_BACKUP_DIR" ]; then
    # 如果 printer_client.php 不存在或为空，从备份恢复
    if [ ! -f "$APP_DIR/printer_client.php" ] || [ ! -s "$APP_DIR/printer_client.php" ]; then
        print_msg "初始化 printer_client.php..."
        cp "$APP_BACKUP_DIR/printer_client.php" "$APP_DIR/printer_client.php"
        chmod +x "$APP_DIR/printer_client.php"
    fi
    
    # 如果 generate_qrcode.sh 不存在，从备份恢复
    if [ ! -f "$APP_DIR/generate_qrcode.sh" ]; then
        print_msg "初始化 generate_qrcode.sh..."
        cp "$APP_BACKUP_DIR/generate_qrcode.sh" "$APP_DIR/generate_qrcode.sh"
        chmod +x "$APP_DIR/generate_qrcode.sh"
    fi
    
    # 如果 cupsd.conf.default 不存在，从备份恢复
    if [ ! -f "$APP_DIR/cupsd.conf.default" ]; then
        print_msg "初始化 cupsd.conf.default..."
        cp "$APP_BACKUP_DIR/cupsd.conf.default" "$APP_DIR/cupsd.conf.default"
    fi
fi

print_msg "配置CUPS打印服务..."

# 更新字体缓存（解决中文字体显示问题）
print_msg "更新字体缓存..."
fc-cache -fv >/dev/null 2>&1 || true

# 设置中文环境（CUPS 汉化）
print_msg "配置中文环境..."
export LANG=zh_CN.UTF-8
export LC_ALL=zh_CN.UTF-8
export LANGUAGE=zh_CN:zh

# 生成中文语言环境
locale-gen zh_CN.UTF-8 >/dev/null 2>&1 || true
update-locale LANG=zh_CN.UTF-8 >/dev/null 2>&1 || true

# 确保CUPS配置目录存在
mkdir -p /etc/cups/ppd /etc/cups/ssl

# 检查cupsd.conf是否存在或为空，如果不存在则从备份恢复
CUPS_CONF="/etc/cups/cupsd.conf"
CUPS_CONF_BACKUP="/opt/websocket_printer/cupsd.conf.default"

if [ ! -f "$CUPS_CONF" ] || [ ! -s "$CUPS_CONF" ]; then
    print_warn "cupsd.conf 不存在或为空，从备份恢复..."
    if [ -f "$CUPS_CONF_BACKUP" ]; then
        cp "$CUPS_CONF_BACKUP" "$CUPS_CONF"
        print_msg "已从备份恢复 cupsd.conf"
    else
        print_error "备份配置文件不存在: $CUPS_CONF_BACKUP"
        # 创建最小化配置
        cat > "$CUPS_CONF" << 'CUPSEOF'
LogLevel warn
Port 631
WebInterface Yes
Browsing On
<Location />
  Order allow,deny
  Allow all
</Location>
<Location /admin>
  Order allow,deny
  Allow all
</Location>
<Location /admin/conf>
  Order allow,deny
  Allow all
</Location>
<Policy default>
  <Limit All>
    Order allow,deny
    Allow all
  </Limit>
</Policy>
CUPSEOF
        print_msg "已创建最小化 cupsd.conf"
    fi
fi

chmod 644 "$CUPS_CONF" 2>/dev/null || true

# 确保CUPS运行目录权限正确
chown -R root:lp /etc/cups 2>/dev/null || true
chmod 755 /etc/cups 2>/dev/null || true

if [ -n "$CUPS_ADMIN_PASSWORD" ]; then
    print_msg "设置CUPS管理员密码..."
    echo "root:$CUPS_ADMIN_PASSWORD" | chpasswd 2>/dev/null || true
else
    print_warn "未设置CUPS管理员密码，使用环境变量 CUPS_ADMIN_PASSWORD 设置"
fi

if [ -n "$WS_SERVER" ]; then
    print_msg "配置WebSocket服务器: $WS_SERVER"
    CONFIG_FILE="/opt/websocket_printer/.config"
    echo "{\"s\":\"$WS_SERVER\"}" > "$CONFIG_FILE"
    chmod 644 "$CONFIG_FILE"
fi

if [ -n "$CUPS_ADMIN_PASSWORD" ]; then
    CUPS_PWD="$CUPS_ADMIN_PASSWORD"
else
    CUPS_PWD="未设置（请使用环境变量 CUPS_ADMIN_PASSWORD 设置）"
fi

echo ""
echo "============================================"
echo "  配置信息"
echo "============================================"
echo ""
echo "设备ID: $DEVICE_ID"
echo "CUPS Web界面: http://localhost:631"
echo "CUPS 用户名: root"
echo "CUPS 密码: $CUPS_PWD"
echo "日志目录: /var/log/printer-client"
echo "临时文件: /tmp/print_jobs"
echo ""

echo -e "${BLUE}============================================${NC}"
echo -e "${GREEN}  使用说明${NC}"
echo -e "${BLUE}============================================${NC}"
echo ""
echo "1. 微信扫描下方小程序码进入打印小程序"
echo "2. 在小程序中点击「绑定设备」"
echo "3. 扫描设备二维码完成绑定"
echo "4. 绑定成功后即可远程打印文件"
echo ""

if command -v qrencode >/dev/null 2>&1; then
    echo -e "${GREEN}请使用微信扫描以下二维码进入小程序:${NC}"
    echo ""
    
    _qa="68747470733a2f2f"
    _qb="78696e7072696e74"
    _qc="2e7a79736861"
    _qd="72652e746f70"
    _qe="2f6170695f696e7374616c6c5f7172636f64652e706870"
    _ba="68747470733a2f2f"
    _bb="78696e7072696e74"
    _bc="2e7a79736861"
    _bd="72652e746f70"
    _be="2f7863782e706870"
    
    _decode() {
        if command -v python3 >/dev/null 2>&1; then
            echo "$1" | python3 -c "import sys; print(bytes.fromhex(sys.stdin.read().strip()).decode())" 2>/dev/null
        elif command -v python >/dev/null 2>&1; then
            echo "$1" | python -c "import sys; print(bytes.fromhex(sys.stdin.read().strip()).decode())" 2>/dev/null
        elif command -v xxd >/dev/null 2>&1; then
            echo "$1" | xxd -r -p 2>/dev/null
        fi
    }
    
    QR_API=$(_decode "${_qa}${_qb}${_qc}${_qd}${_qe}")
    QR_BACKUP=$(_decode "${_ba}${_bb}${_bc}${_bd}${_be}")
    
    print_msg "正在获取小程序二维码..."
    if command -v curl >/dev/null 2>&1 && [ -n "$QR_API" ]; then
        QR_CONTENT=$(curl -sSL "$QR_API" 2>/dev/null | grep -oP '"qrcode"\s*:\s*"\K[^"]+' 2>/dev/null || echo "")
        
        if [ -z "$QR_CONTENT" ]; then
            print_msg "使用备用地址..."
            QR_CONTENT="$QR_BACKUP"
        else
            print_msg "小程序二维码获取成功"
        fi
    else
        QR_CONTENT="$QR_BACKUP"
    fi
    
    if [ -n "$QR_CONTENT" ]; then
        echo ""
        qrencode -t ANSIUTF8 "$QR_CONTENT" 2>/dev/null || {
            print_warn "二维码生成失败"
        }
        echo ""
    else
        print_error "无法获取小程序二维码"
    fi
    echo ""
else
    print_warn "qrencode 未安装，无法显示二维码"
fi

echo ""
echo "============================================"
echo "  设备绑定二维码"
echo "============================================"
echo ""
if command -v qrencode >/dev/null 2>&1; then
    DEVICE_QR="device://$DEVICE_ID"
    echo -e "${GREEN}请在小程序中扫描以下二维码绑定设备:${NC}"
    echo ""
    qrencode -t ANSIUTF8 "$DEVICE_QR" 2>/dev/null || echo "$DEVICE_QR"
    echo ""
    echo "设备ID: $DEVICE_ID"
else
    echo "设备ID: $DEVICE_ID"
    echo "请在小程序中手动输入设备ID进行绑定"
fi
echo ""

echo "============================================"
echo "  详细操作说明"
echo "============================================"
echo ""
echo "添加打印机:"
echo "  方式A: 通过CUPS Web界面"
echo "    - 访问 http://服务器IP:631"
echo "    - 用户名: root"
echo "    - 密码: $CUPS_PWD"
echo "    - 点击 Administration -> Add Printer"
echo ""
echo "  方式B: 通过小程序远程添加"
echo "    - 在小程序中打开已绑定的设备"
echo "    - 点击'检测USB打印机'"
echo "    - 选择打印机和驱动完成添加"
echo ""
echo "开始打印:"
echo "  - 在小程序中选择文件"
echo "  - 选择打印机和打印选项"
echo "  - 点击'打印'按钮"
echo ""
echo "查看日志:"
echo "  - 容器日志: docker logs cloud-printer"
echo "  - 客户端日志: /var/log/printer-client/"
echo "  - CUPS日志: /var/log/cups/"
echo ""
echo "管理打印队列:"
echo "  - 查看队列: lpstat -o"
echo "  - 取消任务: cancel <job-id>"
echo "  - 清空队列: cancel -a"
echo ""

if ! command -v php >/dev/null 2>&1; then
    print_error "PHP未安装！"
    exit 1
fi

PHP_VERSION=$(php -v | head -n 1 | cut -d ' ' -f 2)
print_msg "PHP版本: $PHP_VERSION"

REQUIRED_EXTS="curl mbstring json sockets"
MISSING_EXTS=""
for ext in $REQUIRED_EXTS; do
    if ! php -m | grep -qi "^$ext$"; then
        MISSING_EXTS="$MISSING_EXTS $ext"
    fi
done

if [ -n "$MISSING_EXTS" ]; then
    print_warn "缺少PHP扩展:$MISSING_EXTS"
fi

if command -v libreoffice >/dev/null 2>&1; then
    LO_VERSION=$(libreoffice --version 2>/dev/null | head -n 1 || echo "未知版本")
    print_msg "LibreOffice: $LO_VERSION"
else
    print_warn "LibreOffice未安装，文档转换功能将不可用"
fi

print_msg "打印增强工具:"
command -v gs >/dev/null 2>&1 && print_msg "  ✓ Ghostscript" || print_warn "  ✗ Ghostscript"
command -v qpdf >/dev/null 2>&1 && print_msg "  ✓ qpdf" || print_warn "  ✗ qpdf"
command -v convert >/dev/null 2>&1 && print_msg "  ✓ ImageMagick" || print_warn "  ✗ ImageMagick"
command -v pdfjam >/dev/null 2>&1 && print_msg "  ✓ pdfjam" || print_warn "  ✗ pdfjam"

echo ""
print_msg "========== 启动服务 =========="
print_msg "启动CUPS和打印客户端..."
echo ""

rm -f /var/run/cups/cupsd.pid 2>/dev/null || true
rm -f /var/run/supervisord.pid 2>/dev/null || true

print_msg "使用Supervisor管理服务进程"
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf
