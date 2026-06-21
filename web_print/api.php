<?php
/**
 * 网页打印服务 API
 * 提供打印机列表获取、文件转换和打印功能
 */

// 设置PHP上传限制（运行时配置）
ini_set('upload_max_filesize', '500M');
ini_set('post_max_size', '510M');
ini_set('max_execution_time', '300');
ini_set('memory_limit', '256M');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 配置
define('UPLOAD_DIR', '/tmp/web_print_uploads');
define('MAX_FILE_SIZE', 500 * 1024 * 1024); // 500MB
define('ALLOWED_EXTENSIONS', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'gif', 'bmp']);

// 确保上传目录存在
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

/**
 * 获取打印机列表
 */
function getPrinters(): array
{
    $printers = [];
    $defaultPrinter = '';
    
    // 方法1: 使用 lpstat -v 获取打印机（最可靠）
    $output = [];
    exec('lpstat -v 2>/dev/null', $output);
    foreach ($output as $line) {
        // 格式: device for PrinterName: ...
        // 中文: PrinterName 的设备为 ...
        if (preg_match('/^device for\s+(\S+):/', $line, $matches) ||
            preg_match('/^(\S+)\s+的设备/', $line, $matches)) {
            $name = trim($matches[1], ':');
            $printers[$name] = [
                'name' => $name,
                'status' => 'online',
                'is_default' => false
            ];
        }
    }
    
    // 方法2: 使用 lpstat -p 获取打印机状态
    $output = [];
    exec('lpstat -p 2>/dev/null', $output);
    foreach ($output as $line) {
        // 英文: printer PrinterName is idle/enabled/disabled
        // 中文: 打印机 PrinterName 目前空闲/已启用/已禁用
        if (preg_match('/^printer\s+(\S+)\s+/', $line, $matches) ||
            preg_match('/^打印机\s+(\S+)\s+/', $line, $matches)) {
            $name = $matches[1];
            $status = 'online';
            if (strpos($line, 'disabled') !== false || strpos($line, '禁用') !== false) {
                $status = 'offline';
            }
            if (!isset($printers[$name])) {
                $printers[$name] = ['name' => $name, 'status' => $status, 'is_default' => false];
            } else {
                $printers[$name]['status'] = $status;
            }
        }
    }
    
    // 方法3: 使用 lpstat -a 获取接受请求的打印机
    $output = [];
    exec('lpstat -a 2>/dev/null', $output);
    foreach ($output as $line) {
        // 英文: PrinterName accepting requests
        // 中文: PrinterName 自从 ... 开始接受请求
        if (preg_match('/^(\S+)\s+(accepting|自从)/', $line, $matches)) {
            $name = $matches[1];
            if (!isset($printers[$name])) {
                $printers[$name] = ['name' => $name, 'status' => 'online', 'is_default' => false];
            }
        }
    }
    
    // 获取默认打印机
    $output = [];
    exec('lpstat -d 2>/dev/null', $output);
    foreach ($output as $line) {
        // 英文: system default destination: PrinterName
        // 中文: 系统默认目标: PrinterName 或 无系统默认目标
        if (preg_match('/destination:\s*(\S+)/', $line, $matches) ||
            preg_match('/默认目标:\s*(\S+)/', $line, $matches)) {
            $defaultPrinter = trim($matches[1]);
        }
    }
    
    // 设置默认打印机标记
    if ($defaultPrinter && isset($printers[$defaultPrinter])) {
        $printers[$defaultPrinter]['is_default'] = true;
    }
    
    // 如果仍然没有找到，尝试读取CUPS配置
    if (empty($printers)) {
        $confFile = '/etc/cups/printers.conf';
        if (file_exists($confFile)) {
            $content = file_get_contents($confFile);
            if (preg_match_all('/<Printer\s+([^>]+)>/', $content, $matches)) {
                foreach ($matches[1] as $name) {
                    $printers[$name] = [
                        'name' => $name,
                        'status' => 'unknown',
                        'is_default' => ($name === $defaultPrinter)
                    ];
                }
            }
        }
    }
    
    return array_values($printers);
}

/**
 * 将文件转换为PDF（如果需要）
 */
