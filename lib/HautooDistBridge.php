<?php
declare(strict_types=1);

final class HautooDistBridgeException extends RuntimeException
{
}

/**
 * Pull a fixed ZIP URL and swap into a webroot (hautoo dist-bridge).
 */
final class HautooDistBridge
{
    public const VERSION = '1.2.0';
    public const ZIP_NAME = 'dist-bridge.zip';

    public static function repoRoot(): string
    {
        return dirname(__DIR__);
    }

    public static function configDir(): string
    {
        return self::repoRoot() . '/config';
    }

    public static function backupRoot(): string
    {
        return self::repoRoot() . '/backup';
    }

    /** Per-project ZIP archive directory: backup/<project>/ */
    public static function backupDir(string $project): string
    {
        return self::backupRoot() . '/' . self::normalizeProjectId($project);
    }

    /** @return list<string> project ids (filename without .local.php) */
    public static function listProjects(): array
    {
        $dir = self::configDir();
        if (!is_dir($dir)) {
            return [];
        }
        $ids = [];
        foreach (scandir($dir) ?: [] as $f) {
            if (preg_match('/^(.+)\.local\.php$/', $f, $m)) {
                $ids[] = $m[1];
            }
        }
        sort($ids);
        return $ids;
    }

    public static function configPath(string $project): string
    {
        $project = self::normalizeProjectId($project);
        return self::configDir() . '/' . $project . '.local.php';
    }

