<?php
declare(strict_types=1);

// Single application entry: management page and WebDAV share index.php.
// Runtime state and uploaded files live outside the web root.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('zlib.output_compression', '0');
umask(0077);
const QINDAV_VERSION = '1.4.6';
const PERFORMANCE_LOG_ENABLED = true;
const PERFORMANCE_LOG_MAX_BYTES = 2 * 1024 * 1024;
// Keep only baseline timestamps until the existing configuration read decides whether to log.
$performanceEntryStarted = PERFORMANCE_LOG_ENABLED ? hrtime(true) : 0;
try { $applicationLock = applicationGate(); } catch (Throwable $error) {
    error_log('QinDav bootstrap: ' . $error->getMessage());
    http_response_code(503);
    exit('程序更新恢复未完成，请检查服务器日志和目录权限。');
}
if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    http_response_code(503);
    exit('请先运行 composer install --no-dev --optimize-autoloader');
}
require __DIR__ . '/vendor/autoload.php';

use Sabre\DAV;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

const UPLOAD_PREFIX = '.dav-upload-';
const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'PROPFIND'];
const BROWSER_CHUNK_BYTES = 16 * 1024 * 1024;
const UPLOAD_TTL = 86400;

// Diagnostics are opt-in in settings. PERFORMANCE_LOG_ENABLED is a hard override.
// Timings begin when this PHP entry executes; upstream buffering and client cache time are outside this scope.
function performanceTick(): int
{
    return isset($GLOBALS['qinPerf']) ? hrtime(true) : 0;
}

function performanceTock(string $phase, int $started): void
{
    if ($started === 0 || !isset($GLOBALS['qinPerf'])) return;
    $GLOBALS['qinPerf']['phase_ms'][$phase] = ($GLOBALS['qinPerf']['phase_ms'][$phase] ?? 0) + (hrtime(true) - $started) / 1e6;
}

function performanceSet(string $key, mixed $value): void
{
    if (isset($GLOBALS['qinPerf'])) $GLOBALS['qinPerf'][$key] = $value;
}

function performanceCount(string $key): void
{
    if (isset($GLOBALS['qinPerf'])) $GLOBALS['qinPerf'][$key] = ($GLOBALS['qinPerf'][$key] ?? 0) + 1;
}

function performanceMeasure(string $phase, callable $work): mixed
{
    if (!isset($GLOBALS['qinPerf'])) return $work();
    $started = performanceTick();
    try { return $work(); }
    catch (Throwable $error) {
        performanceSet('error_class', get_class($error));
        if (!isset($GLOBALS['qinPerf']['error_phase'])) performanceSet('error_phase', $phase);
        throw $error;
    } finally { performanceTock($phase, $started); }
}

function performanceCpu(): float
{
    if (!function_exists('getrusage')) return 0;
    $usage = getrusage();
    return ($usage['ru_utime.tv_sec'] ?? 0) * 1000 + ($usage['ru_utime.tv_usec'] ?? 0) / 1000
        + ($usage['ru_stime.tv_sec'] ?? 0) * 1000 + ($usage['ru_stime.tv_usec'] ?? 0) / 1000;
}

// Diagnostic path uses 8 KiB blocks, matching the native copy size observed locally.
// Aggregate in memory; never write a log entry per block. Keep native copy as the default.
function performanceCopy(mixed $input, mixed $output, ?int $maximum): int
{
    $bytes = $reads = $writes = 0;
    $readNs = $writeNs = $readMax = $writeMax = 0;
    $copyStarted = hrtime(true); $windowStarted = $copyStarted;
    $received = $windowBytes = $windows = $firstBytes = $stalls = $slowReads = 0;
    $firstData = $lastData = $firstMiB = null;
    $rateMin = $rateMax = null; $stallNs = $worstOffset = 0;
    try {
        while ($maximum === null || $bytes < $maximum + 1) {
            $length = $maximum === null ? 8192 : min(8192, $maximum + 1 - $bytes);
            $started = hrtime(true);
            try { $chunk = fread($input, $length); }
            finally {
                $readEnded = hrtime(true); $elapsed = $readEnded - $started;
                $readNs += $elapsed; ++$reads;
                if ($elapsed > $readMax) { $readMax = $elapsed; $worstOffset = $received; }
                if ($elapsed >= 10000000) ++$slowReads;
                if ($elapsed >= 100000000) { ++$stalls; $stallNs += $elapsed; }
            }
            if ($chunk === false) throw new DAV\Exception\BadRequest('Upload read failed');
            if ($chunk === '') {
                if (feof($input)) break;
                throw new DAV\Exception\BadRequest('Upload stream stalled');
            }
            $offset = 0; $size = strlen($chunk);
            $received += $size; $windowBytes += $size; $lastData = $readEnded;
            if ($firstData === null) { $firstData = $readEnded; $firstBytes = $size; }
            if ($firstMiB === null && $received >= 1048576) $firstMiB = ($readEnded - $copyStarted) / 1e6;
            if ($windowBytes >= 1048576) {
                $rate = $windowBytes / 1048576 / max(1e-9, ($readEnded - $windowStarted) / 1e9);
                $rateMin = $rateMin === null ? $rate : min($rateMin, $rate);
                $rateMax = $rateMax === null ? $rate : max($rateMax, $rate);
                ++$windows; $windowBytes = 0; $windowStarted = $readEnded;
            }
            while ($offset < $size) {
                $started = hrtime(true);
                try { $written = fwrite($output, $offset === 0 ? $chunk : substr($chunk, $offset)); }
                finally { $elapsed = hrtime(true) - $started; $writeNs += $elapsed; $writeMax = max($writeMax, $elapsed); ++$writes; }
                if ($written === false || $written === 0) throw new DAV\Exception\InsufficientStorage('Upload write failed');
                $offset += $written; $bytes += $written;
            }
        }
        return $bytes;
    } finally {
        performanceSet('copied_bytes', $bytes);
        performanceSet('io_read_calls', $reads); performanceSet('io_write_calls', $writes);
        performanceSet('io_read_max_ms', $readMax / 1e6); performanceSet('io_write_max_ms', $writeMax / 1e6);
        performanceSet('io_received_bytes', $received);
        performanceSet('io_first_data_ms', $firstData === null ? null : ($firstData - $copyStarted) / 1e6);
        performanceSet('io_first_mib_ms', $firstMiB);
        performanceSet('io_sustained_mib_s', $firstData !== null && $lastData > $firstData
            ? ($received - $firstBytes) / 1048576 / (($lastData - $firstData) / 1e9) : null);
        performanceSet('io_window_count', $windows);
        performanceSet('io_window_min_mib_s', $rateMin); performanceSet('io_window_max_mib_s', $rateMax);
        performanceSet('io_read_ge10ms_count', $slowReads); performanceSet('io_read_ge100ms_count', $stalls);
        performanceSet('io_read_ge100ms_total_ms', $stallNs / 1e6);
        performanceSet('io_worst_read_offset_bytes', $worstOffset);
        if (isset($GLOBALS['qinPerf'])) {
            $GLOBALS['qinPerf']['phase_ms']['input_read'] = $readNs / 1e6;
            $GLOBALS['qinPerf']['phase_ms']['output_write'] = $writeNs / 1e6;
        }
    }
}

function performanceEnabled(array $cfg): bool
{
    return PERFORMANCE_LOG_ENABLED && !empty($cfg['performance_log_enabled']);
}

function performanceStart(array $cfg, int $entryStarted, int $configStarted): void
{
    if (!performanceEnabled($cfg)) return;
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (!str_starts_with($path, '/index.php/')) return;
    try {
        $configFinished = hrtime(true);
        $startedUnix = microtime(true) - ($configFinished - $entryStarted) / 1e9;
        $usage = function_exists('getrusage') ? (getrusage() ?: []) : [];
        $protocol = $_SERVER['SERVER_PROTOCOL'] ?? '';
        $expect = strtolower(trim($_SERVER['HTTP_EXPECT'] ?? ''));
        $GLOBALS['qinPerf'] = [
            '_started' => $entryStarted, '_cpu_started' => performanceCpu(), '_usage_started' => $usage,
            'cpu_scope' => 'after-config',
            'schema' => 1, 'request_id' => bin2hex(random_bytes(6)), 'started_unix' => $startedUnix,
            'method' => substr((string) ($_SERVER['REQUEST_METHOD'] ?? ''), 0, 20),
            'pid' => getmypid(), 'app_version' => QINDAV_VERSION, 'php_version' => PHP_VERSION, 'php_sapi' => PHP_SAPI,
            'opcache_enabled' => filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN)
                && (PHP_SAPI !== 'cli' || filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOLEAN)),
            'body_expected_bytes' => isset($_SERVER['CONTENT_LENGTH']) ? max(0, (int) $_SERVER['CONTENT_LENGTH']) : null,
            'body_mode' => isset($_SERVER['CONTENT_LENGTH']) ? 'content-length'
                : (stripos($_SERVER['HTTP_TRANSFER_ENCODING'] ?? '', 'chunked') !== false ? 'chunked' : 'unknown'),
            'server_protocol' => in_array($protocol, ['HTTP/1.0', 'HTTP/1.1', 'HTTP/2', 'HTTP/2.0', 'HTTP/3', 'HTTP/3.0'], true) ? $protocol : 'unknown',
            'expect_mode' => $expect === '' ? 'none' : ($expect === '100-continue' ? '100-continue' : 'other'),
            'transfer_chunked_visible' => stripos($_SERVER['HTTP_TRANSFER_ENCODING'] ?? '', 'chunked') !== false,
            'client' => preg_match('/^rclone\/(v?[0-9]+\.[0-9]+(?:\.[0-9]+)?(?:-[0-9A-Za-z.]+)?)/', $_SERVER['HTTP_USER_AGENT'] ?? '', $match) ? 'rclone/' . substr($match[1], 0, 64) : 'other',
            'copied_bytes' => 0, 'published_bytes' => 0, 'ledger_write_count' => 0, 'storage_lock_count' => 0,
            'phase_ms' => ['bootstrap' => ($configStarted - $entryStarted) / 1e6,
                'config' => ($configFinished - $configStarted) / 1e6],
        ];
        register_shutdown_function('performanceFinish');
    } catch (Throwable $ignored) { unset($GLOBALS['qinPerf']); }
}

function performanceFinish(): void
{
    if (!isset($GLOBALS['qinPerf'])) return;
    $record = $GLOBALS['qinPerf'];
    unset($GLOBALS['qinPerf']); // Diagnostic I/O must not count itself as application work.
    $record['finished_unix'] = microtime(true);
    $record['php_wall_ms'] = round((hrtime(true) - $record['_started']) / 1e6, 3);
    $record['php_cpu_ms'] = round(max(0, performanceCpu() - $record['_cpu_started']), 3);
    $record['peak_memory_bytes'] = memory_get_peak_usage(true);
    $record['status'] = http_response_code() ?: 200;
    // Retain uploads, copies, failures and slow metadata requests, not every fast query.
    if (!in_array($record['method'], ['PUT', 'COPY'], true)
        && $record['status'] < 400 && $record['php_wall_ms'] < 100) return;
    $usage = function_exists('getrusage') ? (getrusage() ?: []) : [];
    foreach (['ru_nvcsw' => 'voluntary_context_switches', 'ru_nivcsw' => 'involuntary_context_switches'] as $key => $label) {
        if (isset($usage[$key], $record['_usage_started'][$key])) $record[$label] = max(0, $usage[$key] - $record['_usage_started'][$key]);
    }
    $fatal = error_get_last();
    if ($fatal && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $record['fatal_error_type'] = $fatal['type'];
    }
    foreach ($record['phase_ms'] as &$ms) $ms = round($ms, 3);
    unset($ms, $record['_started'], $record['_cpu_started'], $record['_usage_started']);
    $topLevel = ['bootstrap', 'config', 'authentication', 'file_open', 'quota_reserve', 'copy_io', 'validate_flush', 'publish', 'file_close', 'cleanup'];
    $classified = 0;
    foreach ($topLevel as $phase) $classified += $record['phase_ms'][$phase] ?? 0;
    $record['php_other_ms'] = round(max(0, $record['php_wall_ms'] - $classified), 3);
    $record['copy_io_mib_s'] = ($record['phase_ms']['copy_io'] ?? 0) > 0
        ? round($record['copied_bytes'] / 1048576 / ($record['phase_ms']['copy_io'] / 1000), 3) : null;
    $lock = $output = null;
    try {
        $root = stateDir();
        $waiting = hrtime(true);
        $lock = fopen($root . '/performance.log.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock diagnostic log');
        $record['log_lock_wait_ms'] = round((hrtime(true) - $waiting) / 1e6, 3);
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
        $path = $root . '/performance.ndjson';
        $previous = $root . '/performance.previous.ndjson';
        if (is_link($path) || is_link($previous)) throw new RuntimeException('Invalid diagnostic log path');
        clearstatcache(true, $path);
        if (is_file($path) && filesize($path) + strlen($line) > PERFORMANCE_LOG_MAX_BYTES) {
            if (!rename($path, $previous)) throw new RuntimeException('Cannot rotate diagnostic log');
        }
        $output = fopen($path, 'ab');
        if ($output === false) throw new RuntimeException('Cannot append diagnostic log');
        $length = strlen($line); $written = 0;
        while ($written < $length) {
            $bytes = fwrite($output, substr($line, $written));
            if ($bytes === false || $bytes === 0) throw new RuntimeException('Cannot write diagnostic log');
            $written += $bytes;
        }
    } catch (Throwable $ignored) {
        error_log('QinDav performance: diagnostic log unavailable');
    } finally {
        if (is_resource($output)) fclose($output);
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

function performanceSummary(string $snapshot): string
{
    $records = $uploads = $operations = $slow = []; $statuses = []; $phases = []; $errors = []; $modes = []; $streams = [];
    $bytes = 0; $success = 0; $failed = 0; $copyCount = 0; $first = null; $last = null;
    foreach (explode("\n", $snapshot) as $line) {
        $row = json_decode($line, true);
        if (!is_array($row) || !isset($row['method'], $row['php_wall_ms'], $row['started_unix'], $row['finished_unix'])) continue;
        $status = (int) ($row['status'] ?? 0); $statuses[$status] = ($statuses[$status] ?? 0) + 1;
        if ($status >= 400) {
            $key = $row['method'] . ' ' . $status;
            $errors[$key]['count'] = ($errors[$key]['count'] ?? 0) + 1;
            $errors[$key]['ms'] = ($errors[$key]['ms'] ?? 0) + $row['php_wall_ms'];
        }
        $records[] = $row;
        if ($row['method'] === 'PUT') {
            $uploads[] = $row;
            $first = $first === null ? $row['started_unix'] : min($first, $row['started_unix']);
            $last = $last === null ? $row['finished_unix'] : max($last, $row['finished_unix']);
            if ($status >= 200 && $status < 300) { ++$success; $bytes += $row['published_bytes'] ?? 0; }
            else ++$failed;
        }
        if (in_array($row['method'], ['PUT', 'COPY'], true)) {
            $operations[] = $row;
            $mode = $row['copy_mode'] ?? 'native'; $modes[$mode] = ($modes[$mode] ?? 0) + 1;
            $stream = $row['input_stream_type'] ?? 'unknown'; $streams[$stream] = ($streams[$stream] ?? 0) + 1;
            if ($row['method'] === 'COPY') ++$copyCount;
            foreach ($row['phase_ms'] ?? [] as $phase => $ms) $phases[$phase][] = (float) $ms;
            if (isset($row['log_lock_wait_ms'])) $phases['log_lock_wait'][] = (float) $row['log_lock_wait_ms'];
        }
        $slow[] = $row;
        usort($slow, fn($a, $b) => $b['php_wall_ms'] <=> $a['php_wall_ms']);
        $slow = array_slice($slow, 0, 5);
    }
    if (!$records) return "暂无关键操作日志。请先用 rclone 上传，再查看或复制摘要。\n";
    $latest = $records[count($records) - 1];
    $lines = ['QinDav 性能摘要', sprintf('应用 %s · PHP %s · %s · OPcache %s',
        $latest['app_version'] ?? '?', $latest['php_version'] ?? '?', $latest['php_sapi'] ?? '?', !empty($latest['opcache_enabled']) ? '开' : '关')];
    $lines[] = sprintf('已记录 %d 个关键请求；上传成功 %d / 失败 %d；COPY %d', count($records), $success, $failed, $copyCount);
    if ($uploads) {
        $seconds = max(0.000001, $last - $first);
        $lines[] = sprintf('上传窗口 %s — %s UTC，%.3f 秒', gmdate('Y-m-d H:i:s', (int) $first), gmdate('H:i:s', (int) $last), $seconds);
        $lines[] = sprintf('成功上传 %.2f MiB，PHP 记录窗口平均 %.2f MiB/s', $bytes / 1048576, $bytes / 1048576 / $seconds);
    }
    ksort($statuses);
    $codes = []; foreach ($statuses as $code => $count) $codes[] = $code . '×' . $count;
    $lines[] = '状态码：' . implode('，', $codes);
    if ($uploads) {
        $transport = [];
        foreach ($uploads as $row) {
            $key = ($row['server_protocol'] ?? 'unknown') . ' / ' . ($row['body_mode'] ?? 'unknown')
                . ' / Expect=' . ($row['expect_mode'] ?? 'unknown')
                . (!empty($row['transfer_chunked_visible']) ? ' / TE=chunked' : '');
            $transport[$key] = ($transport[$key] ?? 0) + 1;
        }
        arsort($transport); $parts = [];
        foreach (array_slice($transport, 0, 8, true) as $key => $count) $parts[] = $key . '×' . $count;
        $lines[] = 'PHP 可见传输特征：' . implode('；', $parts);
        $lengths = array_values(array_filter(array_column($uploads, 'body_expected_bytes'), fn($value) => $value !== null));
        if ($lengths) $lines[] = sprintf('Content-Length：%d 个请求，%.2f—%.2f MiB', count($lengths), min($lengths) / 1048576, max($lengths) / 1048576);
    }
    if ($errors) {
        uasort($errors, fn($a, $b) => $b['count'] <=> $a['count']);
        $parts = [];
        foreach (array_slice($errors, 0, 8, true) as $key => $error) $parts[] = sprintf('%s ×%d（均值 %.3f ms）', $key, $error['count'], $error['ms'] / $error['count']);
        $lines[] = '异常请求：' . implode('；', $parts);
    }
    if ($operations) {
        $parts = []; foreach ($modes as $mode => $count) $parts[] = $mode . '×' . $count;
        $lines[] = '复制模式：' . implode('，', $parts);
        $parts = []; foreach ($streams as $stream => $count) $parts[] = $stream . '×' . $count;
        $lines[] = '输入流：' . implode('，', $parts);
        $walls = array_column($operations, 'php_wall_ms'); sort($walls, SORT_NUMERIC);
        $p95 = $walls[max(0, (int) ceil(count($walls) * .95) - 1)];
        $cpu = array_sum(array_column($operations, 'php_cpu_ms')) / count($operations);
        $peak = max(array_column($operations, 'peak_memory_bytes') ?: [0]) / 1048576;
        $lines[] = sprintf('上传/COPY：PHP 总耗时均值 %.3f ms，P95 %.3f ms，CPU 均值 %.3f ms，峰值内存 %.1f MiB', array_sum($walls) / count($walls), $p95, $cpu, $peak);
        if (($latest['cpu_scope'] ?? '') === 'after-config') $lines[] = 'CPU 和上下文切换从配置读取后开始统计；PHP 总耗时仍含入口启动与配置读取。';
        $scheduled = array_values(array_filter($operations, fn($row) => isset($row['voluntary_context_switches'], $row['involuntary_context_switches'])));
        if ($scheduled) $lines[] = sprintf('进程上下文切换（%d 请求）：主动均值 %.1f 次，被动均值 %.1f 次 / 最大 %d 次；计数不能直接判定原因',
            count($scheduled), array_sum(array_column($scheduled, 'voluntary_context_switches')) / count($scheduled),
            array_sum(array_column($scheduled, 'involuntary_context_switches')) / count($scheduled), max(array_column($scheduled, 'involuntary_context_switches')));
        $lines[] = '';
        $lines[] = '关键阶段：均值 / P95（ms；嵌套项不直接相加）';
        $labels = ['bootstrap' => 'PHP 启动', 'config' => '配置读取', 'authentication' => '认证', 'file_open' => '临时文件创建',
            'quota_reserve' => '容量预留', 'copy_io' => '读取请求体＋写入文件',
            'input_read' => '读取请求体（含等待）', 'output_write' => '文件写入调用',
            'validate_flush' => '校验＋刷新', 'file_close' => '文件关闭',
            'publish' => '文件发布＋记账', 'file_rename' => '原子重命名', 'storage_lock_wait' => '容量锁等待',
            'storage_lock_hold' => '容量锁持有', 'ledger_read' => '账本读取',
            'ledger_write' => '账本写入', 'usage_scan' => '用量恢复扫描', 'cleanup' => '收尾', 'log_lock_wait' => '日志锁等待'];
        foreach ($labels as $key => $label) {
            if (empty($phases[$key])) continue;
            $values = $phases[$key]; sort($values, SORT_NUMERIC);
            $lines[] = sprintf('%s：%.3f / %.3f', $label, array_sum($values) / count($values), $values[max(0, (int) ceil(count($values) * .95) - 1)]);
        }
        $lines[] = sprintf('每请求账本写入 %.2f 次，容量锁 %.2f 次', array_sum(array_column($operations, 'ledger_write_count')) / count($operations), array_sum(array_column($operations, 'storage_lock_count')) / count($operations));
        $cache = ['hit' => 0, 'miss' => 0];
        foreach ($operations as $row) if (isset($cache[$row['auth_cache'] ?? ''])) ++$cache[$row['auth_cache']];
        $lines[] = sprintf('认证缓存：命中 %d / 未命中 %d', $cache['hit'], $cache['miss']);
        $diagnostics = array_values(array_filter($operations, fn($row) => ($row['copy_mode'] ?? '') === 'split-8k'));
        if ($diagnostics) {
            $lines[] = sprintf('分段诊断 %d 次：平均读取 %.0f 次 / 写入 %.0f 次；最慢单次读取 %.3f ms / 写入 %.3f ms',
                count($diagnostics), array_sum(array_column($diagnostics, 'io_read_calls')) / count($diagnostics),
                array_sum(array_column($diagnostics, 'io_write_calls')) / count($diagnostics),
                max(array_column($diagnostics, 'io_read_max_ms') ?: [0]), max(array_column($diagnostics, 'io_write_max_ms') ?: [0]));
            $progress = array_values(array_filter($diagnostics, fn($row) => isset($row['io_received_bytes'])));
            if ($progress) {
                $metric = function (string $key) use ($progress): string {
                    $values = array_values(array_filter(array_column($progress, $key), fn($value) => $value !== null));
                    if (!$values) return '无样本';
                    sort($values, SORT_NUMERIC);
                    return sprintf('%.3f / %.3f ms', array_sum($values) / count($values), $values[max(0, (int) ceil(count($values) * .95) - 1)]);
                };
                $lines[] = '读取进度（均值/P95）：首个非空块 ' . $metric('io_first_data_ms') . '；首个 MiB ' . $metric('io_first_mib_ms');
                $rates = array_values(array_filter(array_column($progress, 'io_sustained_mib_s'), fn($value) => $value !== null));
                if ($rates) $lines[] = sprintf('首块后单请求观察速度：均值 %.2f MiB/s，最低 %.2f / 最高 %.2f（含交错写入及调度）', array_sum($rates) / count($rates), min($rates), max($rates));
                $mins = array_values(array_filter(array_column($progress, 'io_window_min_mib_s'), fn($value) => $value !== null));
                $maxs = array_values(array_filter(array_column($progress, 'io_window_max_mib_s'), fn($value) => $value !== null));
                if ($mins && $maxs) $lines[] = sprintf('约 1 MiB 窗口 %d 个：最低 %.2f / 最高 %.2f MiB/s（含首块等待，末尾不足 1 MiB 不计窗口）', array_sum(array_column($progress, 'io_window_count')), min($mins), max($maxs));
                $lines[] = sprintf('慢读取 ≥10 ms：%d 次；其中 ≥100 ms：%d 次，合计 %.3f ms（跨并发请求相加）',
                    array_sum(array_column($progress, 'io_read_ge10ms_count')), array_sum(array_column($progress, 'io_read_ge100ms_count')),
                    array_sum(array_column($progress, 'io_read_ge100ms_total_ms')));
            }
            $lines[] = '分段计时会增加循环与计时开销；写入耗时包含系统缓存接收，不代表物理磁盘落盘耗时。';
        }
    }
    $lines[] = ''; $lines[] = '最慢请求（最多 5 条）：';
    foreach ($slow as $row) {
        $lines[] = sprintf('%s UTC %s %d · %.2f MiB · 总 %.3f ms / 复制 %.3f ms / 锁等待 %.3f ms',
            gmdate('H:i:s', (int) $row['started_unix']), $row['method'], $row['status'] ?? 0, ($row['copied_bytes'] ?? 0) / 1048576,
            $row['php_wall_ms'], $row['phase_ms']['copy_io'] ?? 0, $row['phase_ms']['storage_lock_wait'] ?? 0);
        if (($row['copy_mode'] ?? '') === 'split-8k') $lines[count($lines) - 1] .= sprintf(' / 读取 %.3f ms / 写入 %.3f ms', $row['phase_ms']['input_read'] ?? 0, $row['phase_ms']['output_write'] ?? 0);
        if (isset($row['io_received_bytes'])) $lines[count($lines) - 1] .= sprintf(' / 慢读取≥100ms %d 次 / 最慢读取 %.3f ms @ %.2f MiB',
            $row['io_read_ge100ms_count'] ?? 0, $row['io_read_max_ms'] ?? 0, ($row['io_worst_read_offset_bytes'] ?? 0) / 1048576);
    }
    $lines[] = ''; $lines[] = '范围：仅 PHP 执行阶段，复制耗时包含读取等待与写入；不含客户端缓存和 PHP 执行前的上游等待。';
    $lines[] = '日志追加在上述计时后执行，不包含在 PHP 总耗时中；日志锁等待不代表完整日志写入开销。';
    $lines[] = '平均速度包含窗口内空闲时间，建议每轮上传前清空日志。';
    if ($uploads) $lines[] = '协议与请求头仅反映 PHP 可见值，上游可能已转换；读取耗时也可能包含进程调度，不能单凭这些计数定位网络或前置服务。';
    return implode("\n", $lines) . "\n";
}

function stateDir(): string
{
    static $path;
    if ($path !== null) return $path;
    $candidate = getenv('WEBDAV_STATE_DIR') ?: dirname(__DIR__) . '/.webdav-state-' . substr(hash('sha256', __DIR__), 0, 12);
    if (!str_starts_with($candidate, '/')) throw new RuntimeException('WEBDAV_STATE_DIR must be absolute');
    if (!is_dir($candidate) && !mkdir($candidate, 0700, true) && !is_dir($candidate)) {
        throw new RuntimeException('Cannot create state directory');
    }
    $path = realpath($candidate);
    if ($path === false || $path === __DIR__ || str_starts_with($path . '/', __DIR__ . '/')) {
        throw new RuntimeException('State directory must be outside the application web root');
    }
    foreach (['files', 'sessions', 'auth', 'rate', 'uploads'] as $dir) {
        if (!is_dir($path . '/' . $dir) && !mkdir($path . '/' . $dir, 0700) && !is_dir($path . '/' . $dir)) {
            throw new RuntimeException('Cannot create runtime directory');
        }
    }
    return $path;
}

function readJson(string $path): array
{
    if (!is_file($path)) return [];
    $raw = file_get_contents($path);
    if ($raw === false) throw new RuntimeException('Cannot read state');
    $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Invalid state');
    return $data;
}

function atomicJson(string $path, array $data): void
{
    $ledger = isset($GLOBALS['qinPerf']) && basename($path) === 'usage.json';
    $started = $ledger ? performanceTick() : 0;
    $tmp = tempnam(dirname($path), '.state-');
    if ($tmp === false) throw new RuntimeException('Cannot allocate state file');
    try {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json) !== strlen($json) || !rename($tmp, $path)) {
            throw new RuntimeException('Cannot save state');
        }
        if ($ledger) performanceCount('ledger_write_count');
    } finally {
        if (is_file($tmp)) unlink($tmp);
        if ($ledger) performanceTock('ledger_write', $started);
    }
}

