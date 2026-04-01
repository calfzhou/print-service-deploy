#!/bin/bash
set -e

_d="aHR0cDovL3hpbnByaW50Lnp5c2hhcmUudG9wL3VwZGF0ZS9kb2NrZXI="
DEFAULT_UPDATE_URL=$(echo "$_d" | base64 -d)
UPDATE_URL="${1:-$DEFAULT_UPDATE_URL}"
BACKUP_DIR="/opt/websocket_printer_backup"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
declare -A FILES=(
    ["printer_client.php"]="/opt/websocket_printer/printer_client.php"
    ["supervisord.conf"]="/etc/supervisor/conf.d/supervisord.conf"
    ["cupsd.conf"]="/etc/cups/cupsd.conf"
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
log "备份当前文件..."
for filename in "${!FILES[@]}"; do
    filepath="${FILES[$filename]}"
    if [ -f "$filepath" ]; then
        cp "$filepath" "$BACKUP_DIR/backup_$TIMESTAMP/$filename"
        log "  ✓ 已备份: $filename"
    else
        log "  ⚠ 文件不存在: $filepath"
    fi
done

log "下载新文件..."
TEMP_DIR="/tmp/printer_update_$$"
mkdir -p "$TEMP_DIR"
download_success=true
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

log "应用更新..."
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

log "========== 更新完成 =========="
log "备份位置: $BACKUP_DIR/backup_$TIMESTAMP"
exit 0
