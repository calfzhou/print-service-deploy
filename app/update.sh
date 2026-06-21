#!/bin/bash
set -e

_d="aHR0cDovL3hpbnByaW50Lnp5c2hhcmUudG9wL3VwZGF0ZS9kb2NrZXI="
DEFAULT_UPDATE_URL=$(echo "$_d" | base64 -d)
UPDATE_URL="${1:-$DEFAULT_UPDATE_URL}"
BACKUP_DIR="/opt/websocket_printer_backup"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
WEB_PRINT_DIR="/opt/websocket_printer/web_print"

declare -A FILES=(
    ["printer_client.php"]="/opt/websocket_printer/printer_client.php"
    ["supervisord.conf"]="/etc/supervisor/conf.d/supervisord.conf"
    ["cupsd.conf"]="/etc/cups/cupsd.conf"
)

# 网页打印服务文件列表（需要备份更新的文件）
WEB_PRINT_FILES=(
    "index.php"
    "api.php"
    "start.sh"
)
# 其他网页打印文件（只更新不备份）
WEB_PRINT_OTHER_FILES=(
    ".user.ini"
    "README.md"
)

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
}

error() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: $1" >&2
}

show_usage() {
    cat << EOF
打印客户端更新工具

用法:
    update.sh 

说明:
    下载并更新以下文件：
    - printer_client.php
    - supervisord.conf
    - cupsd.conf
    - web_print/index.php
    - web_print/api.php
    - web_print/start.sh

备份位置:
    $BACKUP_DIR/backup_$TIMESTAMP/

EOF
}

if [ "$1" = "-h" ] || [ "$1" = "--help" ]; then
    show_usage
    exit 0
fi

UPDATE_URL="${UPDATE_URL%/}"

log "========== 开始更新流程 =========="
log "备份目录: $BACKUP_DIR/backup_$TIMESTAMP"
mkdir -p "$BACKUP_DIR/backup_$TIMESTAMP"

# 备份主程序文件
log "备份主程序文件..."
for filename in "${!FILES[@]}"; do
    filepath="${FILES[$filename]}"
    if [ -f "$filepath" ]; then
        cp "$filepath" "$BACKUP_DIR/backup_$TIMESTAMP/$filename"
        log "  ✓ 已备份: $filename"
    else
        log "  ⚠ 文件不存在: $filepath"
    fi
done

# 备份网页打印服务文件
log "备份网页打印服务文件..."
for file in "${WEB_PRINT_FILES[@]}"; do
    filepath="$WEB_PRINT_DIR/$file"
    if [ -f "$filepath" ]; then
        cp "$filepath" "$BACKUP_DIR/backup_$TIMESTAMP/$file"
        log "  ✓ 已备份: web_print/$file"
    else
        log "  ⚠ 文件不存在: web_print/$file"
    fi
done

log "下载新文件..."
TEMP_DIR="/tmp/printer_update_$$"
mkdir -p "$TEMP_DIR"
download_success=true

# 下载主程序文件（从 /update/docker/ 路径）
for filename in "${!FILES[@]}"; do
    temp_file="$TEMP_DIR/$filename"
    url="$UPDATE_URL/download.php?file=$filename"
    
    if curl -f -L -o "$temp_file" "$url" --connect-timeout 10 --max-time 60 2>&1; then
        if [ "$filename" = "printer_client.php" ]; then
            if php -l "$temp_file" > /dev/null 2>&1; then
                log "  ✓ $filename 下载成功并验证通过"
            else
                error "  ✗ $filename PHP语法验证失败"
                download_success=false
                break
            fi
        else
            log "  ✓ $filename 下载成功"
        fi
    else
        error "  ✗ $filename 下载失败"
        download_success=false
        break
    fi
done

if [ "$download_success" = false ]; then
    error "下载失败，取消更新"
    rm -rf "$TEMP_DIR"
    exit 1
fi

log "应用主程序更新..."
for filename in "${!FILES[@]}"; do
    temp_file="$TEMP_DIR/$filename"
    target_file="${FILES[$filename]}"
    
    if [ -f "$temp_file" ]; then
        cp "$temp_file" "$target_file"
        if [ "$filename" = "printer_client.php" ]; then
            chmod +x "$target_file"
        else
            chmod 644 "$target_file"
        fi
        log "  ✓ 已更新: $filename"
    fi
done

rm -rf "$TEMP_DIR"
log "重新加载配置..."
if command -v supervisorctl > /dev/null 2>&1; then
    log "  重新加载supervisord配置..."
    supervisorctl reread
    supervisorctl update
    log "  重启printer_client服务..."
    supervisorctl restart printer_client
    log "  ✓ 服务已重启"