function convertToPdf(string $filePath, string $ext): ?string
{
    // PDF文件直接返回
    if ($ext === 'pdf') {
        return $filePath;
    }
    
    $dir = dirname($filePath);
    $baseName = pathinfo($filePath, PATHINFO_FILENAME);
    $pdfPath = $dir . '/' . $baseName . '.pdf';
    
    // 图片转PDF
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'bmp'])) {
        // 使用 ImageMagick
        $cmd = "convert " . escapeshellarg($filePath) . " " . escapeshellarg($pdfPath) . " 2>&1";
        exec($cmd, $output, $ret);
        if ($ret === 0 && file_exists($pdfPath)) {
            return $pdfPath;
        }
        
        // 使用 img2pdf
        $cmd = "img2pdf " . escapeshellarg($filePath) . " -o " . escapeshellarg($pdfPath) . " 2>&1";
        exec($cmd, $output, $ret);
        if ($ret === 0 && file_exists($pdfPath)) {
            return $pdfPath;
        }
    }
    
    // Office文档转PDF
    if (in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'txt', 'rtf'])) {
        // 使用 LibreOffice（需要设置HOME环境变量）
        $homeDir = '/tmp/.libreoffice_home';
        if (!is_dir($homeDir)) {
            mkdir($homeDir, 0755, true);
        }
        
        $loCmd = '/usr/bin/libreoffice';
        if (!file_exists($loCmd)) {
            $loCmd = '/usr/bin/soffice';
        }
        
        if (file_exists($loCmd)) {
            // LibreOffice需要HOME环境变量
            $cmd = "HOME=" . escapeshellarg($homeDir) . " " . $loCmd . " --headless --convert-to pdf --outdir " . escapeshellarg($dir) . " " . escapeshellarg($filePath) . " 2>&1";
            exec($cmd, $output, $ret);
            
            // LibreOffice可能生成不同的文件名，检查目录中的PDF文件
            if (file_exists($pdfPath)) {
                return $pdfPath;
            }
            
            // 查找可能的PDF文件
            $possiblePdfs = glob($dir . '/*.pdf');
            foreach ($possiblePdfs as $pdf) {
                if (strpos(basename($pdf), $baseName) !== false || 
                    filemtime($pdf) > time() - 60) {
                    return $pdf;
                }
            }
        }
        
        // 使用 unoconv
        $cmd = "unoconv -f pdf -o " . escapeshellarg($pdfPath) . " " . escapeshellarg($filePath) . " 2>&1";
        exec($cmd, $output, $ret);
        if ($ret === 0 && file_exists($pdfPath)) {
            return $pdfPath;
        }
    }
    
    return null;
}

/**
 * 检查命令是否存在
 */
function command_exists(string $cmd): bool
{
    $return = shell_exec("which " . escapeshellarg($cmd) . " 2>/dev/null");
    return !empty(trim($return ?? ''));
}

/**
 * 执行打印任务
 */
