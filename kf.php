<?php
/**
 * kf.php - 无头浏览器视频直链提取 API
 *
 * 策略: PHP 接收参数 → 调用 Playwright Node.js 脚本 → 返回 JSON
 * 两种模式:
 *   iframe  - 访问公共解析源捕获 m3u8/mp4
 *   direct  - 直接访问视频站点抓真实请求
 *   all     - 两者都试 (默认)
 *
 * 用法:
 *   kf.php?url=https://v.qq.com/x/page/xxx.html
 *   kf.php?url=xxx&mode=direct&timeout=25
 *   kf.php?url=xxx&mode=iframe
 *
 * 环境要求: node + playwright + chromium
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

// ============ 参数 ============
$url     = isset($_GET['url'])     ? trim($_GET['url'])     : '';
$mode    = isset($_GET['mode'])    ? trim($_GET['mode'])    : 'all';
$timeout = isset($_GET['timeout']) ? intval($_GET['timeout']) : 20;
if ($timeout > 60) $timeout = 60;

// 也支持 POST
if (empty($url) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $url     = $input['url']     ?? '';
    $mode    = $input['mode']    ?? 'all';
    $timeout = $input['timeout'] ?? 20;
}

if (empty($url)) {
    echo json_encode(['code' => 0, 'msg' => '需要 url 参数', 'example' => 'kf.php?url=https://v.qq.com/x/page/xxx.html']);
    exit;
}

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['code' => 0, 'msg' => 'url 格式不正确']);
    exit;
}

// ============ 执行 Playwright 脚本 ============
$scriptPath = __DIR__ . '/scripts/kf.mjs';
$nodeCmd    = 'cd ' . escapeshellarg(__DIR__) . ' && '
            . 'node ' . escapeshellarg($scriptPath)
            . ' --url='     . escapeshellarg($url)
            . ' --mode='    . escapeshellarg($mode)
            . ' --timeout=' . $timeout
            . ' 2>&1'; // stderr 合并到 stdout

set_time_limit(120);

// shell_exec 带超时 (PHP 8.2+)
if (function_exists('proc_open')) {
    // 用 proc_open 加超时
    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($nodeCmd, $descriptorspec, $pipes, __DIR__);
    if (!is_resource($proc)) {
        echo json_encode(['code' => 0, 'msg' => '无法启动 Playwright 进程']);
        exit;
    }
    fclose($pipes[0]);

    $output = '';
    $start  = time();
    while (true) {
        $status = proc_get_status($proc);
        if (!$status['running']) break;
        if (time() - $start > 120) {
            proc_terminate($proc);
            break;
        }
        $output .= stream_get_contents($pipes[1]);
        usleep(200000);
    }
    $output .= stream_get_contents($pipes[1]);
    proc_close($proc);
} else {
    $output = shell_exec($nodeCmd);
}

// ============ 解析输出 ============
// Node 最后一段输出是完整 JSON (以 { 开头 })
$output = trim($output);
$jsonStart = strrpos($output, "\n{");
if ($jsonStart === false) $jsonStart = strpos($output, '{');
$lastJson = $jsonStart !== false ? substr($output, $jsonStart + ($jsonStart > 0 ? 1 : 0)) : '';

// 也可能直接是 JSON
if (empty($lastJson) && str_starts_with($output, '{')) {
    $lastJson = $output;
}

if (empty($lastJson)) {
    echo json_encode([
        'code' => 0,
        'msg'  => 'Playwright 无有效 JSON 输出',
        'raw'  => substr($output, 0, 800),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = json_decode($lastJson, true);
if (!$result) {
    echo json_encode([
        'code' => 0,
        'msg'  => 'JSON 解析失败',
        'raw'  => substr($lastJson, 0, 500),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 附加 PHP 侧信息
$result['_php'] = [
    'version'  => PHP_VERSION,
    'mode'     => $mode,
    'timeout'  => $timeout,
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
