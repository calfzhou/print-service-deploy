#!/usr/bin/env php
<?php
define('CLIENT_VERSION', '1.2.0');
define('LOG_DIR', '/var/log/printer-client/');
define('LOG_RETENTION_DAYS', 2);
define('PRINT_TEMP_DIR', '/tmp/print_jobs/');
define('TEMP_CLEAN_INTERVAL', 300);
define('LIBREOFFICE_CONVERT_TIMEOUT', max(5, (int)($_CFG['libreoffice_timeout'] ?? getenv('LIBREOFFICE_TIMEOUT') ?: 60)));

$_CFG_FILE = dirname(__FILE__) . '/.config';
$_CFG = [];
if (file_exists($_CFG_FILE)) {
    $_CFG = @json_decode(file_get_contents($_CFG_FILE), true) ?: [];
}

$_H = base64_decode('eGlucHJpbnQuenlzaGFyZS50b3A=');
$WS_SERVER = $_CFG['s'] ?? "ws://{$_H}:8089";
$RECONNECT_INTERVAL = $_CFG['r'] ?? 5;
$HEARTBEAT_INTERVAL = $_CFG['h'] ?? 20;
$MAX_RECONNECT_INTERVAL = 60;
$LAST_TEMP_CLEAN = 0;
$LAST_CUPS_JOBS_CACHE = [];
$LAST_CUPS_JOBS_TIME = 0;

// 备用服务器支持
$WS_BACKUP_SERVERS = [];
if (!empty($_CFG['backup_servers']) && is_array($_CFG['backup_servers'])) {
    $WS_BACKUP_SERVERS = $_CFG['backup_servers'];
} elseif (getenv('WS_BACKUP_SERVERS')) {
    $WS_BACKUP_SERVERS = array_filter(array_map('trim', explode(',', getenv('WS_BACKUP_SERVERS'))));
}

// HTTP API 备用通道支持
$HTTP_API_URL = '';
if (preg_match('/ws:\/\/([^:\/]+):?(\d+)?/', $WS_SERVER, $m)) {
    $host = $m[1];
    $HTTP_API_URL = "http://{$host}:8092";  // 修复：HTTP API服务器运行在8092端口
}
// 配置文件或环境变量可以覆盖默认值
if (!empty($_CFG['http_api_url'])) {
    $HTTP_API_URL = $_CFG['http_api_url'];
} elseif (getenv('HTTP_API_URL')) {
    $HTTP_API_URL = getenv('HTTP_API_URL');
}
$HTTP_API_POLL_INTERVAL = $_CFG['http_api_poll_interval'] ?? getenv('HTTP_API_POLL_INTERVAL') ?: 5;
$WS_RECOVERY_CHECK_INTERVAL = $_CFG['ws_recovery_check_interval'] ?? getenv('WS_RECOVERY_CHECK_INTERVAL') ?: 30;

// WebSocket假死检测超时时间（秒）
$WS_ZOMBIE_TIMEOUT = $_CFG['ws_zombie_timeout'] ?? getenv('WS_ZOMBIE_TIMEOUT') ?: 120;

// 当前服务器索引
$CURRENT_SERVER_INDEX = 0;
$ALL_WS_SERVERS = array_merge([$WS_SERVER], $WS_BACKUP_SERVERS);

function initLogDir(): void
{
    if (!is_dir(LOG_DIR)) {
        @mkdir(LOG_DIR, 0755, true);
    }
}

function writeLog(string $level, string $message, array $context = []): void
{
    initLogDir();
    
    $date = date('Y-m-d');
    $time = date('Y-m-d H:i:s');
    $logFile = LOG_DIR . "client_{$date}.log";
    
    $logLine = "[{$time}] [{$level}] {$message}";
    if (!empty($context)) {
        $logLine .= " " . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    $logLine .= "\n";
    
    $result = file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    if ($result === false) {
        error_log("日志写入失败: $logFile - $message");
    }
    echo $logLine;
}

function httpApiLog($level, $message, $context = [])
{
    writeLog("[HTTP-API] {$level}", $message, $context);
}

function cleanOldLogs(): void
{
    if (!is_dir(LOG_DIR)) return;
    
    $files = glob(LOG_DIR . 'client_*.log');
    $cutoffTime = time() - (LOG_RETENTION_DAYS * 86400);
    
    foreach ($files as $file) {
        if (filemtime($file) < $cutoffTime) {
            @unlink($file);
            writeLog('INFO', "清理过期日志: " . basename($file));
        }
    }
}

function getLogContent(int $lines = 200, string $date = ''): array
{
    initLogDir();
    
    if (empty($date)) {
        $date = date('Y-m-d');
    }
    
    $logFile = LOG_DIR . "client_{$date}.log";
    
    if (!file_exists($logFile)) {
        return [
            'success' => false,
            'message' => '日志文件不存在',
            'date' => $date,
            'logs' => []
        ];
    }
    
    $fileSize = filesize($logFile);
    if ($fileSize === false) {
        return [
            'success' => false,
            'message' => '无法获取文件大小',
            'date' => $date,
            'logs' => []
        ];
    }
    
    // 小文件直接读取
    if ($fileSize < 1024 * 1024) { // 小于1MB
        $content = @file_get_contents($logFile);
        if ($content === false) {
            return [
                'success' => false,
                'message' => '读取日志失败',
                'date' => $date,
                'logs' => []
            ];
        }
        
        $allLines = explode("\n", trim($content));
        $logLines = array_slice($allLines, -$lines);
        
        return [
            'success' => true,
            'date' => $date,
            'total_lines' => count($allLines),
            'returned_lines' => count($logLines),
            'logs' => $logLines
        ];
    }
    
    // 大文件使用反向读取，只读取最后N行
    try {
        $handle = fopen($logFile, 'r');
        if (!$handle) {
            return [
                'success' => false,
                'message' => '无法打开日志文件',
                'date' => $date,
                'logs' => []
            ];
        }
        
        $logLines = [];
        $lineCount = 0;
        $pos = -2; // 从文件末尾开始
        $currentLine = '';
        
        fseek($handle, 0, SEEK_END);
        $fileSize = ftell($handle);
        
        while ($lineCount < $lines && abs($pos) < $fileSize) {
            fseek($handle, $pos, SEEK_END);
            $char = fgetc($handle);
            
            if ($char === "\n" && $currentLine !== '') {
                $logLines[] = $currentLine;
                $lineCount++;
                $currentLine = '';
            } elseif ($char !== "\r") {
                $currentLine = $char . $currentLine;
            }
            
            $pos--;
        }
        
        if ($currentLine !== '') {
            $logLines[] = $currentLine;
            $lineCount++;
        }
        
        fclose($handle);
        
        // 获取总行数（快速估算）
        $totalLines = $lineCount + 100; // 估算值，避免再次遍历整个文件
        
        return [
            'success' => true,
            'date' => $date,
            'total_lines' => $totalLines,
            'returned_lines' => count($logLines),
            'logs' => array_reverse($logLines) // 反转以保持正确顺序
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => '读取日志异常: ' . $e->getMessage(),
            'date' => $date,
            'logs' => []
        ];
    }
}

function getLogDates(): array
{
    initLogDir();
    
    $files = glob(LOG_DIR . 'client_*.log');
    $dates = [];
    
    foreach ($files as $file) {
        $basename = basename($file, '.log');
        if (preg_match('/client_(\d{4}-\d{2}-\d{2})/', $basename, $m)) {
            $dates[] = [
                'date' => $m[1],
                'size' => filesize($file),
                'modified' => date('Y-m-d H:i:s', filemtime($file))
            ];
        }
    }
    
    usort($dates, function($a, $b) {
        return strcmp($b['date'], $a['date']);
    });
    
    return $dates;
}

function cleanTempPrintFiles(): array
{
    $cleaned = 0;
    $errors = 0;
    
    if (is_dir(PRINT_TEMP_DIR)) {
        $files = glob(PRINT_TEMP_DIR . '*');
        $cutoffTime = time() - 300;
        
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoffTime) {
                if (@unlink($file)) {
                    $cleaned++;
                } else {
                    $errors++;
                }
            }
        }
    }
    
    $tmpPatterns = [
        '/tmp/print_*.txt',
        '/tmp/print_*.pdf',
        '/tmp/test_print_*.txt',
        '/tmp/*.ppd',
        '/tmp/printer_client_*.php',
        '/tmp/lu*',  
    ];
    
    foreach ($tmpPatterns as $pattern) {
        $files = glob($pattern);
        $cutoffTime = time() - 300;
        
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoffTime) {
                if (@unlink($file)) {
                    $cleaned++;
                } else {
                    $errors++;
                }
            }
        }
    }
    
    $loTmpDir = '/tmp/.libreoffice';
    if (is_dir($loTmpDir)) {
        $cutoffTime = time() - 600; 
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($loTmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getMTime() < $cutoffTime) {
                if (@unlink($file->getPathname())) {
                    $cleaned++;
                }
            }
        }
    }
    
    $cupsSpoolDir = '/var/spool/cups';
    if (is_dir($cupsSpoolDir) && is_readable($cupsSpoolDir)) {
        $files = glob($cupsSpoolDir . '/d*');
        $cutoffTime = time() - 3600; 
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoffTime) {
                if (@unlink($file)) {
                    $cleaned++;
                }
            }
        }
    }
    
    $cupsLogDir = '/var/log/cups';
    if (is_dir($cupsLogDir)) {
        $logPatterns = [
            $cupsLogDir . '/access_log.*',
            $cupsLogDir . '/error_log.*',
            $cupsLogDir . '/page_log.*',
        ];
        $cutoffTime = time() - (2 * 86400);
        foreach ($logPatterns as $pattern) {
            $files = glob($pattern);
            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < $cutoffTime) {
                    if (@unlink($file)) {
                        $cleaned++;
                    }
                }
            }
        }
    }
    
    if ($cleaned > 0) {
        writeLog('INFO', "清理临时文件: {$cleaned} 个", ['errors' => $errors]);
    }
    
    return ['cleaned' => $cleaned, 'errors' => $errors];
}


function getCupsJobs(string $printerName = ''): array
{
    global $LAST_CUPS_JOBS_CACHE, $LAST_CUPS_JOBS_TIME;
    
    // 缓存5秒，避免频繁查询
    $cacheKey = $printerName ?: 'all';
    $now = time();
    if (isset($LAST_CUPS_JOBS_CACHE[$cacheKey]) && ($now - $LAST_CUPS_JOBS_TIME) < 5) {
        writeLog('DEBUG', "使用CUPS任务缓存", ['cache_key' => $cacheKey]);
        return $LAST_CUPS_JOBS_CACHE[$cacheKey];
    }
    
    $jobs = [];
    
    $cmd = 'LANG=C lpstat -o';
    if (!empty($printerName)) {
        $cmd .= ' ' . escapeshellarg($printerName);
    }
    $cmd .= ' 2>&1';
    
    $output = [];
    $returnCode = 0;
    exec($cmd, $output, $returnCode);
    
    // 如果命令超时或失败，直接返回空数组
    if ($returnCode !== 0) {
        writeLog('WARN', "获取CUPS任务失败", ['cmd' => $cmd, 'return_code' => $returnCode]);
        return [];
    }
    
    foreach ($output as $line) {
        if (preg_match('/^(\S+)-(\d+)\s+(\S+)\s+(\d+)\s+(.+)$/', $line, $m)) {
            $printer = $m[1];
            $jobId = $m[2];
            $user = $m[3];
            $size = intval($m[4]);
            $timeStr = $m[5];
            
            $jobInfo = getCupsJobInfo($printer . '-' . $jobId);
            
            $jobs[] = [
                'id' => $jobId,
                'printer' => $printer,
                'user' => $user,
                'size' => formatFileSizeForJobs($size),
                'size_bytes' => $size,
                'creation_time' => strtotime($timeStr),
                'title' => $jobInfo['title'] ?? '',
                'state' => $jobInfo['state'] ?? 'pending',
                'pages' => $jobInfo['pages'] ?? ''
            ];
        }
    }
    
    if (empty($jobs)) {
        $lpqCmd = 'LANG=C lpq -a 2>&1';
        $lpqOutput = [];
        $lpqReturnCode = 0;
        exec($lpqCmd, $lpqOutput, $lpqReturnCode);
        
        // 如果lpq也失败，直接返回空数组
        if ($lpqReturnCode !== 0) {
            writeLog('WARN', "lpq命令失败", ['cmd' => $lpqCmd, 'return_code' => $lpqReturnCode]);
            return [];
        }
        
        foreach ($lpqOutput as $line) {
            if (preg_match('/^(\w+)\s+(\S+)\s+(\d+)\s+(.+?)\s+(\d+)\s*bytes/', $line, $m)) {
                $jobs[] = [
                    'id' => $m[3],
                    'printer' => '',
                    'user' => $m[2],
                    'title' => trim($m[4]),
                    'size' => formatFileSizeForJobs(intval($m[5])),
                    'size_bytes' => intval($m[5]),
                    'state' => strtolower($m[1]) === 'active' ? 'processing' : 'pending',
                    'creation_time' => time(),
                    'pages' => ''
                ];
            }
        }
    }
    
    // 更新缓存
    $LAST_CUPS_JOBS_CACHE[$cacheKey] = $jobs;
    $LAST_CUPS_JOBS_TIME = $now;
    
    return $jobs;
}


function getCupsJobInfo(string $jobId): array
{
    $info = [
        'title' => '',
        'state' => 'pending',
        'pages' => ''
    ];
    
    $cmd = 'LANG=C lpstat -l ' . escapeshellarg($jobId) . ' 2>&1';
    $output = [];
    $returnCode = 0;
    exec($cmd, $output, $returnCode);
    
    // 如果命令超时或失败，返回默认信息
    if ($returnCode !== 0) {
        writeLog('DEBUG', "获取任务详情失败", ['job_id' => $jobId, 'return_code' => $returnCode]);
        return $info;
    }
    
    $fullOutput = implode("\n", $output);
    
    if (preg_match('/job-name[=:]\s*(.+)/i', $fullOutput, $m)) {
        $info['title'] = trim($m[1]);
    }
    
    if (stripos($fullOutput, 'printing') !== false || stripos($fullOutput, 'processing') !== false) {
        $info['state'] = 'processing';
    } elseif (stripos($fullOutput, 'held') !== false) {
        $info['state'] = 'held';
    } elseif (stripos($fullOutput, 'stopped') !== false) {
        $info['state'] = 'stopped';
    } elseif (stripos($fullOutput, 'canceled') !== false || stripos($fullOutput, 'cancelled') !== false) {
        $info['state'] = 'canceled';
    } elseif (stripos($fullOutput, 'aborted') !== false) {
        $info['state'] = 'aborted';
    } elseif (stripos($fullOutput, 'completed') !== false) {
        $info['state'] = 'completed';
    }
    
    if (preg_match('/pages[=:]\s*(\d+)/i', $fullOutput, $m)) {
        $info['pages'] = $m[1];
    }
    
    return $info;
}

/**
 * 取消CUPS打印任务
 * @param string $jobId 任务ID
 * @param string $printerName 可选，打印机名称
 * @return array 操作结果
 */
function cancelCupsJob(string $jobId, string $printerName = ''): array
{
    echo "[cancelCupsJob] 取消任务: $jobId, 打印机: $printerName\n";
    
    $jobSpec = $jobId;
    if (!empty($printerName) && strpos($jobId, '-') === false) {
        $jobSpec = $printerName . '-' . $jobId;
    }
    
    $cmd = 'cancel ' . escapeshellarg($jobSpec) . ' 2>&1';
    $output = [];
    $returnCode = 0;
    exec($cmd, $output, $returnCode);
    
    echo "[cancelCupsJob] 命令: $cmd, 返回码: $returnCode, 输出: " . implode(' ', $output) . "\n";
    
    if ($returnCode === 0) {
        return ['success' => true, 'message' => "任务 $jobId 已取消"];
    } else {
        $cmd2 = 'lprm ' . escapeshellarg($jobId) . ' 2>&1';
        $output2 = [];
        exec($cmd2, $output2, $returnCode2);
        
        if ($returnCode2 === 0) {
            return ['success' => true, 'message' => "任务 $jobId 已取消"];
        }
        
        return ['success' => false, 'message' => '取消失败: ' . implode("\n", $output)];
    }
}

function formatFileSizeForJobs(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    } elseif ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    } else {
        return round($bytes / 1048576, 1) . ' MB';
    }
}

function getDeviceStatus(): array
{
    $status = [
        'device_id' => getDeviceId(),
        'version' => CLIENT_VERSION,
        'uptime' => @file_get_contents('/proc/uptime'),
        'load_avg' => sys_getloadavg(),
        'memory' => [],
        'disk' => [],
        'temp_files' => 0
    ];
    
    $memInfo = @file_get_contents('/proc/meminfo');
    if ($memInfo) {
        if (preg_match('/MemTotal:\s+(\d+)/', $memInfo, $m)) {
            $status['memory']['total'] = intval($m[1]) * 1024;
        }
        if (preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $m)) {
            $status['memory']['available'] = intval($m[1]) * 1024;
        }
    }
    
    $status['disk']['total'] = @disk_total_space('/');
    $status['disk']['free'] = @disk_free_space('/');
    
    if (is_dir(PRINT_TEMP_DIR)) {
        $status['temp_files'] = count(glob(PRINT_TEMP_DIR . '*'));
    }
    
    return $status;
}