function printFile(string $filePath, string $printer, array $options): array
{
    // 构建 lp 命令
    $cmd = 'lp';
    
    // 指定打印机
    $cmd .= ' -d ' . escapeshellarg($printer);
    
    // 打印份数
    if (!empty($options['copies']) && is_numeric($options['copies'])) {
        $copies = max(1, min(999, intval($options['copies'])));
        $cmd .= ' -n ' . $copies;
    }
    
    // 打印选项
    $lpOptions = [];
    
    // 纸张大小
    if (!empty($options['paper_size'])) {
        $paperSize = preg_replace('/[^A-Za-z0-9]/', '', $options['paper_size']);
        $lpOptions[] = 'media=' . $paperSize;
    }
    
    // 打印方向
    if (!empty($options['orientation'])) {
        if ($options['orientation'] === 'landscape') {
            $lpOptions[] = 'orientation-requested=4';
        } else {
            $lpOptions[] = 'orientation-requested=3';
        }
    }
    
    // 双面打印
    if (!empty($options['sides'])) {
        switch ($options['sides']) {
            case 'two-sided-long-edge':
                $lpOptions[] = 'sides=two-sided-long-edge';
                break;
            case 'two-sided-short-edge':
                $lpOptions[] = 'sides=two-sided-short-edge';
                break;
            default:
                $lpOptions[] = 'sides=one-sided';
        }
    }
    
    // 颜色模式
    if (!empty($options['color_mode'])) {
        switch ($options['color_mode']) {
            case 'color':
                $lpOptions[] = 'ColorModel=RGB';
                break;
            case 'monochrome':
            case 'grayscale':
                $lpOptions[] = 'ColorModel=Gray';
                break;
        }
    }
    
    // 打印质量
    if (!empty($options['quality'])) {
        switch ($options['quality']) {
            case 'draft':
                $lpOptions[] = 'print-quality=3';
                break;
            case 'high':
            case 'photo':
                $lpOptions[] = 'print-quality=5';
                break;
            default:
                $lpOptions[] = 'print-quality=4';
        }
    }
    
    // 缩放
    if (!empty($options['scale'])) {
        $scale = $options['scale'];
        if ($scale === 'fit') {
            $lpOptions[] = 'fit-to-page=true';
        } elseif ($scale === 'fill') {
            $lpOptions[] = 'fill=true';
        } elseif (is_numeric($scale)) {
            // 自定义缩放百分比
            $lpOptions[] = 'scaling=' . intval($scale);
        }
    }
    
    // 自定义缩放
    if (!empty($options['custom_scale']) && is_numeric($options['custom_scale'])) {
        $lpOptions[] = 'scaling=' . intval($options['custom_scale']);
    }
    
    // 边距
    if (!empty($options['margins'])) {
        switch ($options['margins']) {
            case 'none':
                $lpOptions[] = 'page-left=0';
                $lpOptions[] = 'page-right=0';
                $lpOptions[] = 'page-top=0';
                $lpOptions[] = 'page-bottom=0';
                break;
            case 'minimum':
                $lpOptions[] = 'page-left=18';
                $lpOptions[] = 'page-right=18';
                $lpOptions[] = 'page-top=18';
                $lpOptions[] = 'page-bottom=18';
                break;
            case 'wide':
                $lpOptions[] = 'page-left=72';
                $lpOptions[] = 'page-right=72';
                $lpOptions[] = 'page-top=72';
                $lpOptions[] = 'page-bottom=72';
                break;
        }
    }
    
    // 逐份打印
    if (!empty($options['collate']) && $options['collate'] === 'true') {
        $lpOptions[] = 'collate=true';
    }
    
    // 打印范围
    if (!empty($options['page_range_text'])) {
        $pageRange = preg_replace('/[^0-9,\-]/', '', $options['page_range_text']);
        if (!empty($pageRange)) {
            $cmd .= ' -P ' . escapeshellarg($pageRange);
        }
    }
    
    // 添加选项
    foreach ($lpOptions as $opt) {
        $cmd .= ' -o ' . escapeshellarg($opt);
    }
    
    // 添加文件
    $cmd .= ' ' . escapeshellarg($filePath);
    
    // 执行打印
    $output = [];
    exec($cmd . ' 2>&1', $output, $returnCode);
    
    $outputStr = implode("\n", $output);
    
    if ($returnCode === 0) {
        // 解析任务ID
        $jobId = '';
        if (preg_match('/request id is\s+(\S+)/', $outputStr, $matches)) {
            $jobId = $matches[1];
        } elseif (preg_match('/(\S+-\d+)/', $outputStr, $matches)) {
            $jobId = $matches[1];
        }
        
        return [
            'success' => true,
            'job_id' => $jobId ?: 'unknown',
            'message' => '打印任务已提交',
            'command' => $cmd
        ];
    } else {
        return [
            'success' => false,
            'error' => '打印失败: ' . $outputStr,
            'command' => $cmd
        ];
    }
}

/**
 * 处理文件上传
 */
function handleUpload(): array
{
    if (!isset($_FILES['file'])) {
        return ['success' => false, 'error' => '未上传文件'];
    }
    
    $file = $_FILES['file'];
    
    // 检查上传错误
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => '文件超过服务器限制',
            UPLOAD_ERR_FORM_SIZE => '文件超过表单限制',
            UPLOAD_ERR_PARTIAL => '文件上传不完整',
            UPLOAD_ERR_NO_FILE => '未选择文件',
            UPLOAD_ERR_NO_TMP_DIR => '服务器临时目录不存在',
            UPLOAD_ERR_CANT_WRITE => '无法写入文件',
        ];
        return ['success' => false, 'error' => $errors[$file['error']] ?? '上传错误'];
    }
    
    // 检查文件大小
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'error' => '文件大小超过限制（最大50MB）'];
    }
    
    // 检查文件扩展名（主要依据扩展名判断，MIME类型作为辅助）
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
        return ['success' => false, 'error' => '不支持的文件格式: ' . $ext];
    }
    
    // 生成唯一文件名（保留原扩展名）
    $newFileName = uniqid('print_', true) . '.' . $ext;
    $targetPath = UPLOAD_DIR . '/' . $newFileName;
    
    // 移动文件
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['success' => false, 'error' => '文件保存失败'];
    }
    
    return ['success' => true, 'path' => $targetPath, 'ext' => $ext];
}

/**
 * 重启web_print PHP内置服务器
 * 适用于Docker、物理机和盒子环境
 */
