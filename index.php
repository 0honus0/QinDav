<?php
declare(strict_types=1);

// Application logic lives here; dav.php is the dedicated WebDAV entry point.
// Runtime state and uploaded files live outside the web root.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('zlib.output_compression', '0');
umask(0077);
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
    $tmp = tempnam(dirname($path), '.state-');
    if ($tmp === false) throw new RuntimeException('Cannot allocate state file');
    try {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json) !== strlen($json) || !rename($tmp, $path)) {
            throw new RuntimeException('Cannot save state');
        }
    } finally {
        if (is_file($tmp)) unlink($tmp);
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
    if (($cached['expires'] ?? 0) > time()) return true;
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
    if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock storage accounting');
    try {
        clearstatcache();
        $usage = readJson($path);
        if (!$usage || !empty($usage['dirty'])) {
            set_time_limit(0);
            $usage = array_replace(['limit_bytes' => 0, 'reservations' => []], $usage, scanStorage(), ['dirty' => false]);
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
                $usage = array_replace($usage, scanStorage(), ['dirty' => false]);
                atomicJson($path, $usage);
            }
            throw $error;
        }
    } finally {
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

function storageInfo(bool $rescan = false): array
{
    return storageTransaction(function (&$usage) use ($rescan) {
        if ($rescan) $usage = array_replace($usage, scanStorage());
        $reserved = storageReserved($usage);
        return ['used_bytes' => $usage['used_bytes'], 'files' => $usage['files'],
            'limit_bytes' => $usage['limit_bytes'], 'reserved_bytes' => $reserved,
            'available_bytes' => $usage['limit_bytes'] ? max(0, $usage['limit_bytes'] - $usage['used_bytes'] - $reserved) : null,
            'disk_free_bytes' => disk_free_space(stateDir() . '/files')];
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
    $output = fopen($tmp, 'xb');
    if ($output === false) throw new DAV\Exception\InsufficientStorage('Cannot create upload');
    $id = basename($tmp);
    try {
        if ($data === null) $data = '';
        $expected = is_resource($data) ? null : strlen((string) $data);
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'PUT' && isset($_SERVER['CONTENT_LENGTH'])) $expected = (int) $_SERVER['CONTENT_LENGTH'];
        elseif (is_resource($data) && (stream_get_meta_data($data)['stream_type'] ?? '') === 'STDIO') {
            $stat = fstat($data);
            if ($stat !== false) $expected = max(0, $stat['size'] - ftell($data));
        }
        $maximum = reserveStorage($id, $expected, $destination);
        $bytes = is_resource($data)
            ? ($maximum === null ? stream_copy_to_stream($data, $output) : stream_copy_to_stream($data, $output, $maximum + 1))
            : fwrite($output, (string) $data);
        if ($maximum !== null && $bytes > $maximum) throw new DAV\Exception\InsufficientStorage('容量上限不足');
        if ($bytes === false || !fflush($output)) throw new DAV\Exception\InsufficientStorage('Upload write failed');
        if (is_resource($data) && !feof($data)) throw new DAV\Exception\BadRequest('Incomplete upload');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'PUT' && isset($_SERVER['CONTENT_LENGTH'])
            && $bytes !== (int) $_SERVER['CONTENT_LENGTH']) throw new DAV\Exception\BadRequest('Incomplete upload');
        fclose($output);
        $output = null;
        publishStorage($id, $destination, $bytes, function () use ($tmp, $destination, $createOnly) {
            if (is_link($destination) || is_dir($destination)) throw new DAV\Exception\Forbidden('Invalid destination');
            // Every application publication shares the accounting lock, including create-only PUT/COPY.
            if ($createOnly && file_exists($destination)) throw new DAV\Exception\PreconditionFailed('Destination already exists');
            if (!rename($tmp, $destination)) throw new DAV\Exception\InsufficientStorage('Cannot commit upload');
        });
        clearstatcache(true, $destination);
        return (new FastFile($destination))->getETag();
    } finally {
        if (is_resource($output)) fclose($output);
        if (is_file($tmp)) unlink($tmp);
        releaseStorage($id);
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
        if ((string) $request->getHeader('Authorization') !== '') return parent::check($request, $response);
        if (!isset($_COOKIE['single_dav'])) return [false, 'Authentication required'];
        openSession();
        $valid = sessionUser($this->cfg);
        $csrf = in_array($request->getMethod(), SAFE_METHODS, true) || csrfValid((string) $request->getHeader('X-CSRF-Token'));
        session_write_close(); // Release the lock before streaming or traversing directories.
        if ($valid && !$csrf) throw new DAV\Exception\Forbidden('CSRF token required');
        return $valid ? [true, 'principals/' . $this->cfg['username']] : [false, 'Session expired'];
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

function handleApi(array $cfg, string $action): never
{
    openSession();
    $authenticated = sessionUser($cfg);
    $csrf = csrfValid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sessionEpoch = $_SESSION['epoch'] ?? '';
    session_write_close();
    if (!$authenticated) jsonResponse(['error' => '请先登录'], 401);
    if ($action === 'storage' && $_SERVER['REQUEST_METHOD'] === 'GET') jsonResponse(storageInfo());
    if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $path = trim((string) ($_GET['path'] ?? ''), '/');
        $node = new FastDirectory(stateDir() . '/files', 'root');
        foreach ($path === '' ? [] : explode('/', $path) as $part) {
            if (!$node instanceof FastDirectory) throw new DAV\Exception\NotFound('目录不存在');
            $node = $node->getChild($part);
        }
        if (!$node instanceof FastDirectory) throw new DAV\Exception\NotFound('目录不存在');
        $offset = max(0, min(10000000, (int) ($_GET['offset'] ?? 0)));
        $limit = 200;
        $items = [];
        $position = 0;
        $more = false;
        foreach ($node->getChildren() as $child) {
            if ($position++ < $offset) continue;
            if (count($items) === $limit) { $more = true; break; }
            $items[] = ['name' => $child->getName(), 'directory' => $child instanceof FastDirectory,
                'size' => $child instanceof FastFile ? $child->getSize() : null, 'modified' => $child->getLastModified()];
        }
        $storage = storageInfo();
        jsonResponse(['items' => $items, 'more' => $more, 'offset' => $offset, 'free' => $storage['disk_free_bytes'], 'storage' => $storage]);
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
    $cfg = config();
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $davBase = '/dav.php/';
    if (defined('QINGDAV_DAV_ENTRY')) {
        if ($uri !== '/dav.php' && !str_starts_with($uri, $davBase)) {
            http_response_code(404);
            exit('Not found');
        }
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
        500 => '服务器错误，请检查日志', 423 => '目标路径已被 WebDAV 客户端锁定', default => $e->getMessage(),
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
</style>
<main>
<header><div class="brand"><i class="brand-mark" aria-hidden="true"></i>轻<span>DAV</span><small>文件索引</small></div><div class="account"><?php if ($loggedIn): ?><span class="info"><?= html($cfg['username']) ?></span><form method="post"><input type="hidden" name="csrf" value="<?= html($csrfToken) ?>"><input type="hidden" name="action" value="logout"><button class="quiet" id="settings-button" type="button" aria-haspopup="dialog" aria-controls="settings" aria-expanded="false">设置</button><button class="quiet">退出</button></form><?php endif ?></div></header>
<?php if (!$loggedIn): ?>
<section class="card auth"><h1><?= $cfg ? '欢迎回来' : '创建你的文件空间' ?></h1><p><?= $cfg ? '登录后管理文件和连接凭据。' : '仅创建一个管理员账号，完成后自动关闭注册。' ?></p>
<?php if ($error): ?><div class="error" role="alert"><?= html($error) ?></div><?php endif ?>
<form method="post"><input type="hidden" name="csrf" value="<?= html($csrfToken) ?>"><input type="hidden" name="action" value="<?= $cfg ? 'login' : 'setup' ?>"><label class="label" for="username">用户名</label><input id="username" name="username" autocomplete="username" maxlength="64" required><label class="label" for="password">密码<?= $cfg ? '' : ' · 至少 12 字节' ?></label><input id="password" name="password" type="password" autocomplete="<?= $cfg ? 'current-password' : 'new-password' ?>" maxlength="72" required><?php if (!$cfg): ?><label class="label" for="confirm">确认密码</label><input id="confirm" name="confirm" type="password" autocomplete="new-password" required><?php if (getenv('WEBDAV_SETUP_TOKEN')): ?><label class="label" for="setup_token">初始化令牌</label><input id="setup_token" name="setup_token" type="password" required><?php endif ?><?php endif ?><button class="primary"><?= $cfg ? '登录' : '创建账号' ?></button></form></section>
<?php else: ?>
<section class="file-browser" aria-label="文件浏览">
<div class="file-heading"><h1>文件</h1><div class="tools"><button class="primary" id="upload-button">上传文件</button><button id="mkdir">新建文件夹</button><button class="quiet" id="refresh" title="刷新文件列表">刷新</button><input type="file" id="files" multiple hidden></div></div>
<div class="browser-bar"><nav class="crumb" id="breadcrumb" aria-label="当前路径"></nav><label class="filter"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg><input id="filter" type="search" placeholder="筛选当前页" aria-label="筛选当前页文件"></label></div>
<div class="status" id="status" role="status" aria-live="polite"></div><progress id="progress" value="0" max="1" hidden></progress>
<div class="table-wrap"><table><thead><tr><th scope="col"><button id="sort-name">名称 ↑</button></th><th scope="col"><button id="sort-size">大小</button></th><th scope="col" class="modified"><button id="sort-modified">修改时间</button></th><th scope="col" class="actions">操作</th></tr></thead><tbody id="rows"></tbody></table></div>
<div class="pager"><span class="info" id="capacity"></span><div><button id="prev">上一页</button><button id="next">下一页</button></div></div>
</section>
<dialog class="settings" id="settings" aria-labelledby="settings-title"><div class="dialog-heading"><h2 id="settings-title">连接与账号设置</h2><button class="quiet" id="settings-close" type="button" aria-label="关闭设置" autofocus>关闭</button></div><div class="settings-content"><div class="connection"><div><span class="info">WEBDAV 连接地址</span><p><code id="endpoint"></code></p><span class="info">用户名：<?= html($cfg['username']) ?> · rclone 类型：other</span></div><button id="copy-url">复制地址</button></div><section class="storage-panel" aria-labelledby="storage-title"><h3 id="storage-title">存储空间</h3><div class="storage-summary"><span><strong class="storage-used" id="storage-used">—</strong> 已用</span><span class="info" id="storage-details"></span></div><meter class="storage-meter" id="storage-meter" min="0" max="1" value="0" aria-label="容量使用比例"></meter><p class="info" id="storage-remaining"></p><form id="quota-form"><div class="quota-row"><label><span class="label">容量上限 · 0 表示不限</span><input id="quota-limit" type="number" min="0" step="any" value="0" required></label><select id="quota-unit" aria-label="容量单位"><option value="1073741824">GiB</option><option value="1099511627776">TiB</option><option value="1048576">MiB</option></select><button class="primary" id="quota-save">保存上限</button></div></form><div class="storage-actions"><span class="info">上传会预留空间，删除后释放用量。</span><button id="storage-rescan" type="button">重新统计</button></div><div class="status" id="storage-result" role="status" aria-live="polite"></div></section><p class="info">应用密码用于 WebDAV 客户端，创建新密码会替换旧密码。可随时查看或复制。</p><div class="tools"><button id="view-app">查看应用密码</button><button id="copy-app">复制应用密码</button><button id="app-password">生成应用密码</button><button id="revoke-app">撤销应用密码</button></div><input id="app-secret" type="text" aria-label="应用密码" readonly autocomplete="off" spellcheck="false" hidden><div class="status" id="app-result" role="status" aria-live="polite"></div><form id="password-form"><div class="password-grid"><label><span class="label">当前密码</span><input name="current" type="password" autocomplete="current-password" required></label><label><span class="label">新密码 · 至少 12 字节</span><input name="password" type="password" autocomplete="new-password" required></label><button>修改密码</button></div><div class="status" id="password-result" role="status"></div></form></div></dialog>
<script nonce="<?= html($nonce) ?>">
'use strict';
const csrf = <?= json_encode($csrfToken) ?>, dav = <?= json_encode($davBase, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const $ = id => document.getElementById(id);
let path = '', offset = 0, hasMore = false, loading = false, entries = [], freeSpace = 0, sortKey = 'name', sortDirection = 1, storage = null;
const collator=new Intl.Collator('zh-CN',{numeric:true,sensitivity:'base'});
const endpoint = new URL(dav, location.origin).href;
$('endpoint').textContent = endpoint;
function url(p) { return dav + p.split('/').filter(Boolean).map(encodeURIComponent).join('/'); }
function size(n) { if(n == null) return '—'; const units=['B','KiB','MiB','GiB','TiB']; let i=0; while(n>=1024&&i<4){n/=1024;i++}return `${n.toFixed(i?1:0)} ${units[i]}`; }
function status(message) { $('status').textContent = message; }
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
async function uploadPart(id, part, blob) {
 for(let attempt=0;;attempt++){
  try{
   const response=await fetch(`/?api=upload-part&id=${encodeURIComponent(id)}&part=${part}`,{method:'PUT',body:blob,credentials:'same-origin',redirect:'error',headers:{'X-CSRF-Token':csrf}});
   const result=await response.json();
   if(!response.ok){const error=Error(result.error||`上传失败（${response.status}）`);error.retryable=[408,429,500,502,503,504].includes(response.status);throw error;}
   if(result.ok!==true)throw Error('上传响应无效，请检查主机防护');
   return;
  }catch(error){
   if(attempt>=2||(!(error instanceof TypeError)&&!error.retryable))throw error;
   await new Promise(resolve=>setTimeout(resolve,500*2**attempt));
  }
 }
}
async function uploadFile(file, destination, advance) {
 if(file.size<32*1024*1024){await transfer(()=>request(destination,{method:'PUT',body:file,headers:{'If-None-Match':'*'}}));advance(file.size);return;}
 const job=await api('upload-start',{path:destination,size:file.size});let next=0,failure=null;
 try{
  async function worker(){while(!failure&&next<job.chunks){const part=next++,start=part*job.chunk_size,blob=file.slice(start,Math.min(file.size,start+job.chunk_size));try{await transfer(()=>uploadPart(job.id,part,blob));advance(blob.size);}catch(error){failure=error;}}}
  await Promise.all(Array.from({length:Math.min(4,job.chunks)},worker));
  if(failure)throw failure;
  await api('upload-finish',{id:job.id});
 }catch(error){await api('upload-cancel',{id:job.id}).catch(()=>{});throw error;}
}
function button(label, fn, className='') { const b=document.createElement('button');b.textContent=label;b.className=className;b.onclick=()=>Promise.resolve().then(fn).catch(e=>status(e.message));return b; }
function icon(kind, className='icon') {
 const paths={folder:'<path d="M3 7h7l2 2h9v11H3z" fill="currentColor" opacity=".2"/><path d="M3 7V5h7l2 2h9v13H3z"/>',file:'<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v5h4M9 12h6M9 15h6"/>',image:'<rect x="4" y="3" width="16" height="18" rx="1"/><circle cx="9" cy="8" r="1.5"/><path d="m5 18 5-6 4 4 3-3 3 5"/>',archive:'<path d="M6 3h12v18H6zM11 3v12M11 7h3M11 11h3"/><path d="M10 16h4v3h-4z"/>',code:'<path d="M6 3h8l4 4v14H6zM14 3v5h4M10 12l-2 3 2 3M14 12l2 3-2 3"/>',rename:'<path d="m4 16 11-11 4 4L8 20H4zM13 7l4 4"/>',delete:'<path d="M4 6h16M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7M14 10v7"/>',up:'<path d="M12 20V4m-6 6 6-6 6 6"/>'};
 const span=document.createElement('span');span.className=className;span.setAttribute('aria-hidden','true');
 span.innerHTML=`<svg viewBox="0 0 24 24" width="100%" height="100%" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round">${paths[kind]||paths.file}</svg>`;return span;
}
function fileIcon(item){if(item.directory)return icon('folder','file-icon folder');const ext=item.name.split('.').pop().toLowerCase();const type=/^(png|jpg|jpeg|gif|webp|svg|avif|bmp)$/.test(ext)?'image':/^(zip|gz|tar|7z|rar|bz2|xz)$/.test(ext)?'archive':/^(php|js|ts|py|html|css|json|xml|sh|c|cpp|rs)$/.test(ext)?'code':'file';return icon(type,`file-icon ${type}`);}
function actionButton(label,fn,kind,className=''){const b=button(label,fn,className);b.replaceChildren(icon(kind));b.setAttribute('aria-label',label);b.title=label;return b;}
function navigate(p) { if(loading)return;path=p;offset=0;$('filter').value='';load(); }
function renderItems(){
 const query=$('filter').value.trim().toLocaleLowerCase();const items=entries.filter(item=>item.name.toLocaleLowerCase().includes(query));
 items.sort((a,b)=>{if(a.directory!==b.directory)return a.directory?-1:1;const value=sortKey==='name'?collator.compare(a.name,b.name):(a[sortKey]||0)-(b[sortKey]||0);return (value||collator.compare(a.name,b.name))*sortDirection;});
 $('rows').replaceChildren();
 if(path&&!query){const row=document.createElement('tr'),cell=document.createElement('td');cell.colSpan=4;const name=button('..',()=>navigate(path.split('/').slice(0,-1).join('/')),'name dir');name.prepend(icon('up','file-icon'));name.setAttribute('aria-label','返回上级目录');cell.append(name);row.append(cell);$('rows').append(row);}
 for(const item of items){
  const p=(path?path+'/':'')+item.name,row=document.createElement('tr'),name=document.createElement('td'),bytes=document.createElement('td'),date=document.createElement('td'),actions=document.createElement('td');
  const filename=button('',()=>item.directory?navigate(p):location.assign(url(p)),`name ${item.directory?'dir':''}`),label=document.createElement('span');label.className='filename';label.textContent=item.name;filename.title=item.name;filename.append(fileIcon(item),label);name.append(filename);
  bytes.textContent=item.directory?'—':size(item.size);date.textContent=new Date(item.modified*1000).toLocaleString();date.className='modified';actions.className='actions';const tools=document.createElement('div');tools.className='row-actions';
  tools.append(actionButton('移动 / 重命名',async()=>{const destination=prompt('目标路径（从根目录开始，不加开头的 /）',p);if(destination===null||destination===p)return;if(!destination||destination.split('/').some(n=>!n||n==='.'||n==='..'))throw Error('请输入有效目标路径');await request(p,{method:'MOVE',headers:{Destination:new URL(url(destination),location.origin).href,Overwrite:'F'}});status('移动完成');await load();},'rename'),actionButton('删除',async()=>{if(!confirm(`删除“${item.name}”${item.directory?'及其全部内容':''}？`))return;await request(p,{method:'DELETE'});status('已删除');await load();},'delete','delete'));
  actions.append(tools);row.append(name,bytes,date,actions);$('rows').append(row);
 }
 if(!items.length){const row=document.createElement('tr'),cell=document.createElement('td');cell.colSpan=4;cell.className='empty';cell.textContent=query?'没有匹配的文件':offset?'本页没有文件，请返回上一页':'此目录为空，点击右上角上传文件。';row.append(cell);$('rows').append(row);}
 $('capacity').textContent=`已用 ${size(storage?.used_bytes||0)}${storage?.limit_bytes?` / ${size(storage.limit_bytes)}`:''} · ${query?`匹配 ${items.length} / ${entries.length} 项`:`本页 ${entries.length} 项`} · 可用 ${size(freeSpace)}`;
 for(const [key,label] of Object.entries({name:'名称',size:'大小',modified:'修改时间'})){const b=$('sort-'+key);b.textContent=label+(sortKey===key?(sortDirection===1?' ↑':' ↓'):'');b.parentElement.setAttribute('aria-sort',sortKey===key?(sortDirection===1?'ascending':'descending'):'none');if(sortKey===key)b.setAttribute('data-sort-direction',String(sortDirection));else b.removeAttribute('data-sort-direction');}
}
async function load() {
 if(loading)return; loading=true; $('prev').disabled=$('next').disabled=true;
 try {
 const response=await fetch(`/?api=list&path=${encodeURIComponent(path)}&offset=${offset}`,{credentials:'same-origin',cache:'no-store'});
 const data=await response.json();if(!response.ok)throw Error(data.error||'读取失败');
 $('breadcrumb').replaceChildren(button('全部文件',()=>navigate('')));
 let partial='';for(const part of path.split('/').filter(Boolean)){partial+=(partial?'/':'')+part;const destination=partial,separator=document.createElement('span');separator.className='crumb-separator';separator.textContent='/';$('breadcrumb').append(separator,button(part,()=>navigate(destination)));}
 entries=data.items;freeSpace=data.free;storage=data.storage;hasMore=data.more;renderItems();
 }catch(e){status(e.message);}finally{loading=false;$('prev').disabled=offset===0;$('next').disabled=!hasMore;}
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
async function loadStorage(){const request=++storageRequest;const response=await fetch('/?api=storage',{credentials:'same-origin',cache:'no-store'});const info=await response.json();if(!response.ok)throw Error(info.error||'读取用量失败');if(request===storageRequest)renderStorage(info);}
$('settings-button').onclick=()=>{quotaEdited=false;settings.showModal();document.body.classList.add('modal-open');$('settings-button').setAttribute('aria-expanded','true');if(storage)renderStorage(storage);loadStorage().catch(error=>$('storage-result').textContent=error.message);};
$('quota-form').onsubmit=async event=>{event.preventDefault();const bytes=Math.round(Number($('quota-limit').value)*Number($('quota-unit').value));if(!Number.isSafeInteger(bytes)||bytes<0){$('storage-result').textContent='请输入有效容量';return;}$('quota-save').disabled=true;++storageRequest;try{const info=await api('storage-limit',{limit_bytes:bytes});quotaEdited=false;renderStorage(info);$('storage-result').textContent='容量上限已保存';await load();}catch(error){$('storage-result').textContent=error.message;}finally{$('quota-save').disabled=false;}};
$('storage-rescan').onclick=async()=>{$('storage-rescan').disabled=true;++storageRequest;$('storage-result').textContent='正在统计…';try{renderStorage(await api('storage-rescan'));$('storage-result').textContent='用量统计已更新';await load();}catch(error){$('storage-result').textContent=error.message;}finally{$('storage-rescan').disabled=false;}};
function closeSettings(){hideAppPassword();settings.close();}
$('settings-close').onclick=closeSettings;
settings.addEventListener('cancel',hideAppPassword);
settings.addEventListener('close',()=>{hideAppPassword();$('app-result').textContent='';document.body.classList.remove('modal-open');$('settings-button').setAttribute('aria-expanded','false');});
settings.addEventListener('click',event=>{const rect=settings.getBoundingClientRect();if(event.target===settings&&(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom))closeSettings();});
$('copy-url').onclick=async()=>{try{await navigator.clipboard.writeText(endpoint);status('连接地址已复制');}catch{status(endpoint);}};
$('refresh').onclick=()=>load();$('prev').onclick=()=>{if(!loading){offset=Math.max(0,offset-200);load();}};$('next').onclick=()=>{if(!loading&&hasMore){offset+=200;load();}};
$('mkdir').onclick=async()=>{const name=prompt('文件夹名称');if(!name)return;if(name.includes('/')||name.includes('\\')||name==='.'||name==='..'){status('请输入有效的文件夹名称');return;}try{await request((path?path+'/':'')+name,{method:'MKCOL'});status('文件夹已创建');await load();}catch(e){status(e.message);}};
$('upload-button').onclick=()=>$('files').click();
$('files').onchange=async()=>{
 const files=Array.from($('files').files);if(!files.length)return;
 const uploadPath=path,failures=[];let index=0,done=0,bytes=0;const total=files.reduce((n,file)=>n+file.size,0),started=performance.now();
 $('upload-button').disabled=true;$('progress').hidden=false;$('progress').max=Math.max(1,total);$('progress').value=0;
 function advance(n){bytes+=n;$('progress').value=bytes;status(`已传输 ${size(bytes)} / ${size(total)} · ${size(bytes/Math.max(.001,(performance.now()-started)/1000))}/s · 已处理 ${done} / ${files.length} 个文件`);}
 async function worker(){while(index<files.length){const file=files[index++];try{await uploadFile(file,(uploadPath?uploadPath+'/':'')+file.name,advance);}catch(e){failures.push(`${file.name}：${e.message}`);}done++;advance(0);}}
 await Promise.all(Array.from({length:Math.min(4,files.length)},worker));
 $('upload-button').disabled=false;$('files').value='';$('progress').hidden=true;status(failures.length?`成功 ${files.length-failures.length} 个，失败 ${failures.length} 个\n${failures.join('\n')}`:`已上传 ${files.length} 个文件`);await load();
};
let appRequest=0;
function hideAppPassword(){++appRequest;$('app-secret').value='';$('app-secret').hidden=true;$('view-app').textContent='查看应用密码';}
function showAppPassword(password){$('app-secret').value=password;$('app-secret').hidden=false;$('view-app').textContent='隐藏应用密码';}
async function readAppPassword(){const result=await api('view-app-password');if(!result.password)throw Error(result.configured?'旧应用密码仅保存了校验值，无法还原；重新生成一次后即可随时查看。':'尚未生成应用密码');return result.password;}
$('view-app').onclick=async()=>{if(!$('app-secret').hidden){hideAppPassword();return;}const serial=++appRequest;try{const password=await readAppPassword();if(serial===appRequest&&settings.open){showAppPassword(password);$('app-result').textContent='';}}catch(e){if(serial===appRequest&&settings.open)$('app-result').textContent=e.message;}};
$('copy-app').onclick=async()=>{const serial=++appRequest;try{const password=await readAppPassword();if(serial!==appRequest||!settings.open)return;try{await navigator.clipboard.writeText(password);if(serial===appRequest&&settings.open)$('app-result').textContent='应用密码已复制';}catch{if(serial===appRequest&&settings.open){showAppPassword(password);$('app-secret').select();$('app-result').textContent='请手动复制应用密码';}}}catch(e){if(serial===appRequest&&settings.open)$('app-result').textContent=e.message;}};
$('app-password').onclick=async()=>{if(!confirm('生成新应用密码后，旧应用密码会立即失效。继续？'))return;hideAppPassword();const serial=appRequest;try{const result=await api('app-password');if(serial===appRequest&&settings.open){showAppPassword(result.password);$('app-result').textContent='应用密码已生成，可随时回来查看';}}catch(e){if(serial===appRequest&&settings.open)$('app-result').textContent=e.message;}};
$('revoke-app').onclick=async()=>{if(!confirm('撤销应用密码后，使用该密码的客户端将无法连接。继续？'))return;hideAppPassword();const serial=appRequest;try{await api('revoke-app-password');if(serial===appRequest&&settings.open)$('app-result').textContent='应用密码已撤销';}catch(e){if(serial===appRequest&&settings.open)$('app-result').textContent=e.message;}};
$('password-form').onsubmit=async event=>{event.preventDefault();const form=event.currentTarget;try{await api('password',Object.fromEntries(new FormData(form)));form.reset();$('password-result').textContent='密码已修改，其他网页登录会话已失效';}catch(e){$('password-result').textContent=e.message;}};
load();
</script>
<?php endif ?>
<footer><span>轻 DAV · 私人文件空间</span><span class="footer-note">simple files, simply yours.</span></footer>
</main>
</html>