    public static function normalizeProjectId(string $project): string
    {
        $project = trim($project);
        $project = preg_replace('/\.local\.php$/i', '', $project) ?? $project;
        if ($project === '' || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $project)) {
            throw new HautooDistBridgeException(
                'Invalid project id (use letters, digits, . _ -), e.g. beispiel.de'
            );
        }
        return $project;
    }

    /**
     * If URL has no .zip suffix, append /dist-bridge.zip.
     */
    public static function resolveZipUrl(string $urlOrBase): string
    {
        $urlOrBase = trim($urlOrBase);
        $urlOrBase = rtrim($urlOrBase, '/');
        if ($urlOrBase === '') {
            return '';
        }
        if (preg_match('/\.zip(\?.*)?$/i', $urlOrBase)) {
            return $urlOrBase;
        }
        return $urlOrBase . '/' . self::ZIP_NAME;
    }

    public static function log(string $msg): void
    {
        fwrite(STDERR, '[hdb] ' . $msg . PHP_EOL);
    }

    /**
     * @return array{zipUrl:string,targetDir:string,token:string,keepBackups:int,workDir:?string,project:?string}
     */
    public static function loadConfig(?string $project = null): array
    {
        $file = [];
        $projectId = null;

        if ($project !== null && $project !== '') {
            $projectId = self::normalizeProjectId($project);
            $path = self::configPath($projectId);
            if (!is_file($path)) {
                throw new HautooDistBridgeException(
                    "Config not found: {$path}\nCreate with: ./hdb.sh init"
                );
            }
            $loaded = require $path;
            if (!is_array($loaded)) {
                throw new HautooDistBridgeException('Config must return an array: ' . $path);
            }
            $file = $loaded;
        } else {
            // Legacy single-file fallback
            $legacy = self::repoRoot() . '/config.local.php';
            if (is_file($legacy)) {
                $loaded = require $legacy;
                if (is_array($loaded)) {
                    $file = $loaded;
                }
            }
        }

        $zipUrl = self::envOr('DIST_BRIDGE_ZIP_URL', $file['zipUrl'] ?? '');
        $zipUrl = self::resolveZipUrl((string) $zipUrl);
        $targetDir = self::envOr('DIST_BRIDGE_TARGET_DIR', $file['targetDir'] ?? '');
        $token = self::envOr('DIST_BRIDGE_TOKEN', $file['token'] ?? '');
        $workDirEnv = getenv('DIST_BRIDGE_WORKDIR');

        $keepBackups = self::resolveKeepBackups($file);

        $cfg = [
            'zipUrl' => $zipUrl,
            'targetDir' => (string) $targetDir,
            'token' => (string) $token,
            'keepBackups' => $keepBackups,
            'workDir' => null,
            'project' => $projectId,
        ];

        $wd = ($workDirEnv !== false && $workDirEnv !== '')
            ? $workDirEnv
            : ($file['workDir'] ?? null);
        if (is_string($wd) && $wd !== '') {
            $cfg['workDir'] = $wd;
        }

        if ($cfg['zipUrl'] === '' || $cfg['targetDir'] === '') {
            throw new HautooDistBridgeException(
                'Missing zipUrl / targetDir in config (or DIST_BRIDGE_ZIP_URL / DIST_BRIDGE_TARGET_DIR)'
            );
        }

        if (!preg_match('#^https://#i', $cfg['zipUrl'])) {
            throw new HautooDistBridgeException('zipUrl must be https:// (got: ' . $cfg['zipUrl'] . ')');
        }

        if (realpath(dirname($cfg['targetDir'])) === false) {
            throw new HautooDistBridgeException('Parent of targetDir does not exist: ' . $cfg['targetDir']);
        }
        $cfg['targetDir'] = rtrim($cfg['targetDir'], "/\\");

        return $cfg;
    }

    /** @param array<string,mixed> $file */
    private static function resolveKeepBackups(array $file): int
    {
        $env = getenv('DIST_BRIDGE_KEEP_BACKUPS');
        if ($env === false || $env === '') {
            $env = getenv('DIST_BRIDGE_KEEP_BACKUP');
        }
        if ($env !== false && $env !== '') {
            if (in_array(strtolower($env), ['true', 'yes', 'on'], true)) {
                return 1;
            }
            if (in_array(strtolower($env), ['false', 'no', 'off'], true)) {
                return 0;
            }
            return max(0, (int) $env);
        }
        if (array_key_exists('keepBackups', $file)) {
            return max(0, (int) $file['keepBackups']);
        }
        // Legacy bool keepBackup
        if (array_key_exists('keepBackup', $file)) {
            $v = $file['keepBackup'];
            if (is_bool($v)) {
                return $v ? 1 : 0;
            }
            return self::boolish($v, true) ? 1 : 0;
        }
        return 1;
    }

    private static function envOr(string $key, mixed $fallback): string
    {
        $v = getenv($key);
        if ($v !== false && $v !== '') {
            return $v;
        }
        return (string) $fallback;
    }

    private static function boolish(mixed $v, bool $default): bool
    {
        if ($v === null || $v === '') {
            return $default;
        }
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @param array{zipUrl:string,targetDir:string,token:string,keepBackups:int,workDir:?string,project:?string} $cfg */
    public static function pull(array $cfg): void
    {
        $label = $cfg['project'] ?? 'default';
        self::log('version ' . self::VERSION . ' project=' . $label);
        self::log('zip ' . $cfg['zipUrl']);

        $workBase = $cfg['workDir'] ?? sys_get_temp_dir();
        $workBase = rtrim($workBase, "/\\");
        if (!is_dir($workBase) && !mkdir($workBase, 0755, true) && !is_dir($workBase)) {
            throw new HautooDistBridgeException('Cannot create workDir ' . $workBase);
        }

        $runId = 'dist-bridge-' . gmdate('Ymd\THis') . '-' . bin2hex(random_bytes(4));
        $runDir = $workBase . DIRECTORY_SEPARATOR . $runId;
        $zipFile = $runDir . DIRECTORY_SEPARATOR . 'artifact.zip';
        $staging = $runDir . DIRECTORY_SEPARATOR . 'staging';

        if (!mkdir($staging, 0755, true) && !is_dir($staging)) {
            throw new HautooDistBridgeException('Cannot create staging ' . $staging);
        }

        try {
            self::download($cfg['zipUrl'], $zipFile, $cfg['token']);
            $size = filesize($zipFile);
            if ($size === false || $size < 64) {
                throw new HautooDistBridgeException('Downloaded file too small or empty');
            }
            self::log('downloaded ' . $size . ' bytes');

            if ($cfg['keepBackups'] > 0 && $cfg['project'] !== null) {
                self::saveZipBackup($cfg['project'], $zipFile, $cfg['keepBackups']);
            }

            self::log('extract → staging');
            self::extract($zipFile, $staging);
            self::unwrapSingleRootIfNeeded($staging, $runDir);

            if (!is_file($staging . '/index.html')) {
                self::log('warning: no index.html at ZIP root (continuing anyway)');
            }

            self::log('swap into ' . $cfg['targetDir']);
            self::swap($staging, $cfg['targetDir']);
            $staging = '';

            self::log('ok');
        } finally {
            if (is_dir($runDir)) {
                self::log('cleanup ' . $runDir);
                self::rmTree($runDir);
            }
        }
    }

    /**
     * Copy downloaded ZIP to backup/<project>/yyyy-mm-dd--hh-mm-ss--dist-bridge.zip
     * and prune to keepBackups newest files.
     */
    public static function saveZipBackup(string $project, string $zipFile, int $keepBackups): void
    {
        if ($keepBackups < 1) {
            return;
        }
        $dir = self::backupDir($project);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new HautooDistBridgeException('Cannot create backup dir ' . $dir);
        }
        $stamp = date('Y-m-d--H-i-s');
        $dest = $dir . '/' . $stamp . '--' . self::ZIP_NAME;
        $n = 0;
        while (is_file($dest)) {
            $n++;
            $dest = $dir . '/' . $stamp . '--' . sprintf('%02d', $n) . '--' . self::ZIP_NAME;
        }
        if (!copy($zipFile, $dest)) {
            throw new HautooDistBridgeException('Cannot write backup ZIP ' . $dest);
        }
        self::log('backup ZIP ' . $dest);
        self::pruneZipBackups($dir, $keepBackups);
    }

    public static function pruneZipBackups(string $dir, int $keepBackups): void
    {
        $files = self::listBackupZips($dir);
        if (count($files) <= $keepBackups) {
            return;
        }
        $drop = array_slice($files, $keepBackups);
        foreach ($drop as $path) {
            self::log('prune backup ' . $path);
            @unlink($path);
        }
    }

    /** @return list<string> newest first */
    public static function listBackupZips(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        foreach (scandir($dir) ?: [] as $f) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}--\d{2}-\d{2}-\d{2}(--\d+)?--.+\.zip$/', $f)) {
                $files[] = $dir . '/' . $f;
            }
        }
        rsort($files, SORT_STRING);
        return $files;
    }

    public static function download(string $url, string $destFile, string $token): void
    {
        self::log('download ' . $url);

        $dir = dirname($destFile);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new HautooDistBridgeException('Cannot mkdir ' . $dir);
        }

        if (function_exists('curl_init')) {
            $fh = fopen($destFile, 'wb');
            if ($fh === false) {
                throw new HautooDistBridgeException('Cannot write ' . $destFile);
            }
            $ch = curl_init($url);
            if ($ch === false) {
                fclose($fh);
                throw new HautooDistBridgeException('curl_init failed');
            }
            $headers = ['Accept: application/zip, application/octet-stream, */*'];
            if ($token !== '') {
                $headers[] = 'Authorization: Bearer ' . $token;
            }
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fh,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 600,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'hautoo-dist-bridge/' . self::VERSION,
            ]);
            $ok = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            fclose($fh);
            if ($ok === false || $code < 200 || $code >= 300) {
                @unlink($destFile);
                throw new HautooDistBridgeException("Download failed (HTTP $code): $err");
            }
            return;
        }

        $ctxOpts = [
            'http' => [
                'method' => 'GET',
                'timeout' => 600,
                'header' => "User-Agent: hautoo-dist-bridge/" . self::VERSION . "\r\n"
                    . "Accept: application/zip, application/octet-stream, */*\r\n"
                    . ($token !== '' ? "Authorization: Bearer {$token}\r\n" : ''),
                'follow_location' => 1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ];
        $data = @file_get_contents($url, false, stream_context_create($ctxOpts));
        if ($data === false) {
            throw new HautooDistBridgeException('Download failed (file_get_contents); enable curl preferred');
        }
        if (file_put_contents($destFile, $data) === false) {
            throw new HautooDistBridgeException('Cannot write ' . $destFile);
        }
    }

    public static function extract(string $zipPath, string $destDir): void
    {
        if (!class_exists('ZipArchive')) {
            throw new HautooDistBridgeException('PHP extension zip (ZipArchive) required');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new HautooDistBridgeException('Cannot open ZIP: ' . $zipPath);
        }

        $destReal = realpath($destDir);
        if ($destReal === false) {
            throw new HautooDistBridgeException('Staging dir missing: ' . $destDir);
        }
        $destReal = $destReal . DIRECTORY_SEPARATOR;
        $destBase = rtrim($destReal, DIRECTORY_SEPARATOR);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            $name = str_replace('\\', '/', $name);
            if ($name === '' || str_ends_with($name, '/')) {
                $dir = $destReal . $name;
                if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                    $zip->close();
                    throw new HautooDistBridgeException('Cannot mkdir ' . $dir);
                }
                continue;
            }
            if (str_starts_with($name, '/') || str_contains($name, '../')) {
                $zip->close();
                throw new HautooDistBridgeException('Refusing unsafe ZIP path: ' . $name);
            }
            $target = $destReal . $name;
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
                $zip->close();
                throw new HautooDistBridgeException('Cannot mkdir ' . $parent);
            }
            $parentReal = realpath($parent);
            if (
                $parentReal === false
                || ($parentReal !== $destBase && !str_starts_with($parentReal, $destBase . DIRECTORY_SEPARATOR))
            ) {
                $zip->close();
                throw new HautooDistBridgeException('Zip-Slip blocked: ' . $name);
            }
            $stream = $zip->getStream($name);
            if ($stream === false) {
                $zip->close();
                throw new HautooDistBridgeException('Cannot read ZIP member: ' . $name);
            }
            $out = fopen($target, 'wb');
            if ($out === false) {
                fclose($stream);
                $zip->close();
                throw new HautooDistBridgeException('Cannot write ' . $target);
            }
            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);
        }
        $zip->close();
    }

    private static function unwrapSingleRootIfNeeded(string $staging, string $runDir): void
    {
        if (is_file($staging . '/index.html')) {
            return;
        }
        $entries = array_values(array_filter(scandir($staging) ?: [], static fn ($e) => $e !== '.' && $e !== '..'));
        if (count($entries) === 1 && is_dir($staging . '/' . $entries[0])
            && is_file($staging . '/' . $entries[0] . '/index.html')) {
            self::log('unwrap nested folder ' . $entries[0]);
            $inner = $staging . '/' . $entries[0];
            $unwrap = $runDir . '/staging-flat';
            rename($inner, $unwrap);
            self::rmTree($staging);
            rename($unwrap, $staging);
        }
    }

    public static function pathExists(string $path): bool
    {
        return is_dir($path) || is_file($path) || is_link($path);
    }

    public static function removePath(string $path): void
    {
        if (!self::pathExists($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            self::rmTree($path);
            return;
        }
        @unlink($path);
    }

    public static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            if ($file->isDir()) {
                rmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Atomic-ish webroot replace. Previous tree is discarded (history = ZIP backups).
     */
    public static function swap(string $staging, string $targetDir): void
    {
        $parent = dirname($targetDir);
        $base = basename($targetDir);
        $tmpNew = $parent . DIRECTORY_SEPARATOR . $base . '.dist-bridge.new';
        $discard = $parent . DIRECTORY_SEPARATOR . $base . '.dist-bridge.old';

        self::removePath($tmpNew);
        if (!rename($staging, $tmpNew)) {
            throw new HautooDistBridgeException('Cannot move staging to ' . $tmpNew);
        }

        $hadTarget = self::pathExists($targetDir);
        if ($hadTarget) {
            self::removePath($discard);
            if (!rename($targetDir, $discard)) {
                rename($tmpNew, $staging);
                throw new HautooDistBridgeException('Cannot move current target aside');
            }
        }

        if (!rename($tmpNew, $targetDir)) {
            if ($hadTarget && self::pathExists($discard)) {
                rename($discard, $targetDir);
            }
            throw new HautooDistBridgeException('Cannot promote new tree to ' . $targetDir);
        }

        if (self::pathExists($discard)) {
            self::removePath($discard);
        }
    }

    /**
     * Interactive config creation.
     * @return string project id
     */
    public static function initInteractive(): string
    {
        $out = static function (string $s): void {
            fwrite(STDOUT, $s);
        };
        $ask = static function (string $prompt, string $default = '') use ($out): string {
            $suffix = $default !== '' ? " [{$default}]" : '';
            $out($prompt . $suffix . ': ');
            $line = fgets(STDIN);
            if ($line === false) {
                throw new HautooDistBridgeException('No input');
            }
            $line = trim($line);
            return $line !== '' ? $line : $default;
        };

        $out("hautoo dist-bridge: neue Projekt-Config\n\n");

        $project = $ask('Projekt-Name (z.B. beispiel.de)');
        $project = self::normalizeProjectId($project);
        $path = self::configPath($project);
        if (is_file($path)) {
            $overwrite = $ask("Config existiert bereits ($path). Überschreiben? (y/N)", 'N');
            if (!in_array(strtolower($overwrite), ['y', 'yes', 'j', 'ja'], true)) {
                throw new HautooDistBridgeException('Aborted');
            }
        }

        $zipIn = $ask(
            'ZIP-URL oder Basis-URL der Build-Site (ohne Pfad → /' . self::ZIP_NAME . ')'
        );
        $zipUrl = self::resolveZipUrl($zipIn);
        if ($zipUrl === '' || !preg_match('#^https://#i', $zipUrl)) {
            throw new HautooDistBridgeException('Need https:// ZIP or base URL');
        }
        $out("  → $zipUrl\n");

        $targetDir = $ask('Zielverzeichnis (Webroot, absolut)');
        if ($targetDir === '' || $targetDir[0] !== '/') {
            throw new HautooDistBridgeException('targetDir must be an absolute path');
        }
        $parent = dirname($targetDir);
        if (!is_dir($parent)) {
            throw new HautooDistBridgeException("Parent directory does not exist: $parent");
        }

        $keepIn = $ask(
            'Anzahl ZIP-Backups unter backup/' . $project . '/ (yyyy-mm-dd--hh-mm-ss--…)',
            '1'
        );
        if (!ctype_digit($keepIn)) {
            throw new HautooDistBridgeException('keepBackups must be a number (0, 1, 2, …)');
        }
        $keepBackups = (int) $keepIn;

        $token = $ask('Optional: Bearer-Token für Download (leer = öffentlich)', '');

        $export = [
            'zipUrl' => $zipUrl,
            'targetDir' => rtrim($targetDir, '/'),
            'token' => $token,
            'keepBackups' => $keepBackups,
            'workDir' => null,
        ];

        $php = self::exportConfigPhp($export);
        if (!is_dir(self::configDir()) && !mkdir(self::configDir(), 0755, true)) {
            throw new HautooDistBridgeException('Cannot create config/');
        }
        if (file_put_contents($path, $php) === false) {
            throw new HautooDistBridgeException('Cannot write ' . $path);
        }
        @chmod($path, 0600);

        $out("\nGeschrieben: $path\n");
        $out("Backups:     backup/{$project}/yyyy-mm-dd--hh-mm-ss--" . self::ZIP_NAME . "\n");
        $out("Prüfen:      ./hdb.sh check project $project\n");
        $out("Pull/Live:   ./hdb.sh pull $project\n");
        return $project;
    }

    /**
     * @return array{ok:bool,lines:list<string>}
     */
    public static function checkRequirements(): array
    {
        $lines = [];
        $ok = true;
        $phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
        $lines[] = ($phpOk ? 'ok' : 'FAIL') . "  PHP " . PHP_VERSION . " (need >= 8.1)";
        $ok = $ok && $phpOk;

        $zipOk = class_exists('ZipArchive');
        $lines[] = ($zipOk ? 'ok' : 'FAIL') . '  extension zip (ZipArchive)';
        $ok = $ok && $zipOk;

        $curlOk = function_exists('curl_init');
        $fopenOk = (bool) ini_get('allow_url_fopen');
        $dlOk = $curlOk || $fopenOk;
        $lines[] = ($dlOk ? 'ok' : 'FAIL') . '  download via curl'
            . ($curlOk ? '' : ' (missing)') . ' or allow_url_fopen'
            . ($fopenOk ? '' : ' (off)');
        $ok = $ok && $dlOk;

        $configWritable = is_dir(self::configDir())
            ? is_writable(self::configDir())
            : is_writable(self::repoRoot());
        $lines[] = ($configWritable ? 'ok' : 'FAIL') . '  config/ writable';
        $ok = $ok && $configWritable;

        $backupRoot = self::backupRoot();
        if (!is_dir($backupRoot)) {
            @mkdir($backupRoot, 0755, true);
        }
        $backupWritable = is_dir($backupRoot) && is_writable($backupRoot);
        $lines[] = ($backupWritable ? 'ok' : 'FAIL') . '  backup/ writable';
        $ok = $ok && $backupWritable;

        return ['ok' => $ok, 'lines' => $lines];
    }

    /**
     * @return array{ok:bool,lines:list<string>}
     */
    public static function checkProject(string $project): array
    {
        $lines = [];
        $ok = true;
        $project = self::normalizeProjectId($project);

        $path = self::configPath($project);
        $cfgOk = is_file($path);
        $lines[] = ($cfgOk ? 'ok' : 'FAIL') . '  config ' . $path;
        if (!$cfgOk) {
            return ['ok' => false, 'lines' => $lines];
        }

        try {
            $cfg = self::loadConfig($project);
        } catch (HautooDistBridgeException $e) {
            $lines[] = 'FAIL  load config: ' . $e->getMessage();
            return ['ok' => false, 'lines' => $lines];
        }

        $httpsOk = (bool) preg_match('#^https://#i', $cfg['zipUrl']);
        $lines[] = ($httpsOk ? 'ok' : 'FAIL') . '  zipUrl https: ' . $cfg['zipUrl'];
        $ok = $ok && $httpsOk;

        $head = self::probeZipUrl($cfg['zipUrl'], $cfg['token']);
        $lines[] = ($head['ok'] ? 'ok' : 'FAIL') . '  ZIP erreichbar: ' . $head['detail'];
        $ok = $ok && $head['ok'];

        $parent = dirname($cfg['targetDir']);
        $parentOk = is_dir($parent);
        $lines[] = ($parentOk ? 'ok' : 'FAIL') . '  target parent exists: ' . $parent;
        $ok = $ok && $parentOk;

        $writable = $parentOk && is_writable($parent);
        $lines[] = ($writable ? 'ok' : 'FAIL') . '  target parent writable';
        $ok = $ok && $writable;

        if (is_dir($cfg['targetDir'])) {
            $lines[] = 'ok  targetDir exists: ' . $cfg['targetDir'];
        } else {
            $lines[] = 'info  targetDir not yet present (created on first pull): ' . $cfg['targetDir'];
        }

        $bdir = self::backupDir($project);
        if (!is_dir($bdir)) {
            @mkdir($bdir, 0755, true);
        }
        $bOk = is_dir($bdir) && is_writable($bdir);
        $lines[] = ($bOk ? 'ok' : 'FAIL') . '  backup dir: ' . $bdir;
        $ok = $ok && $bOk;

        $zips = self::listBackupZips($bdir);
        $lines[] = 'info  local ZIP backups: ' . count($zips)
            . ' (keepBackups=' . $cfg['keepBackups'] . ')';
        foreach (array_slice($zips, 0, 5) as $z) {
            $lines[] = '      - ' . basename($z);
        }

        return ['ok' => $ok, 'lines' => $lines];
    }

    /**
     * @return array{ok:bool,detail:string}
     */
    public static function probeZipUrl(string $url, string $token): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return ['ok' => false, 'detail' => 'curl_init failed'];
            }
            $headers = ['Accept: application/zip, application/octet-stream, */*'];
            if ($token !== '') {
                $headers[] = 'Authorization: Bearer ' . $token;
            }
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'hautoo-dist-bridge/' . self::VERSION,
                CURLOPT_RETURNTRANSFER => true,
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            // Some hosts reject HEAD → retry GET range
            if ($code === 405 || $code === 501 || $code === 0) {
                return self::probeZipUrlGet($url, $token);
            }
            if ($code >= 200 && $code < 400) {
                return ['ok' => true, 'detail' => "HTTP $code (HEAD)"];
            }
            return ['ok' => false, 'detail' => "HTTP $code" . ($err !== '' ? " $err" : '')];
        }
        return self::probeZipUrlGet($url, $token);
    }

    /**
     * @return array{ok:bool,detail:string}
     */
    private static function probeZipUrlGet(string $url, string $token): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return ['ok' => false, 'detail' => 'curl_init failed'];
            }
            $headers = [
                'Accept: application/zip, application/octet-stream, */*',
                'Range: bytes=0-0',
            ];
            if ($token !== '') {
                $headers[] = 'Authorization: Bearer ' . $token;
            }
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'hautoo-dist-bridge/' . self::VERSION,
                CURLOPT_RETURNTRANSFER => true,
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($code >= 200 && $code < 400) {
                return ['ok' => true, 'detail' => "HTTP $code (GET range)"];
            }
            return ['ok' => false, 'detail' => "HTTP $code" . ($err !== '' ? " $err" : '')];
        }

        $headers = @get_headers($url, true);
        if ($headers === false || !isset($headers[0])) {
            return ['ok' => false, 'detail' => 'get_headers failed'];
        }
        $status = (string) $headers[0];
        $ok = (bool) preg_match('/\s(2\d\d|3\d\d)\s/', $status);
        return ['ok' => $ok, 'detail' => $status];
    }

    /** @param array{zipUrl:string,targetDir:string,token:string,keepBackups:int,workDir:?string} $cfg */
    public static function exportConfigPhp(array $cfg): string
    {
        $zip = var_export($cfg['zipUrl'], true);
        $target = var_export($cfg['targetDir'], true);
        $token = var_export($cfg['token'], true);
        $keep = (int) $cfg['keepBackups'];
        return <<<PHP
<?php
/**
 * Generated by ./hdb.sh init — do not commit.
 */
return [
    'zipUrl' => {$zip},
    'targetDir' => {$target},
    'token' => {$token},
    'keepBackups' => {$keep},
    'workDir' => null,
];

PHP;
    }
}