// The stable sidecar lock protects the entire read/modify/atomic-replace transaction.
function transaction(string $path, callable $callback): mixed
{
    $handle = fopen($path . '.lock', 'c');
    if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('Cannot lock state');
    try {
        $data = readJson($path);
        $result = $callback($data);
        atomicJson($path, $data);
        return $result;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function config(): array { return readJson(stateDir() . '/config.json'); }
function secureRequest(): bool { return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'; }

function openSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '86400');
    session_name('single_dav');
    session_save_path(stateDir() . '/sessions');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => secureRequest(), 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

function sessionUser(array $cfg): bool
{
    return !empty($cfg['username']) && ($_SESSION['username'] ?? null) === $cfg['username']
        && hash_equals($cfg['session_epoch'], (string) ($_SESSION['epoch'] ?? ''))
        && ($_SESSION['expires'] ?? 0) > time();
}

function csrfValid(string $token): bool
{
    return isset($_SESSION['csrf']) && $token !== '' && hash_equals($_SESSION['csrf'], $token);
}

function ratePath(): string
{
    return stateDir() . '/rate/' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'local') . '.json';
}

function rateLimited(): bool
{
    $record = readJson(ratePath());
    return ($record['until'] ?? 0) > time() && ($record['count'] ?? 0) >= 10;
}

function failedLogin(): void
{
    transaction(ratePath(), function (&$record) {
        if (($record['until'] ?? 0) <= time()) $record = ['until' => time() + 300, 'count' => 0];
        ++$record['count'];
    });
}

function credentialsValid(array $cfg, string $username, string $password, bool $allowApp = true): bool
{
    if (!$cfg || strlen($username) > 128 || strlen($password) > 1024) return false;
    // Only a keyed fingerprint is persisted, never the Authorization header or password.
    // Changing either password immediately invalidates the corresponding cache keys.
    $key = hash_hmac('sha256', $username . "\0" . $password . "\0" . $cfg['password_hash']
        . "\0" . ($allowApp ? ($cfg['app_hash'] ?? '') : 'web-login'), $cfg['secret']);
    $path = stateDir() . '/auth/' . $key . '.json';
    $cached = readJson($path);
    if (($cached['expires'] ?? 0) > time()) { performanceSet('auth_cache', 'hit'); return true; }
    performanceSet('auth_cache', 'miss');
    if (rateLimited()) return false;
    $valid = hash_equals($cfg['username'], $username) && (
        password_verify($password, $cfg['password_hash']) ||
        ($allowApp && !empty($cfg['app_hash']) && password_verify($password, $cfg['app_hash']))
    );
    if ($valid) atomicJson($path, ['expires' => time() + 300]);
    else failedLogin();
    return $valid;
}

function validName(string $name): bool
{
    return $name !== '' && $name !== '.' && $name !== '..' && strlen($name) <= 255
        && !preg_match('/[\x00-\x1f\x7f\/\\\\]/', $name)
        && !str_starts_with($name, UPLOAD_PREFIX) && mb_check_encoding($name, 'UTF-8');
}

function assertName(string $name): void
{
    if (!validName($name)) throw new DAV\Exception\Forbidden('Invalid or reserved filename');
}

function checkedStat(string $path): array
{
    $stat = @lstat($path); // Missing nodes are routine DAV lookups, not server errors.
    if ($stat === false) throw new DAV\Exception\NotFound('File not found');
    if (!in_array($stat['mode'] & 0170000, [0100000, 0040000], true)) {
        throw new DAV\Exception\Forbidden('Symlinks and special files are not accessible');
    }
    return $stat;
}

function scanStorage(): array
{
    $bytes = $files = 0;
    $pending = [stateDir() . '/files'];
    while ($pending) {
        foreach (new FilesystemIterator(array_pop($pending), FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!validName($entry->getFilename()) || $entry->isLink()) continue;
            if ($entry->isDir()) $pending[] = $entry->getPathname();
            elseif ($entry->isFile()) { $bytes += $entry->getSize(); ++$files; }
        }
    }
    return ['used_bytes' => $bytes, 'files' => $files];
}

function storageTransaction(callable $callback): mixed
{
    $path = stateDir() . '/usage.json';
    $lock = fopen($path . '.lock', 'c');
    $waiting = performanceTick();
    if ($lock === false || !flock($lock, LOCK_EX)) {
        performanceTock('storage_lock_wait', $waiting);
        throw new RuntimeException('Cannot lock storage accounting');
    }
    performanceTock('storage_lock_wait', $waiting);
    performanceCount('storage_lock_count');
    $holding = performanceTick();
    try {
        clearstatcache();
        $usage = performanceMeasure('ledger_read', fn() => readJson($path));
        if (!$usage || !empty($usage['dirty'])) {
            set_time_limit(0);
            $usage = array_replace(['limit_bytes' => 0, 'reservations' => []], $usage, performanceMeasure('usage_scan', fn() => scanStorage()), ['dirty' => false]);
            atomicJson($path, $usage);
        }
        foreach ($usage['reservations'] as $id => $reservation) {
            if ($reservation['expires'] <= time()) unset($usage['reservations'][$id]);
        }
        try {
            $result = $callback($usage);
            atomicJson($path, $usage);
            return $result;
        } catch (Throwable $error) {
            if (!empty($usage['dirty'])) {
                $usage = array_replace($usage, performanceMeasure('usage_scan', fn() => scanStorage()), ['dirty' => false]);
                atomicJson($path, $usage);
            }
            throw $error;
        }
    } finally {
        performanceTock('storage_lock_hold', $holding);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function storageReserved(array $usage, ?string $except = null): int
{
    $bytes = 0;
    foreach ($usage['reservations'] as $id => $reservation) {
        if ($id !== $except) $bytes += $reservation['bytes'];
    }
    return $bytes;
}

function storageSummary(array $usage): array
{
    $reserved = storageReserved($usage);
    return ['used_bytes' => $usage['used_bytes'], 'files' => $usage['files'],
        'limit_bytes' => $usage['limit_bytes'], 'reserved_bytes' => $reserved,
        'available_bytes' => $usage['limit_bytes'] ? max(0, $usage['limit_bytes'] - $usage['used_bytes'] - $reserved) : null,
        'disk_free_bytes' => disk_free_space(stateDir() . '/files')];
}

function storageInfo(bool $rescan = false): array
{
    if (!$rescan) {
        $path = stateDir() . '/usage.json';
        $lock = fopen($path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_SH)) throw new RuntimeException('Cannot read storage accounting');
        try {
            $usage = readJson($path);
            if ($usage && empty($usage['dirty'])) {
                // Expired reservations no longer count; read-only views do not rewrite the ledger.
                foreach ($usage['reservations'] as $id => $reservation) if ($reservation['expires'] <= time()) unset($usage['reservations'][$id]);
                return storageSummary($usage);
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    return storageTransaction(function (&$usage) use ($rescan) {
        if ($rescan) $usage = array_replace($usage, scanStorage());
        return storageSummary($usage);
    });
}

function storageFileSize(string $path): ?int
{
    $stat = @lstat($path);
    return $stat && ($stat['mode'] & 0170000) === 0100000 ? $stat['size'] : null;
}

function reserveStorage(string $id, ?int $expected, string $destination): ?int
{
    return storageTransaction(function (&$usage) use ($id, $expected, $destination) {
        $old = storageFileSize($destination) ?? 0;
        $available = $usage['limit_bytes'] ? max(0, $usage['limit_bytes'] - $usage['used_bytes'] - storageReserved($usage)) : null;
        $maximum = $expected ?? ($available === null ? null : $old + $available);
        $growth = $maximum === null ? 0 : max(0, $maximum - $old);
        if ($available !== null && $growth > $available) throw new DAV\Exception\InsufficientStorage('容量上限不足，请删除文件或提高上限');
        $usage['reservations'][$id] = ['bytes' => $growth, 'expires' => time() + UPLOAD_TTL];
        return $maximum;
    });
}

function releaseStorage(string $id): void
{
    storageTransaction(function (&$usage) use ($id) { unset($usage['reservations'][$id]); });
}

function storageMutationStart(array &$usage): void
{
    // A crash between filesystem mutation and accounting is repaired with one metadata scan.
    $usage['dirty'] = true;
    atomicJson(stateDir() . '/usage.json', $usage);
}

function publishStorage(string $id, string $destination, int $bytes, callable $publish): mixed
{
    return storageTransaction(function (&$usage) use ($id, $destination, $bytes, $publish) {
        $old = storageFileSize($destination);
        $delta = $bytes - ($old ?? 0);
        if ($delta > 0 && $usage['limit_bytes'] && $usage['used_bytes'] + storageReserved($usage, $id) + $delta > $usage['limit_bytes']) {
            throw new DAV\Exception\InsufficientStorage('容量上限不足，请删除文件或提高上限');
        }
        storageMutationStart($usage);
        $result = $publish();
        $usage['used_bytes'] += $delta;
        if ($old === null) ++$usage['files'];
        unset($usage['reservations'][$id]);
        $usage['dirty'] = false;
        return $result;
    });
}

function renameStorage(string $source, string $destination): void
{
    storageTransaction(function (&$usage) use ($source, $destination) {
        if (is_link($destination)) throw new DAV\Exception\Forbidden('Invalid destination');
        $old = storageFileSize($destination);
        $sourceStat = checkedStat($source);
        $targetStat = @lstat($destination);
        $same = $targetStat && $sourceStat['dev'] === $targetStat['dev'] && $sourceStat['ino'] === $targetStat['ino'];
        storageMutationStart($usage);
        if (!rename($source, $destination)) throw new DAV\Exception\Forbidden('Rename failed');
        if ($old !== null && !$same) { $usage['used_bytes'] -= $old; --$usage['files']; }
        $usage['dirty'] = false;
    });
}

function writeStream(string $destination, mixed $data, bool $createOnly = false): string
{
    $createOnly = $createOnly || (($_SERVER['REQUEST_METHOD'] ?? '') === 'PUT' && trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === '*');
    // Stage beside the destination: same filesystem, atomic rename, no second full-file copy.
    $tmp = dirname($destination) . '/' . UPLOAD_PREFIX . bin2hex(random_bytes(16));
    $output = performanceMeasure('file_open', fn() => fopen($tmp, 'xb'));
    if ($output === false) throw new DAV\Exception\InsufficientStorage('Cannot create upload');
    $id = basename($tmp);
    $reserved = false;
    try {
        if ($data === null) $data = '';
        $expected = is_resource($data) ? null : strlen((string) $data);
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'PUT' && isset($_SERVER['CONTENT_LENGTH'])) $expected = (int) $_SERVER['CONTENT_LENGTH'];
        elseif (is_resource($data) && (stream_get_meta_data($data)['stream_type'] ?? '') === 'STDIO') {
            $stat = fstat($data);
            if ($stat !== false) $expected = max(0, $stat['size'] - ftell($data));
        }
        performanceSet('upload_expected_bytes', $expected);
        performanceSet('input_stream_type', is_resource($data) ? (stream_get_meta_data($data)['stream_type'] ?? 'unknown') : 'string');
        $maximum = performanceMeasure('quota_reserve', fn() => reserveStorage($id, $expected, $destination));
        $reserved = true;
        $split = is_resource($data) && !empty($GLOBALS['qinPerf']['split_io_enabled']);
        performanceSet('copy_mode', $split ? 'split-8k' : 'native');
        $bytes = performanceMeasure('copy_io', fn() => is_resource($data)
            ? ($split ? performanceCopy($data, $output, $maximum)
                : ($maximum === null ? stream_copy_to_stream($data, $output) : stream_copy_to_stream($data, $output, $maximum + 1)))
            : fwrite($output, (string) $data));
        performanceSet('copied_bytes', $bytes === false ? 0 : $bytes);
        performanceMeasure('validate_flush', function () use ($maximum, $bytes, $output, $data) {
            if ($maximum !== null && $bytes > $maximum) throw new DAV\Exception\InsufficientStorage('容量上限不足');
            if ($bytes === false || !fflush($output)) throw new DAV\Exception\InsufficientStorage('Upload write failed');
            if (is_resource($data) && !feof($data)) throw new DAV\Exception\BadRequest('Incomplete upload');
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'PUT' && isset($_SERVER['CONTENT_LENGTH'])
                && $bytes !== (int) $_SERVER['CONTENT_LENGTH']) throw new DAV\Exception\BadRequest('Incomplete upload');
        });
        performanceMeasure('file_close', fn() => fclose($output));
        $output = null;
        performanceMeasure('publish', fn() => publishStorage($id, $destination, $bytes, function () use ($tmp, $destination, $createOnly) {
            if (is_link($destination) || is_dir($destination)) throw new DAV\Exception\Forbidden('Invalid destination');
            // Every application publication shares the accounting lock, including create-only PUT/COPY.
            if ($createOnly && file_exists($destination)) throw new DAV\Exception\PreconditionFailed('Destination already exists');
            performanceMeasure('file_rename', function () use ($tmp, $destination) {
                if (!rename($tmp, $destination)) throw new DAV\Exception\InsufficientStorage('Cannot commit upload');
            });
        }));
        performanceSet('published_bytes', $bytes);
        // Publication already removes the reservation in the same accounting transaction.
        $reserved = false;
        clearstatcache(true, $destination);
        return (new FastFile($destination))->getETag();
    } finally {
        $cleanup = performanceTick();
        try {
            if (is_resource($output)) fclose($output);
            if (is_file($tmp)) unlink($tmp);
            if ($reserved) releaseStorage($id);
        } finally { performanceTock('cleanup', $cleanup); }
    }
}

trait FastNode
{
    private ?array $nodeStat = null;
    public function diskPath(): string { return $this->path; }
    private function metadata(): array { return $this->nodeStat ??= checkedStat($this->path); }
    public function getLastModified() { return $this->metadata()['mtime']; }
    public function setName($name)
    {
        assertName($name);
        if ($this->overrideName !== null) throw new DAV\Exception\Forbidden('Cannot rename root');
        $destination = dirname($this->path) . '/' . $name;
        if (is_link($destination)) throw new DAV\Exception\Forbidden('Invalid destination');
        renameStorage($this->path, $destination);
        $this->path = $destination;
        $this->nodeStat = null;
    }
}

final class FastFile extends DAV\FS\File
{
    use FastNode;
    public function put($data)
    {
        $etag = writeStream($this->path, $data);
        $this->nodeStat = null;
        return $etag;
    }
    public function get()
    {
        checkedStat($this->path);
        $stream = fopen($this->path, 'rb');
        if ($stream === false) throw new DAV\Exception\NotFound('Cannot open file');
        return $stream;
    }
    public function getSize() { return $this->metadata()['size']; }
    public function getETag()
    {
        $s = $this->metadata();
        // Constant-time metadata lookup: never hash entire file contents during listing.
        return '"' . dechex($s['ino']) . '-' . dechex($s['size']) . '-' . dechex($s['mtime']) . '-' . dechex($s['ctime']) . '"';
    }
    public function delete()
    {
        storageTransaction(function (&$usage) {
            $bytes = checkedStat($this->path)['size'];
            storageMutationStart($usage);
            if (!unlink($this->path)) throw new DAV\Exception\Forbidden('Delete failed');
            $usage['used_bytes'] -= $bytes;
            --$usage['files'];
            $usage['dirty'] = false;
        });
    }
}

final class FastDirectory extends DAV\FS\Directory implements DAV\IMoveTarget, DAV\IQuota
{
    use FastNode;
    public function getQuotaInfo()
    {
        static $info;
        $info ??= storageInfo();
        return [$info['used_bytes'], min($info['disk_free_bytes'], $info['available_bytes'] ?? $info['disk_free_bytes'])];
    }
    public function createFile($name, $data = null)
    {
        assertName($name);
        return writeStream($this->path . '/' . $name, $data);
    }
    public function createDirectory($name)
    {
        assertName($name);
        storageTransaction(function (&$usage) use ($name) {
            if (!mkdir($this->path . '/' . $name, 0700)) throw new DAV\Exception\Forbidden('Cannot create directory');
        });
    }
    public function getChild($name)
    {
        assertName($name);
        $path = $this->path . '/' . $name;
        $s = checkedStat($path);
        return ($s['mode'] & 0170000) === 0040000 ? new self($path) : new FastFile($path);
    }
    public function getChildren()
    {
        $iterator = new FilesystemIterator($this->path, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME);
        foreach ($iterator as $path) {
            $name = basename($path);
            if (!validName($name) || is_link($path)) continue;
            $s = @lstat($path);
            if ($s === false) continue; // Concurrent deletion is normal during a listing.
            $type = $s['mode'] & 0170000;
            if ($type === 0040000) yield new self($path);
            elseif ($type === 0100000) yield new FastFile($path);
        }
    }
    public function listPage(int $offset, int $limit = 200): array
    {
        $directory = opendir($this->path);
        if ($directory === false) throw new DAV\Exception\Forbidden('Cannot read directory');
        $items = []; $position = 0; $more = false;
        try {
            while (($name = readdir($directory)) !== false) {
                if (!validName($name)) continue;
                $stat = @lstat($this->path . '/' . $name);
                if ($stat === false) continue;
                $type = $stat['mode'] & 0170000;
                if (!in_array($type, [0040000, 0100000], true)) continue;
                if ($position++ < $offset) continue;
                if (count($items) === $limit) { $more = true; break; }
                $items[] = ['name' => $name, 'directory' => $type === 0040000,
                    'size' => $type === 0100000 ? $stat['size'] : null, 'modified' => $stat['mtime']];
            }
        } finally { closedir($directory); }
        return ['items' => $items, 'more' => $more, 'offset' => $offset];
    }
    public function childExists($name)
    {
        assertName($name);
        $path = $this->path . '/' . $name;
        return !is_link($path) && (is_file($path) || is_dir($path));
    }
    public function delete()
    {
        if ($this->overrideName !== null) throw new DAV\Exception\Forbidden('Cannot delete root');
        foreach ($this->getChildren() as $child) $child->delete();
        storageTransaction(function (&$usage) {
            if (!rmdir($this->path)) throw new DAV\Exception\Forbidden('Cannot delete directory');
        });
    }
    public function moveInto($targetName, $sourcePath, DAV\INode $sourceNode)
    {
        assertName($targetName);
        if (!$sourceNode instanceof FastFile && !$sourceNode instanceof self) return false;
        $destination = $this->path . '/' . $targetName;
        if (is_link($destination)) throw new DAV\Exception\Forbidden('Invalid destination');
        // Native rename, including directory trees. No recursive copy for same-disk moves.
        renameStorage($sourceNode->diskPath(), $destination);
        return true;
    }
}

final class JsonLocks extends DAV\Locks\Backend\AbstractBackend
{
    public function __construct(private string $file) {}
    private function active(): array
    {
        return array_filter(readJson($this->file), fn ($lock) => $lock['created'] + $lock['timeout'] > time());
    }
    private static function covers(string $parent, string $child): bool
    {
        return $parent === $child || $parent === '' || str_starts_with($child, $parent . '/');
    }
    public function getLocks($uri, $returnChildLocks)
    {
        $found = [];
        foreach ($this->active() as $record) {
            if ($record['uri'] === $uri || ($record['depth'] !== 0 && self::covers($record['uri'], $uri))
                || ($returnChildLocks && self::covers($uri, $record['uri']))) {
                $lock = new DAV\Locks\LockInfo();
                foreach ($record as $key => $value) $lock->$key = $value;
                $found[] = $lock;
            }
        }
        return $found;
    }
    public function lock($uri, DAV\Locks\LockInfo $lockInfo)
    {
        return transaction($this->file, function (&$records) use ($uri, $lockInfo) {
            $records = array_filter($records, fn ($r) => $r['created'] + $r['timeout'] > time());
            foreach ($records as $r) {
                if ($r['token'] === $lockInfo->token) continue;
                $overlap = $r['uri'] === $uri || ($r['depth'] !== 0 && self::covers($r['uri'], $uri))
                    || ($lockInfo->depth !== 0 && self::covers($uri, $r['uri']));
                if ($overlap && ($r['scope'] === DAV\Locks\LockInfo::EXCLUSIVE || $lockInfo->scope === DAV\Locks\LockInfo::EXCLUSIVE)) {
                    throw new DAV\Exception\Locked($lockInfo);
                }
            }
            $lockInfo->uri = $uri;
            $lockInfo->created = time();
            $lockInfo->timeout = min(3600, $lockInfo->timeout > 0 ? $lockInfo->timeout : 1800);
            $records[$lockInfo->token] = get_object_vars($lockInfo);
            return true;
        });
    }
    public function unlock($uri, DAV\Locks\LockInfo $lockInfo)
    {
        return transaction($this->file, function (&$records) use ($uri, $lockInfo) {
            if (!isset($records[$lockInfo->token]) || $records[$lockInfo->token]['uri'] !== $uri) return false;
            unset($records[$lockInfo->token]);
            return true;
        });
    }
}

final class AppAuth extends DAV\Auth\Backend\AbstractBasic
{
    public function __construct(private array $cfg) { $this->setRealm('Single WebDAV'); }
    protected function validateUserPass($username, $password)
    {
        return credentialsValid($this->cfg, $username, $password);
    }
    public function check(RequestInterface $request, ResponseInterface $response)
    {
        $started = performanceTick();
        try {
            if ((string) $request->getHeader('Authorization') !== '') return parent::check($request, $response);
            if (!isset($_COOKIE['single_dav'])) return [false, 'Authentication required'];
            openSession();
            $valid = sessionUser($this->cfg);
            $csrf = in_array($request->getMethod(), SAFE_METHODS, true) || csrfValid((string) $request->getHeader('X-CSRF-Token'));
            session_write_close(); // Release the lock before streaming or traversing directories.
            if ($valid && !$csrf) throw new DAV\Exception\Forbidden('CSRF token required');
            return $valid ? [true, 'principals/' . $this->cfg['username']] : [false, 'Session expired'];
        } finally { performanceTock('authentication', $started); }
    }
}

function streamDownload(DAV\Server $server, RequestInterface $request, ResponseInterface $response, FastFile $node): false
{
    $response->addHeaders($server->getHTTPHeaders($request->getPath()));
    $response->setHeader('Content-Type', 'application/octet-stream');
    $response->setHeader('Accept-Ranges', 'bytes');
    $length = $node->getSize();
    $response->setHeader('Content-Length', (string) $length);
    $response->setStatus(200);
    if ($request->getHeader('X-Sabre-Original-Method') === 'HEAD') {
        $response->setBody('');
        return false;
    }
    $range = $server->getHTTPRange();
    $ifRange = $request->getHeader('If-Range');
    if ($range !== null && $ifRange !== null) {
        $date = strtotime($ifRange);
        $matches = str_starts_with($ifRange, '"') ? hash_equals($node->getETag(), $ifRange)
            : (!str_starts_with($ifRange, 'W/') && $date !== false && $date >= $node->getLastModified());
        if (!$matches) $range = null;
    }
    $start = 0;
    if ($range !== null) {
        if ($range[0] === null) {
            $start = max(0, $length - $range[1]);
            $end = $length - 1;
        } else {
            $start = $range[0];
            $end = min($range[1] ?? ($length - 1), $length - 1);
        }
        if ($length === 0 || $start >= $length || $end < $start) {
            $response->setStatus(416);
            $response->setHeader('Content-Range', 'bytes */' . $length);
            $response->setHeader('Content-Length', '0');
            $response->setBody('');
            return false;
        }
        $response->setStatus(206);
        $response->setHeader('Content-Range', "bytes $start-$end/$length");
        $response->setHeader('Content-Length', (string) ($end - $start + 1));
    }
    $stream = $node->get();
    if ($start > 0 && fseek($stream, $start) !== 0) {
        fclose($stream);
        throw new DAV\Exception('Cannot seek file');
    }
    $response->setBody($stream); // sabre/http sends the bounded stream in 4 MiB blocks.
    return false;
}

function serveDav(array $cfg, string $base): never
{
    if (!$cfg) {
        http_response_code(503);
        exit('请先在管理页面创建账号');
    }
    set_time_limit(0);
    while (ob_get_level() > 0) ob_end_clean();
    DAV\Server::$exposeVersion = false;
    $server = new DAV\Server(new FastDirectory(stateDir() . '/files', 'root'));
    $server->setBaseUri($base);
    $server->addPlugin(new DAV\Auth\Plugin(new AppAuth($cfg)));
    $server->addPlugin(new DAV\Locks\Plugin(new JsonLocks(stateDir() . '/locks.json')));
    $server->httpResponse->setHeader('X-Content-Type-Options', 'nosniff');
    $server->httpResponse->setHeader('Cache-Control', 'private, no-store');
    $server->on('method:COPY', function ($request, $response) use ($server) {
        $path = $request->getPath();
        $source = $server->tree->getNodeForPath($path);
        if (!$source instanceof FastFile) return true;
        $info = $server->getCopyAndMoveInfo($request);
        if ($info['destinationExists'] && !$info['destinationNode'] instanceof FastFile) return true;
        if (!$server->emit('beforeBind', [$info['destination']])
            || !$server->emit('beforeCopy', [$path, $info['destination'], $info['depth']])) return false;
        if ($info['destinationExists'] && !$server->emit('beforeUnbind', [$info['destination']])) return false;
        [$parentPath, $name] = \Sabre\Uri\split($info['destination']);
        assertName($name);
        $parent = $server->tree->getNodeForPath($parentPath);
        $stream = $source->get();
        try {
            // Stage the copy while retaining the old target, including its quota credit.
            writeStream($parent->diskPath() . '/' . $name, $stream, strtoupper($request->getHeader('Overwrite') ?? 'T') === 'F');
        } finally {
            fclose($stream);
        }
        $server->emit('afterCopy', [$path, $info['destination'], $info['depth']]);
        $server->emit('afterBind', [$info['destination']]);
        $response->setHeader('Content-Length', '0');
        $response->setStatus($info['destinationExists'] ? 204 : 201);
        return false;
    }, 90);
    $server->on('method:GET', function ($request, $response) use ($server) {
        $node = $server->tree->getNodeForPath($request->getPath());
        if (!$node instanceof FastFile) throw new DAV\Exception\MethodNotAllowed('Use PROPFIND to list directories');
        $response->setHeader('Content-Disposition', 'attachment; filename*=UTF-8\'\'' . rawurlencode($node->getName()));
        $prefix = getenv('WEBDAV_ACCEL_PREFIX');
        if (!$prefix || $node->getSize() === 0 || $request->getHeader('If-Range') !== null
            || $request->getHeader('X-Sabre-Original-Method') === 'HEAD') {
            return streamDownload($server, $request, $response, $node);
        }
        // Nginx clears upstream ETags during internal redirects. HEAD and If-Range
        // stay in sabre/dav; ordinary full/range downloads use sendfile.
        if (!preg_match('#^/[A-Za-z0-9_/-]+/$#D', $prefix)) throw new RuntimeException('Invalid acceleration prefix');
        $headers = $server->getHTTPHeaders($request->getPath());
        unset($headers['Content-Length']);
        $response->addHeaders($headers);
        $relative = substr($node->diskPath(), strlen(stateDir() . '/files/'));
        $response->setHeader('Content-Type', 'application/octet-stream');
        $response->setHeader('X-Accel-Redirect', $prefix . implode('/', array_map('rawurlencode', explode('/', $relative))));
        $response->setStatus(200);
        $response->setBody('');
        return false; // Nginx handles file transmission, Range and If-Range.
    }, 90);
    $server->exec();
    exit;
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    exit;
}

function passwordHash(string $password): string
{
    if (strlen($password) < 12 || strlen($password) > 72) throw new InvalidArgumentException('密码长度应为 12–72 字节');
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function uploadDestination(string $path, ?string $staging = null): string
{
    $parts = explode('/', $path);
    $name = array_pop($parts);
    assertName($name);
    $node = new FastDirectory(stateDir() . '/files', 'root');
    foreach ($parts as $part) {
        if (!$node instanceof FastDirectory) throw new DAV\Exception\Conflict('目标目录不存在');
        $node = $node->getChild($part);
    }
    if (!$node instanceof FastDirectory) throw new DAV\Exception\Conflict('目标目录不存在');
    $locks = (new JsonLocks(stateDir() . '/locks.json'))->getLocks($path, false);
    if ($locks) {
        throw new DAV\Exception\Locked($locks[0]);
    }
    $destination = $node->diskPath() . '/' . $name;
    if (file_exists($destination) || is_link($destination)) {
        $source = $staging === null ? false : @lstat($staging);
        $target = @lstat($destination);
        if (!$source || !$target || is_link($destination) || $source['dev'] !== $target['dev'] || $source['ino'] !== $target['ino']) {
            throw new DAV\Exception\PreconditionFailed('同名文件已存在');
        }
    }
    return $destination;
}

function removeUpload(string $id, string $directory, bool $keepReceipt = false): void
{
    @unlink(stateDir() . '/files/' . UPLOAD_PREFIX . $id);
    @unlink(stateDir() . '/files/' . UPLOAD_PREFIX . $id . '-probe');
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
        if ($keepReceipt && in_array($file->getFilename(), ['job.json', 'lock'], true)) continue;
        @unlink($file->getPathname());
    }
    if (!$keepReceipt) @rmdir($directory);
    releaseStorage($id);
}

function expireUploads(): void
{
    foreach (new FilesystemIterator(stateDir() . '/uploads', FilesystemIterator::SKIP_DOTS) as $entry) {
        if (!$entry->isDir() || $entry->isLink() || !preg_match('/^[a-f0-9]{48}$/D', $entry->getFilename())) continue;
        $directory = $entry->getPathname();
        $lock = @fopen($directory . '/lock', 'r+');
        if ($lock === false) continue;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) continue;
            $job = readJson($directory . '/job.json');
            $created = $job['created'] ?? (@filemtime($directory . '/lock') ?: time());
            if ($created + UPLOAD_TTL <= time()) removeUpload($entry->getFilename(), $directory);
        } finally {
            fclose($lock);
        }
    }
}

function startUpload(array $body, string $epoch): array
{
    $size = $body['size'] ?? null;
    if (!is_int($size) || $size <= 0 || $size > 9007199254740991) throw new InvalidArgumentException('文件大小无效');
    $path = (string) ($body['path'] ?? '');
    uploadDestination($path);
    expireUploads();
    $root = stateDir() . '/files';
    if ($size > disk_free_space($root)) throw new DAV\Exception\InsufficientStorage('磁盘空间不足');
    $id = bin2hex(random_bytes(24));
    $directory = stateDir() . '/uploads/' . $id;
    if (!mkdir($directory, 0700)) throw new DAV\Exception\InsufficientStorage('无法创建上传任务');
    $staging = $root . '/' . UPLOAD_PREFIX . $id;
    $stream = null;
    try {
        reserveStorage($id, $size, uploadDestination($path));
        $lock = fopen($directory . '/lock', 'xb');
        if ($lock === false) throw new DAV\Exception\InsufficientStorage('无法创建上传锁');
        fclose($lock);
        $stream = fopen($staging, 'xb');
        // A sparse file permits disjoint concurrent writes and avoids a final full-file copy.
        if ($stream === false || !ftruncate($stream, $size)) throw new DAV\Exception\InsufficientStorage('无法创建临时文件');
        fclose($stream);
        $stream = null;
        $probe = $staging . '-probe';
        if (!function_exists('link') || !@link($staging, $probe)) {
            throw new DAV\Exception\InsufficientStorage('存储不支持分块上传所需的硬链接');
        }
        unlink($probe);
        atomicJson($directory . '/job.json', ['path' => $path, 'size' => $size, 'created' => time(), 'epoch' => $epoch]);
        return ['id' => $id, 'chunk_size' => BROWSER_CHUNK_BYTES, 'chunks' => intdiv($size - 1, BROWSER_CHUNK_BYTES) + 1];
    } catch (Throwable $error) {
        if (is_resource($stream)) fclose($stream);
        removeUpload($id, $directory);
        throw $error;
    }
}

function updateUpload(string $action, string $id, string $epoch): array
{
    if (!preg_match('/^[a-f0-9]{48}$/D', $id)) throw new InvalidArgumentException('上传任务无效');
    $directory = stateDir() . '/uploads/' . $id;
    $lock = @fopen($directory . '/lock', 'r+');
    if ($lock === false) throw new DAV\Exception\NotFound('上传任务不存在');
    // Parts share the job lock; completion and cancellation wait for active writers.
    if (!flock($lock, $action === 'upload-part' ? LOCK_SH : LOCK_EX)) {
        fclose($lock);
        throw new RuntimeException('Cannot lock upload');
    }
    try {
        $job = readJson($directory . '/job.json');
        if (!$job) throw new DAV\Exception\NotFound('上传任务不存在');
        if (!hash_equals($job['epoch'], $epoch)) throw new DAV\Exception\Forbidden('上传会话已过期');
        if ($action === 'upload-cancel') { removeUpload($id, $directory); return ['ok' => true]; }
        if ($job['created'] + UPLOAD_TTL <= time()) throw new DAV\Exception\NotFound('上传任务已过期');
        if (!empty($job['completed'])) {
            if ($action === 'upload-finish') return ['ok' => true];
            throw new DAV\Exception\Conflict('上传任务已完成');
        }
        $chunks = intdiv($job['size'] - 1, BROWSER_CHUNK_BYTES) + 1;
        $staging = stateDir() . '/files/' . UPLOAD_PREFIX . $id;
        if ($action === 'upload-part') {
            $value = (string) ($_GET['part'] ?? '');
            if (!preg_match('/^(0|[1-9][0-9]{0,14})$/D', $value) || (int) $value >= $chunks) {
                throw new InvalidArgumentException('分块编号无效');
            }
            $part = (int) $value;
            $offset = $part * BROWSER_CHUNK_BYTES;
            $expected = min(BROWSER_CHUNK_BYTES, $job['size'] - $offset);
            if (!isset($_SERVER['CONTENT_LENGTH']) || (int) $_SERVER['CONTENT_LENGTH'] !== $expected) {
                throw new DAV\Exception\BadRequest('分块长度不匹配');
            }
            $partLock = fopen($directory . '/' . $part . '.lock', 'c');
            if ($partLock === false || !flock($partLock, LOCK_EX)) throw new RuntimeException('Cannot lock part');
            $input = $output = null;
            try {
                $receipt = $directory . '/' . $part . '.json';
                if (is_file($receipt)) return ['ok' => true]; // Safe retry after a lost acknowledgement.
                $input = fopen('php://input', 'rb');
                $output = fopen($staging, 'r+b');
                if ($input === false || $output === false || fseek($output, $offset) !== 0) {
                    throw new DAV\Exception\InsufficientStorage('无法写入分块');
                }
                $bytes = stream_copy_to_stream($input, $output, $expected);
                if ($bytes !== $expected || fread($input, 1) !== '') throw new DAV\Exception\BadRequest('分块不完整');
                if (!fflush($output)) throw new DAV\Exception\InsufficientStorage('分块写入失败');
                atomicJson($receipt, ['bytes' => $bytes]);
                return ['ok' => true];
            } finally {
                if (is_resource($input)) fclose($input);
                if (is_resource($output)) fclose($output);
                flock($partLock, LOCK_UN);
                fclose($partLock);
            }
        }
        for ($part = 0; $part < $chunks; ++$part) {
            $expected = min(BROWSER_CHUNK_BYTES, $job['size'] - $part * BROWSER_CHUNK_BYTES);
            if ((readJson($directory . '/' . $part . '.json')['bytes'] ?? -1) !== $expected) {
                throw new DAV\Exception\Conflict('文件尚未传输完整');
            }
        }
        if (checkedStat($staging)['size'] !== $job['size']) throw new DAV\Exception\Conflict('文件大小不匹配');
        $davLock = fopen(stateDir() . '/locks.json.lock', 'c');
        if ($davLock === false || !flock($davLock, LOCK_SH)) throw new RuntimeException('Cannot lock DAV state');
        try {
            // Serialize the lock check and publication against new DAV LOCK transactions.
            $destination = uploadDestination($job['path'], $staging);
            // link() publishes atomically without overwriting a destination created during upload.
            $source = checkedStat($staging);
            $target = @lstat($destination);
            $published = $target && ($target['mode'] & 0170000) === 0100000
                && $source['dev'] === $target['dev'] && $source['ino'] === $target['ino'];
            publishStorage($id, $destination, $job['size'], function () use ($published, $staging, $destination) {
                if (!$published && !@link($staging, $destination)) {
                    if (file_exists($destination) || is_link($destination)) throw new DAV\Exception\PreconditionFailed('同名文件已存在');
                    throw new DAV\Exception\InsufficientStorage('无法提交文件，请确认存储支持硬链接');
                }
            });
            $job['completed'] = true;
            atomicJson($directory . '/job.json', $job);
            removeUpload($id, $directory, true);
            return ['ok' => true];
        } finally {
            flock($davLock, LOCK_UN);
            fclose($davLock);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function updateRemove(string $path): void
{
    if (is_link($path) || is_file($path)) { if (!unlink($path)) throw new RuntimeException('Cannot remove update file'); return; }
    if (!is_dir($path)) return;
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) updateRemove($entry->getPathname());
    if (!rmdir($path)) throw new RuntimeException('Cannot remove update directory');
}

// The package determines its application files; runtime state stays outside the web root.
function updateEntries(string $root): array
{
    $names = [];
    foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
        $name = $entry->getFilename();
        if ($entry->isLink() || preg_match('/[\\x00-\\x1f\\x7f\\\\]/', $name)) throw new InvalidArgumentException('程序包路径无效');
        $names[] = $name;
    }
    sort($names, SORT_STRING);
    // Publish/recover the bootstrap last, after its supporting files.
    return array_merge(array_values(array_diff($names, ['index.php'])), in_array('index.php', $names, true) ? ['index.php'] : []);
}

function updateManagedEntries(): array
{
    $names = readJson(stateDir() . '/update-managed.json')['entries'] ?? ['vendor', 'index.php'];
    if (file_exists(__DIR__ . '/dav.php')) $names[] = 'dav.php';
    foreach ($names as $name) {
        if (!is_string($name) || $name === '' || $name === '.' || $name === '..' || preg_match('/[\\x00-\\x1f\\x7f\\\\\/]/', $name)) {
            throw new RuntimeException('Invalid managed application path');
        }
    }
    return array_values(array_unique($names));
}

function updateInvalidate(array $entries = []): void
{
    if (!function_exists('opcache_invalidate')) return;
    foreach (array_unique(array_merge(updateManagedEntries(), $entries)) as $name) {
        $path = __DIR__ . '/' . $name;
        if (is_file($path)) { @opcache_invalidate($path, true); continue; }
        if (!is_dir($path) || is_link($path)) continue;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) if ($entry->isFile() && !$entry->isLink() && $entry->getExtension() === 'php') @opcache_invalidate($entry->getPathname(), true);
    }
}

// Called before loading vendor, so an interrupted directory swap can be recovered.
function updateRecover(): bool
{
    $journalPath = stateDir() . '/update-journal.json';
    $journal = readJson($journalPath);
    if (!$journal) return false;
    if (!preg_match('/^job-[a-f0-9]{24}$/D', $journal['job'] ?? '')) throw new RuntimeException('Invalid update journal');
    $job = stateDir() . '/updates/' . $journal['job'];
    if (($journal['committed'] ?? false) === true) {
        updateRecordBackup($journal);
    } else {
        foreach ($journal['entries'] ?? ['vendor', 'dav.php', 'index.php'] as $name) {
            $backup = $job . '/old/' . $name;
            $live = __DIR__ . '/' . $name;
            if (file_exists($backup)) {
                if (is_dir($backup) || is_dir($live)) updateRemove($live);
                if (!rename($backup, $live)) throw new RuntimeException('Cannot restore previous application');
            } elseif (($journal['existed'][$name] ?? true) === false && !file_exists($job . '/new/' . $name)) {
                updateRemove($live);
            }
        }
        updateInvalidate($journal['entries'] ?? []);
    }
    if (!unlink($journalPath)) throw new RuntimeException('Cannot clear update journal');
    if (!in_array($journal['job'], array_column(updateBackups()['backups'], 'id'), true)) updateRemove($job);
    return true;
}

function applicationGate(): mixed
{
    $lock = fopen(stateDir() . '/application.lock', 'c');
    if ($lock === false) throw new RuntimeException('Cannot open application lock');
    if (!flock($lock, LOCK_SH | LOCK_NB)) {
        header('Retry-After: 5');
        jsonResponse(['error' => '正在更新程序，请稍后重试'], 503);
    }
    if (is_file(stateDir() . '/update-journal.json')) {
        flock($lock, LOCK_UN);
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            header('Retry-After: 5');
            jsonResponse(['error' => '正在恢复程序，请稍后重试'], 503);
        }
        updateRecover();
        header('Retry-After: 1');
        jsonResponse(['error' => '程序恢复完成，请重试请求'], 503);
    }
    return $lock; // PHP closes this handle and releases the shared lock at request end.
}

