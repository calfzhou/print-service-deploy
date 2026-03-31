#!/bin/bash

if [ -f /etc/printer-device-id ]; then
    DEVICE_ID=$(cat /etc/printer-device-id)
else
    echo "未找到设备ID文件 /etc/printer-device-id"
    echo "请先启动打印机服务，服务会自动生成设备ID"
    echo ""
    echo "  sudo systemctl start websocket-printer"
    echo ""
    exit 1
fi

if [ -z "$DEVICE_ID" ]; then
    echo "设备ID为空"
    exit 1
fi

QR_CONTENT="device://$DEVICE_ID"

echo "========================================"
echo "  设备二维码生成器"
echo "========================================"
echo ""
echo "设备ID: $DEVICE_ID"
echo "二维码内容: $QR_CONTENT"
echo ""

if command -v qrencode &> /dev/null; then
    echo "在终端显示二维码:"
    echo ""
    qrencode -t ANSI "$QR_CONTENT"
    echo ""
    
    OUTPUT_FILE="/tmp/device_qr.png"
    qrencode -o "$OUTPUT_FILE" -s 10 "$QR_CONTENT"
    echo "二维码图片已保存到: $OUTPUT_FILE"
else
    echo "未安装 qrencode，请先安装:"
    echo "  sudo apt install qrencode"
    echo ""
    echo "或者手动将以下内容生成二维码:"
    echo "  $QR_CONTENT"
fi

echo ""
echo "========================================"