else
    error "  ✗ supervisorctl未找到"
fi

if command -v supervisorctl > /dev/null 2>&1; then
    log "  重启CUPS服务..."
    supervisorctl restart cups
    log "  ✓ CUPS已重启"
fi

# 更新网页打印服务
log "更新网页打印服务..."
mkdir -p "$WEB_PRINT_DIR"
mkdir -p "$WEB_PRINT_DIR/uploads"

# 获取基础URL（去掉docker路径，用于web_print文件）
BASE_URL=$(echo "$UPDATE_URL" | sed 's|/docker$||')

WEB_PRINT_TEMP_DIR="/tmp/web_print_update_$$"
mkdir -p "$WEB_PRINT_TEMP_DIR"
web_print_success=true

# 下载需要备份的主要文件（从 /update/web_print/ 路径）
for file in "${WEB_PRINT_FILES[@]}"; do
    url="$BASE_URL/web_print/download.php?file=$file"
    temp_file="$WEB_PRINT_TEMP_DIR/$file"
    
    if curl -f -L -o "$temp_file" "$url" --connect-timeout 10 --max-time 60 2>/dev/null; then
        if [ -s "$temp_file" ]; then
            log "  ✓ $file 下载成功"
        else
            rm -f "$temp_file"
            error "  ✗ $file (文件为空)"
            web_print_success=false
            break
        fi
    else
        rm -f "$temp_file" 2>/dev/null
        error "  ✗ $file 下载失败"
        web_print_success=false
        break
    fi
done

# 下载其他文件（失败不中断）
for file in "${WEB_PRINT_OTHER_FILES[@]}"; do
    url="$BASE_URL/web_print/download.php?file=$file"
    temp_file="$WEB_PRINT_TEMP_DIR/$file"
    
    if curl -f -L -o "$temp_file" "$url" --connect-timeout 10 --max-time 60 2>/dev/null; then
        if [ -s "$temp_file" ]; then
            log "  ✓ $file"
        else
            rm -f "$temp_file"
            log "  ⚠ $file (文件为空)"
        fi
    else
        rm -f "$temp_file" 2>/dev/null
        log "  ⚠ $file (下载失败)"
    fi
done

if [ "$web_print_success" = false ]; then
    error "网页打印服务文件下载失败，取消更新"
    rm -rf "$WEB_PRINT_TEMP_DIR"
    exit 1
fi

log "应用网页打印服务更新..."
for file in "${WEB_PRINT_FILES[@]}"; do
    temp_file="$WEB_PRINT_TEMP_DIR/$file"
    target="$WEB_PRINT_DIR/$file"
    
    if [ -f "$temp_file" ]; then
        cp "$temp_file" "$target"
        if [ "$file" = "start.sh" ]; then
            chmod +x "$target"
        else
            chmod 644 "$target"
        fi
        log "  ✓ 已更新: $file"
    fi
done

# 更新其他文件
for file in "${WEB_PRINT_OTHER_FILES[@]}"; do
    temp_file="$WEB_PRINT_TEMP_DIR/$file"
    target="$WEB_PRINT_DIR/$file"
    
    if [ -f "$temp_file" ]; then
        cp "$temp_file" "$target"
        chmod 644 "$target"
        log "  ✓ 已更新: $file"
    fi
done

chmod 755 "$WEB_PRINT_DIR/uploads" 2>/dev/null || true

rm -rf "$WEB_PRINT_TEMP_DIR"

if [ "$web_print_success" = true ]; then
    log "  ✓ 网页打印服务更新完成"
else
    log "  ⚠ 部分网页打印文件更新失败"
fi

# 检查并重启网页打印服务
if command -v supervisorctl > /dev/null 2>&1; then
    # 先检查服务是否存在
    if supervisorctl avail | grep -q "^web_print"; then
        log "  检查网页打印服务状态..."
        # 检查是否正在运行
        if supervisorctl status web_print 2>/dev/null | grep -q "RUNNING"; then
            log "  网页打印服务正在运行，执行重启..."
            supervisorctl restart web_print
            log "  ✓ 网页打印服务已重启"
        else
            log "  网页打印服务未运行，尝试启动..."
            supervisorctl start web_print
            log "  ✓ 网页打印服务已启动"
        fi
    else
        log "  ⚠ 网页打印服务未配置到supervisor，跳过重启"
    fi
else
    error "  ✗ supervisorctl未找到"
fi

log "========== 更新完成 =========="
log "备份位置: $BACKUP_DIR/backup_$TIMESTAMP"
exit 0