function updateCapabilities(): array
{
    $errors = [];
    if (!extension_loaded('curl')) $errors[] = '主机 PHP 未启用 cURL 扩展';
    if (!class_exists('ZipArchive')) $errors[] = '主机 PHP 未启用 ZIP 扩展';
    $restricted = (string) ini_get('opcache.restrict_api');
    if (filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN)
        && (!function_exists('opcache_invalidate') || ($restricted !== '' && !str_starts_with(__FILE__, $restricted)))) {
        $errors[] = '主机禁止清除 PHP 程序缓存，无法安全自动替换';
    }
    if (!is_writable(__DIR__)) $errors[] = 'PHP 没有应用目录写入权限';
    foreach (updateManagedEntries() as $name) {
        $path = __DIR__ . '/' . $name;
        if (is_link($path)) $errors[] = '应用入口与依赖不能使用符号链接';
        if (file_exists($path) && !is_writable($path)) $errors[] = $name . ' 不可写';
    }
    if (stat(__DIR__)['dev'] !== stat(stateDir())['dev']) $errors[] = '应用目录与状态目录必须在同一文件系统';
    return ['current' => QINDAV_VERSION, 'can_update' => !$errors, 'requirements' => $errors];
}

function updateAllowedUrl(string $url): bool
{
    $parts = parse_url($url);
    return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
        && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
        && in_array($parts['host'] ?? '', ['api.github.com', 'github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com'], true);
}