function convertPdfToImages(string $pdfFile, string $tmpDir, string $paperSize, string $orientation, int $pageFrom = 1, int $pageTo = 999999): array
{
    try {
        // 纸张尺寸映射（像素，300 DPI）
        $paperSizes = [
            'A4' => ['width' => 2480, 'height' => 3508],
            'A5' => ['width' => 1748, 'height' => 2480],
            'A3' => ['width' => 3508, 'height' => 4961],
            'Letter' => ['width' => 2550, 'height' => 3300],
            'Legal' => ['width' => 2550, 'height' => 4200]
        ];
        
        if (!isset($paperSizes[$paperSize])) {
            return ['success' => false, 'error' => "不支持的纸张大小: {$paperSize}"];
        }
        
        $size = $paperSizes[$paperSize];
        
        // 如果是横向，交换宽高
        if (strpos($orientation, 'landscape') !== false) {
            [$size['width'], $size['height']] = [$size['height'], $size['width']];
        }
        
        // 获取PDF页数
        $pageCountCmd = sprintf('pdfinfo %s 2>/dev/null | grep Pages | awk \'{print $2}\'', escapeshellarg($pdfFile));
        $pageCount = intval(trim(shell_exec($pageCountCmd) ?: '0'));
        
        if ($pageCount <= 0) {
            return ['success' => false, 'error' => '无法获取PDF页数'];
        }
        
        // 限制页码范围
        $pageFrom = max(1, $pageFrom);
        $pageTo = min($pageCount, $pageTo);
        
        if ($pageFrom > $pageTo || $pageFrom > $pageCount) {
            return ['success' => false, 'error' => '无效的页码范围'];
        }
        
        writeLog('INFO', "PDF页码范围确定", [
            'total_pages' => $pageCount,
            'page_from' => $pageFrom,
            'page_to' => $pageTo,
            'pages_to_convert' => ($pageTo - $pageFrom + 1)
        ]);
        
        $images = [];
        $density = 300; // 300 DPI
        
        // 使用Ghostscript转换指定页码范围的PDF到PNG
        for ($page = $pageFrom; $page <= $pageTo; $page++) {
            $imageFile = $tmpDir . 'page_' . $page . '_' . uniqid() . '.png';
            
            // Ghostscript命令，使用-dPDFFitPage确保PDF内容缩放而不是裁剪
            // -dFIXEDMEDIA: 固定输出媒体尺寸
            // -dPDFFitPage: 缩放PDF页面以适应输出媒体
            // -dPSFitPage: 缩放PostScript页面以适应输出媒体
            $gsCmd = sprintf(
                'gs -dQUIET -dSAFER -dBATCH -dNOPAUSE -dNOPROMPT -sDEVICE=pngalpha -r%d -dFIXEDMEDIA -dPDFFitPage -dPSFitPage -g%dx%d -dFirstPage=%d -dLastPage=%d -sOutputFile=%s %s 2>&1',
                $density,
                $size['width'],
                $size['height'],
                $page,
                $page,
                escapeshellarg($imageFile),
                escapeshellarg($pdfFile)
            );
            
            writeLog('INFO', "使用Ghostscript转换PDF页面为图片（缩放模式）", [
                'page' => $page,
                'total' => $pageCount,
                'range' => "{$pageFrom}-{$pageTo}",
                'size' => $size['width'] . 'x' . $size['height'],
                'density' => $density,
                'output' => $imageFile,
                'fit_mode' => 'PDFFitPage'
            ]);
            
            exec($gsCmd, $output, $ret);
            
            if ($ret === 0 && file_exists($imageFile)) {
                // 验证图片尺寸
                $imgInfo = getimagesize($imageFile);
                if ($imgInfo) {
                    $images[] = $imageFile;
                    writeLog('INFO', "页面转换成功", [
                        'page' => $page, 
                        'image' => $imageFile,
                        'actual_size' => $imgInfo[0] . 'x' . $imgInfo[1],
                        'expected_size' => $size['width'] . 'x' . $size['height']
                    ]);
                } else {
                    writeLog('WARN', "无法获取图片信息，但文件存在", ['page' => $page, 'image' => $imageFile]);
                    $images[] = $imageFile;
                }
            } else {
                writeLog('ERROR', "页面转换失败", [
                    'page' => $page,
                    'ret' => $ret,
                    'output' => implode('; ', array_slice($output, 0, 3)) // 只显示前3行错误
                ]);
                // 清理已转换的图片
                foreach ($images as $img) {
                    @unlink($img);
                }
                return ['success' => false, 'error' => "页面{$page}转换失败: " . implode('; ', array_slice($output, 0, 2))];
            }
        }
        
        return [
            'success' => true,
            'images' => $images,
            'page_count' => $pageCount,
            'paper_size' => $paperSize,
            'orientation' => $orientation,
            'final_size' => $size['width'] . 'x' . $size['height']
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => '转换异常: ' . $e->getMessage()];
    }
}

function getDeviceId(): string
{
    $idFile = '/etc/printer-device-id';

    if (file_exists($idFile)) {
        $id = trim(@file_get_contents($idFile) ?: '');
        if ($id !== '' && preg_match('/^[0-9a-fA-F]{30,32}$/', $id)) {
            return strtolower($id);
        }
    }

    $randomBytes = random_bytes(15);
    $deviceId = bin2hex($randomBytes);

    $saved = file_put_contents($idFile, $deviceId);
    if ($saved === false) {
        $error = error_get_last();
        $errorMsg = $error ? $error['message'] : '未知错误';
        writeLog('ERROR', "设备ID文件写入失败", [
            'file' => $idFile,
            'error' => $errorMsg
        ]);
        throw new \RuntimeException('无法写入设备ID文件: ' . $idFile . ' - ' . $errorMsg);
    }

    @chmod($idFile, 0644);

    return $deviceId;
}

function getSystemInfo(): array
{
    $info = [
        'hostname' => gethostname(),
        'os' => php_uname('s') . ' ' . php_uname('r'),
        'arch' => php_uname('m'),
        'php_version' => PHP_VERSION,
    ];
    
    $ip = getLocalIp();
    if ($ip) {
        $info['ip'] = $ip;
    }
    
    return $info;
}

function getLocalIp(): string
{
    $ip = @shell_exec("hostname -I 2>/dev/null | awk '{print \$1}'");
    if ($ip && filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $ip = trim($ip);
        if ($ip !== '127.0.0.1') {
            return $ip;
        }
    }
    
    $ip = @shell_exec("ip route get 1.1.1.1 2>/dev/null | grep -oP 'src \\K[0-9.]+'");
    if ($ip && filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return trim($ip);
    }
    
    $output = @shell_exec("ifconfig 2>/dev/null | grep -Eo 'inet (addr:)?([0-9]*\\.){3}[0-9]*' | grep -v '127.0.0.1' | head -1");
    if ($output) {
        preg_match('/([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)/', $output, $matches);
        if (!empty($matches[1])) {
            return $matches[1];
        }
    }
    
    $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ($sock) {
        @socket_connect($sock, "8.8.8.8", 53);
        @socket_getsockname($sock, $ip);
        @socket_close($sock);
        if ($ip && $ip !== '0.0.0.0' && $ip !== '127.0.0.1') {
            return $ip;
        }
    }
    
    return '';
}

function getPrinterList(): array
{
    $printers = [];
    
    $output = [];
    exec('LANG=C lpstat -a 2>&1', $output);
    echo "[getPrinterList] lpstat -a 输出: " . implode(' | ', $output) . "\n";
    
    foreach ($output as $line) {
        if (preg_match('/^(\S+)\s+(accepting|接受)/', $line, $m)) {
            $printers[$m[1]] = ['name' => $m[1], 'uri' => '', 'is_default' => false];
        }
    }
    
    if (empty($printers)) {
        $output2 = [];
        exec('LANG=C lpstat -p 2>&1', $output2);
        echo "[getPrinterList] lpstat -p 输出: " . implode(' | ', $output2) . "\n";
        
        foreach ($output2 as $line) {
            if (preg_match('/^(printer|打印机)\s+(\S+)/', $line, $m)) {
                $name = $m[2];
                $printers[$name] = ['name' => $name, 'uri' => '', 'is_default' => false];
            }
        }
    }
    
    if (empty($printers)) {
        $cupsDir = '/etc/cups/ppd/';
        if (is_dir($cupsDir)) {
            $files = glob($cupsDir . '*.ppd');
            foreach ($files as $file) {
                $name = basename($file, '.ppd');
                $printers[$name] = ['name' => $name, 'uri' => '', 'is_default' => false];
            }
            echo "[getPrinterList] 从PPD目录找到: " . implode(', ', array_keys($printers)) . "\n";
        }
    }
    
    $defaultOutput = [];
    exec('lpstat -d 2>&1', $defaultOutput);
    $defaultPrinter = '';
    if (preg_match('/system default destination:\s*(\S+)/', implode('', $defaultOutput), $m)) {
        $defaultPrinter = $m[1];
    }
    
    $uriOutput = [];
    exec('LANG=C lpstat -v 2>&1', $uriOutput);
    echo "[getPrinterList] lpstat -v 输出: " . implode(' | ', $uriOutput) . "\n";
    
    foreach ($uriOutput as $line) {
        if (preg_match('/device for (\S+):\s*(.+)/i', $line, $m)) {
            $name = rtrim($m[1], ':');
            $uri = trim($m[2]);
            if (isset($printers[$name])) {
                $printers[$name]['uri'] = $uri;
                $printers[$name]['is_default'] = ($name === $defaultPrinter);
            } else {
                $printers[$name] = [
                    'name' => $name,
                    'uri' => $uri,
                    'is_default' => ($name === $defaultPrinter)
                ];
            }
        }
        elseif (preg_match('/^(\S+)\s+的设备[：:]\s*(.+)/', $line, $m)) {
            $name = trim($m[1]);
            $uri = trim($m[2]);
            if (isset($printers[$name])) {
                $printers[$name]['uri'] = $uri;
                $printers[$name]['is_default'] = ($name === $defaultPrinter);
            } else {
                $printers[$name] = [
                    'name' => $name,
                    'uri' => $uri,
                    'is_default' => ($name === $defaultPrinter)
                ];
            }
        }
    }
    
    foreach ($printers as $name => &$printer) {
        $driver = '';
        $description = '';
        $optOutput = [];
        exec('lpoptions -p ' . escapeshellarg($name) . ' -l 2>/dev/null | head -1', $optOutput);
        
        $ppdFile = "/etc/cups/ppd/{$name}.ppd";
        if (file_exists($ppdFile)) {
            $ppdContent = file_get_contents($ppdFile);
            // 优先获取CUPS的Description字段
            if (preg_match('/\*Description:\s*"([^"]+)"/', $ppdContent, $m)) {
                $description = $m[1];
            }
            // 其次使用NickName
            elseif (preg_match('/\*NickName:\s*"([^"]+)"/', $ppdContent, $m)) {
                $driver = $m[1];
                $description = $m[1];
            } 
            // 最后使用ModelName
            elseif (preg_match('/\*ModelName:\s*"([^"]+)"/', $ppdContent, $m)) {
                $driver = $m[1];
                $description = $m[1];
            }
            // 尝试获取更详细的描述信息
            if (preg_match('/\*ShortNickName:\s*"([^"]+)"/', $ppdContent, $m)) {
                $description = $m[1]; // ShortNickName优先于NickName
            }
        }
        
        if (empty($driver)) {
            $lpstatOutput = [];
            exec('LANG=C lpstat -l -p ' . escapeshellarg($name) . ' 2>&1', $lpstatOutput);
            $lpstatStr = implode(' ', $lpstatOutput);
            if (strpos($lpstatStr, 'raw') !== false) {
                $driver = 'Raw Queue';
                if (empty($description)) $description = 'Raw打印队列';
            } elseif (strpos($lpstatStr, 'everywhere') !== false || strpos($lpstatStr, 'IPP') !== false) {
                $driver = 'IPP Everywhere';
                if (empty($description)) $description = 'IPP Everywhere打印机';
            }
        }
        
        // 如果还是没有描述，使用打印机名称
        if (empty($description)) {
            $description = $name;
        }
        
        $printer['driver'] = $driver;
        $printer['description'] = $description;
    }
    unset($printer);
    
    echo "[getPrinterList] 最终找到 " . count($printers) . " 台打印机\n";
    
    return array_values($printers);
}

function detectUsbPrinters(): array
{
    echo "[detectUsbPrinters] 开始检测...\n";
    
    $result = [
        'usb_devices' => [],
        'drivers' => []
    ];
    
    $usbOutput = [];
    exec('lpinfo -v 2>/dev/null', $usbOutput);
    foreach ($usbOutput as $line) {
        if (strpos($line, 'usb://') !== false) {
            if (preg_match('/(usb:\/\/\S+)/', $line, $m)) {
                $uri = trim($m[1]);
                if (preg_match('/usb:\/\/([^\/]+)\/([^?]+)/', $uri, $pm)) {
                    $result['usb_devices'][] = [
                        'uri' => $uri,
                        'brand' => urldecode($pm[1]),
                        'model' => urldecode($pm[2])
                    ];
                }
            }
        }
    }
    echo "[detectUsbPrinters] 找到 " . count($result['usb_devices']) . " 个USB设备\n";
    
    if (!empty($result['usb_devices'])) {
        $brand = $result['usb_devices'][0]['brand'];
        $model = $result['usb_devices'][0]['model'];
        $brandLower = strtolower($brand);
        $modelLower = strtolower($model);
        
        $modelClean = preg_replace('/[^a-z0-9]/i', '', $model);
        $modelParts = preg_split('/[-_\s]+/', $model);
        
        echo "[detectUsbPrinters] 品牌: $brand, 型号: $model\n";
        echo "[detectUsbPrinters] 关键字: $modelClean, 部分: " . implode(',', $modelParts) . "\n";
        
        $allDrivers = [];
        exec("LANG=C lpinfo -m 2>/dev/null | grep -i " . escapeshellarg($brandLower), $allDrivers);
        
        $matchedDrivers = [];
        $brandOnlyDrivers = [];
        
        foreach ($allDrivers as $line) {
            if (!preg_match('/^(\S+)\s+(.+)/', $line, $m)) continue;
            
            $ppd = trim($m[1]);
            $name = trim($m[2]);
            $nameLower = strtolower($name);
            $ppdLower = strtolower($ppd);
            
            $score = 0;
            
            if (stripos($nameLower, $modelClean) !== false || stripos($ppdLower, $modelClean) !== false) {
                $score = 100;
            }
            else {
                foreach ($modelParts as $part) {
                    $partClean = preg_replace('/[^a-z0-9]/i', '', $part);
                    if (strlen($partClean) >= 2) {
                        if (stripos($nameLower, $partClean) !== false || stripos($ppdLower, $partClean) !== false) {
                            $score += 30;
                        }
                    }
                }
            }
            
            if (stripos($nameLower, $brandLower) !== false) {
                $score += 10;
            }
            
            if ($score >= 30) {
                $matchedDrivers[] = ['ppd' => $ppd, 'name' => $name, 'score' => $score];
            } else if ($score >= 10) {
                $brandOnlyDrivers[] = ['ppd' => $ppd, 'name' => $name, 'score' => $score];
            }
        }
        
        usort($matchedDrivers, function($a, $b) { return $b['score'] - $a['score']; });
        usort($brandOnlyDrivers, function($a, $b) { return $b['score'] - $a['score']; });
        
        $maxDrivers = 18;
        $count = 0;
        
        foreach ($matchedDrivers as $d) {
            if ($count >= $maxDrivers) break;
            $result['drivers'][] = ['ppd' => $d['ppd'], 'name' => '★ ' . $d['name']];
            $count++;
        }
        
        foreach ($brandOnlyDrivers as $d) {
            if ($count >= $maxDrivers) break;
            $result['drivers'][] = ['ppd' => $d['ppd'], 'name' => $d['name']];
            $count++;
        }
        
        echo "[detectUsbPrinters] 匹配到 " . count($matchedDrivers) . " 个精确驱动, " . count($brandOnlyDrivers) . " 个品牌驱动, 显示 $count 个\n";
    }
    
    $result['drivers'][] = ['ppd' => 'drv:///sample.drv/generic.ppd', 'name' => '【通用】Generic PostScript'];
    $result['drivers'][] = ['ppd' => 'drv:///sample.drv/generpcl.ppd', 'name' => '【通用】Generic PCL'];
    $result['drivers'][] = ['ppd' => 'everywhere', 'name' => '【通用】IPP Everywhere'];
    $result['drivers'][] = ['ppd' => 'driverless', 'name' => '【通用】Driverless (无驱动)'];
    $result['drivers'][] = ['ppd' => 'raw', 'name' => '【原始】Raw Queue (不推荐)'];
    
    echo "[detectUsbPrinters] 找到 " . count($result['drivers']) . " 个驱动\n";
    
    return $result;
}

function addPrinter(string $name, string $uri, string $driver): array
{
    $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    $name = preg_replace('/_+/', '_', $name);
    $name = trim($name, '_');
    if (empty($name)) {
        $name = 'Printer_' . time();
    }
    
    echo "[addPrinter] 原始名称: $name\n";
    echo "[addPrinter] 清理后名称: $name, URI: $uri, 驱动: $driver\n";
    
    if (empty($uri) || strpos($uri, '://') === false) {
        return ['success' => false, 'message' => '无效的打印机URI'];
    }
    
    exec('lpadmin -x ' . escapeshellarg($name) . ' 2>/dev/null');
    
    $fallbackDrivers = [
        $driver,
        'drv:///sample.drv/generic.ppd',
        'drv:///sample.drv/generpcl.ppd',
        'everywhere',
        'driverless',
        'raw',
    ];
    
    $fallbackDrivers = array_unique($fallbackDrivers);
    
    $lastError = '';
    $usedDriver = '';
    
    foreach ($fallbackDrivers as $tryDriver) {
        $output = [];
        $returnCode = 1;
        
        if ($tryDriver === 'driverless') {
            $cmd = sprintf(
                'lpadmin -p %s -v %s -E 2>&1',
                escapeshellarg($name),
                escapeshellarg($uri)
            );
            echo "[addPrinter] 尝试无驱动模式: $cmd\n";
            exec($cmd, $output, $returnCode);
            
            if ($returnCode !== 0) {
                $ppdFile = "/tmp/{$name}.ppd";
                $driverlessCmd = sprintf('driverless %s > %s 2>&1', escapeshellarg($uri), escapeshellarg($ppdFile));
                exec($driverlessCmd, $dlOutput, $dlCode);
                
                if ($dlCode === 0 && file_exists($ppdFile) && filesize($ppdFile) > 100) {
                    $cmd = sprintf(
                        'lpadmin -p %s -v %s -P %s 2>&1',
                        escapeshellarg($name),
                        escapeshellarg($uri),
                        escapeshellarg($ppdFile)
                    );
                    echo "[addPrinter] 使用driverless生成的PPD: $cmd\n";
                    $output = [];
                    exec($cmd, $output, $returnCode);
                    @unlink($ppdFile);
                }
            }
        } else {
            $cmd = sprintf(
                'lpadmin -p %s -v %s -m %s 2>&1',
                escapeshellarg($name),
                escapeshellarg($uri),
                escapeshellarg($tryDriver)
            );
            echo "[addPrinter] 尝试驱动 $tryDriver: $cmd\n";
            exec($cmd, $output, $returnCode);
        }
        
        echo "[addPrinter] 返回码: $returnCode, 输出: " . implode(' ', $output) . "\n";
        
        if ($returnCode === 0) {
            $usedDriver = $tryDriver;
            break;
        }
        
        $lastError = implode("\n", $output);
    }
    
    if (empty($usedDriver)) {
        return ['success' => false, 'message' => '所有驱动均失败: ' . $lastError];
    }
    
    exec("lpadmin -p " . escapeshellarg($name) . " -E 2>&1", $enableOutput);
    exec("cupsenable " . escapeshellarg($name) . " 2>&1");
    exec("cupsaccept " . escapeshellarg($name) . " 2>&1");
    
    exec("lpstat -p " . escapeshellarg($name) . " 2>&1", $checkOutput, $checkCode);
    
    if ($checkCode === 0) {
        markPrinterSource($name, 'miniprogram');
        
        $msg = "打印机 $name 添加成功";
        if ($usedDriver !== $driver) {
            $msg .= "（使用回退驱动: $usedDriver）";
        }
        return ['success' => true, 'message' => $msg, 'used_driver' => $usedDriver];
    } else {
        return ['success' => false, 'message' => '添加失败: 打印机未能正确配置'];
    }
}