function restartWebPrintService(): array
{
    $actions = [];
    $output = [];
    $returnCode = 0;
    
    // 1. 查找并终止现有的web_print PHP进程
    exec('ps aux | grep "php.*-S.*8080" | grep -v grep', $output, $returnCode);
    
    if (!empty($output)) {
        foreach ($output as $line) {
            $parts = preg_split('/\s+/', $line);
            $pid = $parts[1] ?? '';
            if (is_numeric($pid)) {
                exec("kill $pid 2>&1", $output, $returnCode);
                if ($returnCode === 0) {
                    $actions[] = "终止web_print进程(PID: $pid)";
                }
            }
        }
        // 等待进程完全终止
        sleep(1);
    }
    
    // 2. 清理临时文件
    $tempDirs = ['/tmp/web_print_uploads', '/tmp/.libreoffice_home'];
    foreach ($tempDirs as $dir) {
        if (is_dir($dir)) {
            $files = glob($dir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            $actions[] = "清理临时目录: $dir";
        }
    }
    
    // 3. 重新启动web_print服务
    $scriptPath = __DIR__ . '/start.sh';
    if (file_exists($scriptPath)) {
        // 使用nohup在后台启动
        $cmd = 'nohup bash ' . escapeshellarg($scriptPath) . ' > /dev/null 2>&1 &';
        exec($cmd, $output, $returnCode);
        
        if ($returnCode === 0) {
            $actions[] = 'web_print服务启动成功';
        } else {
            // 备用方案：直接启动PHP内置服务器
            $cmd = 'cd ' . escapeshellarg(__DIR__) . ' && nohup php -d upload_max_filesize=500M -d post_max_size=510M -d max_execution_time=300 -d memory_limit=512M -S 0.0.0.0:8080 > /dev/null 2>&1 &';
            exec($cmd, $output, $returnCode);
            
            if ($returnCode === 0) {
                $actions[] = 'web_print服务启动成功(直接启动)';
            } else {
                return ['success' => false, 'error' => 'web_print服务启动失败'];
            }
        }
    } else {
        // 没有start.sh脚本，直接启动
        $cmd = 'cd ' . escapeshellarg(__DIR__) . ' && nohup php -d upload_max_filesize=500M -d post_max_size=510M -d max_execution_time=300 -d memory_limit=512M -S 0.0.0.0:8080 > /dev/null 2>&1 &';
        exec($cmd, $output, $returnCode);
        
        if ($returnCode === 0) {
            $actions[] = 'web_print服务启动成功';
        } else {
            return ['success' => false, 'error' => 'web_print服务启动失败'];
        }
    }
    
    // 4. 等待服务启动并检查状态
    sleep(2);
    exec('ps aux | grep "php.*-S.*8080" | grep -v grep', $output, $returnCode);
    
    if (!empty($output)) {
        $actions[] = 'web_print服务运行正常';
    } else {
        $actions[] = '警告：web_print服务可能未正常启动';
    }
    
    // 5. 刷新CUPS打印机列表
    exec('lpstat -r 2>&1', $output, $returnCode);
    if ($returnCode === 0) {
        $actions[] = 'CUPS打印机列表刷新成功';
    }
    
    return [
        'success' => true,
        'message' => 'web_print服务重启完成',
        'actions' => $actions
    ];
}

/**
 * 清理旧文件（超过5分钟的临时文件）
 * 处理上传但未打印的文件，避免占用存储空间
 */
function cleanupOldFiles(): void
{
    $now = time();
    $maxAge = 300; // 5分钟，足够用户完成打印操作
    
    // 清理上传目录中的所有临时文件
    $patterns = [
        UPLOAD_DIR . '/*',
        __DIR__ . '/uploads/*'
    ];
    
    foreach ($patterns as $pattern) {
        $files = glob($pattern);
        foreach ($files as $file) {
            if (is_file($file) && ($now - filemtime($file) > $maxAge)) {
                @unlink($file);
            }
        }
    }
    
    // 清理LibreOffice临时目录
    $loTmpDir = '/tmp/.libreoffice_home';
    if (is_dir($loTmpDir)) {
        $files = glob($loTmpDir . '/*');
        foreach ($files as $file) {
            if (is_file($file) && ($now - filemtime($file) > $maxAge)) {
                @unlink($file);
            }
        }
    }
}

/**
 * 立即清理指定文件
 */
function cleanupFile(string $filePath): void
{
    if (file_exists($filePath)) {
        @unlink($filePath);
    }
}

// 主逻辑
try {
    // 每次请求都尝试清理旧文件（提高清理频率）
    cleanupOldFiles();
    
    // GET 请求 - 获取打印机列表
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';
        
        if ($action === 'printers') {
            $printers = getPrinters();
            echo json_encode([
                'success' => true,
                'printers' => $printers
            ]);
            exit;
        }
        
        echo json_encode(['success' => false, 'error' => '未知操作']);
        exit;
    }
    
    // POST 请求 - 打印文件或转换
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        
        // 重启web_print服务
        if ($action === 'restart') {
            $result = restartWebPrintService();
            echo json_encode($result);
            exit;
        }
        
        // 文件转换为PDF
        if ($action === 'convert') {
            $uploadResult = handleUpload();
            if (!$uploadResult['success']) {
                echo json_encode($uploadResult);
                exit;
            }
            
            $filePath = $uploadResult['path'];
            $fileExt = $uploadResult['ext'] ?? 'pdf';
            
            if ($fileExt === 'pdf') {
                // 已经是PDF，直接返回
                $pdfUrl = 'uploads/' . basename($filePath);
                // 移动到可访问目录
                $webDir = __DIR__ . '/uploads';
                if (!is_dir($webDir)) mkdir($webDir, 0755, true);
                $webPath = $webDir . '/' . basename($filePath);
                copy($filePath, $webPath);
                @unlink($filePath);
                
                echo json_encode([
                    'success' => true,
                    'pdf_url' => 'uploads/' . basename($filePath),
                    'message' => '文件已就绪'
                ]);
                exit;
            }
            
            // 转换为PDF
            $pdfPath = convertToPdf($filePath, $fileExt);
            if ($pdfPath === null) {
                @unlink($filePath);
                echo json_encode([
                    'success' => false,
                    'error' => '转换失败，请确保服务器安装了 LibreOffice 或 ImageMagick'
                ]);
                exit;
            }
            
            // 移动到可访问目录
            $webDir = __DIR__ . '/uploads';
            if (!is_dir($webDir)) mkdir($webDir, 0755, true);
            $pdfName = basename($pdfPath);
            $webPath = $webDir . '/' . $pdfName;
            copy($pdfPath, $webPath);
            @unlink($filePath);
            @unlink($pdfPath);
            
            echo json_encode([
                'success' => true,
                'pdf_url' => 'uploads/' . $pdfName,
                'message' => '转换成功'
            ]);
            exit;
        }
        
        if ($action === 'print') {
            // 检查打印机
            $printer = $_POST['printer'] ?? '';
            if (empty($printer)) {
                echo json_encode(['success' => false, 'error' => '请选择打印机']);
                exit;
            }
            
            // 处理文件上传
            $uploadResult = handleUpload();
            if (!$uploadResult['success']) {
                echo json_encode($uploadResult);
                exit;
            }
            
            $filePath = $uploadResult['path'];
            $fileExt = $uploadResult['ext'] ?? 'pdf';
            $convertedPath = null;
            
            // 如果不是PDF，尝试转换
            if ($fileExt !== 'pdf') {
                $convertedPath = convertToPdf($filePath, $fileExt);
                if ($convertedPath === null) {
                    @unlink($filePath);
                    echo json_encode([
                        'success' => false, 
                        'error' => '文件转换失败，请确保服务器安装了 LibreOffice 或 ImageMagick'
                    ]);
                    exit;
                }
                $filePath = $convertedPath;
            }
            
            // 执行打印
            $options = [
                'copies' => $_POST['copies'] ?? 1,
                'paper_size' => $_POST['paper_size'] ?? 'A4',
                'sides' => $_POST['sides'] ?? 'one-sided',
                'color_mode' => $_POST['color_mode'] ?? 'auto',
                'orientation' => $_POST['orientation'] ?? 'portrait',
                'quality' => $_POST['quality'] ?? 'normal',
                'scale' => $_POST['scale'] ?? '100',
                'custom_scale' => $_POST['custom_scale'] ?? '',
                'margins' => $_POST['margins'] ?? 'default',
                'collate' => $_POST['collate'] ?? 'true',
                'page_range_text' => $_POST['page_range_text'] ?? ''
            ];
            
            $printResult = printFile($filePath, $printer, $options);
            
            // 打印后删除临时文件
            @unlink($uploadResult['path']);
            if ($convertedPath && $convertedPath !== $uploadResult['path']) {
                @unlink($convertedPath);
            }
            
            echo json_encode($printResult);
            exit;
        }
        
        echo json_encode(['success' => false, 'error' => '未知操作']);
        exit;
    }
    
    // OPTIONS 请求（CORS预检）
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => '不支持的请求方法']);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => '服务器错误: ' . $e->getMessage()
    ]);
}