function updateDownload(string $url, string $destination, int $limit): void
{
    if (!extension_loaded('curl')) throw new InvalidArgumentException('检查更新需要 PHP cURL 扩展');
    for ($redirect = 0; $redirect < 6; ++$redirect) {
        if (!updateAllowedUrl($url)) throw new InvalidArgumentException('更新下载地址无效');
        $output = fopen($destination, 'wb');
        if ($output === false) throw new RuntimeException('Cannot create update download');
        $curl = curl_init($url);
        $bytes = 0; $location = null;
        curl_setopt_array($curl, [CURLOPT_USERAGENT => 'QinDav/' . QINDAV_VERSION,
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'], CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 180, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$location): int {
                if (str_starts_with(strtolower($line), 'location:')) $location = trim(substr($line, 9));
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use ($output, $limit, &$bytes): int {
                $bytes += strlen($chunk);
                return $bytes <= $limit ? (int) fwrite($output, $chunk) : 0;
            }]);
        try {
            $success = curl_exec($curl);
            $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($success === false) throw new InvalidArgumentException('下载更新失败，请检查网络或下载大小限制');
            if (in_array($code, [301, 302, 303, 307, 308], true) && is_string($location)) { $url = $location; continue; }
            if ($code !== 200) throw new InvalidArgumentException('GitHub 更新服务返回 HTTP ' . $code . '，请稍后重试');
            return;
        } finally {
            fclose($output);
            curl_close($curl);
        }
    }
    throw new InvalidArgumentException('更新下载重定向过多');
}

function updateRelease(bool $fresh = false): array
{
    $cache = stateDir() . '/update-cache.json';
    $data = readJson($cache);
    if (!$fresh && ($data['checked_at'] ?? 0) > time() - 300) return $data;
    $tmp = tempnam(stateDir(), '.update-check-');
    if ($tmp === false) throw new RuntimeException('Cannot create update check');
    try {
        updateDownload('https://api.github.com/repos/0honus0/QinDav/releases/latest', $tmp, 1048576);
        $release = readJson($tmp);
        $tag = $release['tag_name'] ?? '';
        if (!is_string($tag) || !preg_match('/^v(\d+\.\d+\.\d+)$/D', $tag, $match)
            || !empty($release['draft']) || !empty($release['prerelease'])) throw new InvalidArgumentException('发布版本信息无效');
        $assets = [];
        foreach ($release['assets'] ?? [] as $asset) {
            $name = $asset['name'] ?? '';
            if (in_array($name, ['QinDav.zip', 'SHA256SUMS'], true)) {
                $expected = 'https://github.com/0honus0/QinDav/releases/download/' . $tag . '/' . $name;
                if (($asset['browser_download_url'] ?? '') !== $expected) throw new InvalidArgumentException('发布文件地址无效');
                $assets[$name] = $expected;
            }
        }
        if (count($assets) !== 2) throw new InvalidArgumentException('发布版本缺少部署包或校验文件');
        $data = ['version' => $match[1], 'tag' => $tag, 'assets' => $assets, 'checked_at' => time()];
        atomicJson($cache, $data);
        return $data;
    } finally { if (is_file($tmp)) unlink($tmp); }
}

function updateStatus(bool $fresh = false): array
{
    $release = updateRelease($fresh);
    return updateCapabilities() + ['latest' => $release['version'],
        'available' => version_compare($release['version'], QINDAV_VERSION, '>'),
        'release_url' => 'https://github.com/0honus0/QinDav/releases/tag/' . $release['tag']];
}

function updateExtract(string $archive, string $destination, string $version): void
{
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::CHECKCONS) !== true) throw new InvalidArgumentException('更新 ZIP 文件损坏');
    try {
        if ($zip->numFiles > 5000) throw new InvalidArgumentException('更新包文件数量超限');
        $total = 0; $seen = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];
            $parts = explode('/', rtrim($name, '/'));
            if (isset($seen[$name]) || preg_match('/[\x00-\x1f\x7f\\\\]/', $name) || in_array('', $parts, true)
                || in_array('..', $parts, true) || in_array('.', $parts, true)) {
                throw new InvalidArgumentException('更新包包含非法路径');
            }
            $seen[$name] = true;
            $zip->getExternalAttributesIndex($i, $system, $attributes);
            $type = ($attributes >> 16) & 0170000;
            if (!in_array($type, [0, 0100000, 0040000], true) || ($type === 0040000 && !str_ends_with($name, '/'))) {
                throw new InvalidArgumentException('更新包包含符号链接或特殊文件');
            }
            $total += $stat['size'];
            if ($total > 64 * 1024 * 1024) throw new InvalidArgumentException('更新包解压大小超限');
        }
        foreach (['index.php'] as $required) {
            if (!isset($seen[$required])) throw new InvalidArgumentException('更新包缺少必要程序文件');
        }
        $source = $zip->getFromName('index.php');
        if (!is_string($source) || !preg_match("/const QINDAV_VERSION = '([0-9]+\\.[0-9]+\\.[0-9]+)';/", $source, $match)
            || $match[1] !== $version) throw new InvalidArgumentException('更新包版本与发布版本不一致');
        if (disk_free_space(stateDir()) < $total + 8 * 1024 * 1024) throw new InvalidArgumentException('磁盘空间不足以解压更新');
        // Every path has been checked; streams keep extraction memory bounded.
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $stat = $zip->statIndex($i); $name = $stat['name']; $path = $destination . '/' . $name;
            if (str_ends_with($name, '/')) { if (!is_dir($path) && !mkdir($path, 0755, true)) throw new RuntimeException('Cannot extract directory'); continue; }
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true)) throw new RuntimeException('Cannot extract parent');
            $input = $zip->getStream($name); $output = fopen($path, 'xb');
            if ($input === false || $output === false) throw new RuntimeException('Cannot extract file');
            try {
                if (stream_copy_to_stream($input, $output, $stat['size'] + 1) !== $stat['size']) throw new InvalidArgumentException('更新包文件长度无效');
            } finally { fclose($input); fclose($output); }
            chmod($path, 0644);
        }
    } finally { $zip->close(); }
}

function updateBackups(): array
{
    $data = readJson(stateDir() . '/update-backups.json');
    return ['keep' => max(1, min(10, (int) ($data['keep'] ?? 2))), 'backups' => $data['backups'] ?? []];
}

function updateSaveBackups(array $data): void
{
    $discarded = array_slice($data['backups'], $data['keep']);
    $data['backups'] = array_slice($data['backups'], 0, $data['keep']);
    atomicJson(stateDir() . '/update-backups.json', $data);
    foreach ($discarded as $backup) {
        if (preg_match('/^job-[a-f0-9]{24}$/D', $backup['id'] ?? '')) {
            try { updateRemove(stateDir() . '/updates/' . $backup['id']); }
            catch (Throwable $ignored) { error_log('QinDav: old update backup cleanup failed'); }
        }
    }
}