function removePrinter(string $name): array
{
    exec('lpadmin -x ' . escapeshellarg($name) . ' 2>&1', $output, $returnCode);
    
    if ($returnCode === 0) {
        return ['success' => true, 'message' => "打印机 $name 已删除"];
    } else {
        return ['success' => false, 'message' => '删除失败: ' . implode("\n", $output)];
    }
}

function changeDriver(string $printerName, string $newDriver): array
{
    echo "[changeDriver] 打印机: $printerName, 新驱动: $newDriver\n";
    
    $output = [];
    $returnCode = 1;
    
    $uri = '';
    $uriOutput = [];
    exec('LANG=C lpstat -v ' . escapeshellarg($printerName) . ' 2>&1', $uriOutput);
    foreach ($uriOutput as $line) {
        if (preg_match('/device for [^:]+:\s*(.+)/i', $line, $m)) {
            $uri = trim($m[1]);
            break;
        }
        if (preg_match('/(usb:\/\/\S+|ipp:\/\/\S+|socket:\/\/\S+|lpd:\/\/\S+)/', $line, $m)) {
            $uri = trim($m[1]);
            break;
        }
    }
    
    if ($newDriver === 'driverless') {
        if (!empty($uri)) {
            $ppdFile = "/tmp/{$printerName}.ppd";
            $driverlessCmd = sprintf('driverless %s > %s 2>&1', escapeshellarg($uri), escapeshellarg($ppdFile));
            exec($driverlessCmd, $dlOutput, $dlCode);
            
            if ($dlCode === 0 && file_exists($ppdFile) && filesize($ppdFile) > 100) {
                $cmd = sprintf(
                    'lpadmin -p %s -P %s 2>&1',
                    escapeshellarg($printerName),
                    escapeshellarg($ppdFile)
                );
                echo "[changeDriver] 使用driverless生成的PPD: $cmd\n";
                exec($cmd, $output, $returnCode);
                @unlink($ppdFile);
            } else {
                $cmd = sprintf('lpadmin -p %s -v %s -E 2>&1', escapeshellarg($printerName), escapeshellarg($uri));
                echo "[changeDriver] 尝试无驱动模式: $cmd\n";
                exec($cmd, $output, $returnCode);
            }
        } else {
            return ['success' => false, 'message' => '无法获取打印机URI'];
        }
    } else {
        $cmd = sprintf(
            'lpadmin -p %s -m %s 2>&1',
            escapeshellarg($printerName),
            escapeshellarg($newDriver)
        );
        echo "[changeDriver] 执行命令: $cmd\n";
        exec($cmd, $output, $returnCode);
    }
    
    echo "[changeDriver] 返回码: $returnCode, 输出: " . implode(' ', $output) . "\n";
    
    if ($returnCode === 0) {
        exec("cupsenable " . escapeshellarg($printerName) . " 2>&1");
        exec("cupsaccept " . escapeshellarg($printerName) . " 2>&1");
        return ['success' => true, 'message' => "驱动已更换为 $newDriver"];
    } else {
        return ['success' => false, 'message' => '更换失败: ' . implode("\n", $output)];
    }
}

function getAvailableGenericDrivers(): array
{
    $drivers = [];
    
    $checkDrivers = [
        ['ppd' => 'everywhere', 'name' => '【通用】IPP Everywhere'],
        ['ppd' => 'drv:///sample.drv/generic.ppd', 'name' => '【通用】Generic PostScript Printer'],
        ['ppd' => 'drv:///sample.drv/generpcl.ppd', 'name' => '【通用】Generic PCL Laser Printer'],
        ['ppd' => 'lsb/usr/cupsfilters/Generic-PDF_Printer-PDF.ppd', 'name' => '【通用】Generic PDF Printer'],
        ['ppd' => 'raw', 'name' => '【原始】Raw Queue (不推荐)'],
    ];
    
    $availableOutput = [];
    exec('lpinfo -m 2>/dev/null', $availableOutput);
    $availableDrivers = implode("\n", $availableOutput);
    
    foreach ($checkDrivers as $d) {
        if ($d['ppd'] === 'everywhere' || $d['ppd'] === 'raw') {
            $drivers[] = $d;
            continue;
        }
        if (strpos($availableDrivers, $d['ppd']) !== false) {
            $drivers[] = $d;
        }
    }
    
    return $drivers;
}

function upgradeClient(string $downloadUrl): array
{
    echo "[upgradeClient] 开始升级客户端\n";
    
    $currentScript = realpath(__FILE__);
    $backupScript = $currentScript . '.backup';
    $tempScript = '/tmp/printer_client_new.php';
    
    $oldBackups = glob($currentScript . '.backup.*');
    foreach ($oldBackups as $oldBackup) {
        @unlink($oldBackup);
        echo "[upgradeClient] 删除旧备份: $oldBackup\n";
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $downloadUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $newContent = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || empty($newContent)) {
        $errorMsg = "下载新版本失败";
        if ($httpCode !== 200) {
            $errorMsg .= " (状态码: $httpCode)";
        } elseif (empty($newContent)) {
            $errorMsg .= " (文件为空)";
        }
        return ['success' => false, 'message' => $errorMsg];
    }
    
    if (strpos($newContent, '<?php') === false) {
        return ['success' => false, 'message' => '下载的文件不是有效的PHP文件'];
    }
    
    if (file_put_contents($tempScript, $newContent) === false) {
        return ['success' => false, 'message' => '保存临时文件失败'];
    }
    
    exec('php -l ' . escapeshellarg($tempScript) . ' 2>&1', $syntaxOutput, $syntaxCode);
    if ($syntaxCode !== 0) {
        @unlink($tempScript);
        return ['success' => false, 'message' => 'PHP语法错误: ' . implode("\n", $syntaxOutput)];
    }
    
    if (file_exists($backupScript)) {
        @unlink($backupScript);
    }
    
    echo "[upgradeClient] 备份当前文件: {$backupScript}\n";
    if (!copy($currentScript, $backupScript)) {
        @unlink($tempScript);
        return ['success' => false, 'message' => '备份当前文件失败'];
    }
    
    echo "[upgradeClient] 替换文件: {$tempScript} -> {$currentScript}\n";
    if (!copy($tempScript, $currentScript)) {
        copy($backupScript, $currentScript);
        @unlink($tempScript);
        return ['success' => false, 'message' => '替换文件失败'];
    }
    @unlink($tempScript);
    
    chmod($currentScript, 0755);
    
    echo "[upgradeClient] 文件替换成功\n";
    
    $newVersion = '';
    if (preg_match("/'version'\s*=>\s*'([^']+)'/", file_get_contents($currentScript), $m)) {
        $newVersion = $m[1];
    }
    echo "[upgradeClient] 新版本: {$newVersion}\n";
    
    $cmd = "(sleep 3 && systemctl restart websocket-printer) > /dev/null 2>&1 &";
    shell_exec($cmd);
    
    echo "[upgradeClient] 重启命令已发送: {$cmd}\n";
    
    return ['success' => true, 'message' => "升级成功，新版本: {$newVersion}，服务将在3秒后重启"];
}


function rebootDevice(bool $rebootSystem = false): array
{
    if ($rebootSystem) {
        writeLog('WARN', '收到远程重启系统命令，系统将在5秒后重启');
        $cmd = "(sleep 5 && reboot) > /dev/null 2>&1 &";
        shell_exec($cmd);
        return ['success' => true, 'message' => '系统将在5秒后重启'];
    } else {
        writeLog('INFO', '收到远程重启服务命令');
        $cmd = "(sleep 2 && systemctl restart websocket-printer) > /dev/null 2>&1 &";
        shell_exec($cmd);
        return ['success' => true, 'message' => '打印服务将在2秒后重启'];
    }
}

function checkPrinterAvailable(string $printerName): bool
{
    // 添加超时控制，防止在Docker环境中lpstat命令挂起
    $output = [];
    $cmd = sprintf('timeout 3 lpstat -p %s 2>&1', escapeshellarg($printerName));
    exec('LANG=C ' . $cmd, $output, $ret);
    
    // 如果命令超时（返回124）或失败，假定打印机不可用
    if ($ret === 124) {
        echo "[checkPrinterAvailable] lpstat -p 命令超时: $printerName\n";
        return false;
    }
    
    $statusLine = implode(' ', $output);
    
    if (strpos($statusLine, 'disabled') !== false || 
        strpos($statusLine, 'not exist') !== false) {
        return false;
    }
    
    $output2 = [];
    $cmd2 = sprintf('timeout 3 lpstat -a %s 2>&1', escapeshellarg($printerName));
    exec('LANG=C ' . $cmd2, $output2, $ret2);
    
    // 如果命令超时或失败，假定打印机不可用
    if ($ret2 === 124) {
        echo "[checkPrinterAvailable] lpstat -a 命令超时: $printerName\n";
        return false;
    }
    
    $acceptLine = implode(' ', $output2);
    
    if (strpos($acceptLine, 'not accepting') !== false) {
        return false;
    }
    
    return true;
}

function getPrinterSourceFile(): string
{
    return '/etc/printer-sources.json';
}

function getPrinterSources(): array
{
    $file = getPrinterSourceFile();
    if (file_exists($file)) {
        $data = @json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }
    return [];
}

