<?php
/**
 * kf.php - 无头浏览器视频直链提取 API
 *
 * 策略: PHP 接收参数 → 调用 Playwright Node.js 脚本 → 返回 JSON
 *
 * 环境要求:
 *   - PHP 8.0+
 *   - node + playwright + chromium
 *   - 服务器不能禁用 proc_open/popen/exec 中至少一个
 *
 * 用法:
 *   kf.php?url=https://v.qq.com/x/page/xxx.html
 *   kf.php?url=xxx&mode=direct&timeout=25
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

// ============ 环境诊断 ============
$disabled = array_map('trim', explode(',', ini_get('disable_functions')));
$disabled = array_filter($disabled);

$canProcOpen = function_exists('proc_open') && !in_array('proc_open', $disabled);
$canPopen    = function_exists('popen')    && !in_array('popen',    $disabled);
$canExec     = function_exists('exec')     && !in_array('exec',     $disabled);
$canSystem   = function_exists('system')   && !in_array('system',   $disabled);
$canShellExec= function_exists('shell_exec') && !in_array('shell_exec', $disabled);

// ============ 构建命令 ============
$scriptPath = __DIR__ . '/scripts/kf.mjs';
$nodeCmd = 'cd ' . escapeshellarg(__DIR__) . ' && '
         . 'node ' . escapeshellarg($scriptPath)
         . ' --url='     . escapeshellarg($url)
         . ' --mode='    . escapeshellarg($mode)
         . ' --timeout=' . $timeout
         . ' 2>&1';

set_time_limit(120);

$output = '';

// ============ 方式 1: proc_open (支持超时, 最可靠) ============
if ($canProcOpen) {
    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($nodeCmd, $descriptorspec, $pipes, __DIR__);
    if (is_resource($proc)) {
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        $start = time();
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) break;
            if (time() - $start > 120) {
                proc_terminate($proc);
                break;
            }
            $chunk = stream_get_contents($pipes[1]);
            if ($chunk !== false) $output .= $chunk;
            usleep(200000);
        }
        $output .= stream_get_contents($pipes[1]);
        proc_close($proc);
    }
}

// ============ 方式 2: popen (管道模式, 第二可靠) ============
if (empty($output) && $canPopen) {
    $fp = popen($nodeCmd, 'r');
    if ($fp !== false) {
        $start = time();
        stream_set_blocking($fp, false);
        while (!feof($fp)) {
            if (time() - $start > 120) break;
            $chunk = fread($fp, 8192);
            if ($chunk !== false && $chunk !== '') $output .= $chunk;
            else usleep(200000);
        }
        $output .= stream_get_contents($fp);
        pclose($fp);
    }
}

// ============ 方式 3: exec (同步, 有输出数组) ============
if (empty($output) && $canExec) {
    exec($nodeCmd, $lines, $returnVar);
    if (!empty($lines)) {
        $output = implode("\n", $lines);
    }
}

// ============ 方式 4: system (同步, 直接输出) ============
if (empty($output) && $canSystem) {
    ob_start();
    system($nodeCmd);
    $output = ob_get_clean();
}

// ============ 都不行 ============
if (empty($output)) {
    echo json_encode([
        'code' => 0,
        'msg'  => '服务器禁用了进程执行函数',
        'detail' => [
            'proc_open'  => $canProcOpen  ? '可用' : '禁用',
            'popen'      => $canPopen     ? '可用' : '禁用',
            'exec'       => $canExec      ? '可用' : '禁用',
            'system'     => $canSystem    ? '可用' : '禁用',
            'shell_exec' => $canShellExec ? '可用' : '禁用',
            'disable_functions' => $disabled,
        ],
        'solution' => '宝塔面板 → 软件商店 → PHP → 设置 → 禁用函数 → 删除 proc_open,popen,exec',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ============ 解析输出 (取最后的完整 JSON) ============
$output = trim($output);

// 找最后一个完整 JSON 对象 (从最后一个 { 开始匹配)
$lastBrace = strrpos($output, '{');
if ($lastBrace !== false) {
    $jsonText = substr($output, $lastBrace);
    $result = json_decode($jsonText, true);
    if ($result === null) {
        // 尝试逐步截断找有效 JSON
        for ($i = strlen($jsonText) - 1; $i > 0; $i--) {
            $sub = substr($jsonText, 0, $i);
            $test = json_decode($sub, true);
            if ($test !== null) {
                $result = $test;
                break;
            }
        }
    }
}

if (!isset($result) || !$result) {
    echo json_encode([
        'code' => 0,
        'msg'  => 'Playwright 无有效 JSON 输出',
        'raw'  => substr($output, -800),
        'funcs' => [
            'proc_open' => $canProcOpen ? 'OK' : 'OFF',
            'popen'     => $canPopen    ? 'OK' : 'OFF',
            'exec'      => $canExec     ? 'OK' : 'OFF',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 附加 PHP 侧信息
$result['_php'] = [
    'version' => PHP_VERSION,
    'mode'    => $mode,
    'timeout' => $timeout,
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