function updateSnapshotHash(string $root, ?array $names = null): string
{
    if (!is_file($root . '/index.php') || is_link($root . '/index.php')) throw new InvalidArgumentException('程序快照缺少有效入口');
    $files = [];
    foreach ($names ?? ($root === __DIR__ ? updateManagedEntries() : updateEntries($root)) as $name) {
        $path = $root . '/' . $name;
        if (is_link($path)) throw new InvalidArgumentException('程序快照包含符号链接');
        if (is_file($path)) { $files[$name] = $path; continue; }
        if (!file_exists($path)) continue;
        if (!is_dir($path)) throw new InvalidArgumentException('程序快照缺少必要文件');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->isLink()) throw new InvalidArgumentException('程序快照包含特殊文件');
            $files[substr($entry->getPathname(), strlen($root) + 1)] = $entry->getPathname();
        }
    }
    ksort($files, SORT_STRING); $hash = hash_init('sha256');
    foreach ($files as $relative => $path) {
        $digest = hash_file('sha256', $path);
        if ($digest === false) throw new RuntimeException('Cannot hash application snapshot');
        hash_update($hash, $relative . "\0" . $digest . "\n");
    }
    return hash_final($hash);
}

function updateRecordBackup(array $journal): void
{
    if (isset($journal['new_entries'])) atomicJson(stateDir() . '/update-managed.json', ['entries' => $journal['new_entries']]);
    $data = updateBackups(); $existing = null;
    foreach ($data['backups'] as $backup) {
        if (($backup['fingerprint'] ?? '') === $journal['fingerprint']) { $existing = $backup; break; }
    }
    if ($existing) {
        // Reuse the exact snapshot and refresh its retention order when switching versions.
        $data['backups'] = array_values(array_filter($data['backups'], fn($backup) => $backup['id'] !== $existing['id']));
        array_unshift($data['backups'], $existing);
    } else {
        array_unshift($data['backups'], ['id' => $journal['job'], 'version' => $journal['previous_version'],
            'created_at' => $journal['created_at'], 'fingerprint' => $journal['fingerprint']]);
    }
    updateSaveBackups($data);
}

class UpdateBusy extends DAV\Exception
{
    public function getHTTPCode(): int { return 423; }
}

function updateTaskLock(): mixed
{
    $lock = fopen(stateDir() . '/update.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) throw new UpdateBusy('另一个更新或回退任务正在运行');
    // A killed download has no journal and is safe to discard while owning the task lock.
    $retained = array_column(updateBackups()['backups'], 'id');
    $pending = readJson(stateDir() . '/update-journal.json')['job'] ?? null;
    foreach (glob(stateDir() . '/updates/job-*') ?: [] as $path) {
        $id = basename($path);
        if (preg_match('/^job-[a-f0-9]{24}$/D', $id) && !in_array($id, $retained, true) && $id !== $pending) updateRemove($path);
    }
    return $lock;
}

function updatePublish(string $jobName, string $sessionEpoch, string $version): array
{
    $job = stateDir() . '/updates/' . $jobName;
    $gate = $GLOBALS['applicationLock'];
    flock($gate, LOCK_UN);
    if (!flock($gate, LOCK_EX | LOCK_NB)) throw new UpdateBusy('仍有文件传输或其他请求正在执行，请稍后重试');
    $current = config();
    if (!hash_equals($current['session_epoch'], $sessionEpoch)) throw new DAV\Exception\Forbidden('Session expired');
    $journal = ['job' => $jobName, 'committed' => false, 'existed' => [], 'previous_version' => QINDAV_VERSION,
        'created_at' => time(), 'new_entries' => updateEntries($job . '/new')];
    $entries = array_unique(array_merge(updateManagedEntries(), $journal['new_entries']));
    $journal['entries'] = array_merge(array_values(array_diff($entries, ['index.php'])), ['index.php']);
    $journal['fingerprint'] = updateSnapshotHash(__DIR__, $journal['entries']);
    foreach ($journal['entries'] as $name) {
        $journal['existed'][$name] = file_exists(__DIR__ . '/' . $name);
        if (is_link(__DIR__ . '/' . $name)) throw new InvalidArgumentException('应用文件变成了符号链接，请重新检查');
    }
    atomicJson(stateDir() . '/update-journal.json', $journal);
    try {
        updateInvalidate($journal['entries']);
        foreach ($journal['entries'] as $name) {
            $live = __DIR__ . '/' . $name;
            if ($journal['existed'][$name]) {
                $backup = $job . '/old/' . $name;
                if (is_dir($live)) {
                    if (!rename($live, $backup)) throw new RuntimeException('Cannot back up ' . $name);
                } else {
                    // Keep PHP entry files present until the new file atomically replaces them.
                    if (!copy($live, $backup . '.tmp') || !rename($backup . '.tmp', $backup)) throw new RuntimeException('Cannot back up ' . $name);
                }
            }
            if (!file_exists($job . '/new/' . $name)) {
                updateRemove($live); // Files removed from the package disappear from the active application.
                continue;
            }
            if (is_dir($live)) updateRemove($live);
            if (!rename($job . '/new/' . $name, $live)) throw new RuntimeException('Cannot publish ' . $name);
        }
        updateInvalidate($journal['entries']);
        $journal['committed'] = true;
        atomicJson(stateDir() . '/update-journal.json', $journal);
    } catch (Throwable $error) {
        try { updateRecover(); } catch (Throwable $recoveryError) { throw $recoveryError; }
        throw new InvalidArgumentException('程序替换失败，已恢复旧版本，请检查主机目录权限');
    }
    // Once committed, later housekeeping failures must never report the swap as failed.
    try {
        updateRecordBackup($journal);
        unlink(stateDir() . '/update-journal.json');
        foreach (['QinDav.zip', 'SHA256SUMS', 'new'] as $name) updateRemove($job . '/' . $name);
    } catch (Throwable $error) { error_log('QinDav: committed update housekeeping: ' . $error->getMessage()); }
    return ['ok' => true, 'version' => $version];
}

function installUpdate(string $expectedVersion, string $sessionEpoch): array
{
    $capabilities = updateCapabilities();
    if (!$capabilities['can_update']) throw new InvalidArgumentException(implode('；', $capabilities['requirements']));
    $lock = updateTaskLock();
    $jobName = 'job-' . bin2hex(random_bytes(12));
    $job = stateDir() . '/updates/' . $jobName;
    try {
        $release = updateRelease(true);
        if ($release['version'] !== $expectedVersion) throw new DAV\Exception\Conflict('最新版本已变化，请重新检查更新');
        if (!version_compare($release['version'], QINDAV_VERSION, '>')) throw new DAV\Exception\Conflict('当前版本已是最新版本');
        if (!mkdir($job . '/new', 0700, true) || !mkdir($job . '/old', 0700)) throw new RuntimeException('Cannot prepare update');
        updateDownload($release['assets']['SHA256SUMS'], $job . '/SHA256SUMS', 8192);
        $checksum = file_get_contents($job . '/SHA256SUMS');
        if (!preg_match('/^([a-f0-9]{64})  QinDav\.zip\s*$/D', $checksum, $match)) throw new InvalidArgumentException('更新校验文件格式无效');
        updateDownload($release['assets']['QinDav.zip'], $job . '/QinDav.zip', 32 * 1024 * 1024);
        if (!hash_equals($match[1], hash_file('sha256', $job . '/QinDav.zip'))) throw new InvalidArgumentException('更新包 SHA-256 校验失败');
        updateExtract($job . '/QinDav.zip', $job . '/new', $release['version']);
        return updatePublish($jobName, $sessionEpoch, $release['version']);
    } finally {
        // A job referenced by the recovery journal must survive an interrupted rollback.
        $backups = updateBackups()['backups'];
        $retained = in_array($jobName, array_column($backups, 'id'), true)
            || (readJson(stateDir() . '/update-journal.json')['job'] ?? '') === $jobName;
        if (!$retained && is_dir($job)) updateRemove($job);
        flock($lock, LOCK_UN); fclose($lock);
    }
}

function updateCopy(string $source, string $destination): void
{
    if (is_link($source)) throw new InvalidArgumentException('备份包含符号链接');
    if (is_dir($source)) {
        if (!mkdir($destination, 0755)) throw new RuntimeException('Cannot stage backup directory');
        foreach (new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS) as $entry) updateCopy($entry->getPathname(), $destination . '/' . $entry->getFilename());
    } elseif (is_file($source)) {
        if (!copy($source, $destination)) throw new RuntimeException('Cannot stage backup file');
        chmod($destination, 0644);
    } else { throw new InvalidArgumentException('备份文件缺失或无效'); }
}

function restoreUpdate(string $backupId, string $sessionEpoch): array
{
    $capabilities = updateCapabilities();
    if (!$capabilities['can_update']) throw new InvalidArgumentException(implode('；', $capabilities['requirements']));
    $lock = updateTaskLock();
    $jobName = 'job-' . bin2hex(random_bytes(12));
    $job = stateDir() . '/updates/' . $jobName;
    try {
        $backup = null;
        foreach (updateBackups()['backups'] as $entry) if ($entry['id'] === $backupId) $backup = $entry;
        if (!$backup || !preg_match('/^job-[a-f0-9]{24}$/D', $backupId)) throw new DAV\Exception\NotFound('备份不存在');
        if (($backup['fingerprint'] ?? '') === updateSnapshotHash(__DIR__)) throw new DAV\Exception\Conflict('当前程序与此备份完全相同，无需回退');
        if (($backup['fingerprint'] ?? '') !== updateSnapshotHash(stateDir() . '/updates/' . $backupId . '/old')) throw new InvalidArgumentException('备份内容校验失败，不能回退');
        if (!mkdir($job . '/new', 0700, true) || !mkdir($job . '/old', 0700)) throw new RuntimeException('Cannot prepare restore');
        foreach (updateEntries(stateDir() . '/updates/' . $backupId . '/old') as $name) {
            $source = stateDir() . '/updates/' . $backupId . '/old/' . $name;
            updateCopy($source, $job . '/new/' . $name);
        }
        return updatePublish($jobName, $sessionEpoch, $backup['version']);
    } finally {
        $retained = in_array($jobName, array_column(updateBackups()['backups'], 'id'), true)
            || (readJson(stateDir() . '/update-journal.json')['job'] ?? '') === $jobName;
        if (!$retained && is_dir($job)) updateRemove($job);
        flock($lock, LOCK_UN); fclose($lock);
    }
}