function savePrinterSources(array $sources): void
{
    $file = getPrinterSourceFile();
    file_put_contents($file, json_encode($sources, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function markPrinterSource(string $printerName, string $source): void
{
    $sources = getPrinterSources();
    $sources[$printerName] = [
        'source' => $source,
        'time' => date('Y-m-d H:i:s')
    ];
    savePrinterSources($sources);
}

function syncCupsPrinters(): array
{
    echo "[syncCupsPrinters] 开始同步CUPS打印机...\n";
    
    $printers = getPrinterList();
    $sources = getPrinterSources();
    
    $result = [
        'success' => true,
        'printers' => [],
        'removed' => [],
        'message' => ''
    ];
    
    foreach ($printers as $printer) {
        $name = $printer['name'];
        $uri = $printer['uri'] ?? '';
        $isAvailable = checkPrinterAvailable($name);
        
        $source = 'manual';
        if (isset($sources[$name]) ) {
            $source = $sources[$name]['source'];
        }
        
        echo "[syncCupsPrinters] 打印机 $name (来源: $source, 状态: " . ($isAvailable ? '可用' : '不可用') . ")\n";
        
        $result['printers'][] = [
            'name' => $name,
            'display_name' => $name,
            'uri' => $uri,
            'is_default' => $printer['is_default'] ?? false,
            'status' => $isAvailable ? 'ready' : 'error',
            'source' => $source
        ];
    }
    
    $result['message'] = sprintf(
        '同步完成: 共 %d 台打印机',
        count($result['printers'])
    );
    
    echo "[syncCupsPrinters] {$result['message']}\n";
    
    return $result;
}

function getClientVersion(): array
{
    $scriptPath = realpath(__FILE__);
    $scriptContent = file_get_contents($scriptPath);
    
    $version = CLIENT_VERSION;
    $modTime = filemtime($scriptPath);
    
    // 尝试从脚本中提取版本信息
    if (preg_match("/'version'\s*=>\s*'([^']+)'/", $scriptContent, $m)) {
        $version = $m[1];
    }
    
    // 检查是否为Docker环境
    $isDocker = getenv('DOCKER_ENV') === '1' || file_exists('/.dockerenv');
    
    return [
        'version' => $version,
        'build_time' => date('Y-m-d H:i:s', $modTime),
        'script_path' => $scriptPath,
        'script_size' => filesize($scriptPath),
        'is_docker' => $isDocker,
        'php_version' => PHP_VERSION,
        'system' => php_uname('s') . ' ' . php_uname('r')
    ];
}

// HTTP API处理器类
class HttpApiServer
{
    private $messageQueue = [];
    private $deviceStatus = [];
    private $startTime;
    
    public function __construct()
    {
        $this->startTime = time();
    }
    
    public function handleRequest($method, $path, $data)
    {
        try {
            switch ($path) {
                case 'register':
                    return $this->handleRegister($data);
                case 'poll':
                    return $this->handlePoll($data);
                case 'heartbeat':
                    return $this->handleHeartbeat($data);
                case 'task_complete':
                    return $this->handleTaskComplete($data);
                case 'print_result':
                    return $this->handlePrintResult($data);
                case 'reset_device':
                    return $this->handleResetDevice($data);
                case 'api/status':
                    return $this->getStatus();
                case 'api/health':
                    return $this->getHealth();
                case 'api/devices':
                    return $this->getDevices();
                default:
                    return [
                        'success' => true,
                        'message' => '云打印 HTTP API 服务',
                        'version' => HTTP_API_VERSION,
                        'endpoints' => [
                            '设备' => ['/register', '/poll', '/heartbeat', '/task_complete', '/print_result', '/reset_device'],
                            '管理' => ['/api/status', '/api/health', '/api/devices']
                        ]
                    ];
            }
        } catch (Exception $e) {
            httpApiLog('ERROR', 'HTTP API请求处理异常', ['path' => $path, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => '服务器内部错误'];
        }
    }
    
    private function handleRegister($data)
    {
        $deviceId = $data['device_id'] ?? '';
        if (empty($deviceId)) {
            return ['success' => false, 'message' => '缺少device_id'];
        }
        
        // 清理该设备的旧状态
        unset($this->deviceStatus[$deviceId]);
        unset($this->messageQueue[$deviceId]);
        
        // 重新注册设备
        $this->deviceStatus[$deviceId] = [
            'last_seen' => time(),
            'status' => 'online',
            'registered_at' => time()
        ];
        
        httpApiLog('INFO', '设备HTTP重新注册', ['device_id' => $deviceId]);
        return ['success' => true, 'message' => '注册成功'];
    }
    
    private function handlePoll($data)
    {
        $deviceId = $data['device_id'] ?? '';
        if (empty($deviceId)) {
            return ['success' => false, 'message' => '缺少device_id'];
        }
        
        // 确保设备状态存在，如果不存在则创建
        if (!isset($this->deviceStatus[$deviceId])) {
            $this->deviceStatus[$deviceId] = [
                'last_seen' => 0,
                'status' => 'offline',
                'registered_at' => 0
            ];
        }
        
        // 更新设备状态
        $this->deviceStatus[$deviceId]['last_seen'] = time();
        $this->deviceStatus[$deviceId]['status'] = 'online';
        
        // 获取待处理任务
        $tasks = $this->messageQueue[$deviceId] ?? [];
        $this->messageQueue[$deviceId] = [];
        
        httpApiLog('INFO', '设备HTTP轮询', [
            'device_id' => $deviceId,
            'status' => $this->deviceStatus[$deviceId]['status'],
            'last_seen' => $this->deviceStatus[$deviceId]['last_seen'],
            'tasks_count' => count($tasks)
        ]);
        
        return [
            'success' => true,
            'tasks' => $tasks
        ];
    }
    
    private function handleHeartbeat($data)
    {
        $deviceId = $data['device_id'] ?? '';
        if (empty($deviceId)) {
            return ['success' => false, 'message' => '缺少device_id'];
        }
        
        // 确保设备状态存在，如果不存在则创建
        if (!isset($this->deviceStatus[$deviceId])) {
            $this->deviceStatus[$deviceId] = [
                'last_seen' => 0,
                'status' => 'offline',
                'registered_at' => 0
            ];
        }
        
        // 更新设备状态
        $this->deviceStatus[$deviceId]['last_seen'] = time();
        $this->deviceStatus[$deviceId]['status'] = $data['status'] ?? 'online';
        $this->deviceStatus[$deviceId]['device_info'] = $data['device_info'] ?? [];
        
        httpApiLog('INFO', '设备心跳更新', [
            'device_id' => $deviceId,
            'status' => $this->deviceStatus[$deviceId]['status'],
            'last_seen' => $this->deviceStatus[$deviceId]['last_seen']
        ]);
        
        return ['success' => true, 'message' => '心跳收到'];
    }
    
    private function handleTaskComplete($data)
    {
        $deviceId = $data['device_id'] ?? '';
        $taskId = $data['task_id'] ?? '';
        
        if (empty($deviceId) || empty($taskId)) {
            return ['success' => false, 'message' => '缺少必要参数'];
        }
        
        httpApiLog('INFO', '任务完成', [
            'device_id' => $deviceId,
            'task_id' => $taskId
        ]);
        
        return ['success' => true, 'message' => '任务完成确认'];
    }
    
    private function handlePrintResult($data)
    {
        $deviceId = $data['device_id'] ?? '';
        $jobId = $data['job_id'] ?? '';
        $success = $data['success'] ?? false;
        $message = $data['message'] ?? '';
        
        if (empty($deviceId) || empty($jobId)) {
            return ['success' => false, 'message' => '缺少必要参数'];
        }
        
        httpApiLog('INFO', '打印结果', [
            'device_id' => $deviceId,
            'job_id' => $jobId,
            'success' => $success,
            'message' => $message
        ]);
        
        return ['success' => true, 'message' => '打印结果确认'];
    }
    
    private function handleResetDevice($data)
    {
        $deviceId = $data['device_id'] ?? '';
        if (empty($deviceId)) {
            return ['success' => false, 'message' => '缺少device_id'];
        }
        
        // 清理该设备所有状态
        unset($this->deviceStatus[$deviceId]);
        unset($this->messageQueue[$deviceId]);
        
        httpApiLog('INFO', '设备状态已重置', ['device_id' => $deviceId]);
        return ['success' => true, 'message' => '设备状态已重置'];
    }
    
    private function getStatus()
    {
        return [
            'success' => true,
            'service' => '云打印 HTTP API',
            'version' => HTTP_API_VERSION,
            'uptime' => time() - $this->startTime,
            'devices' => count($this->deviceStatus),
            'online_devices' => count(array_filter($this->deviceStatus, fn($s) => $s['status'] === 'online'))
        ];
    }
    
    private function getHealth()
    {
        return [
            'success' => true,
            'status' => 'healthy',
            'timestamp' => time()
        ];
    }
    
    private function getDevices()
    {
        $devices = [];
        foreach ($this->deviceStatus as $deviceId => $status) {
            $devices[] = [
                'device_id' => $deviceId,
                'status' => $status['status'],
                'last_seen' => $status['last_seen'],
                'registered_at' => $status['registered_at'] ?? 0
            ];
        }
        
        return [
            'success' => true,
            'devices' => $devices
        ];
    }
    
    public function addMessage($deviceId, $message)
    {
        if (!isset($this->messageQueue[$deviceId])) {
            $this->messageQueue[$deviceId] = [];
        }
        
        $this->messageQueue[$deviceId][] = $message;
        
        httpApiLog('INFO', '添加HTTP API消息', [
            'device_id' => $deviceId,
            'queue_size' => count($this->messageQueue[$deviceId])
        ]);
    }
}

function testPrint(string $printerName, string $paperSize = 'A4'): array
{
    echo "[testPrint] 开始测试打印: $printerName\n";
    
    $testContent = "
========================================
        Print Test Page
========================================

Printer: $printerName
Time: " . date('Y-m-d H:i:s') . "
Device: " . getDeviceId() . "

If you can see this page,
the printer is configured correctly!

========================================
";
    
    $tmpFile = '/tmp/test_print_' . time() . '.txt';
    file_put_contents($tmpFile, $testContent);
    
    $cmd = sprintf('lp -d %s -o cpi=12 -o lpi=7 -o media=%s -o job-hold-until=no-hold -o job-priority=50 -o page-delivery=same-order %s 2>&1',
        escapeshellarg($printerName),
        escapeshellarg($paperSize),
        escapeshellarg($tmpFile)
    );
    
    echo "[testPrint] 执行命令: $cmd\n";
    exec($cmd, $output, $returnCode);
    echo "[testPrint] 返回码: $returnCode, 输出: " . implode(' ', $output) . "\n";
    
    @unlink($tmpFile);
    
    if ($returnCode === 0) {
        return ['success' => true, 'message' => '测试页已发送到打印队列'];
    } else {
        return ['success' => false, 'message' => '打印失败: ' . implode("\n", $output)];
    }
}

function executePrint(string $printerName, string $fileContent, string $filename, string $fileExt, int $copies = 1, ?int $pageFrom = null, ?int $pageTo = null, string $colorMode = 'color', string $orientation = 'portrait', bool $isDuplex = false, string $paperSize = 'A4'): array
{
    try {
        writeLog('INFO', "开始打印任务", [
            'printer' => $printerName,
            'filename' => $filename,
            'ext' => $fileExt,
            'copies' => $copies,
            'color_mode' => $colorMode,
            'orientation' => $orientation,
            'page_from' => $pageFrom,
            'page_to' => $pageTo,
            'is_duplex' => $isDuplex,
            'paper_size' => $paperSize,
            'content_size' => strlen($fileContent)
        ]);
    
    // 解析缩放模式：从orientation参数中提取（如 portrait_fit, portrait_none）
    $scaleMode = 'none'; // 默认不缩放
    if (strpos($orientation, '_fit') !== false) {
        $scaleMode = 'fit';
    } elseif (strpos($orientation, '_none') !== false) {
        $scaleMode = 'none';
    }
    
    // 生成缩放选项
    $scalingOption = ($scaleMode === 'fit') ? '-o fit-to-page' : '-o print-scaling=none';
    writeLog('INFO', "缩放模式设置", ['scale_mode' => $scaleMode, 'scaling_option' => $scalingOption]);
    
    // 检查打印机状态和队列
    if (!checkPrinterAvailable($printerName)) {
        writeLog('ERROR', "打印机不可用", ['printer' => $printerName]);
        return ['success' => false, 'message' => '打印机不可用或离线'];
    }
    
    // 检查是否有太多排队任务
    $jobs = getCupsJobs($printerName);
    if (count($jobs) > 10) {
        writeLog('WARN', "打印队列任务过多", ['printer' => $printerName, 'queue_count' => count($jobs)]);
        return ['success' => false, 'message' => '打印队列任务过多，请稍后重试'];
    }
    
    $tmpDir = '/tmp/print_jobs/';
    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0755, true);
    }
    
    $tmpFile = $tmpDir . uniqid('print_') . '.' . $fileExt;
    $decoded = base64_decode($fileContent);
    
    if ($decoded === false) {
        writeLog('ERROR', "文件解码失败", ['filename' => $filename]);
        return ['success' => false, 'message' => '文件解码失败'];
    }
    
    writeLog('INFO', "文件解码成功", ['size' => strlen($decoded), 'tmpFile' => $tmpFile]);
    file_put_contents($tmpFile, $decoded);
    
    $ext = strtolower($fileExt);
    $success = false;
    $output = [];
    
    $lpOptions = buildLpOptions($colorMode, $orientation, $isDuplex, $paperSize);
    
    try {
        if ($ext === 'pdf') {
            $printPdf = $tmpFile;
            $useRotatedPdf = false;
            $landscapeOption = '';
            
            // 强制非A4纸张转换为图片打印，确保内容正确缩放
            if ($paperSize !== 'A4') {
                writeLog('INFO', "非A4纸张强制使用图片方式打印", ['paper_size' => $paperSize]);
                
                // 检查页码参数
                $pageFrom = intval($pageFrom ?? 1);
                $pageTo = intval($pageTo ?? 999999);
                writeLog('INFO', "PDF页码范围", ['page_from' => $pageFrom, 'page_to' => $pageTo]);
                
                $imageResult = convertPdfToImages($printPdf, $tmpDir, $paperSize, $orientation, $pageFrom, $pageTo);
                if ($imageResult['success'] && !empty($imageResult['images'])) {
                    // 逐个打印图片
                    $successCount = 0;
                    $totalImages = count($imageResult['images']);
                    
                    foreach ($imageResult['images'] as $index => $imageFile) {
                        // 使用ImageMagick确保PNG颜色空间正确
                        $processedImg = $tmpDir . 'processed_' . basename($imageFile);
                        $convertCmd = sprintf('convert %s -colorspace sRGB -type TrueColor %s 2>&1',
                            escapeshellarg($imageFile),
                            escapeshellarg($processedImg)
                        );
                        exec($convertCmd, $convertOutput, $convertRet);
                        
                        $printImg = ($convertRet === 0 && file_exists($processedImg)) ? $processedImg : $imageFile;
                        
                        $imgCmd = sprintf('lp -d %s -n %d %s -o media=%s -o job-hold-until=no-hold -o job-priority=50 -o page-delivery=same-order %s 2>&1',
                            escapeshellarg($printerName),
                            $copies,
                            $lpOptions,
                            escapeshellarg($paperSize),
                            escapeshellarg($printImg)
                        );
                        
                        writeLog('INFO', "打印PDF图片", [
                            'page' => $pageFrom + $index, 
                            'total' => $totalImages, 
                            'image' => $printImg,
                            'color_processed' => ($printImg === $processedImg)
                        ]);
                        exec($imgCmd, $imgOutput, $imgRet);
                        
                        // 清理处理后的图片
                        if ($printImg === $processedImg && file_exists($processedImg)) {
                            @unlink($processedImg);
                        }
                        
                        if ($imgRet === 0) {
                            $successCount++;
                        } else {
                            writeLog('ERROR', "PDF图片打印失败", [
                                'page' => $pageFrom + $index,
                                'ret' => $imgRet,
                                'output' => implode('; ', array_slice($imgOutput, 0, 3))
                            ]);
                        }
                        
                        // 清理临时图片文件
                        @unlink($imageFile);
                    }
                    
                    $success = ($successCount === $totalImages);
                    $message = $success ? "PDF图片打印成功 ({$successCount}/{$totalImages}页)" : "PDF图片打印失败 (成功{$successCount}/{$totalImages}页)";
                    
                    writeLog($success ? 'INFO' : 'ERROR', "PDF图片打印完成", [
                        'success' => $success,
                        'success_count' => $successCount,
                        'total_images' => $totalImages,
                        'paper_size' => $paperSize,
                        'page_range' => "{$pageFrom}-{$pageTo}"
                    ]);
                    
                    return [
                        'success' => $success,
                        'message' => $message
                    ];
                } else {
                    writeLog('ERROR', "PDF转图片失败，无法打印非A4纸张", ['error' => $imageResult['error'] ?? '未知错误']);
                    return [
                        'success' => false,
                        'message' => "非A4纸张PDF打印失败: " . ($imageResult['error'] ?? '转换失败')
                    ];
                }
            }
            
            // A4纸张使用正常PDF打印流程
            //if ($orientation === 'landscape') {
            if (strpos($orientation, 'landscape') === 0) {
                writeLog('INFO', "PDF需要横向打印，尝试转换PDF");
                $rotatedPdf = rotatePdfForLandscape($tmpFile, $tmpDir);
                if (!empty($rotatedPdf) && file_exists($rotatedPdf)) {
                    $printPdf = $rotatedPdf;
                    $useRotatedPdf = true;
                    writeLog('INFO', "PDF已转换为横向", ['rotatedPdf' => $rotatedPdf]);
                } else {
                    // 不要使用landscape选项，因为它可能覆盖纸张大小
                    // orientation已经在buildLpOptions中处理了
                    writeLog('WARNING', "PDF转换失败，使用orientation参数");
                }
            }
            
            $pageOption = '';
            $pageRange = '';
            if ($pageFrom !== null && $pageTo !== null && $pageFrom >= 1 && $pageTo >= $pageFrom) {
                $pageOption = sprintf(' -P %d-%d', $pageFrom, $pageTo);
                $pageRange = sprintf('%d-%d', $pageFrom, $pageTo);
            }

            // 应用大文档优化
            $fileSize = filesize($printPdf);
            $optimization = optimizeLargeDocumentPrint($fileSize, 18); // 假设18页
            
            // 使用PWG光栅化打印
            $rasterResult = rasterizePdfForPrint($printerName, $printPdf, $tmpDir, [
                '-o media=' . escapeshellarg($paperSize)
            ], $pageRange);
            
            if ($rasterResult['success']) {
                $printRaster = $rasterResult['file'];
                // 构建包含纸张大小等所有必要参数的命令
                $rasterOptions = sprintf('-o media=%s -o orientation-requested=%s', 
                    escapeshellarg($paperSize),
                    (strpos($orientation, 'landscape') !== false) ? '4' : '3'
                );
                if ($colorMode === 'gray') {
                    $rasterOptions .= ' -o ColorModel=Gray -o print-color-mode=monochrome';
                }
                if ($isDuplex) {
                    $rasterOptions .= ' -o sides=two-sided-long-edge';
                }
                
                $cmd = sprintf('lp -d %s -n %d %s -o document-format=image/pwg-raster %s 2>&1',
                    escapeshellarg($printerName),
                    $copies,
                    $rasterOptions,
                    escapeshellarg($printRaster)
                );
                writeLog('INFO', "执行PDF PWG光栅打印命令", ['cmd' => $cmd, 'raster_cmd' => $rasterResult['cmd']]);
                exec($cmd, $output, $ret);
                @unlink($printRaster);
            } else {
                writeLog('WARNING', "PDF光栅化失败，回退到直接PDF打印", ['error' => $rasterResult['error'] ?? '未知错误']);
                // PDF使用动态缩放选项打印（回退）
                $cmd = sprintf('lp -d %s -n %d%s %s %s %s %s -o job-hold-until=no-hold -o job-priority=50 -o page-delivery=same-order %s 2>&1',
                    escapeshellarg($printerName),
                    $copies,
                    $pageOption,
                    $lpOptions,
                    $landscapeOption,
                    $scalingOption,
                    $optimization['options'],
                    escapeshellarg($printPdf)
                );
                writeLog('INFO', "执行PDF打印命令(回退)", ['cmd' => $cmd]);
                exec($cmd, $output, $ret);
            }
            $success = ($ret === 0);
            
            if ($useRotatedPdf && file_exists($printPdf)) {
                @unlink($printPdf);
            }
            
        } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp'])) {
            $printFile = $tmpFile;
            
            // 调试：记录原始图片信息
            $originalSize = filesize($tmpFile);
            $originalInfo = @getimagesize($tmpFile);
            writeLog('DEBUG', "原始图片信息", [
                'file' => $tmpFile,
                'size' => $originalSize,
                'dimensions' => $originalInfo ? "{$originalInfo[0]}x{$originalInfo[1]}" : 'unknown',
                'mime' => $originalInfo[2] ?? 'unknown',
                'color_mode_requested' => $colorMode
            ]);
            
            // 检测打印机是否为黑白打印机
            // 通过lpoptions检查ColorModel选项，如果只有Gray则为黑白打印机
            $isMonochromePrinter = false;
            $lpoptionsCmd = sprintf('lpoptions -p %s -l 2>&1 | grep ColorModel', escapeshellarg($printerName));
            exec($lpoptionsCmd, $lpoptionsOutput, $lpoptionsRet);
            if ($lpoptionsRet === 0 && !empty($lpoptionsOutput)) {
                $colorModelLine = implode(' ', $lpoptionsOutput);
                // 提取选项值部分（冒号后面的内容），避免ColorModel关键词干扰
                if (preg_match('/:\s*(.+)$/', $colorModelLine, $matches)) {
                    $options = $matches[1];
                    // 如果只有Gray选项，没有RGB/Color等彩色选项，则为黑白打印机
                    if (strpos($options, 'Gray') !== false && 
                        strpos($options, 'RGB') === false && 
                        strpos($options, 'Color') === false &&
                        strpos($options, 'CMYK') === false) {
                        $isMonochromePrinter = true;
                    }
                }
            }
            
            writeLog('DEBUG', "打印机类型检测", [
                'printer' => $printerName,
                'is_monochrome' => $isMonochromePrinter,
                'color_model_info' => $lpoptionsOutput ?? []
            ]);
            
            // 根据用户选择的打印模式和打印机类型决定是否进行灰度转换
            $needGrayConversion = ($colorMode === 'gray'); // 用户选择了黑白打印
            
            if ($needGrayConversion) {
                writeLog('INFO', "用户选择黑白打印，进行灰度转换", [
                    'is_monochrome_printer' => $isMonochromePrinter
                ]);
                $processedImg = $tmpDir . 'processed_' . uniqid() . '.jpg';
                
                // 使用ImageMagick的高质量灰度转换算法
                // 黑白打印机需要额外的亮度调整，彩色打印机只需要灰度转换
                if ($isMonochromePrinter) {
                    // 黑白打印机：灰度转换 + 亮度调整 + 黑点提升
                    // -colorspace sRGB: 确保正确的颜色空间
                    // -colorspace Gray: 转换为灰度
                    // -modulate 180: 提高亮度到180%
                    // -level 30%,100%: 将黑点从0提高到30%，防止过黑
                    // -normalize: 归一化灰度范围
                    // -quality 95: 高质量输出
                    $processCmd = sprintf('convert %s -colorspace sRGB -colorspace Gray -modulate 180 -level 30%%,100%% -normalize -quality 95 %s 2>&1',
                        escapeshellarg($tmpFile),
                        escapeshellarg($processedImg)
                    );
                    writeLog('DEBUG', "黑白打印机灰度转换（含亮度调整）", ['cmd' => $processCmd]);
                } else {
                    // 彩色打印机：只进行标准灰度转换，不调整亮度
                    // -colorspace sRGB: 确保正确的颜色空间
                    // -colorspace Gray: 转换为灰度
                    // -quality 95: 高质量输出
                    $processCmd = sprintf('convert %s -colorspace sRGB -colorspace Gray -quality 95 %s 2>&1',
                        escapeshellarg($tmpFile),
                        escapeshellarg($processedImg)
                    );
                    writeLog('DEBUG', "彩色打印机灰度转换（标准转换）", ['cmd' => $processCmd]);
                }
                
                exec($processCmd, $processOutput, $processRet);
                
                if ($processRet === 0 && file_exists($processedImg)) {
                    $processedSize = filesize($processedImg);
                    writeLog('INFO', "图片已转换为灰度", [
                        'processedImg' => $processedImg,
                        'original_size' => $originalSize,
                        'processed_size' => $processedSize,
                        'printer_type' => $isMonochromePrinter ? 'monochrome' : 'color'
                    ]);
                    $printFile = $processedImg;
                } else {
                    writeLog('ERROR', "灰度转换失败，使用原图", [
                        'return_code' => $processRet,
                        'output' => implode('; ', $processOutput)
                    ]);
                }
            } else {
                writeLog('INFO', "用户选择彩色打印，直接使用原图");
            }
            
            if ($orientation === 'landscape') {
                writeLog('INFO', "图片需要横向打印，尝试旋转图片");
                $rotatedImg = $tmpDir . 'rotated_' . uniqid() . '.' . ($colorMode === 'gray' ? 'png' : $ext);
                
                $rotateCmd = sprintf('convert %s -rotate 90 %s 2>&1',
                    escapeshellarg($printFile),
                    escapeshellarg($rotatedImg)
                );
                exec($rotateCmd, $rotateOutput, $rotateRet);
                
                if ($rotateRet === 0 && file_exists($rotatedImg)) {
                    // 清理之前的临时文件
                    if ($printFile !== $tmpFile && file_exists($printFile)) {
                        @unlink($printFile);
                    }
                    $printFile = $rotatedImg;
                    writeLog('INFO', "图片已旋转90度", ['rotatedImg' => $rotatedImg]);
                } else {
                    writeLog('WARNING', "图片旋转失败，使用原图打印", ['output' => implode('; ', $rotateOutput)]);
                }
            }
            
            // 构建打印选项
            $imageOptions = [];
            if (strpos($orientation, 'landscape') !== false) {
                $imageOptions[] = '-o orientation-requested=4';
            } else {
                $imageOptions[] = '-o orientation-requested=3';
            }
            $imageOptions[] = '-o media=' . escapeshellarg($paperSize);
            
            // 根据用户选择的打印模式设置颜色参数
            if ($colorMode === 'color') {
                // 彩色打印：强制使用彩色模式
                $imageOptions[] = '-o print-color-mode=color';
                $imageOptions[] = '-o ColorModel=RGB';
            }
            // 黑白打印：不添加颜色参数，让打印机使用我们预处理的灰度图
            
            // 降低墨粉浓度，防止打印过黑（仅黑白打印机）
            if ($isMonochromePrinter) {
                $imageOptions[] = '-o TonerDensity=1';
            }
            
            $imageOptionsStr = implode(' ', $imageOptions);
            
            writeLog('DEBUG', "图片打印选项", ['options' => $imageOptionsStr]);
            
            // 调试：记录最终打印文件信息
            $finalSize = filesize($printFile);
            $finalInfo = @getimagesize($printFile);
            writeLog('DEBUG', "最终打印文件信息", [
                'file' => $printFile,
                'size' => $finalSize,
                'dimensions' => $finalInfo ? "{$finalInfo[0]}x{$finalInfo[1]}" : 'unknown',
                'is_gray_converted' => ($printFile !== $tmpFile && strpos($printFile, 'gray_') !== false),
                'is_rotated' => ($printFile !== $tmpFile && strpos($printFile, 'rotated_') !== false)
            ]);
            
            $cmd = sprintf('lp -d %s -n %d %s %s -o job-hold-until=no-hold -o job-priority=50 -o page-delivery=same-order %s 2>&1',
                escapeshellarg($printerName),
                $copies,
                $imageOptionsStr,
                $scalingOption,
                escapeshellarg($printFile)
            );
            writeLog('INFO', "执行图片打印命令", [
                'cmd' => $cmd,
                'color_mode' => $colorMode,
                'orientation' => $orientation,
                'printer' => $printerName,
                'paper_size' => $paperSize,
                'scaling' => $scalingOption
            ]);
            
            exec($cmd, $output, $ret);
            
            writeLog('DEBUG', "打印命令执行结果", [
                'return_code' => $ret,
                'success' => ($ret === 0),
                'output' => implode('; ', array_slice($output, 0, 5))
            ]);
            
            $success = ($ret === 0);
            
            // 清理临时处理文件
            if ($printFile !== $tmpFile && file_exists($printFile)) {
                @unlink($printFile);
                writeLog('DEBUG', "已清理临时文件", ['file' => $printFile]);
            }
            
        } elseif (in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'txt'])) {
            writeLog('INFO', "准备转换文档为PDF", ['file' => $tmpFile]);
            
            // 设置LibreOffice环境
            putenv('HOME=/tmp');
            putenv('TMPDIR=/tmp');
            putenv('XDG_RUNTIME_DIR=/tmp');
            
            // 确保LibreOffice临时目录存在
            $loTmpDir = '/tmp/.libreoffice';
            if (!is_dir($loTmpDir)) {
                @mkdir($loTmpDir, 0755, true);
            }
            
            $pdf = $tmpDir . pathinfo($tmpFile, PATHINFO_FILENAME) . '.pdf';
            
            // 根据文件大小设置合理的超时时间，优化超时策略
            $fileSize = filesize($tmpFile);
            $timeout = 120; // 默认2分钟
            
            // 确保文件大小有效
            if ($fileSize === false || $fileSize <= 0) {
                $fileSize = 0;
                writeLog('WARN', "无法获取文件大小，使用默认超时", ['file' => $tmpFile]);
            }
            
            if ($fileSize > 5 * 1024 * 1024) { // 大于5MB
                $timeout = 300; // 5分钟
            } elseif ($fileSize > 1 * 1024 * 1024) { // 大于1MB
                $timeout = 180; // 3分钟
            }
            
            // 确保timeout是有效值
            if (empty($timeout) || $timeout <= 0) {
                $timeout = 120; // 回退到默认值
            }
            
            writeLog('INFO', "设置转换超时", ['file_size' => $fileSize, 'timeout' => $timeout . '秒']);
            
            // 构建LibreOffice转换命令，添加纸张大小参数
            // 对于非A4纸张，需要在转换时就指定正确的纸张大小
            $paperSizeParam = '';
            if ($paperSize !== 'A4') {
                // LibreOffice纸张大小映射
                $loPageSizes = [
                    'A3' => 'A3',
                    'A5' => 'A5',
                    'Letter' => 'LETTER',
                    'Legal' => 'LEGAL'
                ];
                
                if (isset($loPageSizes[$paperSize])) {
                    $loPageSize = $loPageSizes[$paperSize];
                    // 使用LibreOffice的页面设置参数
                    // 注意：这需要通过宏或配置文件来实现，但LibreOffice命令行不直接支持
                    // 我们需要先转换为PDF，然后在PDF转图片时处理尺寸
                    writeLog('INFO', "文档将转换为PDF后再调整为{$paperSize}尺寸", ['paper_size' => $paperSize]);
                }
            }
            
            $convertCmd = sprintf('libreoffice --headless --invisible --nodefault --nolockcheck --nologo --norestore --convert-to pdf --outdir %s %s 2>&1',
                escapeshellarg($tmpDir),
                escapeshellarg($tmpFile)
            );
            
            // 初始化转换相关变量
            $cvtRet = -1;
            $cvtOutput = [];
            $startTime = time();
            
            writeLog('INFO', "执行LibreOffice转换", ['cmd' => $convertCmd, 'timeout' => $timeout]);
            
            // 检查LibreOffice是否可用
            exec('which libreoffice 2>/dev/null', $whichOutput, $whichRet);
            if ($whichRet !== 0) {
                writeLog('ERROR', "LibreOffice未安装或不在PATH中");
                $cvtOutput = ['LibreOffice未安装或不在PATH中'];
                $cvtRet = -1;
                $success = false;
            } else {
                // 检查LibreOffice版本和权限
                exec('libreoffice --version 2>&1', $versionOutput, $versionRet);
                writeLog('INFO', "LibreOffice版本信息", ['version' => implode('', $versionOutput), 'ret' => $versionRet]);
                
                // 检查是否有足够的临时空间
                $freeSpace = disk_free_space('/tmp');
                writeLog('INFO', "临时目录空间检查", ['free_space' => $freeSpace . ' bytes']);
                
                // 清理可能存在的LibreOffice进程
                exec('pkill -f "soffice.*--headless" 2>/dev/null');
                sleep(1);
                
                // 尝试简单的LibreOffice测试
                $testCmd = 'timeout 10 libreoffice --headless --help 2>&1';
                exec($testCmd, $testOutput, $testRet);
                writeLog('INFO', "LibreOffice启动测试", ['ret' => $testRet, 'output' => implode('', $testOutput)]);
                
                if ($testRet !== 0) {
                    writeLog('ERROR', "LibreOffice无法正常启动", ['error' => implode('', $testOutput)]);
                    $cvtOutput = ['LibreOffice无法正常启动: ' . implode('', $testOutput)];
                    $cvtRet = -1;
                    $success = false;
                } else {
                
                // 使用超时机制执行转换
                $startTime = time();
                $process = proc_open($convertCmd, [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w']
                ], $pipes);
                
                if (is_resource($process)) {
                    fclose($pipes[0]);
                    
                    $cvtOutput = [];
                    $timeoutReached = false;
                    
                    while (time() - $startTime < $timeout) {
                        $status = proc_get_status($process);
                        if (!$status['running']) {
                            break;
                        }
                        sleep(1);
                    }
                    
                    $status = proc_get_status($process);
                    if ($status['running']) {
                        writeLog('WARN', "LibreOffice转换超时，强制终止", ['timeout' => $timeout]);
                        proc_terminate($process);
                        $timeoutReached = true;
                        $cvtRet = -1;
                    } else {
                        $cvtRet = $status['exitcode'];
                    }
                    
                    // 读取输出
                    $stdout = stream_get_contents($pipes[1]);
                    $stderr = stream_get_contents($pipes[2]);
                    if (!empty($stdout)) $cvtOutput[] = "STDOUT: " . $stdout;
                    if (!empty($stderr)) $cvtOutput[] = "STDERR: " . $stderr;
                    
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_close($process);
                    
                    if ($timeoutReached) {
                        $cvtOutput[] = "转换超时 ({$timeout}秒)";
                    }
                } else {
                    writeLog('ERROR', "无法启动LibreOffice进程");
                    $cvtRet = -1;
                    $cvtOutput = ['无法启动LibreOffice进程'];
                }
                }
            }
            
            $convertDuration = time() - $startTime;
            writeLog('INFO', "LibreOffice转换结果", [
                'ret' => $cvtRet, 
                'output' => implode('; ', $cvtOutput), 
                'pdf' => $pdf, 
                'exists' => file_exists($pdf),
                'duration' => $convertDuration . '秒',
                'file_size' => $fileSize . ' bytes'
            ]);
            
            if (file_exists($pdf)) {
                $printPdf = $pdf;
                $useRotatedPdf = false;
                $landscapeOption = '';
                
                // 强制非A4纸张转换为图片打印，确保内容正确缩放
                if ($paperSize !== 'A4') {
                    writeLog('INFO', "文档非A4纸张强制使用图片方式打印", ['paper_size' => $paperSize]);
                    
                    // 检查页码参数
                    $pageFrom = intval($pageFrom ?? 1);
                    $pageTo = intval($pageTo ?? 999999);
                    writeLog('INFO', "文档页码范围", ['page_from' => $pageFrom, 'page_to' => $pageTo]);
                    
                    $imageResult = convertPdfToImages($printPdf, $tmpDir, $paperSize, $orientation, $pageFrom, $pageTo);
                    if ($imageResult['success'] && !empty($imageResult['images'])) {
                        // 逐个打印图片
                        $successCount = 0;
                        $totalImages = count($imageResult['images']);
                        
                        foreach ($imageResult['images'] as $index => $imageFile) {
                            // 使用ImageMagick确保PNG颜色空间正确
                            $processedImg = $tmpDir . 'processed_' . basename($imageFile);
                            $convertCmd = sprintf('convert %s -colorspace sRGB -type TrueColor %s 2>&1',
                                escapeshellarg($imageFile),
                                escapeshellarg($processedImg)
                            );
                            exec($convertCmd, $convertOutput, $convertRet);
                            
                            $printImg = ($convertRet === 0 && file_exists($processedImg)) ? $processedImg : $imageFile;
                            
                            $imgCmd = sprintf('lp -d %s -n %d %s -o media=%s -o job-hold-until=no-hold -o job-priority=50 -o page-delivery=same-order %s 2>&1',
                                escapeshellarg($printerName),
                                $copies,
                                $lpOptions,
                                escapeshellarg($paperSize),
                                escapeshellarg($printImg)
                            );
                            
                            writeLog('INFO', "打印文档图片", [
                                'page' => $pageFrom + $index, 
                                'total' => $totalImages, 
                                'image' => $printImg,
                                'color_processed' => ($printImg === $processedImg)
                            ]);
                            exec($imgCmd, $imgOutput, $imgRet);
                            
                            // 清理处理后的图片
                            if ($printImg === $processedImg && file_exists($processedImg)) {
                                @unlink($processedImg);
                            }
                            
                            if ($imgRet === 0) {
                                $successCount++;
                            } else {
                                writeLog('ERROR', "图片打印失败", [
                                    'page' => $pageFrom + $index,
                                    'ret' => $imgRet,
                                    'output' => implode('; ', array_slice($imgOutput, 0, 3))
                                ]);
                            }
                            
                            // 清理临时图片文件
                            @unlink($imageFile);
                        }
                        
                        $success = ($successCount === $totalImages);
                        $message = $success ? "文档图片打印成功 ({$successCount}/{$totalImages}页)" : "文档图片打印失败 (成功{$successCount}/{$totalImages}页)";
                        
                        writeLog($success ? 'INFO' : 'ERROR', "文档图片打印完成", [
                            'success' => $success,
                            'success_count' => $successCount,
                            'total_images' => $totalImages,
                            'paper_size' => $paperSize,
                            'page_range' => "{$pageFrom}-{$pageTo}"
                        ]);
                        
                        // 清理临时PDF文件
                        @unlink($pdf);
                        
                        return [
                            'success' => $success,
                            'message' => $message
                        ];
                    } else {
                        writeLog('ERROR', "文档PDF转图片失败，无法打印非A4纸张", ['error' => $imageResult['error'] ?? '未知错误']);
                        // 清理临时PDF文件
                        @unlink($pdf);
                        return [
                            'success' => false,
                            'message' => "非A4纸张文档打印失败: " . ($imageResult['error'] ?? '转换失败')
                        ];
                    }
                }
                
                // A4纸张使用正常PDF打印流程
                if ($orientation === 'landscape') {
                    writeLog('INFO', "文档需要横向打印，尝试转换PDF");
                    $rotatedPdf = rotatePdfForLandscape($pdf, $tmpDir);
                    if (!empty($rotatedPdf) && file_exists($rotatedPdf)) {
                        $printPdf = $rotatedPdf;
                        $useRotatedPdf = true;
                        writeLog('INFO', "PDF已转换为横向", ['rotatedPdf' => $rotatedPdf]);
                    } else {
                    // 不要使用landscape选项，因为它可能覆盖纸张大小
                    // orientation已经在buildLpOptions中处理了
                    writeLog('WARNING', "PDF转换失败，使用orientation参数");
                }
                }
                
                $pageOption = '';
                $pageRange = '';
                $pFrom = intval($pageFrom);
                $pTo = intval($pageTo);
                writeLog('INFO', "检查页码参数", ['page_from' => $pageFrom, 'page_to' => $pageTo, 'pFrom' => $pFrom, 'pTo' => $pTo]);
                if ($pFrom >= 1 && $pTo >= $pFrom) {
                    $pageOption = sprintf(' -P %d-%d', $pFrom, $pTo);
                    $pageRange = sprintf('%d-%d', $pFrom, $pTo);
                    writeLog('INFO', "文档选页打印", ['pageOption' => $pageOption]);
                }
                
                // 应用大文档优化
                $fileSize = filesize($printPdf);
                $optimization = optimizeLargeDocumentPrint($fileSize, 18); // 18页文档
                
                // 使用PWG光栅化打印
                $rasterResult = rasterizePdfForPrint($printerName, $printPdf, $tmpDir, [
                    '-o media=' . escapeshellarg($paperSize)
                ], $pageRange);
                
                if ($rasterResult['success']) {
                    $printRaster = $rasterResult['file'];
                    // 构建包含纸张大小等所有必要参数的命令
                    $rasterOptions = sprintf('-o media=%s -o orientation-requested=%s', 
                        escapeshellarg($paperSize),
                        (strpos($orientation, 'landscape') !== false) ? '4' : '3'
                    );
                    if ($colorMode === 'gray') {
                        $rasterOptions .= ' -o ColorModel=Gray -o print-color-mode=monochrome';
                    }
                    if ($isDuplex) {
                        $rasterOptions .= ' -o sides=two-sided-long-edge';
                    }
                    
                    $cmd = sprintf('lp -d %s -n %d %s -o document-format=image/pwg-raster %s 2>&1',
                        escapeshellarg($printerName),
                        $copies,
                        $rasterOptions,
                        escapeshellarg($printRaster)
                    );
                    writeLog('INFO', "执行文档PWG光栅打印命令", ['cmd' => $cmd, 'orientation' => $orientation, 'raster_cmd' => $rasterResult['cmd']]);
                    exec($cmd, $output, $ret);
                    @unlink($printRaster);
                } else {
                    writeLog('WARNING', "文档PDF光栅化失败，回退到直接PDF打印", ['error' => $rasterResult['error'] ?? '未知错误']);
                    // 文档转PDF后使用动态缩放选项打印（回退）
                    $cmd = sprintf('lp -d %s -n %d%s %s %s %s %s -o job-hold-until=no-hold -o job-priority=50 -o page-delivery=same-order %s %s 2>&1',
                        escapeshellarg($printerName),
                        $copies,
                        $pageOption,
                        $lpOptions,
                        $landscapeOption,
                        $scalingOption,
                        $optimization['options'],
                        escapeshellarg($printPdf)
                    );
                    writeLog('INFO', "执行文档打印命令(回退)", ['cmd' => $cmd, 'orientation' => $orientation]);
                    exec($cmd, $output, $ret);
                }
                $success = ($ret === 0);
                
                @unlink($pdf);
                if ($useRotatedPdf && file_exists($printPdf)) {
                    @unlink($printPdf);
                }
            } else {
                // 转换失败的详细错误处理
                $errorMsg = "LibreOffice转换失败";
                $errorDetails = [];
                
                if ($cvtRet === -1) {
                    $errorDetails[] = "进程启动失败或超时";
                } elseif ($cvtRet !== 0) {
                    $errorDetails[] = "退出码: $cvtRet";
                }
                
                if (!empty($cvtOutput)) {
                    $errorDetails[] = "输出: " . implode('; ', $cvtOutput);
                }
                
                // 检查LibreOffice是否安装
                exec('which libreoffice 2>/dev/null', $whichOutput, $whichRet);
                if ($whichRet !== 0) {
                    $errorDetails[] = "LibreOffice未安装或不在PATH中";
                }
                
                // 检查文件权限
                if (!is_readable($tmpFile)) {
                    $errorDetails[] = "源文件不可读";
                }
                if (!is_writable($tmpDir)) {
                    $errorDetails[] = "输出目录不可写";
                }
                
                $fullErrorMsg = $errorMsg . ": " . implode(', ', $errorDetails);
                writeLog('ERROR', "文档转换失败详情", [
                    'file' => $tmpFile,
                    'target_pdf' => $pdf,
                    'error' => $fullErrorMsg,
                    'file_size' => $fileSize,
                    'timeout' => $timeout
                ]);
                
                $output = [$fullErrorMsg];
            }
        } else {
            $cmd = sprintf('lp -d %s -n %d %s -o job-hold-until=no-hold -o job-priority=50 -o page-delivery=same-order %s 2>&1',
                escapeshellarg($printerName),
                $copies,
                $lpOptions,
                escapeshellarg($tmpFile)
            );
            exec($cmd, $output, $ret);
            $success = ($ret === 0);
        }
    } finally {
        @unlink($tmpFile);
    }
    
    $message = $success ? '打印任务已提交' : ('打印失败: ' . implode('; ', $output));
    writeLog($success ? 'INFO' : 'ERROR', "打印任务完成", [
        'success' => $success,
        'printer' => $printerName,
        'filename' => $filename,
        'message' => $message
    ]);
    
        return [
            'success' => $success,
            'message' => $message
        ];
    } catch (Exception $e) {
        writeLog('ERROR', "打印任务异常", [
            'printer' => $printerName,
            'filename' => $filename,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return [
            'success' => false,
            'message' => '打印异常: ' . $e->getMessage()
        ];
    } catch (Throwable $e) {
        writeLog('CRITICAL', "打印任务严重错误", [
            'printer' => $printerName,
            'filename' => $filename,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return [
            'success' => false,
            'message' => '系统错误: ' . $e->getMessage()
        ];
    }
}

function buildLpOptions($colorMode, $orientation, $isDuplex = false, $paperSize = 'A4'): string
{
    $options = [];
    
    $colorMode = strval($colorMode ?: 'color');
    $orientation = strval($orientation ?: 'portrait');
    
    if ($colorMode === 'gray') {
        $options[] = '-o ColorModel=Gray';
        $options[] = '-o print-color-mode=monochrome';
    }

    // 双面打印选项
    if ($isDuplex) {
        $options[] = '-o sides=two-sided-long-edge';
    }

    // 打印方向选项
    if (strpos($orientation, 'landscape') !== false) {
        $options[] = '-o orientation-requested=4'; // 4 = landscape
    } else {
        $options[] = '-o orientation-requested=3'; // 3 = portrait
    }

    // 纸张大小选项 - 总是添加，确保覆盖默认设置
    $options[] = '-o media=' . escapeshellarg($paperSize);
    
    return implode(' ', $options);
}

/**
 * 针对大文档的打印优化
 */
function optimizeLargeDocumentPrint($fileSize, $pageCount = 0): array
{
    $options = [];
    
    // 大文档使用更快的打印质量
    if ($fileSize > 3 * 1024 * 1024 || $pageCount > 10) {
        $options[] = '-o print-quality=3'; // 草稿模式
        $options[] = '-o resolution=300dpi'; // 降低分辨率
    }
    
    // 超大文档进一步优化
    if ($fileSize > 10 * 1024 * 1024 || $pageCount > 50) {
        $options[] = '-o print-quality=4'; // 快速草稿模式
        $options[] = '-o resolution=150dpi'; // 更低分辨率
    }
    
    return [
        'options' => implode(' ', $options),
        'timeout' => $fileSize > 10 * 1024 * 1024 ? 300 : 180 // 超大文档5分钟超时
    ];
}

function rotatePdfForLandscape($pdfFile, $tmpDir): string
{
    $pdfFile = strval($pdfFile);
    $tmpDir = strval($tmpDir);
    $rotatedPdf = $tmpDir . uniqid('landscape_') . '.pdf';
    
    exec('which pdfjam 2>/dev/null', $whichPdfjam, $whichPdfjamRet);
    if ($whichPdfjamRet === 0) {
        $pdfjamCmd = sprintf('pdfjam --angle 90 --fitpaper true --rotateoversize true --outfile %s %s 2>&1',
            escapeshellarg($rotatedPdf),
            escapeshellarg($pdfFile)
        );
        exec($pdfjamCmd, $pdfjamOutput, $pdfjamRet);
        
        if ($pdfjamRet === 0 && file_exists($rotatedPdf)) {
            writeLog('INFO', "PDF已使用pdfjam转换为横向", ['rotatedPdf' => $rotatedPdf]);
            return $rotatedPdf;
        }
        writeLog('WARNING', "pdfjam转换失败", ['output' => implode('; ', $pdfjamOutput)]);
    }
    
    exec('which ps2pdf 2>/dev/null', $whichPs2pdf, $whichPs2pdfRet);
    if ($whichPs2pdfRet === 0) {
        $tmpPs = $tmpDir . uniqid('tmp_') . '.ps';
        $pdf2psCmd = sprintf('pdf2ps %s %s 2>&1', escapeshellarg($pdfFile), escapeshellarg($tmpPs));
        exec($pdf2psCmd, $pdf2psOutput, $pdf2psRet);
        
        if ($pdf2psRet === 0 && file_exists($tmpPs)) {
            $ps2pdfCmd = sprintf('ps2pdf -sPAPERSIZE=a4 -dAutoRotatePages=/None %s %s 2>&1',
                escapeshellarg($tmpPs),
                escapeshellarg($rotatedPdf)
            );
            exec($ps2pdfCmd, $ps2pdfOutput, $ps2pdfRet);
            @unlink($tmpPs);
            
            if ($ps2pdfRet === 0 && file_exists($rotatedPdf)) {
                writeLog('INFO', "PDF已使用ps2pdf转换", ['rotatedPdf' => $rotatedPdf]);
                return $rotatedPdf;
            }
        }
        writeLog('WARNING', "ps2pdf转换失败");
    }
    
    writeLog('WARNING', "未安装PDF转换工具(pdfjam/ps2pdf)，将尝试使用打印机横向选项");
    return '';
}

function rasterizePdfForPrint(string $printerName, string $pdfFile, string $tmpDir, array $optionFragments = [], string $pageRange = ''): array
{
    $ppdPath = '/etc/cups/ppd/' . $printerName . '.ppd';
    $rasterFile = $tmpDir . pathinfo($pdfFile, PATHINFO_FILENAME) . '.pwg';
    
    if (!is_readable($ppdPath)) {
        $error = "PPD文件不存在或不可读: {$ppdPath}";
        writeLog('WARNING', "PDF光栅化无法执行", ['printer' => $printerName, 'error' => $error]);
        return ['success' => false, 'error' => $error];
    }
    
    if (file_exists($rasterFile)) {
        @unlink($rasterFile);
    }
    
    $cmdParts = [
        'cupsfilter',
        '-p',
        escapeshellarg($ppdPath),
        '-i',
        'application/pdf',
        '-m',
        'image/pwg-raster'
    ];
    
    if ($pageRange !== '') {
        $cmdParts[] = '-o page-ranges=' . escapeshellarg($pageRange);
    }
    
    foreach ($optionFragments as $optionFragment) {
        $optionFragment = trim((string)$optionFragment);
        if ($optionFragment !== '') {
            $cmdParts[] = $optionFragment;
        }
    }
    
    $cmdParts[] = escapeshellarg($pdfFile);
    $logFile = $tmpDir . pathinfo($pdfFile, PATHINFO_FILENAME) . '.raster.log';
    @unlink($logFile);
    $cmd = implode(' ', $cmdParts) . ' > ' . escapeshellarg($rasterFile) . ' 2> ' . escapeshellarg($logFile);
    
    exec($cmd, $output, $ret);
    $filterLog = file_exists($logFile) ? trim((string)file_get_contents($logFile)) : '';
    $success = ($ret === 0 && file_exists($rasterFile) && filesize($rasterFile) > 0);
    
    writeLog($success ? 'INFO' : 'WARNING', "PDF光栅化结果", [
        'printer' => $printerName,
        'cmd' => $cmd,
        'pdf' => $pdfFile,
        'raster' => $rasterFile,
        'page_range' => $pageRange,
        'ret' => $ret,
        'output' => $filterLog,
        'exists' => file_exists($rasterFile),
        'size' => file_exists($rasterFile) ? filesize($rasterFile) : 0
    ]);
    
    if (!$success) {
        @unlink($rasterFile);
        @unlink($logFile);
        return [
            'success' => false,
            'error' => $filterLog,
            'cmd' => $cmd,
            'output' => $filterLog === '' ? [] : [$filterLog]
        ];
    }
    
    @unlink($logFile);
    return [
        'success' => true,
        'file' => $rasterFile,
        'cmd' => $cmd,
        'output' => $filterLog === '' ? [] : [$filterLog]
    ];
}

class PrinterClient
{
    private const MAX_BUFFER_SIZE = 10 * 1024 * 1024; // 10MB 缓冲区限制
    private const MAX_FRAME_SIZE = 16 * 1024 * 1024;  // 16MB 单帧限制
    
    private $socket;
    private $deviceId;
    private $serverUrl;
    private $connected = false;
    private $lastHeartbeat = 0;
    private $messageBuffer = '';
    private $frameBuffer = '';  // 用于累积不完整的 WebSocket 帧
    private $asyncPrintTasks = [];  // 异步打印任务跟踪
    private $printProgressTasks = [];  // 打印进度监控任务
    
    // HTTP API 备用通道相关属性
    private $httpApiMode = false;           // 是否处于HTTP API模式
    private $lastWsActivity = 0;            // 最后一次WebSocket活动时间
    private $lastHttpPoll = 0;              // 最后一次HTTP轮询时间
    private $wsRecoveryCheckTime = 0;       // WebSocket恢复检查时间
    private $httpApiRegistered = false;     // HTTP API是否已注册
    
    public function __construct(string $serverUrl)
    {
        $this->serverUrl = $serverUrl;
        $this->deviceId = getDeviceId();
        echo "设备ID: {$this->deviceId}\n";
    }
    
    public function connect(): bool
    {
        try {
            $urlParts = parse_url($this->serverUrl);
            if (!$urlParts || !isset($urlParts['host'])) {
                writeLog('ERROR', "无效的服务器配置");
                return false;
            }
            
            $host = $urlParts['host'];
            $port = $urlParts['port'] ?? 80;
            $path = $urlParts['path'] ?? '/';
            
            writeLog('INFO', "正在连接服务器...");
            
            $this->socket = stream_socket_client(
                "tcp://{$host}:{$port}",
                $errno,
                $errstr,
                15,  // 增加连接超时时间
                STREAM_CLIENT_CONNECT
            );
            
            if (!$this->socket) {
                writeLog('ERROR', "连接失败", [
                    'host' => $host,
                    'port' => $port,
                    'errno' => $errno,
                    'errstr' => $errstr
                ]);
                return false;
            }
            
            // 设置socket选项
            stream_set_timeout($this->socket, 30);  // 读写超时30秒
            stream_set_blocking($this->socket, true);  // 握手时阻塞
        } catch (Exception $e) {
            writeLog('ERROR', "连接异常", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
        
        $key = base64_encode(random_bytes(16));
        $headers = "GET $path HTTP/1.1\r\n" .
                   "Host: {$host}:{$port}\r\n" .
                   "Upgrade: websocket\r\n" .
                   "Connection: Upgrade\r\n" .
                   "Sec-WebSocket-Key: {$key}\r\n" .
                   "Sec-WebSocket-Version: 13\r\n\r\n";
        
        fwrite($this->socket, $headers);
        
        $response = '';
        while (($line = fgets($this->socket)) !== false) {
            $response .= $line;
            if ($line === "\r\n") break;
        }
        
        if (strpos($response, '101') === false) {
            writeLog('ERROR', "WebSocket握手失败");
            fclose($this->socket);
            return false;
        }
        
        stream_set_blocking($this->socket, false);
        stream_set_timeout($this->socket, 60);  // 正常通信时60秒超时
        $this->connected = true;
        writeLog('INFO', "已连接到服务器");
        
        $this->register();
        
        return true;
    }
    
    public function disconnect(): void
    {
        try {
            if (is_resource($this->socket)) {
                writeLog('INFO', "正在关闭WebSocket连接");
                fclose($this->socket);
            }
        } catch (Exception $e) {
            writeLog('WARN', "关闭连接时发生异常: " . $e->getMessage());
        } finally {
            $this->connected = false;
            $this->frameBuffer = '';
            $this->messageBuffer = '';
            $this->socket = null;
            writeLog('INFO', "连接资源已清理");
        }
    }
    
    private function register()
    {
        $systemInfo = getSystemInfo();
        
        $openid = $this->loadOpenid();
        
        $this->send([
            'action' => 'register',
            'device_id' => $this->deviceId,
            'openid' => $openid,
            'name' => $systemInfo['hostname'] ?? '',
            'version' => CLIENT_VERSION,
            'client_version' => CLIENT_VERSION,
            'connection_type' => 'websocket',  // 修复：明确标识为WebSocket连接
            'os_info' => $systemInfo['os'] ?? '',
            'ip_address' => $systemInfo['ip'] ?? ''
        ]);
        
        $printers = getPrinterList();
        $formattedPrinters = [];
        foreach ($printers as $p) {
            $formattedPrinters[] = [
                'name' => $p['name'],
                'display_name' => $p['name'],
                'driver' => $p['driver'] ?? '',  
                'is_default' => $p['is_default'] ?? false,
                'status' => 'ready'
            ];
        }
        
        $this->send([
            'action' => 'printers_update',
            'printers' => $formattedPrinters
        ]);
    }
    
    private function loadOpenid(): string
    {
        $configFile = '/etc/printer-client-openid';
        if (file_exists($configFile)) {
            return trim(file_get_contents($configFile));
        }
        return '';
    }
    
    public function saveOpenid(string $openid): void
    {
        $configFile = '/etc/printer-client-openid';
        if ($openid === '') {
            @unlink($configFile);
            return;
        }
        file_put_contents($configFile, $openid);
    }
    
    public function send(array $data)
    {
        if (!$this->connected) return;
        
        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            $jsonSize = strlen($json);
            
            // 检查消息大小，如果过大则分块发送或压缩
            if ($jsonSize > 1024 * 1024) { // 大于1MB
                // 尝试压缩
                $compressed = gzcompress($json, 6);
                if ($compressed && strlen($compressed) < $jsonSize * 0.8) {
                    $compressedData = [
                        'compressed' => true,
                        'original_size' => $jsonSize,
                        'data' => base64_encode($compressed)
                    ];
                    $json = json_encode($compressedData, JSON_UNESCAPED_UNICODE);
                }
            }
            
            $frame = $this->encodeFrame($json);
            $frameSize = strlen($frame);
            
            // 检查帧大小是否超过限制
            if ($frameSize > 16 * 1024 * 1024) { // 16MB限制
                return;
            }
            
            // 分块写入，避免一次性写入过大数据
            $chunkSize = 8192; // 8KB chunks
            $written = 0;
            
            while ($written < $frameSize) {
                $chunk = substr($frame, $written, $chunkSize);
                $result = fwrite($this->socket, $chunk);
                
                if ($result === false) {
                    $this->disconnect();
                    return;
                }
                
                $written += $result;
                
                // 检查socket状态
                $socketInfo = stream_get_meta_data($this->socket);
                if ($socketInfo['timed_out']) {
                    $this->disconnect();
                    return;
                }
            }
            
            // 更新WebSocket活动时间
            $this->lastWsActivity = time();
            
        } catch (Exception $e) {
            $this->disconnect();
        }
    }
    
    private function encodeFrame(string $data): string
    {
        $length = strlen($data);
        $frame = chr(0x81);
        
        if ($length <= 125) {
            $frame .= chr($length | 0x80);
        } elseif ($length <= 65535) {
            $frame .= chr(126 | 0x80) . pack('n', $length);
        } else {
            $frame .= chr(127 | 0x80) . pack('J', $length);
        }
        
        $mask = random_bytes(4);
        $frame .= $mask;
        
        for ($i = 0; $i < $length; $i++) {
            $frame .= $data[$i] ^ $mask[$i % 4];
        }
        
        return $frame;
    }
    
    private function decodeFrame(string $data): ?string
    {
        // 将新数据添加到帧缓冲区
        $this->frameBuffer .= $data;
        
        // 检查缓冲区大小限制，防止内存溢出
        if (strlen($this->frameBuffer) > self::MAX_BUFFER_SIZE) {
            writeLog('ERROR', "帧缓冲区溢出，重置连接", [
                'buffer_size' => strlen($this->frameBuffer),
                'max_size' => self::MAX_BUFFER_SIZE
            ]);
            $this->disconnect();
            return null;
        }
        
        // 检查是否有足够的数据来解析帧头
        if (strlen($this->frameBuffer) < 2) return null;
        
        $firstByte = ord($this->frameBuffer[0]);
        $secondByte = ord($this->frameBuffer[1]);
        
        $opcode = $firstByte & 0x0F;
        $masked = ($secondByte & 0x80) !== 0;
        $length = $secondByte & 0x7F;
        
        $offset = 2;
        
        if ($length === 126) {
            if (strlen($this->frameBuffer) < 4) return null;
            $length = unpack('n', substr($this->frameBuffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($this->frameBuffer) < 10) return null;
            $highBytes = unpack('N', substr($this->frameBuffer, 2, 4))[1];
            $lowBytes = unpack('N', substr($this->frameBuffer, 6, 4))[1];
            $length = ($highBytes > 0) ? PHP_INT_MAX : $lowBytes;
            $offset = 10;
        }
        
        // 检查单帧大小限制
        if ($length > self::MAX_FRAME_SIZE) {
            writeLog('ERROR', "单帧大小超限，断开连接", [
                'frame_size' => $length,
                'max_frame_size' => self::MAX_FRAME_SIZE
            ]);
            $this->disconnect();
            return null;
        }
        
        if ($masked) {
            $offset += 4;
        }
        
        // 计算完整帧所需的总长度
        $totalFrameLength = $offset + $length;
        
        // 检查是否收到了完整的帧
        if (strlen($this->frameBuffer) < $totalFrameLength) {
            // 帧不完整，等待更多数据
            return null;
        }
        
        // 提取 mask 和 payload
        $maskOffset = $offset - ($masked ? 4 : 0);
        $mask = $masked ? substr($this->frameBuffer, $maskOffset, 4) : null;
        $payload = substr($this->frameBuffer, $offset, $length);
        
        // 解码 payload
        if ($masked && $mask) {
            for ($i = 0; $i < strlen($payload); $i++) {
                $payload[$i] = $payload[$i] ^ $mask[$i % 4];
            }
        }
        
        // 从缓冲区移除已处理的帧
        $this->frameBuffer = substr($this->frameBuffer, $totalFrameLength);
        
        return $payload;
    }
    
    public function run()
    {
        global $HEARTBEAT_INTERVAL, $LAST_TEMP_CLEAN, $WS_ZOMBIE_TIMEOUT, $HTTP_API_URL;
        
        cleanOldLogs();
        $this->lastWsActivity = time();
        
        while (true) {
            $now = time();
            
            // 检测WebSocket假死并切换到HTTP API模式
            if (!$this->httpApiMode && $this->connected) {
                $timeSinceActivity = $now - $this->lastWsActivity;
                if ($timeSinceActivity > $WS_ZOMBIE_TIMEOUT) {
                    writeLog('WARN', "WebSocket疑似假死，切换到HTTP API备用通道", [
                        'time_since_activity' => $timeSinceActivity
                    ]);
                    echo "⚠️ WebSocket假死，切换到HTTP API备用通道...\n";
                    $this->switchToHttpApiMode();
                }
            }
            
            // HTTP API模式：轮询任务
            if ($this->httpApiMode) {
                $this->runHttpApiMode();
                continue;
            }
            
            if (!$this->connected) {
                $this->reconnect();
                continue;
            }
            
            $data = @fread($this->socket, 65535);
            if ($data === false || feof($this->socket)) {
                writeLog('WARN', "连接断开，正在清理资源", [
                    'data_false' => ($data === false),
                    'eof' => feof($this->socket),
                    'socket_resource' => is_resource($this->socket)
                ]);
                $this->disconnect();
                continue;
            }
            
            // 检查socket超时
            $socketInfo = stream_get_meta_data($this->socket);
            if ($socketInfo['timed_out']) {
                writeLog('WARN', "Socket读取超时，重新连接");
                $this->disconnect();
                continue;
            }
            
            if ($data) {
                $this->lastWsActivity = $now; // 更新活动时间
                // 尝试解码帧，可能需要多次调用才能获取完整消息
                while (true) {
                    $message = $this->decodeFrame($data);
                    $data = '';  // 后续循环不再传入新数据，只处理缓冲区
                    
                    if ($message === null) {
                        // 帧不完整，等待更多数据
                        break;
                    }
                    
                    // 累积消息片段
                    $this->messageBuffer .= $message;
                    
                    // 尝试解析 JSON
                    $decoded = @json_decode($this->messageBuffer, true);
                    if ($decoded !== null) {
                        writeLog('DEBUG', "完整消息接收完成", ['length' => strlen($this->messageBuffer)]);
                        $this->handleMessage($this->messageBuffer);
                        $this->messageBuffer = '';
                    }
                }
                continue;
            }
            
            $now = time();
            
            // 发送心跳前检查连接状态
            if ($now - $this->lastHeartbeat >= $HEARTBEAT_INTERVAL) {
                if ($this->connected && $this->socket) {
                    $this->send(['action' => 'heartbeat']);
                    $this->lastHeartbeat = $now;
                    writeLog('DEBUG', '发送心跳');
                }
            }
            
            if ($now - $LAST_TEMP_CLEAN >= TEMP_CLEAN_INTERVAL) {
                cleanTempPrintFiles();
                cleanOldLogs();
                $LAST_TEMP_CLEAN = $now;
            }
            
            // 检查异步打印任务结果
            $this->checkAsyncPrintTasks();
            
            // 检查打印进度
            $this->checkPrintProgress();
            
            usleep(50000);
        }
    }
    
    private function handleMessage(string $message)
    {
        $data = json_decode($message, true);
        if (!$data) {
            echo "[handleMessage] JSON解析失败: " . substr($message, 0, 100) . "\n";
            return;
        }
        
        $action = $data['action'] ?? 'unknown';
        
        $silentActions = ['heartbeat_ack', 'get_logs', 'get_log_dates', 'get_status', 'clean_temp'];
        if (!in_array($action, $silentActions)) {
            echo "收到命令: " . $action . "\n";
            if ($action === 'unknown') {
                echo "[DEBUG] 原始消息: " . substr($message, 0, 500) . "\n";
            }
        }
        
        if ($action === 'print') {
            echo "[handleMessage] print命令字段: " . implode(', ', array_keys($data)) . "\n";
        }
        
        switch ($data['action'] ?? '') {
            case 'registered':
                echo "设备注册成功\n";
                break;
            
            case 'register_ok':
                echo "设备注册成功\n";
                break;
                
            case 'bind':
                $openid = $data['openid'] ?? '';
                $this->saveOpenid($openid);
                if ($openid !== '') {
                    echo "设备已绑定到用户: $openid\n";
                    $this->register();
                } else {
                    echo "设备已解绑当前用户\n";
                }
                break;
                
            case 'heartbeat_ack':
                writeLog('DEBUG', '收到心跳响应');
                break;
                
            case 'pong':
                writeLog('DEBUG', '收到pong响应');
                break;
                
            case 'detect_usb':
                echo "[detect_usb] 开始执行检测...\n";
                try {
                    $result = detectUsbPrinters();
                    echo "[detect_usb] 检测完成，发送结果...\n";
                    $this->send([
                        'action' => 'detect_result',
                        'request_id' => $data['request_id'] ?? '',
                        'usb_devices' => $result['usb_devices'],
                        'drivers' => $result['drivers']
                    ]);
                    echo "[detect_usb] 结果已发送\n";
                } catch (\Exception $e) {
                    echo "[detect_usb] 错误: " . $e->getMessage() . "\n";
                }
                break;
                
            case 'add_printer':
                $printerUri = $data['uri'] ?? '';
                $result = addPrinter(
                    $data['name'] ?? 'Printer',
                    $printerUri,
                    $data['driver'] ?? 'everywhere'
                );
                $this->send([
                    'action' => 'add_printer_result',
                    'request_id' => $data['request_id'] ?? '',
                    'success' => $result['success'],
                    'message' => $result['message']
                ]);
                sleep(1);
                $printerList = getPrinterList();
                foreach ($printerList as &$p) {
                    if (empty($p['uri']) && $result['success']) {
                        $p['uri'] = $printerUri;
                    }
                }
                $this->send([
                    'action' => 'printer_list',
                    'printers' => $printerList
                ]);
                break;
                
            case 'remove_printer':
                $result = removePrinter($data['name'] ?? '');
                $this->send([
                    'action' => 'remove_printer_result',
                    'request_id' => $data['request_id'] ?? '',
                    'success' => $result['success'],
                    'message' => $result['message']
                ]);
                $this->send([
                    'action' => 'printer_list',
                    'printers' => getPrinterList()
                ]);
                break;
                
            case 'change_driver':
                $printerName = $data['printer_name'] ?? '';
                $newDriver = $data['driver'] ?? 'everywhere';
                echo "[change_driver] 打印机: $printerName, 新驱动: $newDriver\n";
                
                $result = changeDriver($printerName, $newDriver);
                $this->send([
                    'action' => 'change_driver_result',
                    'request_id' => $data['request_id'] ?? '',
                    'success' => $result['success'],
                    'message' => $result['message']
                ]);
                sleep(1);
                $this->send([
                    'action' => 'printer_list',
                    'printers' => getPrinterList()
                ]);
                break;
                
            case 'print':
                $printer = $data['printer'] ?? $data['printer_name'] ?? '';
                $fileContent = $data['file_content'] ?? '';
                $fileUrl = $data['file_url'] ?? '';
                $filename = $data['filename'] ?? $data['file_name'] ?? 'document';
                $fileExt = $data['file_ext'] ?? pathinfo($filename, PATHINFO_EXTENSION) ?: 'pdf';
                $copies = intval($data['copies'] ?? 1);
                $taskId = $data['task_id'] ?? $data['job_id'] ?? '';
                $pageFrom = isset($data['page_from']) && $data['page_from'] !== '' ? intval($data['page_from']) : null;
                $pageTo   = isset($data['page_to']) && $data['page_to'] !== '' ? intval($data['page_to']) : null;
                $colorMode = strval($data['color_mode'] ?? 'color');
                $orientation = strval($data['orientation'] ?? 'portrait');
                $isDuplex = boolval($data['is_duplex'] ?? false);
                $paperSize = strval($data['paper_size'] ?? 'A4');
                
                echo "[print] 打印机: $printer, 文件: $filename, 扩展名: $fileExt, 份数: $copies, 色彩: $colorMode, 方向: $orientation, 双面: $isDuplex, 纸张: $paperSize\n";
                
                if (!empty($fileUrl) && empty($fileContent)) {
                    echo "[print] 从远程服务器下载文件\n";
                    
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $fileUrl,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_TIMEOUT => 120,
                        CURLOPT_CONNECTTIMEOUT => 10,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => false,
                        CURLOPT_USERAGENT => 'PrinterClient/1.0.5'
                    ]);
                    
                    $downloadedContent = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlError = curl_error($ch);
                    $downloadSize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
                    curl_close($ch);
                    
                    if ($downloadedContent !== false && $httpCode === 200 && strlen($downloadedContent) > 0) {
                        $fileContent = base64_encode($downloadedContent);
                        echo "[print] 下载成功，大小: " . strlen($downloadedContent) . " 字节\n";
                    } else {
                        $errorMsg = "网络连接失败";
                        if ($httpCode !== 200) {
                            $errorMsg = "服务器响应错误 (状态码: $httpCode)";
                        } elseif (!empty($curlError)) {
                            $errorMsg = "连接错误: $curlError";
                        } elseif ($downloadSize === 0) {
                            $errorMsg = "文件为空或不存在";
                        }
                        
                        echo "[print] 文件下载失败: $errorMsg\n";
                        $result = ['success' => false, 'message' => "文件下载失败: $errorMsg"];
                        $this->send([
                            'action' => 'print_result',
                            'task_id' => $taskId,
                            'job_id' => $taskId,
                            'success' => false,
                            'message' => "文件下载失败: $errorMsg"
                        ]);
                        break;
                    }
                }
                
                echo "[print] 文件内容长度: " . strlen($fileContent) . " 字节\n";
                
                if (empty($printer)) {
                    echo "[print] 错误: 打印机名称为空\n";
                    $result = ['success' => false, 'message' => '打印机名称为空'];
                } elseif (empty($fileContent)) {
                    echo "[print] 错误: 文件内容为空\n";
                    $result = ['success' => false, 'message' => '文件内容为空'];
                } else {
                    // 对于大文件，使用异步处理避免阻塞WebSocket
                    $fileSize = strlen($fileContent);
                    if ($fileSize > 1024 * 1024) { // 大于1MB的文件异步处理
                        echo "[print] 大文件($fileSize 字节)，启用异步处理\n";
                        
                        // 立即返回接收确认
                        $this->send([
                            'action' => 'print_result',
                            'task_id' => $taskId,
                            'job_id' => $taskId,
                            'success' => true,
                            'message' => '大文件已接收，正在后台处理...',
                            'async' => true
                        ]);
                        
                        // 异步执行打印
                        $this->executePrintAsync($printer, $fileContent, $filename, $fileExt, $copies, $pageFrom, $pageTo, $colorMode, $orientation, $taskId, $isDuplex, $paperSize);
                        
                        // 启动打印进度监控
                        $this->startPrintProgressMonitor($taskId, $printer);
                        break;
                    } else {
                        // 小文件同步处理
                        $result = executePrint($printer, $fileContent, $filename, $fileExt, $copies, $pageFrom, $pageTo, $colorMode, $orientation, $isDuplex, $paperSize);
                        
                        echo "[print] 结果: " . ($result['success'] ? '成功' : '失败') . " - " . $result['message'] . "\n";
                        
                        $this->send([
                            'action' => 'print_result',
                            'task_id' => $taskId,
                            'job_id' => $taskId,
                            'success' => $result['success'],
                            'message' => $result['message']
                        ]);
                    }
                }
                break;
                
            case 'refresh_printers':
                $this->send([
                    'action' => 'printer_list',
                    'printers' => getPrinterList()
                ]);
                break;
            
            case 'test_print':
                $printer = $data['printer'] ?? '';
                $requestId = $data['request_id'] ?? '';
                echo "[test_print] 打印机: $printer\n";
                
                $result = testPrint($printer);
                $this->send([
                    'action' => 'test_print_result',
                    'request_id' => $requestId,
                    'success' => $result['success'],
                    'message' => $result['message']
                ]);
                break;
                
            case 'error':
                echo "服务器错误: " . ($data['message'] ?? '') . "\n";
                break;
                
            case 'upgrade':
                $downloadUrl = $data['download_url'] ?? '';
                $requestId = $data['request_id'] ?? '';
                echo "[upgrade] 收到升级命令\n";
                
                if (empty($downloadUrl)) {
                    $this->send([
                        'action' => 'upgrade_result',
                        'request_id' => $requestId,
                        'success' => false,
                        'message' => '下载地址为空'
                    ]);
                } else {
                    $result = upgradeClient($downloadUrl);
                    $this->send([
                        'action' => 'upgrade_result',
                        'request_id' => $requestId,
                        'success' => $result['success'],
                        'message' => $result['message']
                    ]);
                }
                break;
            
            case 'restart':
                $requestId = $data['request_id'] ?? '';
                $reason = $data['reason'] ?? 'unknown';
                echo "[restart] 收到重启命令，原因: {$reason}\n";
                writeLog('INFO', "收到重启命令", ['reason' => $reason, 'request_id' => $requestId]);
                
                $this->send([
                    'action' => 'restart_ack',
                    'request_id' => $requestId,
                    'success' => true,
                    'message' => '重启命令已接收，服务将在3秒后重启'
                ]);
                
                // 延迟3秒后重启服务
                echo "服务将在3秒后重启...\n";
                $cmd = "(sleep 3 && systemctl restart websocket-printer) > /dev/null 2>&1 &";
                shell_exec($cmd);
                writeLog('INFO', "已执行重启命令", ['cmd' => $cmd]);
                break;
                
            case 'get_version':
                $requestId = $data['request_id'] ?? '';
                $versionInfo = getClientVersion();
                $this->send([
                    'action' => 'version_info',
                    'request_id' => $requestId,
                    'data' => $versionInfo
                ]);
                break;
                
            case 'sync_cups_printers':
                $requestId = $data['request_id'] ?? '';
                echo "[sync_cups_printers] 收到同步CUPS打印机命令\n";
                
                $syncResult = syncCupsPrinters();
                
                $this->send([
                    'action' => 'sync_cups_result',
                    'request_id' => $requestId,
                    'success' => $syncResult['success'],
                    'message' => $syncResult['message'],
                    'removed' => $syncResult['removed']
                ]);
                
                $this->send([
                    'action' => 'printers_update',
                    'printers' => $syncResult['printers']
                ]);
                break;
                
            case 'get_logs':
                $requestId = $data['request_id'] ?? '';
                $lines = intval($data['lines'] ?? 200);
                $date = $data['date'] ?? '';
                
                // 限制最大行数，避免过大的响应
                $lines = min($lines, 500); // 限制到500行
                
                // 设置5秒超时
                $oldTimeout = ini_get('default_socket_timeout');
                ini_set('default_socket_timeout', 5);
                
                try {
                    $startTime = microtime(true);
                    $logResult = getLogContent($lines, $date);
                    $processTime = (microtime(true) - $startTime) * 1000;
                    
                    // 检查响应大小
                    $responseSize = strlen(json_encode($logResult));
                    if ($responseSize > 512 * 1024) { // 大于512KB
                        // 分页处理，只返回前200行
                        $logResult['logs'] = array_slice($logResult['logs'], 0, 200);
                        $logResult['returned_lines'] = count($logResult['logs']);
                        $logResult['truncated'] = true;
                        $logResult['message'] = '响应过大，已截断显示前200行';
                    }
                    
                    $this->send([
                        'action' => 'logs_result',
                        'request_id' => $requestId,
                        'success' => $logResult['success'],
                        'date' => $logResult['date'],
                        'total_lines' => $logResult['total_lines'] ?? 0,
                        'returned_lines' => $logResult['returned_lines'] ?? 0,
                        'logs' => $logResult['logs'],
                        'truncated' => $logResult['truncated'] ?? false,
                        'message' => $logResult['message'] ?? ''
                    ]);
                } catch (Exception $e) {
                    $this->send([
                        'action' => 'logs_result',
                        'request_id' => $requestId,
                        'success' => false,
                        'message' => '日志读取失败: ' . $e->getMessage(),
                        'date' => $date,
                        'logs' => []
                    ]);
                } finally {
                    // 恢复原来的超时设置
                    ini_set('default_socket_timeout', $oldTimeout);
                }
                break;
                
            case 'get_log_dates':
                $requestId = $data['request_id'] ?? '';
                
                $dates = getLogDates();
                $this->send([
                    'action' => 'log_dates_result',
                    'request_id' => $requestId,
                    'dates' => $dates
                ]);
                break;
                
            case 'get_device_status':
                $requestId = $data['request_id'] ?? '';
                
                $status = getDeviceStatus();
                $this->send([
                    'action' => 'device_status_result',
                    'request_id' => $requestId,
                    'status' => $status
                ]);
                break;
                
            case 'clean_temp_files':
                $requestId = $data['request_id'] ?? '';
                
                writeLog('INFO', "收到清理临时文件请求");
                $cleanResult = cleanTempPrintFiles();
                
                $this->send([
                    'action' => 'clean_temp_result',
                    'request_id' => $requestId,
                    'success' => true,
                    'cleaned' => $cleanResult['cleaned'],
                    'errors' => $cleanResult['errors']
                ]);
                break;
                
            case 'get_cups_jobs':
                $requestId = $data['request_id'] ?? '';
                $printerName = $data['printer_name'] ?? '';
                echo "[get_cups_jobs] 获取打印队列, 打印机: $printerName\n";
                
                $jobs = getCupsJobs($printerName);
                $this->send([
                    'action' => 'cups_jobs_result',
                    'request_id' => $requestId,
                    'success' => true,
                    'jobs' => $jobs
                ]);
                break;
                
            case 'cancel_cups_job':
                $requestId = $data['request_id'] ?? '';
                $jobId = $data['job_id'] ?? '';
                $printerName = $data['printer_name'] ?? '';
                echo "[cancel_cups_job] 取消任务: $jobId\n";
                
                if (empty($jobId)) {
                    $this->send([
                        'action' => 'cancel_job_result',
                        'request_id' => $requestId,
                        'success' => false,
                        'message' => '任务ID不能为空'
                    ]);
                } else {
                    $result = cancelCupsJob($jobId, $printerName);
                    $this->send([
                        'action' => 'cancel_job_result',
                        'request_id' => $requestId,
                        'success' => $result['success'],
                        'message' => $result['message']
                    ]);
                }
                break;
                
            case 'reboot':
                $requestId = $data['request_id'] ?? '';
                $rebootSystem = ($data['reboot_system'] ?? false) === true;
                echo "========================================\n";
                echo "[reboot] 收到远程重启命令, 重启系统: " . ($rebootSystem ? '是' : '否') . "\n";
                echo "========================================\n";
                
                writeLog('WARN', "收到远程重启命令", ['reboot_system' => $rebootSystem]);
                
                $result = rebootDevice($rebootSystem);
                echo "[reboot] 执行结果: " . ($result['success'] ? '成功' : '失败') . " - " . $result['message'] . "\n";
                
                $this->send([
                    'action' => 'reboot_result',
                    'request_id' => $requestId,
                    'success' => $result['success'],
                    'message' => $result['message']
                ]);
                break;
                
            case 'restart_service':
                $requestId = $data['request_id'] ?? '';
                echo "========================================\n";
                echo "[restart_service] 收到远程重启服务命令!\n";
                echo "========================================\n";
                
                writeLog('INFO', "收到远程重启服务命令");
                
                $result = rebootDevice(false);
                echo "[restart_service] 执行结果: " . ($result['success'] ? '成功' : '失败') . " - " . $result['message'] . "\n";
                
                $this->send([
                    'action' => 'restart_service_result',
                    'request_id' => $requestId,
                    'success' => $result['success'],
                    'message' => $result['message']
                ]);
                break;
        }
        
        // 更新WebSocket活动时间，防止处理消息时被误判为假死
        $this->lastWsActivity = time();
    }
    
    private function reconnect()
    {
        global $RECONNECT_INTERVAL, $MAX_RECONNECT_INTERVAL, $ALL_WS_SERVERS, $CURRENT_SERVER_INDEX;
        
        $retryCount = 0;
        $currentInterval = $RECONNECT_INTERVAL;
        $serverCount = count($ALL_WS_SERVERS);
        
        while (true) {
            $retryCount++;
            
            // 如果有多个服务器，在重试多次后尝试切换到备用服务器
            if ($serverCount > 1 && $retryCount % 3 === 0) {
                $CURRENT_SERVER_INDEX = ($CURRENT_SERVER_INDEX + 1) % $serverCount;
                $newServer = $ALL_WS_SERVERS[$CURRENT_SERVER_INDEX];
                writeLog('INFO', "切换到服务器 [{$CURRENT_SERVER_INDEX}]");
                echo "切换到备用服务器...\n";
                $this->serverUrl = $newServer;
            }
            
            writeLog('INFO', "连接断开，尝试第 {$retryCount} 次重连...");
            echo "连接断开，尝试第 {$retryCount} 次重连...\n";
            
            if ($this->socket) {
                @fclose($this->socket);
                $this->socket = null;
            }
            $this->connected = false;
            $this->messageBuffer = '';
            
            sleep($currentInterval);
            
            if ($this->connect()) {
                writeLog('INFO', "重连成功，共尝试 {$retryCount} 次");
                echo "重连成功！\n";
                return; 
            }
            
            $currentInterval = min($currentInterval * 2, $MAX_RECONNECT_INTERVAL);
            writeLog('WARN', "重连失败，{$currentInterval}秒后继续尝试...");
        }
    }
    
    /**
     * 手动切换到下一个服务器
     */
    public function switchServer(): void
    {
        global $ALL_WS_SERVERS, $CURRENT_SERVER_INDEX;
        
        $serverCount = count($ALL_WS_SERVERS);
        if ($serverCount <= 1) {
            return;
        }
        
        $CURRENT_SERVER_INDEX = ($CURRENT_SERVER_INDEX + 1) % $serverCount;
        $this->serverUrl = $ALL_WS_SERVERS[$CURRENT_SERVER_INDEX];
        writeLog('INFO', "手动切换到服务器 [{$CURRENT_SERVER_INDEX}]");
    }
    
    public function getDeviceId(): string
    {
        return $this->deviceId;
    }
    
    /**
     * 异步执行打印任务，避免阻塞WebSocket主线程
     */
    private function executePrintAsync($printer, $fileContent, $filename, $fileExt, $copies, $pageFrom, $pageTo, $colorMode, $orientation, $taskId, $isDuplex = false, $paperSize = 'A4')
    {
        writeLog('INFO', "开始异步打印处理", ['task_id' => $taskId, 'file_size' => strlen($fileContent)]);
        
        // 检查是否支持pcntl扩展
        if (function_exists('pcntl_fork')) {
            // 使用fork创建子进程
            $pid = pcntl_fork();
            if ($pid == 0) {
                // 子进程执行打印
                $result = executePrint($printer, $fileContent, $filename, $fileExt, $copies, $pageFrom, $pageTo, $colorMode, $orientation, $isDuplex, $paperSize);
                
                // 将结果写入临时文件
                $resultFile = "/tmp/print_result_{$taskId}.json";
                file_put_contents($resultFile, json_encode($result));
                
                exit(0);
            } else if ($pid > 0) {
                // 父进程继续，不等待子进程
                writeLog('INFO', "异步打印进程已启动", ['task_id' => $taskId, 'pid' => $pid]);
                
                // 注册异步结果检查
                $this->asyncPrintTasks[$taskId] = [
                    'start_time' => time(),
                    'pid' => $pid
                ];
                return;
            }
        }
        
        // 如果pcntl不可用或fork失败，使用shell后台执行
        writeLog('INFO', "使用shell后台执行异步打印", ['task_id' => $taskId]);
        
        // 创建临时脚本文件
        $scriptFile = "/tmp/async_print_{$taskId}.php";
        $scriptContent = "<?php\n";
        $scriptContent .= "require_once " . escapeshellarg(__FILE__) . ";\n";
        $scriptContent .= "\$result = executePrint(" . 
            escapeshellarg($printer) . ", " .
            escapeshellarg($fileContent) . ", " .
            escapeshellarg($filename) . ", " .
            escapeshellarg($fileExt) . ", " .
            $copies . ", " .
            ($pageFrom === null ? 'null' : $pageFrom) . ", " .
            ($pageTo === null ? 'null' : $pageTo) . ", " .
            escapeshellarg($colorMode) . ", " .
            escapeshellarg($orientation) . ", " .
            ($isDuplex ? 'true' : 'false') . ", " .
            escapeshellarg($paperSize) . ");\n";
        $scriptContent .= "file_put_contents('/tmp/print_result_{$taskId}.json', json_encode(\$result));\n";
        $scriptContent .= "unlink(__FILE__);\n";
        
        file_put_contents($scriptFile, $scriptContent);
        chmod($scriptFile, 0755);
        
        // 后台执行脚本
        shell_exec("php {$scriptFile} > /dev/null 2>&1 &");
        
        // 注册异步结果检查
        $this->asyncPrintTasks[$taskId] = [
            'start_time' => time(),
            'script_file' => $scriptFile
        ];
    }
    
    /**
     * 检查异步打印任务的结果
     */
    private function checkAsyncPrintTasks()
    {
        foreach ($this->asyncPrintTasks as $taskId => $task) {
            $resultFile = "/tmp/print_result_{$taskId}.json";
            
            // 检查结果文件是否存在
            if (file_exists($resultFile)) {
                $resultJson = @file_get_contents($resultFile);
                $result = @json_decode($resultJson, true);
                
                if ($result) {
                    writeLog('INFO', "异步打印任务完成", ['task_id' => $taskId, 'success' => $result['success']]);
                    
                    $this->send([
                        'action' => 'print_result',
                        'task_id' => $taskId,
                        'job_id' => $taskId,
                        'success' => $result['success'],
                        'message' => $result['message'],
                        'async_completed' => true
                    ]);
                    
                    @unlink($resultFile);
                    unset($this->asyncPrintTasks[$taskId]);
                    continue;
                }
            }
            
            // 检查任务是否超时（5分钟）
            if (time() - $task['start_time'] > 300) {
                writeLog('WARN', "异步打印任务超时", ['task_id' => $taskId]);
                
                $this->send([
                    'action' => 'print_result',
                    'task_id' => $taskId,
                    'job_id' => $taskId,
                    'success' => false,
                    'message' => '打印任务处理超时',
                    'async_timeout' => true
                ]);
                
                @unlink($resultFile);
                unset($this->asyncPrintTasks[$taskId]);
            }
        }
    }
    
    /**
     * 启动打印进度监控
     */
    private function startPrintProgressMonitor($taskId, $printerName)
    {
        $this->printProgressTasks[$taskId] = [
            'start_time' => time(),
            'printer' => $printerName,
            'last_check' => 0,
            'notified_processing' => false
        ];
        
        writeLog('INFO', "启动打印进度监控", ['task_id' => $taskId, 'printer' => $printerName]);
    }
    
    /**
     * 检查打印进度
     */
    private function checkPrintProgress()
    {
        if (empty($this->printProgressTasks)) {
            return;
        }
        
        $now = time();
        foreach ($this->printProgressTasks as $taskId => $task) {
            // 每30秒检查一次进度
            if ($now - $task['last_check'] < 30) {
                continue;
            }
            
            $jobs = getCupsJobs($task['printer']);
            $found = false;
            
            foreach ($jobs as $job) {
                if (strpos($job['title'], $taskId) !== false || 
                    (time() - $task['start_time'] < 60)) { // 1分钟内的任务都可能是相关的
                    
                    $found = true;
                    
                    // 如果任务正在处理且还没通知过
                    if ($job['state'] === 'processing' && !$task['notified_processing']) {
                        $this->send([
                            'action' => 'print_progress',
                            'task_id' => $taskId,
                            'status' => 'processing',
                            'message' => '文档正在打印中...',
                            'job_details' => $job
                        ]);
                        
                        $this->printProgressTasks[$taskId]['notified_processing'] = true;
                        writeLog('INFO', "打印任务开始处理", ['task_id' => $taskId]);
                    }
                    break;
                }
            }
            
            // 更新检查时间
            $this->printProgressTasks[$taskId]['last_check'] = $now;
            
            // 如果超过10分钟还没找到任务，停止监控
            if (!$found && $now - $task['start_time'] > 600) {
                writeLog('INFO', "停止打印进度监控", ['task_id' => $taskId, 'reason' => 'timeout']);
                unset($this->printProgressTasks[$taskId]);
            }
        }
    }
    
    // ========== HTTP API 备用通道方法 ==========
    
    /**
     * 切换到HTTP API备用模式
     */
    private function switchToHttpApiMode(): void
    {
        global $HTTP_API_URL;
        
        if (empty($HTTP_API_URL)) {
            writeLog('WARN', "HTTP API URL未配置，无法切换到备用模式");
            return;
        }
        
        $this->httpApiMode = true;
        $this->wsRecoveryCheckTime = time();
        $this->disconnect();
        $this->registerViaHttpApi();
        
        writeLog('INFO', "已切换到HTTP API备用模式");
        echo "✓ 已切换到HTTP API模式\n";
    }
    
    /**
     * 通过HTTP API注册设备
     */
    private function registerViaHttpApi(): bool
    {
        global $HTTP_API_URL;
        
        $url = rtrim($HTTP_API_URL, '/') . '/register';
        $data = [
            'action' => 'register',
            'device_id' => $this->deviceId,
            'client_version' => CLIENT_VERSION,
            'connection_type' => 'http_polling'
        ];
        
        $result = $this->httpPost($url, $data);
        if ($result && isset($result['success']) && $result['success']) {
            $this->httpApiRegistered = true;
            writeLog('INFO', "HTTP API设备注册成功");
            return true;
        }
        
        writeLog('WARN', "HTTP API设备注册失败", ['result' => $result]);
        return false;
    }
    
    /**
     * HTTP API模式运行
     */
    private function runHttpApiMode(): void
    {
        global $HTTP_API_URL, $HTTP_API_POLL_INTERVAL, $WS_RECOVERY_CHECK_INTERVAL, $LAST_TEMP_CLEAN;
        
        $now = time();
        
        // 定期尝试恢复WebSocket连接
        if ($now - $this->wsRecoveryCheckTime >= $WS_RECOVERY_CHECK_INTERVAL) {
            $this->wsRecoveryCheckTime = $now;
            writeLog('INFO', "尝试恢复WebSocket连接...");
            echo "🔄 尝试恢复WebSocket连接...\n";
            
            if ($this->connect()) {
                writeLog('INFO', "WebSocket连接已恢复，退出HTTP API模式");
                echo "✓ WebSocket连接已恢复！\n";
                $this->httpApiMode = false;
                $this->lastWsActivity = $now;
                return;
            }
            writeLog('INFO', "WebSocket仍不可用，继续使用HTTP API模式");
        }
        
        // HTTP轮询获取任务
        if ($now - $this->lastHttpPoll >= $HTTP_API_POLL_INTERVAL) {
            $this->lastHttpPoll = $now;
            $this->pollHttpApiTasks();
        }
        
        // 清理任务
        if ($now - $LAST_TEMP_CLEAN >= TEMP_CLEAN_INTERVAL) {
            cleanTempPrintFiles();
            cleanOldLogs();
            $LAST_TEMP_CLEAN = $now;
        }
        
        $this->checkAsyncPrintTasks();
        $this->checkPrintProgress();
        
        usleep(100000);
    }
    
    /**
     * HTTP轮询获取待处理任务
     */
    private function pollHttpApiTasks(): void
    {
        global $HTTP_API_URL;
        
        $url = rtrim($HTTP_API_URL, '/') . '/poll';
        $result = $this->httpPost($url, ['action' => 'poll', 'device_id' => $this->deviceId]);
        
        if ($result && $result['success'] && !empty($result['tasks'])) {
            writeLog('INFO', "HTTP API收到任务", ['count' => count($result['tasks'])]);
            foreach ($result['tasks'] as $task) {
                $this->handleMessage(json_encode($task));
            }
        }
        
        // 发送心跳
        $deviceStatus = getDeviceStatus();
        $this->httpPost(rtrim($HTTP_API_URL, '/') . '/heartbeat', [
            'action' => 'heartbeat',
            'device_id' => $this->deviceId,
            'status' => 'online',
            'device_info' => $deviceStatus
        ]);
    }
    
    /**
     * HTTP POST请求
     */
    private function httpPost(string $url, array $data): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json']
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            writeLog('DEBUG', "HTTP请求失败", ['error' => $error]);
            return null;
        }
        
        return json_decode($response, true);
    }
}

$deviceId = getDeviceId();
$qrContent = "device://{$deviceId}";

initLogDir();
writeLog('INFO', "========================================");
writeLog('INFO', "  打印机客户端 v" . CLIENT_VERSION);
writeLog('INFO', "========================================");
writeLog('INFO', "设备ID: $deviceId");
writeLog('INFO', "WebSocket: [已配置]");
writeLog('INFO', "HTTP API: [已配置]");
writeLog('INFO', "假死超时: {$WS_ZOMBIE_TIMEOUT}秒, WS恢复检测: {$WS_RECOVERY_CHECK_INTERVAL}秒");

// HTTP API服务器
define('HTTP_API_VERSION', '1.0.0');
$httpApiServer = new HttpApiServer();

// 检查timeout命令是否可用
$timeoutAvailable = @shell_exec('which timeout 2>/dev/null');
if (empty($timeoutAvailable)) {
    writeLog('WARN', "timeout命令不可用，建议安装: apt-get install coreutils 或 yum install coreutils");
    writeLog('INFO', "已移除所有timeout依赖，使用PHP内置超时控制");
}
writeLog('INFO', "启动时间: " . date('Y-m-d H:i:s'));

echo "========================================\n";
echo "  打印机客户端 v" . CLIENT_VERSION . "\n";
echo "========================================\n";
echo "设备ID: $deviceId\n";
echo "WebSocket: [已配置]\n";
echo "HTTP API: [已配置]\n";
echo "启动时间: " . date('Y-m-d H:i:s') . "\n";
echo "----------------------------------------\n";

$qrCmd = "command -v qrencode > /dev/null 2>&1 && qrencode -t ANSI '$qrContent' 2>/dev/null";
$qrOutput = shell_exec($qrCmd);
if ($qrOutput) {
    echo "\n扫描下方二维码绑定设备:\n";
    echo $qrOutput;
    echo "\n";
} else {
    echo "\n提示: 安装 qrencode 可在终端显示二维码\n";
    echo "  sudo apt install qrencode\n\n";
}

$client = new PrinterClient($WS_SERVER);

while (true) {
    if ($client->connect()) {
        $client->run();
        writeLog('WARN', "主循环检测到连接断开，准备重连...");
    } else {
        writeLog('WARN', "连接失败，{$RECONNECT_INTERVAL}秒后重试...");
    }
    sleep($RECONNECT_INTERVAL);
}
