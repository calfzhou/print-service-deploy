# 网页打印服务

基于PHP的网页打印服务，提供文件上传、预览和打印功能。

## 功能特性

- 📁 文件上传（PDF/Word/Excel/图片）
- 🔄 自动转换为PDF（LibreOffice/ImageMagick）
- 👁️ PDF在线预览
- 🖨️ 打印选项配置（份数、方向、纸张、缩放等）
- 🧹 自动清理临时文件

## 访问地址

- 本地: http://localhost:8080
- 局域网: http://[服务器IP]:8080

## 支持的文件格式

- PDF (.pdf)
- Word (.doc, .docx)
- Excel (.xls, .xlsx)
- 图片 (.png, .jpg, .jpeg, .gif, .bmp)

## 打印选项

- 打印范围（全部/当前页/自定义）
- 打印份数
- 打印方向（纵向/横向）
- 纸张大小（A4/A3/A5/B5/Letter/16K）
- 缩放比例
- 双面打印
- 颜色模式
- 逐份打印

## 依赖

- PHP 7.4+
- CUPS
- LibreOffice（文档转换）
- ImageMagick（图片转换）