function handleApi(array $cfg, string $action): never
{
    openSession();
    $authenticated = sessionUser($cfg);
    $csrf = csrfValid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sessionEpoch = $_SESSION['epoch'] ?? '';
    session_write_close();
    if (!$authenticated) jsonResponse(['error' => '请先登录'], 401);
    if ($action === 'storage' && $_SERVER['REQUEST_METHOD'] === 'GET') jsonResponse(storageInfo());
    if ($action === 'performance-log' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        // Snapshot under a short read lock, then release it before sending to a slow browser.
        $lock = fopen(stateDir() . '/performance.log.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_SH)) throw new RuntimeException('Cannot read diagnostic log');
        $snapshot = '';
        try {
            foreach (['performance.previous.ndjson', 'performance.ndjson'] as $name) {
                $path = stateDir() . '/' . $name;
                if (!is_file($path) || is_link($path)) continue;
                $stream = fopen($path, 'rb');
                if ($stream === false) throw new RuntimeException('Cannot open diagnostic log');
                try { $part = stream_get_contents($stream, PERFORMANCE_LOG_MAX_BYTES); }
                finally { fclose($stream); }
                if ($part === false) throw new RuntimeException('Cannot read diagnostic log');
                $snapshot .= $part;
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo ($_GET['raw'] ?? '') === '1' ? $snapshot : performanceSummary($snapshot);
        exit;
    }

    if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $path = trim((string) ($_GET['path'] ?? ''), '/');
        $node = new FastDirectory(stateDir() . '/files', 'root');
        foreach ($path === '' ? [] : explode('/', $path) as $part) {
            if (!$node instanceof FastDirectory) throw new DAV\Exception\NotFound('目录不存在');
            $node = $node->getChild($part);
        }
        if (!$node instanceof FastDirectory) throw new DAV\Exception\NotFound('目录不存在');
        $offset = max(0, min(10000000, (int) ($_GET['offset'] ?? 0)));
        jsonResponse($node->listPage($offset) + ['free' => disk_free_space(stateDir() . '/files')]);
    }
    if ($action === 'upload-part') {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') jsonResponse(['error' => '请求方法不支持'], 405);
        if (!$csrf) jsonResponse(['error' => '页面已过期，请刷新'], 403);
        set_time_limit(0);
        jsonResponse(updateUpload($action, (string) ($_GET['id'] ?? ''), $sessionEpoch));
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => '请求方法不支持'], 405);
    if (!$csrf) jsonResponse(['error' => '页面已过期，请刷新'], 403);
    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if ($raw === false || strlen($raw) > 8192) jsonResponse(['error' => '请求过大'], 413);
    $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($body)) jsonResponse(['error' => '请求格式错误'], 400);
    if ($action === 'performance-clear') {
        $root = stateDir(); $lock = fopen($root . '/performance.log.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot clear diagnostic log');
        try {
            foreach (['performance.ndjson', 'performance.previous.ndjson'] as $name) {
                $path = $root . '/' . $name;
                if (file_exists($path) && !unlink($path)) throw new RuntimeException('Cannot clear diagnostic log');
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        jsonResponse(['ok' => true]);
    }
    if ($action === 'performance-settings') {
        if (!array_key_exists('enabled', $body) && !array_key_exists('split_io', $body)) throw new InvalidArgumentException('诊断设置无效');
        foreach (['enabled', 'split_io'] as $key) {
            if (array_key_exists($key, $body) && !is_bool($body[$key])) throw new InvalidArgumentException('诊断设置无效');
        }
        $settings = transaction(stateDir() . '/config.json', function (&$current) use ($body, $sessionEpoch) {
            if (!hash_equals($current['session_epoch'], $sessionEpoch)) throw new DAV\Exception\Forbidden('Session expired');
            if (array_key_exists('enabled', $body)) $current['performance_log_enabled'] = $body['enabled'];
            if (array_key_exists('split_io', $body)) $current['performance_split_io'] = $body['split_io'];
            if (!performanceEnabled($current)) $current['performance_split_io'] = false;
            return ['enabled' => performanceEnabled($current), 'split_io' => !empty($current['performance_split_io'])];
        });
        jsonResponse(['ok' => true] + $settings);
    }
    if ($action === 'update-info') jsonResponse(updateCapabilities() + updateBackups());
    if ($action === 'update-backup-limit') {
        $keep = $body['keep'] ?? null;
        if (!is_int($keep) || $keep < 1 || $keep > 10) throw new InvalidArgumentException('备份保留份数应为 1–10');
        $lock = updateTaskLock();
        try { $data = updateBackups(); $data['keep'] = $keep; updateSaveBackups($data); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
        jsonResponse(updateBackups());
    }
    if ($action === 'update-restore') {
        set_time_limit(600);
        $id = $body['id'] ?? '';
        if (!is_string($id)) throw new InvalidArgumentException('备份标识无效');
        jsonResponse(restoreUpdate($id, $sessionEpoch));
    }
    if ($action === 'update-check') { set_time_limit(240); jsonResponse(updateStatus(true)); }
    if ($action === 'update-install') {
        set_time_limit(600);
        $version = $body['version'] ?? '';
        if (!is_string($version) || !preg_match('/^\d+\.\d+\.\d+$/D', $version)) throw new InvalidArgumentException('更新版本无效');
        jsonResponse(installUpdate($version, $sessionEpoch));
    }
    if ($action === 'storage-rescan') {
        set_time_limit(0);
        jsonResponse(storageInfo(true));
    }
    if ($action === 'storage-limit') {
        $limit = $body['limit_bytes'] ?? null;
        if (!is_int($limit) || $limit < 0 || $limit > 9007199254740991) throw new InvalidArgumentException('容量上限无效');
        storageTransaction(function (&$usage) use ($limit) {
            if ($limit && $limit < $usage['used_bytes'] + storageReserved($usage)) {
                throw new DAV\Exception\Conflict('容量上限不能小于已用与上传预留空间之和');
            }
            $usage['limit_bytes'] = $limit;
        });
        jsonResponse(storageInfo());
    }
    if ($action === 'upload-start') jsonResponse(startUpload($body, $sessionEpoch));
    if (in_array($action, ['upload-finish', 'upload-cancel'], true)) {
        set_time_limit(0);
        jsonResponse(updateUpload($action, (string) ($body['id'] ?? ''), $sessionEpoch));
    }
    if ($action === 'password') {
        if (!password_verify((string) ($body['current'] ?? ''), $cfg['password_hash'])) jsonResponse(['error' => '当前密码错误'], 403);
        $hash = passwordHash((string) ($body['password'] ?? ''));
        $epoch = bin2hex(random_bytes(16));
        transaction(stateDir() . '/config.json', function (&$current) use ($cfg, $hash, $epoch, $sessionEpoch) {
            if (!hash_equals($current['session_epoch'], $sessionEpoch)) throw new DAV\Exception\Forbidden('Session expired');
            $current['password_hash'] = $hash;
            $current['session_epoch'] = $epoch;
        });
        openSession();
        session_regenerate_id(true);
        $_SESSION['epoch'] = $epoch;
        session_write_close();
        jsonResponse(['ok' => true]);
    }
    if ($action === 'view-app-password') {
        $current = config();
        if (!hash_equals($current['session_epoch'], $sessionEpoch)) throw new DAV\Exception\Forbidden('Session expired');
        jsonResponse(['ok' => true, 'password' => $current['app_password'] ?? null,
            'configured' => !empty($current['app_hash'])]);
    }
    if (in_array($action, ['app-password', 'revoke-app-password'], true)) {
        $password = $action === 'app-password' ? bin2hex(random_bytes(24)) : null;
        $hash = $password === null ? null : passwordHash($password);
        transaction(stateDir() . '/config.json', function (&$current) use ($hash, $password, $sessionEpoch) {
            if (!hash_equals($current['session_epoch'], $sessionEpoch)) throw new DAV\Exception\Forbidden('Session expired');
            $current['app_hash'] = $hash;
            // Kept in the private 0600 configuration so the signed-in owner can retrieve it.
            $current['app_password'] = $password;
        });
        jsonResponse(['ok' => true, 'password' => $password]);
    }
    jsonResponse(['error' => '未知操作'], 404);
}

function html(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

try {
    $performanceConfigStarted = PERFORMANCE_LOG_ENABLED ? hrtime(true) : 0;
    $cfg = config();
    performanceStart($cfg, $performanceEntryStarted, $performanceConfigStarted);
    performanceSet('split_io_enabled', !empty($cfg['performance_split_io']));
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $davBase = '/index.php/';
    if (str_starts_with($uri, $davBase)) {
        serveDav($cfg, $davBase);
    }
    if (!in_array($uri, ['/', '/index.php'], true)) {
        http_response_code(404);
        exit('Not found');
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    if (isset($_GET['api'])) handleApi($cfg, (string) $_GET['api']);
    openSession();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrfValid((string) ($_POST['csrf'] ?? ''))) {
            http_response_code(403);
            $error = '页面已过期，请刷新后重试';
        } else {
            $action = $_POST['action'] ?? '';
            if ($action === 'logout') {
                $_SESSION = [];
                session_destroy();
                setcookie('single_dav', '', ['expires' => 1, 'path' => '/', 'secure' => secureRequest(), 'httponly' => true, 'samesite' => 'Strict']);
                header('Location: /', true, 303);
                exit;
            }
            if ($action === 'setup' && !$cfg) {
                $setupToken = getenv('WEBDAV_SETUP_TOKEN') ?: '';
                $username = trim((string) ($_POST['username'] ?? ''));
                if ($setupToken !== '' && !hash_equals($setupToken, (string) ($_POST['setup_token'] ?? ''))) {
                    $error = '初始化令牌错误';
                } elseif (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $username)) {
                    $error = '用户名应为 1–64 位字母、数字、点、下划线或连字符';
                } elseif (($_POST['password'] ?? '') !== ($_POST['confirm'] ?? '')) {
                    $error = '两次密码不一致';
                } else {
                    try {
                        $hash = passwordHash((string) ($_POST['password'] ?? ''));
                        transaction(stateDir() . '/config.json', function (&$current) use ($username, $hash) {
                            if ($current) throw new InvalidArgumentException('账号已创建，请登录');
                            $current = ['username' => $username, 'password_hash' => $hash, 'app_hash' => null,
                                'secret' => bin2hex(random_bytes(32)), 'session_epoch' => bin2hex(random_bytes(16))];
                        });
                        $cfg = config();
                        session_regenerate_id(true);
                        $_SESSION = ['username' => $username, 'epoch' => $cfg['session_epoch'], 'expires' => time() + 86400,
                            'csrf' => bin2hex(random_bytes(32))];
                        session_write_close();
                        header('Location: /', true, 303);
                        exit;
                    } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
                }
            } elseif ($action === 'login' && $cfg) {
                if (credentialsValid($cfg, (string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''), false)) {
                    session_regenerate_id(true);
                    $_SESSION = ['username' => $cfg['username'], 'epoch' => $cfg['session_epoch'], 'expires' => time() + 86400,
                        'csrf' => bin2hex(random_bytes(32))];
                    session_write_close();
                    header('Location: /', true, 303);
                    exit;
                }
                http_response_code(rateLimited() ? 429 : 401);
                $error = rateLimited() ? '尝试过多，请在五分钟后重试' : '用户名或密码错误';
            }
        }
    }
    $loggedIn = sessionUser($cfg);
    $csrfToken = $_SESSION['csrf'];
    session_write_close();
    $nonce = base64_encode(random_bytes(18));
    header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
} catch (Throwable $e) {
    error_log(get_class($e) . ': ' . $e->getMessage());
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $status = $e instanceof DAV\Exception ? $e->getHTTPCode() : ($e instanceof InvalidArgumentException || $e instanceof JsonException ? 400 : 500);
    if (isset($_GET['api'])) jsonResponse(['error' => match ($status) {
        500 => '服务器错误，请检查日志', 423 => $e instanceof UpdateBusy ? $e->getMessage() : '目标路径已被 WebDAV 客户端锁定', default => $e->getMessage(),
    }], $status);
    http_response_code($status);
    exit('应用暂时不可用，请检查服务器日志和存储目录权限。');
}
?>
<!doctype html>
<html lang="zh-CN">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>轻 DAV · 私人文件空间</title>
<style nonce="<?= html($nonce) ?>">
:root{color-scheme:light;--ink:#49535b;--muted:#9099a1;--line:#e8ecef;--accent:#3786bc;--hover:#f3f8fc}*{box-sizing:border-box}body{margin:0;background:#fff;color:var(--ink);font:14px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}button,input{font:inherit}button{cursor:pointer}button:focus-visible,input:focus-visible,summary:focus-visible{outline:2px solid var(--accent);outline-offset:3px}main{max-width:1180px;margin:0 auto;padding:0 32px}header{height:76px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line);gap:16px}.brand{font-size:21px;font-weight:400;letter-spacing:-.5px;display:flex;align-items:center;gap:10px}.brand span{color:var(--accent)}.brand-mark{width:26px;height:22px;border:1.5px solid var(--accent);border-radius:3px;position:relative}.brand-mark:before{content:"";position:absolute;left:2px;top:-6px;width:11px;height:5px;border:1.5px solid var(--accent);border-bottom:0;border-radius:3px 3px 0 0;background:white}.brand small{font-size:12px;letter-spacing:0;color:#a5adb3;margin-left:10px}h1{font-size:23px;font-weight:400;margin:0 0 8px}h2{font-size:17px;font-weight:500}p{color:var(--muted)}button,.button{border:1px solid #dde3e7;border-radius:4px;padding:7px 12px;background:white;color:var(--ink);text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px;white-space:nowrap}button:hover,.button:hover{background:#f5f7f9;border-color:#c9d3db}button:disabled{opacity:.4;cursor:default}.primary{background:var(--accent);border-color:var(--accent);color:white}.primary:hover{background:#2b75a6;border-color:#2b75a6}.quiet{border-color:transparent;color:var(--muted)}.icon{width:18px;height:18px;display:inline-block;flex-shrink:0;vertical-align:middle}.account{display:flex;align-items:center;gap:12px}.info{font-size:12px;color:var(--muted)}.auth{max-width:360px;margin:80px auto 120px}.auth p{margin:0 0 26px}.label{display:block;font-size:12px;margin:17px 0 6px;color:#78858f}input{width:100%;border:1px solid #dce3e8;border-radius:4px;padding:10px 12px;background:white;color:var(--ink)}.auth .primary{width:100%;margin-top:24px}.file-heading{display:flex;justify-content:space-between;align-items:center;padding:30px 0 20px;gap:16px}.file-heading h1{font-size:18px;margin:0;font-weight:500}.tools{display:flex;gap:8px;flex-wrap:wrap}.browser-bar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 0;border-top:1px solid var(--line);border-bottom:1px solid var(--line)}.crumb{display:flex;align-items:center;gap:6px;flex-wrap:wrap;min-width:0}.crumb button{border:0;padding:3px 6px;color:var(--accent);font-size:13px}.crumb button:last-of-type{color:var(--ink)}.crumb-separator{color:#b9c2c9;font-size:16px}.filter{position:relative;width:220px;flex-shrink:0}.filter input{padding:7px 10px 7px 32px;border-color:#edf0f2;background:#fafbfd;font-size:12px}.filter .icon{position:absolute;left:9px;top:9px;color:#9ba6ae;width:15px;height:15px}.status{font-size:12px;color:var(--accent);white-space:pre-wrap;overflow-wrap:anywhere}.status:not(:empty){padding:12px 0}progress{width:100%;height:4px;accent-color:var(--accent);display:block;margin:8px 0}progress[hidden]{display:none}.table-wrap{overflow:auto;min-height:300px}table{border-collapse:collapse;width:100%;white-space:nowrap}th{text-align:left;font-size:11px;font-weight:400;color:var(--muted);border-bottom:1px solid var(--line);height:42px;padding:0 12px}th:first-child,td:first-child{padding-left:10px}th button{border:0;padding:0;background:transparent!important;color:inherit;font-size:11px}th button[data-sort-direction]{color:var(--accent)}td{padding:10px 12px;border-bottom:1px solid #f0f3f5;height:50px;font-size:12px;color:#8a959d}tbody tr:hover{background:var(--hover)}td:first-child{min-width:220px;width:55%}.name{border:0;background:transparent!important;padding:0;font-size:13px;font-weight:400;text-align:left;justify-content:flex-start;max-width:560px;color:#53616c;gap:12px}.filename{display:block;overflow:hidden;text-overflow:ellipsis;max-width:440px}.name.dir{color:#466579}.file-icon{width:25px;height:28px;flex-shrink:0;color:#93a8b8}.file-icon.folder{color:#d2ae62}.file-icon.image{color:#75a891}.file-icon.archive{color:#b9a0c6}.file-icon.code{color:#7fa2c4}.actions{text-align:right;width:94px}.row-actions{display:flex;justify-content:flex-end;gap:3px;opacity:0}tr:hover .row-actions,tr:focus-within .row-actions{opacity:1}.row-actions button{border:0;background:transparent;padding:5px;color:#8998a4}.row-actions button:hover{color:var(--accent);background:#e8f1f8}.row-actions .delete:hover{color:#c56d6d;background:#faeeee}.empty{text-align:center;color:var(--muted);height:230px;font-size:13px}.pager{display:flex;justify-content:space-between;align-items:center;padding:15px 0;gap:10px}.pager button{font-size:12px;padding:5px 9px;border-color:transparent}.settings{width:min(680px,calc(100% - 32px));max-height:calc(100dvh - 48px);padding:0;margin:auto;border:1px solid #dfe6eb;border-radius:8px;color:var(--ink);background:white;box-shadow:0 16px 70px #24374926;overflow:auto;overscroll-behavior:contain}.settings::backdrop{background:#24374955}.dialog-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 24px;border-bottom:1px solid var(--line);position:sticky;top:0;background:white;z-index:1}.dialog-heading h2{margin:0;font-size:17px;font-weight:500}.settings-content{padding:22px 24px 26px}body.modal-open{overflow:hidden}.storage-panel{padding-bottom:22px;margin-bottom:22px;border-bottom:1px solid var(--line)}.storage-panel h3{font-size:14px;font-weight:500;margin:0 0 12px}.storage-summary{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;font-size:12px}.storage-used{font-size:20px;color:#536c7e;font-weight:400}.storage-meter{width:100%;height:10px;margin:12px 0 8px;accent-color:var(--accent)}.quota-row{display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap}.quota-row label{flex:1;min-width:130px}.quota-row select{padding:10px 8px;border:1px solid #dce3e8;border-radius:4px;background:white;color:var(--ink);font:inherit}.storage-actions{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:12px}.storage-actions button{font-size:12px}.connection{display:flex;align-items:center;justify-content:space-between;gap:16px;background:#f7f9fb;border:1px solid #eef1f4;padding:16px;margin-bottom:18px}.connection p{margin:5px 0}.connection code{font:12px ui-monospace,monospace;color:#5d7588;overflow-wrap:anywhere}.settings .tools{margin:15px 0}.password-grid{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;max-width:720px}.password-grid label{flex:1;min-width:180px}.error{color:#b76464;background:#fcf2f2;border-radius:4px;padding:10px;font-size:12px}footer{border-top:1px solid var(--line);font-size:11px;color:#a8b1b8;margin-top:38px;padding:20px 0 30px;display:flex;justify-content:space-between}.footer-note{color:#c0c7cc}@media(hover:none){.row-actions{opacity:1}}@media(max-width:700px){main{padding:0 16px}header{height:62px}.brand small,.account>.info,.modified{display:none}.brand{font-size:19px}.account{gap:4px}.account button{font-size:12px;padding:6px 8px}.file-heading{padding:22px 0 16px;align-items:flex-start}.tools{gap:5px}.tools button{padding:6px 8px;font-size:12px}.browser-bar{align-items:flex-start;flex-direction:column;gap:10px}.filter{width:100%}.filename{max-width:calc(100vw - 196px)}.name{gap:8px;font-size:12px}.file-icon{width:21px;height:24px}td:first-child{min-width:140px}.row-actions{opacity:1}.actions{width:64px}td{padding:9px 6px}.row-actions button{padding:4px}.connection{flex-direction:column;align-items:flex-start}.auth{margin:58px auto 90px}.pager .info{font-size:11px}footer{margin-top:26px}.file-heading h1{font-size:16px}}
/* File browser uses the viewport; account settings stay in the modal. */
body.app{height:100dvh;overflow:hidden}.app main{max-width:none;width:100%;height:100%;padding:0 24px;display:flex;flex-direction:column}.app header{height:52px;flex-shrink:0}.app .brand{font-size:19px}.app .brand small{font-size:11px}.app .file-browser{display:flex;flex:1;flex-direction:column;min-height:0}.sr-only{position:absolute;width:1px;height:1px;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap}.app .browser-bar{padding:10px 0;gap:20px;border-top:0;flex-shrink:0}.app .crumb{flex:1;flex-wrap:nowrap;overflow-x:auto;white-space:nowrap;scrollbar-width:thin;min-height:30px;align-items:center}.app .crumb button{flex-shrink:0}.browser-tools{display:flex;align-items:center;gap:12px;min-width:0}.app .filter{width:180px}.app .browser-tools .tools{flex-wrap:nowrap}.app #status{flex-shrink:0;max-height:72px;overflow:auto}.app #status:not(:empty){padding:8px 0}.app progress{flex-shrink:0}.app .table-wrap{flex:1;min-height:0;overflow:auto;overscroll-behavior:contain;scrollbar-gutter:stable}.app table{table-layout:fixed}.app thead{position:sticky;top:0;z-index:1;background:#fff}.app th{height:36px;background:#fff;box-shadow:0 1px 0 var(--line)}.app th:nth-child(2){width:110px}.app th.modified{width:180px}.app th.actions{width:84px}.app td{height:40px;padding:6px 12px}.app td:first-child{width:auto;min-width:0}.app .name{max-width:100%;min-width:0;gap:10px}.app .filename{min-width:0;max-width:none}.app .file-icon{width:22px;height:24px}.app .row-actions button{min-width:30px;min-height:30px;padding:6px}.app .pager{flex-shrink:0;padding:7px 0;border-top:1px solid var(--line);min-height:42px;gap:12px}.app .pager>div{display:flex;flex-shrink:0}.app .pager .info{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.app footer{display:none}.loading-cell{text-align:center;color:var(--muted);height:120px!important}.app .table-wrap[aria-busy=true] tbody{opacity:.65}
@media(min-width:1600px){.app main{padding:0 40px}}
@media(max-width:700px){.app main{padding:0 12px}.app header{height:48px}.app .browser-bar{flex-direction:column;align-items:stretch;padding:6px 0 8px;gap:6px}.app .crumb{flex:none;width:100%;min-height:28px}.browser-tools{gap:6px;width:100%}.app .filter{width:auto;flex:1;min-width:80px}.app .filter input{font-size:12px;padding-right:6px}.app .browser-tools .tools{gap:4px;flex-shrink:0}.app .browser-tools button{padding:6px 7px;font-size:12px}.app th:nth-child(2){width:68px}.app th.actions{width:76px}.app td{padding:6px 5px;height:44px}.app .name{gap:7px;font-size:12px}.app .row-actions button{min-width:34px;min-height:34px;padding:7px}.app .pager{gap:5px;min-height:40px}.app .pager button{padding:6px;font-size:11px}.app .pager .info{font-size:11px}.app .file-icon{width:20px;height:23px}}
.upload-panel{flex-shrink:0;border:1px solid #dce8f0;border-radius:10px;background:#f7fbfe;padding:12px 16px;margin-bottom:10px}.upload-summary,.upload-detail{display:flex;justify-content:space-between;gap:16px}.upload-summary{font-size:13px}.upload-summary strong{font-weight:500}.upload-summary-actions{display:flex;align-items:center;gap:12px}#upload-close{padding:0;border:0;line-height:1;font-size:19px}#upload-close[hidden]{display:none}.upload-detail{font-size:12px;color:#748593}.upload-panel progress{display:block;width:100%;height:6px;border:0;border-radius:8px;overflow:hidden;accent-color:var(--accent);margin:9px 0;background:#e4edf3}.upload-panel progress::-webkit-progress-bar{background:#e4edf3}.upload-panel progress::-webkit-progress-value{background:var(--accent);border-radius:8px;transition:width .15s}.upload-panel progress::-moz-progress-bar{background:var(--accent)}#upload-current{margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.action-dialog{border:1px solid #e5ebf0;border-radius:16px;width:min(420px,calc(100vw - 32px));padding:26px;box-shadow:0 22px 80px #23384930;color:var(--ink)}.action-dialog::backdrop{background:#28374655;backdrop-filter:blur(3px)}.action-dialog h2{font-size:19px;margin:14px 0 8px}.action-dialog p{color:#73808b;white-space:pre-wrap;overflow-wrap:anywhere;margin:0 0 20px}.action-dialog input{width:100%}.action-symbol{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;background:#eaf4fc;color:var(--accent);font-size:22px}.action-dialog.danger .action-symbol{color:#c05c5c;background:#fcEEEE}.action-dialog.danger #action-submit{background:#be5d5d;border-color:#be5d5d}.action-controls{display:flex;justify-content:flex-end;gap:8px;margin-top:22px}.action-dialog label[hidden],.upload-panel[hidden]{display:none}@media(max-width:700px){.app .browser-tools .tools{flex-wrap:wrap;flex-shrink:1;min-width:0;justify-content:flex-end}.upload-panel{padding:10px 12px}.upload-detail{gap:8px;flex-wrap:wrap}}
.performance-toggle{display:flex;align-items:center;gap:8px;margin:12px 0}.performance-toggle input{width:auto;margin:0}.performance-output{width:100%;border:1px solid #dce3e8;border-radius:6px;padding:12px;background:#f8fafc;color:var(--ink);font:12px/1.65 ui-monospace,monospace;resize:vertical}.performance-output[hidden]{display:none}
</style>
<body<?= $loggedIn ? ' class="app"' : '' ?>>
<main>
<header><div class="brand"><i class="brand-mark" aria-hidden="true"></i>轻<span>DAV</span><small>文件索引</small></div><div class="account"><?php if ($loggedIn): ?><span class="info"><?= html($cfg['username']) ?></span><form method="post"><input type="hidden" name="csrf" value="<?= html($csrfToken) ?>"><input type="hidden" name="action" value="logout"><button class="quiet" id="settings-button" type="button" aria-haspopup="dialog" aria-controls="settings" aria-expanded="false">设置</button><button class="quiet">退出</button></form><?php endif ?></div></header>
<?php if (!$loggedIn): ?>
<section class="card auth"><h1><?= $cfg ? '欢迎回来' : '创建你的文件空间' ?></h1><p><?= $cfg ? '登录后管理文件和连接凭据。' : '仅创建一个管理员账号，完成后自动关闭注册。' ?></p>
<?php if ($error): ?><div class="error" role="alert"><?= html($error) ?></div><?php endif ?>
<form method="post"><input type="hidden" name="csrf" value="<?= html($csrfToken) ?>"><input type="hidden" name="action" value="<?= $cfg ? 'login' : 'setup' ?>"><label class="label" for="username">用户名</label><input id="username" name="username" autocomplete="username" maxlength="64" required><label class="label" for="password">密码<?= $cfg ? '' : ' · 至少 12 字节' ?></label><input id="password" name="password" type="password" autocomplete="<?= $cfg ? 'current-password' : 'new-password' ?>" maxlength="72" required><?php if (!$cfg): ?><label class="label" for="confirm">确认密码</label><input id="confirm" name="confirm" type="password" autocomplete="new-password" required><?php if (getenv('WEBDAV_SETUP_TOKEN')): ?><label class="label" for="setup_token">初始化令牌</label><input id="setup_token" name="setup_token" type="password" required><?php endif ?><?php endif ?><button class="primary"><?= $cfg ? '登录' : '创建账号' ?></button></form></section>
<?php else: ?>
<section class="file-browser" aria-label="文件浏览">
<h1 class="sr-only">文件</h1><div class="browser-bar"><nav class="crumb" id="breadcrumb" aria-label="当前路径"></nav><div class="browser-tools"><label class="filter"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg><input id="filter" type="search" placeholder="筛选当前页" aria-label="筛选当前页文件"></label><div class="tools"><button class="primary" id="upload-button">上传文件</button><button id="upload-folder">上传文件夹</button><button id="mkdir">新建文件夹</button><button class="quiet" id="refresh" title="刷新文件列表">刷新</button><input type="file" id="files" multiple hidden><input type="file" id="folders" webkitdirectory multiple hidden></div></div></div>
<div class="status" id="status" role="status" aria-live="polite"></div><section id="upload-panel" class="upload-panel" hidden aria-label="上传进度"><div class="upload-summary"><strong id="upload-title">准备上传</strong><div class="upload-summary-actions"><span id="upload-percent">0%</span><button id="upload-close" class="quiet" type="button" aria-label="关闭上传进度" hidden>×</button></div></div><progress id="progress" value="0" max="1" aria-label="上传总进度"></progress><div class="upload-detail"><span id="upload-details"></span><span id="upload-speed"></span></div><div id="upload-current" class="info"></div></section>
<div class="table-wrap" id="file-list" aria-busy="true"><table><thead><tr><th scope="col"><button id="sort-name">名称 ↑</button></th><th scope="col"><button id="sort-size">大小</button></th><th scope="col" class="modified"><button id="sort-modified">修改时间</button></th><th scope="col" class="actions">操作</th></tr></thead><tbody id="rows"><tr><td colspan="4" class="loading-cell">正在读取文件…</td></tr></tbody></table></div>
<div class="pager"><span class="info" id="capacity"></span><div><button id="prev">上一页</button><button id="next">下一页</button></div></div>
</section>
<dialog class="settings" id="settings" aria-labelledby="settings-title"><div class="dialog-heading"><h2 id="settings-title">连接与账号设置</h2><button class="quiet" id="settings-close" type="button" aria-label="关闭设置" autofocus>关闭</button></div><div class="settings-content"><div class="connection"><div><span class="info">WEBDAV 连接地址</span><p><code id="endpoint"></code></p><span class="info">用户名：<?= html($cfg['username']) ?> · rclone 类型：other</span></div><button id="copy-url">复制地址</button></div><section class="storage-panel" aria-labelledby="storage-title"><h3 id="storage-title">存储空间</h3><div class="storage-summary"><span><strong class="storage-used" id="storage-used">—</strong> 已用</span><span class="info" id="storage-details"></span></div><meter class="storage-meter" id="storage-meter" min="0" max="1" value="0" aria-label="容量使用比例"></meter><p class="info" id="storage-remaining"></p><form id="quota-form"><div class="quota-row"><label><span class="label">容量上限 · 0 表示不限</span><input id="quota-limit" type="number" min="0" step="any" value="0" required></label><select id="quota-unit" aria-label="容量单位"><option value="1073741824">GiB</option><option value="1099511627776">TiB</option><option value="1048576">MiB</option></select><button class="primary" id="quota-save">保存上限</button></div></form><div class="storage-actions"><span class="info">上传会预留空间，删除后释放用量。</span><button id="storage-rescan" type="button">重新统计</button></div><div class="status" id="storage-result" role="status" aria-live="polite"></div></section><section class="storage-panel" aria-labelledby="update-title"><h3 id="update-title">程序更新</h3><p class="info">当前版本 <span id="update-current"><?= html(QINDAV_VERSION) ?></span> · 更新保留账号、设置与用户文件。</p><div class="tools"><button id="update-check" type="button">检查更新</button><button id="update-install" type="button" class="primary" disabled>立即更新</button></div><form id="backup-form"><div class="quota-row"><label><span class="label">备份保留份数 · 1–10</span><input id="backup-keep" type="number" min="1" max="10" step="1" value="2" required></label><button id="backup-save" type="submit">保存份数</button></div></form><div id="update-backups"></div><div class="status" id="update-result" role="status" aria-live="polite"></div></section><section class="storage-panel" aria-labelledby="performance-title"><h3 id="performance-title">性能日志</h3><p class="info">记录上传、复制、异常和超过 100 ms 的请求。每轮上传前清空，完成后复制摘要用于分析。</p><label class="performance-toggle"><input id="performance-enabled" type="checkbox"<?= performanceEnabled($cfg) ? ' checked' : '' ?><?= !PERFORMANCE_LOG_ENABLED ? ' disabled' : '' ?>> 开启性能日志</label><p class="info">默认关闭。关闭后停止性能计时与日志写入，并使用原生复制；已有日志仍可查看、复制或清空。</p><label class="performance-toggle"><input id="performance-split" type="checkbox"<?= performanceEnabled($cfg) && !empty($cfg['performance_split_io']) ? ' checked' : '' ?><?= !performanceEnabled($cfg) ? ' disabled' : '' ?>> 开启读写分段诊断</label><p class="info">仅用于一轮诊断，完成后关闭。记录读写耗时、首块等待和约 1 MiB 窗口速度；使用 8 KiB 分段计时，可能影响速度。关闭时使用原生流复制。</p><div class="tools"><button id="performance-view" type="button">查看摘要</button><button id="performance-copy" type="button">复制摘要</button><button id="performance-clear" type="button">清空日志</button></div><textarea id="performance-output" class="performance-output" rows="10" readonly spellcheck="false" aria-label="性能日志摘要" hidden></textarea><div class="status" id="performance-result" role="status" aria-live="polite"></div></section><p class="info">应用密码用于 WebDAV 客户端，创建新密码会替换旧密码。可随时查看或复制。</p><div class="tools"><button id="view-app">查看应用密码</button><button id="copy-app">复制应用密码</button><button id="app-password">生成应用密码</button><button id="revoke-app">撤销应用密码</button></div><input id="app-secret" type="text" aria-label="应用密码" readonly autocomplete="off" spellcheck="false" hidden><div class="status" id="app-result" role="status" aria-live="polite"></div><form id="password-form"><div class="password-grid"><label><span class="label">当前密码</span><input name="current" type="password" autocomplete="current-password" required></label><label><span class="label">新密码 · 至少 12 字节</span><input name="password" type="password" autocomplete="new-password" required></label><button>修改密码</button></div><div class="status" id="password-result" role="status"></div></form></div></dialog>
<dialog id="action-dialog" class="action-dialog" aria-labelledby="action-title" aria-describedby="action-message"><form id="action-form"><div class="action-symbol" id="action-symbol" aria-hidden="true">!</div><h2 id="action-title"></h2><p id="action-message"></p><label id="action-input-label" hidden><span class="label" id="action-label"></span><input id="action-input" autocomplete="off" spellcheck="false"></label><div class="action-controls"><button type="button" id="action-cancel">取消</button><button type="submit" id="action-submit" class="primary">确认</button></div></form></dialog>
<script nonce="<?= html($nonce) ?>">
'use strict';
const csrf = <?= json_encode($csrfToken) ?>, dav = <?= json_encode($davBase, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const $ = id => document.getElementById(id);
let path = '', offset = 0, hasMore = false, loading = false, entries = [], freeSpace = 0, sortKey = 'name', sortDirection = 1, storage = null;
const collator=new Intl.Collator('zh-CN',{numeric:true,sensitivity:'base'});
const dateFormatter=new Intl.DateTimeFormat('zh-CN',{dateStyle:'short',timeStyle:'short'});
let loadController=null,loadSerial=0,lastRendered='';const iconTemplates=new Map();
const endpoint = new URL(dav, location.origin).href;
$('endpoint').textContent = endpoint;
function url(p) { return dav + p.split('/').filter(Boolean).map(encodeURIComponent).join('/'); }
function size(n) { if(n == null) return '—'; const units=['B','KiB','MiB','GiB','TiB']; let i=0; while(n>=1024&&i<4){n/=1024;i++}return `${n.toFixed(i?1:0)} ${units[i]}`; }
function status(message) { $('status').textContent = message; }
let actionPending=false;
function ask({title='确认操作',message='',value=null,label='',confirm='确认',danger=false}={}){
 if(actionPending)return Promise.resolve(null);actionPending=true;
 const dialog=$('action-dialog'),input=$('action-input');$('action-title').textContent=title;$('action-message').textContent=message;$('action-label').textContent=label;$('action-input-label').hidden=value===null;input.value=value??'';input.required=value!==null;$('action-submit').textContent=confirm;dialog.classList.toggle('danger',danger);$('action-symbol').textContent=danger?'!':value===null?'✓':'+';
 return new Promise(resolve=>{let settled=false;const finish=result=>{if(settled)return;settled=true;actionPending=false;dialog.close();resolve(result);};$('action-form').onsubmit=event=>{event.preventDefault();finish(value===null?true:input.value.trim());};$('action-cancel').onclick=()=>finish(null);dialog.oncancel=event=>{event.preventDefault();finish(null);};dialog.showModal();if(value!==null){input.focus();input.select();}else $('action-cancel').focus();});
}
async function request(p, options={}) {
 const response = await fetch(url(p), {...options, headers:{'X-CSRF-Token':csrf,...options.headers}, credentials:'same-origin', redirect:'error'});
 if(!response.ok) { if(response.status===401) throw Error('登录已过期，请刷新页面'); if(response.status===507)throw Error('空间不足：请检查容量上限或磁盘剩余空间'); throw Error(`操作失败（${response.status}），请检查文件名、目录权限或文件锁`); }
 return response;
}
async function api(action, data) {
 for(let attempt=0;;attempt++){
  try{
   const response=await fetch(`/?api=${action}`,{method:'POST',credentials:'same-origin',redirect:'error',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data||{})});
   const result=await response.json();if(!response.ok){const error=Error(result.error||'操作失败');error.retryable=[408,429,500,502,503,504].includes(response.status);throw error;}return result;
  }catch(error){if(action!=='upload-finish'||attempt>=2||(!(error instanceof TypeError)&&!error.retryable))throw error;await new Promise(resolve=>setTimeout(resolve,500*2**attempt));}
 }
}
let activeTransfers=0;const transferQueue=[];
async function transfer(work) {
 await new Promise(resolve=>{if(activeTransfers<4){activeTransfers++;resolve();}else transferQueue.push(resolve);});
 try{return await work();}finally{if(transferQueue.length)transferQueue.shift()();else activeTransfers--;}
}
function sendUpload(target,blob,headers,onProgress){
 return new Promise((resolve,reject)=>{const xhr=new XMLHttpRequest();xhr.open('PUT',target);xhr.withCredentials=true;for(const [key,value] of Object.entries({'X-CSRF-Token':csrf,...headers}))xhr.setRequestHeader(key,value);
  xhr.upload.onprogress=event=>onProgress(Math.min(blob.size,event.loaded));
  xhr.onerror=()=>reject(new TypeError('上传连接中断'));xhr.onabort=()=>reject(Error('上传已取消'));
  xhr.onload=()=>{if(xhr.responseURL!==new URL(target,location.href).href){reject(Error('上传请求被重定向，请检查主机配置'));return;}resolve({status:xhr.status,ok:xhr.status>=200&&xhr.status<300,text:xhr.responseText});};xhr.send(blob);
 });
}
async function uploadPart(id,part,blob,onProgress){
 for(let attempt=0;;attempt++){
  try{
   onProgress(0);const response=await sendUpload(`/?api=upload-part&id=${encodeURIComponent(id)}&part=${part}`,blob,{},onProgress);
   let result;try{result=JSON.parse(response.text);}catch{const error=Error('上传响应无效，请检查主机防护');error.retryable=response.status>=500;throw error;}
   if(!response.ok){const error=Error(result.error||`上传失败（${response.status}）`);error.retryable=[408,429,500,502,503,504].includes(response.status);throw error;}
   if(result.ok!==true)throw Error('上传响应无效，请检查主机防护');onProgress(blob.size);return;
  }catch(error){if(attempt>=2||(!(error instanceof TypeError)&&!error.retryable))throw error;onProgress(0);await new Promise(resolve=>setTimeout(resolve,500*2**attempt));}
 }
}
async function uploadFile(file,destination,onProgress){
 if(file.size<32*1024*1024){await transfer(async()=>{const response=await sendUpload(url(destination),file,{'If-None-Match':'*'},onProgress);if(!response.ok)throw Error(response.status===507?'空间不足：请检查容量上限或磁盘剩余空间':response.status===412?'同名文件已存在，已保留原文件':`上传失败（${response.status}）`);onProgress(file.size);});return;}
 const job=await api('upload-start',{path:destination,size:file.size});let next=0,failure=null;const loaded=new Map();let sent=0;
 function progress(part,n){sent+=n-(loaded.get(part)||0);loaded.set(part,n);onProgress(sent);}
 try{
  async function worker(){while(!failure&&next<job.chunks){const part=next++,start=part*job.chunk_size,blob=file.slice(start,Math.min(file.size,start+job.chunk_size));try{await transfer(()=>uploadPart(job.id,part,blob,n=>progress(part,n)));}catch(error){failure=error;}}}
  await Promise.all(Array.from({length:Math.min(4,job.chunks)},worker));if(failure)throw failure;await api('upload-finish',{id:job.id});
 }catch(error){await api('upload-cancel',{id:job.id}).catch(()=>{});throw error;}
}
function button(label, fn, className='') { const b=document.createElement('button');b.textContent=label;b.className=className;b.onclick=()=>Promise.resolve().then(fn).catch(e=>status(e.message));return b; }
function icon(kind, className='icon') {
 const key=kind+' '+className;if(iconTemplates.has(key))return iconTemplates.get(key).cloneNode(true);
 const paths={folder:'<path d="M3 7h7l2 2h9v11H3z" fill="currentColor" opacity=".2"/><path d="M3 7V5h7l2 2h9v13H3z"/>',file:'<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v5h4M9 12h6M9 15h6"/>',image:'<rect x="4" y="3" width="16" height="18" rx="1"/><circle cx="9" cy="8" r="1.5"/><path d="m5 18 5-6 4 4 3-3 3 5"/>',archive:'<path d="M6 3h12v18H6zM11 3v12M11 7h3M11 11h3"/><path d="M10 16h4v3h-4z"/>',code:'<path d="M6 3h8l4 4v14H6zM14 3v5h4M10 12l-2 3 2 3M14 12l2 3-2 3"/>',rename:'<path d="m4 16 11-11 4 4L8 20H4zM13 7l4 4"/>',delete:'<path d="M4 6h16M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7M14 10v7"/>',up:'<path d="M12 20V4m-6 6 6-6 6 6"/>'};
 const span=document.createElement('span');span.className=className;span.setAttribute('aria-hidden','true');
 span.innerHTML=`<svg viewBox="0 0 24 24" width="100%" height="100%" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round">${paths[kind]||paths.file}</svg>`;iconTemplates.set(key,span);return span.cloneNode(true);
}
function fileIcon(item){if(item.directory)return icon('folder','file-icon folder');const ext=item.name.split('.').pop().toLowerCase();const type=/^(png|jpg|jpeg|gif|webp|svg|avif|bmp)$/.test(ext)?'image':/^(zip|gz|tar|7z|rar|bz2|xz)$/.test(ext)?'archive':/^(php|js|ts|py|html|css|json|xml|sh|c|cpp|rs)$/.test(ext)?'code':'file';return icon(type,`file-icon ${type}`);}
function actionButton(label,fn,kind,className=''){const b=button(label,fn,className);b.replaceChildren(icon(kind));b.setAttribute('aria-label',label);b.title=label;return b;}
function navigate(p) { return load({nextPath:p,nextOffset:0,resetFilter:true,reloadStorage:!storage}); }
function renderItems(){
 const query=$('filter').value.trim().toLocaleLowerCase();const items=entries.filter(item=>item.name.toLocaleLowerCase().includes(query));
 items.sort((a,b)=>{if(a.directory!==b.directory)return a.directory?-1:1;const value=sortKey==='name'?collator.compare(a.name,b.name):(a[sortKey]||0)-(b[sortKey]||0);return (value||collator.compare(a.name,b.name))*sortDirection;});
 const signature=JSON.stringify([path,offset,query,sortKey,sortDirection,items.map(item=>[item.name,item.directory,item.size,item.modified])]);
 if(signature===lastRendered){renderCapacity(items.length,query);return;}
 const fragment=document.createDocumentFragment();
 if(path&&!query){const row=document.createElement('tr'),cell=document.createElement('td');cell.colSpan=4;const name=button('..',()=>navigate(path.split('/').slice(0,-1).join('/')),'name dir');name.prepend(icon('up','file-icon'));name.setAttribute('aria-label','返回上级目录');cell.append(name);row.append(cell);fragment.append(row);}
 for(const item of items){
  const p=(path?path+'/':'')+item.name,row=document.createElement('tr'),name=document.createElement('td'),bytes=document.createElement('td'),date=document.createElement('td'),actions=document.createElement('td');
  const filename=button('',()=>item.directory?navigate(p):location.assign(url(p)),`name ${item.directory?'dir':''}`),label=document.createElement('span');label.className='filename';label.textContent=item.name;filename.title=item.name;filename.append(fileIcon(item),label);name.append(filename);
  bytes.textContent=item.directory?'—':size(item.size);date.textContent=dateFormatter.format(new Date(item.modified*1000));date.className='modified';actions.className='actions';const tools=document.createElement('div');tools.className='row-actions';
  tools.append(actionButton('移动 / 重命名',async()=>{const destination=await ask({title:'移动 / 重命名',message:'目标路径从根目录开始，不加开头的 /。',label:'目标路径',value:p,confirm:'保存'});if(destination===null||destination===p)return;if(!destination||destination.split('/').some(n=>!n||n==='.'||n==='..'))throw Error('请输入有效目标路径');await request(p,{method:'MOVE',headers:{Destination:new URL(url(destination),location.origin).href,Overwrite:'F'}});status('移动完成');await load();},'rename'),actionButton('删除',async()=>{if(!await ask({title:item.directory?'删除文件夹':'删除文件',message:`“${item.name}”${item.directory?'及其全部内容':''}将被永久删除。此操作无法撤销。`,confirm:'删除',danger:true}))return;await request(p,{method:'DELETE'});status('已删除');await load();},'delete','delete'));
  actions.append(tools);row.append(name,bytes,date,actions);fragment.append(row);
 }
 if(!items.length){const row=document.createElement('tr'),cell=document.createElement('td');cell.colSpan=4;cell.className='empty';cell.textContent=query?'没有匹配的文件':offset?'本页没有文件，请返回上一页':'此目录为空，点击右上角上传文件。';row.append(cell);fragment.append(row);}
 $('rows').replaceChildren(fragment);lastRendered=signature;renderCapacity(items.length,query);
 for(const [key,label] of Object.entries({name:'名称',size:'大小',modified:'修改时间'})){const b=$('sort-'+key);b.textContent=label+(sortKey===key?(sortDirection===1?' ↑':' ↓'):'');b.parentElement.setAttribute('aria-sort',sortKey===key?(sortDirection===1?'ascending':'descending'):'none');if(sortKey===key)b.setAttribute('data-sort-direction',String(sortDirection));else b.removeAttribute('data-sort-direction');}
}
function renderCapacity(count=entries.length,query=$('filter').value.trim()){
 const usage=storage?`已用 ${size(storage.used_bytes)}${storage.limit_bytes?` / ${size(storage.limit_bytes)}`:''} · `:'';
 $('capacity').textContent=`${query?`匹配 ${count} / ${entries.length} 项`:`第 ${Math.floor(offset/200)+1} 页 · ${entries.length} 项`}${storage?` · ${usage}可用 ${size(storage.available_bytes??freeSpace)}`:''}`;
}
async function load({nextPath=path,nextOffset=offset,resetFilter=false,reloadStorage=true}={}) {
 const serial=++loadSerial;loadController?.abort();loadController=new AbortController();loading=true;$('file-list').setAttribute('aria-busy','true');$('prev').disabled=$('next').disabled=true;
 try {
  const response=await fetch(`/?api=list&path=${encodeURIComponent(nextPath)}&offset=${nextOffset}`,{credentials:'same-origin',cache:'no-store',signal:loadController.signal});
  const data=await response.json();if(!response.ok)throw Error(data.error||'读取失败');if(serial!==loadSerial)return;
  const moved=path!==nextPath||offset!==nextOffset;path=nextPath;offset=nextOffset;if(resetFilter)$('filter').value='';
  $('breadcrumb').replaceChildren(button('全部文件',()=>navigate('')));
  let partial='';for(const part of path.split('/').filter(Boolean)){partial+=(partial?'/':'')+part;const destination=partial,separator=document.createElement('span');separator.className='crumb-separator';separator.textContent='/';$('breadcrumb').append(separator,button(part,()=>navigate(destination)));}
  entries=data.items;freeSpace=data.free;hasMore=data.more;renderItems();if(moved)$('file-list').scrollTop=0;
  // Show files first; capacity recovery or a first-time scan runs separately.
  if(reloadStorage||!storage)loadStorage().catch(()=>{});
 }catch(e){if(e.name!=='AbortError'&&serial===loadSerial){status(e.message);const cell=$('rows').querySelector('.loading-cell');if(cell)cell.textContent='读取失败，点击刷新重试';}}
 finally{if(serial===loadSerial){loading=false;$('file-list').setAttribute('aria-busy','false');$('prev').disabled=offset===0;$('next').disabled=!hasMore;}}
}
$('filter').oninput=renderItems;
for(const key of ['name','size','modified'])$('sort-'+key).onclick=()=>{if(sortKey===key)sortDirection*=-1;else{sortKey=key;sortDirection=1;}renderItems();};
const settings=$('settings');let quotaEdited=false,storageRequest=0;
$('quota-limit').oninput=() => quotaEdited=true;$('quota-unit').onchange=() => quotaEdited=true;
function renderStorage(info,fillLimit=true){
 storage=info;$('storage-used').textContent=size(info.used_bytes);$('storage-details').textContent=`${info.files} 个文件 · 上限 ${info.limit_bytes?size(info.limit_bytes):'不限'}`;
 $('storage-meter').hidden=info.limit_bytes===0;$('storage-meter').max=info.limit_bytes||Math.max(1,info.used_bytes+info.disk_free_bytes);$('storage-meter').value=info.used_bytes;
 $('storage-remaining').textContent=`${info.available_bytes===null?'磁盘可用':'额度剩余'} ${size(info.available_bytes??info.disk_free_bytes)}${info.available_bytes!==null?` · 磁盘可用 ${size(info.disk_free_bytes)}`:''}${info.reserved_bytes?` · 上传预留 ${size(info.reserved_bytes)}`:''}`;
 if(fillLimit&&!quotaEdited){const unit=info.limit_bytes&&info.limit_bytes<1073741824?1048576:1073741824;$('quota-unit').value=String(unit);$('quota-limit').value=info.limit_bytes/unit;}
}
async function loadStorage(){const request=++storageRequest;const response=await fetch('/?api=storage',{credentials:'same-origin',cache:'no-store'});const info=await response.json();if(!response.ok)throw Error(info.error||'读取用量失败');if(request===storageRequest){freeSpace=info.disk_free_bytes;renderStorage(info);renderCapacity(entries.filter(item=>item.name.toLocaleLowerCase().includes($('filter').value.trim().toLocaleLowerCase())).length);}}
$('settings-button').onclick=()=>{quotaEdited=false;settings.showModal();document.body.classList.add('modal-open');$('settings-button').setAttribute('aria-expanded','true');if(storage)renderStorage(storage);loadStorage().catch(error=>$('storage-result').textContent=error.message);loadUpdates().catch(error=>$('update-result').textContent=error.message);};
$('quota-form').onsubmit=async event=>{event.preventDefault();const bytes=Math.round(Number($('quota-limit').value)*Number($('quota-unit').value));if(!Number.isSafeInteger(bytes)||bytes<0){$('storage-result').textContent='请输入有效容量';return;}$('quota-save').disabled=true;++storageRequest;try{const info=await api('storage-limit',{limit_bytes:bytes});quotaEdited=false;renderStorage(info);$('storage-result').textContent='容量上限已保存';await load();}catch(error){$('storage-result').textContent=error.message;}finally{$('quota-save').disabled=false;}};
$('storage-rescan').onclick=async()=>{$('storage-rescan').disabled=true;++storageRequest;$('storage-result').textContent='正在统计…';try{renderStorage(await api('storage-rescan'));$('storage-result').textContent='用量统计已更新';await load();}catch(error){$('storage-result').textContent=error.message;}finally{$('storage-rescan').disabled=false;}};
function closeSettings(){hideAppPassword();settings.close();}
$('settings-close').onclick=closeSettings;
settings.addEventListener('cancel',hideAppPassword);
settings.addEventListener('close',()=>{hideAppPassword();$('app-result').textContent='';document.body.classList.remove('modal-open');$('settings-button').setAttribute('aria-expanded','false');});
settings.addEventListener('click',event=>{const rect=settings.getBoundingClientRect();if(event.target===settings&&(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom))closeSettings();});
let performanceSettings={enabled:<?= performanceEnabled($cfg) ? 'true' : 'false' ?>,split_io:<?= performanceEnabled($cfg) && !empty($cfg['performance_split_io']) ? 'true' : 'false' ?>};
function renderPerformanceSettings(){$('performance-enabled').checked=performanceSettings.enabled;$('performance-enabled').disabled=<?= PERFORMANCE_LOG_ENABLED ? 'false' : 'true' ?>;$('performance-split').checked=performanceSettings.split_io;$('performance-split').disabled=!performanceSettings.enabled;}
async function savePerformanceSettings(change){$('performance-enabled').disabled=$('performance-split').disabled=true;try{performanceSettings=await api('performance-settings',change);$('performance-result').textContent=!performanceSettings.enabled?'性能日志已关闭，后续请求不再记录，上传使用原生复制':performanceSettings.split_io?'性能日志及分段诊断已开启，请清空日志后开始上传':'性能日志已开启，上传使用原生复制';}catch(e){$('performance-result').textContent=e.message;}finally{renderPerformanceSettings();}}
$('performance-enabled').onchange=()=>savePerformanceSettings({enabled:$('performance-enabled').checked});
$('performance-split').onchange=()=>savePerformanceSettings({split_io:$('performance-split').checked});
async function readPerformance(){const response=await fetch('/?api=performance-log',{credentials:'same-origin',cache:'no-store'});if(!response.ok)throw Error('读取日志失败，请检查登录状态');return response.text();}
function setPerformanceBusy(busy){for(const id of ['performance-view','performance-copy','performance-clear'])$(id).disabled=busy;}
$('performance-view').onclick=async()=>{const output=$('performance-output');if(!output.hidden){output.hidden=true;$('performance-view').textContent='查看摘要';return;}setPerformanceBusy(true);$('performance-result').textContent='正在整理摘要…';try{output.value=await readPerformance();output.hidden=false;$('performance-view').textContent='收起摘要';$('performance-result').textContent='';}catch(e){$('performance-result').textContent=e.message;}finally{setPerformanceBusy(false);}};
$('performance-copy').onclick=async()=>{setPerformanceBusy(true);$('performance-result').textContent='正在整理摘要…';try{const text=await readPerformance();try{await navigator.clipboard.writeText(text);$('performance-result').textContent='性能摘要已复制';}catch{const output=$('performance-output');output.value=text;output.hidden=false;output.focus();output.select();$('performance-view').textContent='收起摘要';$('performance-result').textContent='请手动复制已选中的摘要';}}catch(e){$('performance-result').textContent=e.message;}finally{setPerformanceBusy(false);}};
$('performance-clear').onclick=async()=>{if(!await ask({title:'清空性能日志',message:'清理上一轮诊断记录。建议在下一轮上传开始前操作。',confirm:'清空'}))return;setPerformanceBusy(true);try{await api('performance-clear');$('performance-output').value='';$('performance-output').hidden=true;$('performance-view').textContent='查看摘要';$('performance-result').textContent='日志已清空，可以开始下一轮上传';}catch(e){$('performance-result').textContent=e.message;}finally{setPerformanceBusy(false);}};
$('copy-url').onclick=async()=>{try{await navigator.clipboard.writeText(endpoint);status('连接地址已复制');}catch{status(endpoint);}};
$('refresh').onclick=()=>load();$('prev').onclick=()=>{if(!loading)load({nextOffset:Math.max(0,offset-200),reloadStorage:false});};$('next').onclick=()=>{if(!loading&&hasMore)load({nextOffset:offset+200,reloadStorage:false});};
$('mkdir').onclick=async()=>{const name=await ask({title:'新建文件夹',message:'在当前目录创建一个文件夹。',label:'文件夹名称',value:'',confirm:'创建'});if(!name)return;if(name.includes('/')||name.includes('\\')||name==='.'||name==='..'){status('请输入有效的文件夹名称');return;}try{await request((path?path+'/':'')+name,{method:'MKCOL'});status('文件夹已创建');await load();}catch(e){status(e.message);}};
$('upload-close').onclick=()=>$('upload-panel').hidden=true;
$('upload-button').onclick=()=>$('files').click();$('upload-folder').onclick=()=>$('folders').click();
if(!('webkitdirectory' in $('folders'))){$('upload-folder').disabled=true;$('upload-folder').title='当前浏览器不支持文件夹选择';}
let uploading=false;
async function uploadSelection(input,folder=false){
 const files=Array.from(input.files);if(!files.length||uploading)return;
 uploading=true;const uploadPath=path,failures=[],loaded=new Map(),activeNames=new Map(),directories=new Map();let index=0,done=0,succeeded=0,sentBytes=0;
 const total=files.reduce((n,file)=>n+file.size,0),started=performance.now();
 $('upload-button').disabled=$('upload-folder').disabled=true;$('upload-panel').hidden=false;$('upload-close').hidden=true;$('progress').max=Math.max(1,total);$('progress').value=0;status('');
 function renderProgress(){const bytes=sentBytes,seconds=Math.max(.001,(performance.now()-started)/1000),percent=total?Math.floor(bytes/total*100):Math.floor(done/files.length*100);
  $('progress').value=total?bytes:done===files.length?1:0;$('upload-percent').textContent=`${percent}%`;$('upload-title').textContent=done===files.length?(failures.length?'上传结束，部分文件失败':'上传完成'):bytes===total&&total>0?'正在完成保存…':folder?'正在上传文件夹':'正在上传文件';
  $('upload-details').textContent=`${size(bytes)} / ${size(total)} · 已处理 ${done} / ${files.length} 个文件`;$('upload-speed').textContent=`平均 ${size(bytes/seconds)}/s`;$('upload-current').textContent=Array.from(activeNames.values()).join(' · ')||'正在准备上传…';
 }
 renderProgress();const ticker=setInterval(renderProgress,250);
 async function ensureDirectory(dir){if(!dir||dir===uploadPath)return;if(directories.has(dir))return directories.get(dir);const promise=(async()=>{const parent=dir.split('/').slice(0,-1).join('/');await ensureDirectory(parent);await transfer(async()=>{const response=await fetch(url(dir),{method:'MKCOL',credentials:'same-origin',redirect:'error',headers:{'X-CSRF-Token':csrf}});if(response.ok)return;if(response.status===405){const check=await request(dir,{method:'PROPFIND',headers:{Depth:'0'}});const xml=new DOMParser().parseFromString(await check.text(),'application/xml');if(xml.getElementsByTagNameNS('DAV:','collection').length)return;}throw Error(`创建文件夹失败（${response.status}）`);});})();directories.set(dir,promise);return promise;}
 async function worker(){while(index<files.length){const id=index++,file=files[id],relative=folder?file.webkitRelativePath:file.name;activeNames.set(id,relative);renderProgress();try{const parts=relative.split('/');if(parts.some(name=>!name||name==='.'||name==='..'||name.includes('\\')))throw Error('文件路径无效');const destination=(uploadPath?uploadPath+'/':'')+relative;if(folder)await ensureDirectory(destination.split('/').slice(0,-1).join('/'));await uploadFile(file,destination,n=>{sentBytes+=n-(loaded.get(id)||0);loaded.set(id,n);renderProgress();});succeeded++;}catch(error){failures.push(`${relative}：${error.message}`);}done++;activeNames.delete(id);renderProgress();}}
 try{await Promise.all(Array.from({length:Math.min(4,files.length)},worker));}finally{clearInterval(ticker);renderProgress();uploading=false;$('upload-close').hidden=false;$('upload-button').disabled=false;$('upload-folder').disabled=!('webkitdirectory' in $('folders'));input.value='';$('upload-current').textContent=failures.length?failures[0]:'所有文件已保存';status(failures.length?`成功 ${succeeded} 个，失败 ${failures.length} 个\n${failures.join('\n')}`:`已上传 ${files.length} 个文件`);await load();}
}
$('files').onchange=()=>uploadSelection($('files'));$('folders').onchange=()=>uploadSelection($('folders'),true);
window.addEventListener('beforeunload',event=>{if(uploading){event.preventDefault();event.returnValue='';}});
let appRequest=0;
function hideAppPassword(){++appRequest;$('app-secret').value='';$('app-secret').hidden=true;$('view-app').textContent='查看应用密码';}
function showAppPassword(password){$('app-secret').value=password;$('app-secret').hidden=false;$('view-app').textContent='隐藏应用密码';}
async function readAppPassword(){const result=await api('view-app-password');if(!result.password)throw Error(result.configured?'旧应用密码仅保存了校验值，无法还原；重新生成一次后即可随时查看。':'尚未生成应用密码');return result.password;}
$('view-app').onclick=async()=>{if(!$('app-secret').hidden){hideAppPassword();return;}const serial=++appRequest;try{const password=await readAppPassword();if(serial===appRequest&&settings.open){showAppPassword(password);$('app-result').textContent='';}}catch(e){if(serial===appRequest&&settings.open)$('app-result').textContent=e.message;}};
$('copy-app').onclick=async()=>{const serial=++appRequest;try{const password=await readAppPassword();if(serial!==appRequest||!settings.open)return;try{await navigator.clipboard.writeText(password);if(serial===appRequest&&settings.open)$('app-result').textContent='应用密码已复制';}catch{if(serial===appRequest&&settings.open){showAppPassword(password);$('app-secret').select();$('app-result').textContent='请手动复制应用密码';}}}catch(e){if(serial===appRequest&&settings.open)$('app-result').textContent=e.message;}};
$('app-password').onclick=async()=>{if(!await ask({title:'生成应用密码',message:'生成后，旧应用密码会立即失效。',confirm:'生成'}))return;hideAppPassword();const serial=appRequest;try{const result=await api('app-password');if(serial===appRequest&&settings.open){showAppPassword(result.password);$('app-result').textContent='应用密码已生成，可随时回来查看';}}catch(e){if(serial===appRequest&&settings.open)$('app-result').textContent=e.message;}};
$('revoke-app').onclick=async()=>{if(!await ask({title:'撤销应用密码',message:'使用该密码的客户端将无法继续连接。',confirm:'撤销',danger:true}))return;hideAppPassword();const serial=appRequest;try{await api('revoke-app-password');if(serial===appRequest&&settings.open)$('app-result').textContent='应用密码已撤销';}catch(e){if(serial===appRequest&&settings.open)$('app-result').textContent=e.message;}};
function renderBackups(info){$('backup-keep').value=info.keep;const list=$('update-backups');list.replaceChildren();for(const backup of info.backups){const row=document.createElement('div');row.className='storage-actions';const label=document.createElement('span');label.className='info';label.textContent=`${backup.version} · ${new Date(backup.created_at*1000).toLocaleString()}`;const restore=document.createElement('button');restore.type='button';restore.textContent='回退此版本';restore.disabled=updateBusy||!canRestore;restore.onclick=()=>restoreBackup(backup);row.append(label,restore);list.append(row);}if(!info.backups.length){const empty=document.createElement('p');empty.className='info';empty.textContent='暂无程序备份，更新或回退前会自动备份当前版本。';list.append(empty);}}
let canRestore=false;
async function loadUpdates(){const info=await api('update-info');canRestore=info.can_update;$('update-current').textContent=info.current;renderBackups(info);if(!info.can_update)$('update-result').textContent=info.requirements.join('；')+'，请使用发布包手动更新';}
function setUpdateBusy(busy){updateBusy=busy;$('update-check').disabled=busy;$('update-install').disabled=busy||!updateVersion||!canRestore;$('backup-save').disabled=busy;for(const button of $('update-backups').querySelectorAll('button'))button.disabled=busy||!canRestore;}
async function restoreBackup(backup){if(updateBusy||!await ask({title:`回退到 ${backup.version}`,message:'当前程序会先备份，账号和用户文件保留。请先暂停其他客户端的传输。',confirm:'回退'}))return;setUpdateBusy(true);$('update-result').textContent='正在备份当前程序并回退…';try{await api('update-restore',{id:backup.id});location.reload();}catch(e){$('update-result').textContent=e.message+'；如连接中断，请刷新页面检查当前版本';setUpdateBusy(false);}}
$('backup-form').onsubmit=async event=>{event.preventDefault();if(updateBusy)return;const keep=Number($('backup-keep').value);if(!Number.isInteger(keep)||keep<1||keep>10){$('update-result').textContent='请输入 1–10 的整数';return;}setUpdateBusy(true);try{const info=await api('update-backup-limit',{keep});renderBackups(info);$('update-result').textContent=`已设置保留最近 ${keep} 份备份，超出部分已清理`;}catch(e){$('update-result').textContent=e.message;}finally{setUpdateBusy(false);}};
let updateVersion=null,updateBusy=false;
$('update-check').onclick=async()=>{if(updateBusy)return;updateVersion=null;setUpdateBusy(true);$('update-result').textContent='正在检查 GitHub 最新版本…';try{const info=await api('update-check');canRestore=info.can_update;updateVersion=info.available?info.latest:null;$('update-result').textContent=(info.available?`发现新版本 ${info.latest}`:'当前已是最新版本')+(info.can_update?'':`\n${info.requirements.join('；')}，请下载发布包手动更新`);$('update-install').disabled=!info.available||!info.can_update;}catch(e){$('update-result').textContent=e.message;}finally{setUpdateBusy(false);}};
$('update-install').onclick=async()=>{if(updateBusy||!updateVersion)return;if(!await ask({title:`更新到 ${updateVersion}`,message:'程序文件将替换并备份，账号和用户文件保留。请先暂停其他客户端的传输。',confirm:'更新'}))return;setUpdateBusy(true);$('update-result').textContent='正在下载、校验并安装更新，请保持页面打开…';try{const result=await api('update-install',{version:updateVersion});$('update-result').textContent=`已更新到 ${result.version}，正在刷新页面…`;location.reload();}catch(e){$('update-result').textContent=e.message+'；如连接中断，请刷新页面检查当前版本';setUpdateBusy(false);}};
$('password-form').onsubmit=async event=>{event.preventDefault();const form=event.currentTarget;try{await api('password',Object.fromEntries(new FormData(form)));form.reset();$('password-result').textContent='密码已修改，其他网页登录会话已失效';}catch(e){$('password-result').textContent=e.message;}};
load();
</script>
<?php endif ?>
<footer><span>轻 DAV · 私人文件空间</span><span class="footer-note">simple files, simply yours.</span></footer>
</main>
</body>
</html>
