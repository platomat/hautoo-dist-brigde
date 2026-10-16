#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * hautoo dist-bridge: pull a fixed ZIP URL and swap into the webroot.
 * Config: config.local.php and/or ENV (see config.example.php / README).
 */

const DIST_BRIDGE_VERSION = '1.0.0';

final class DistBridgeException extends RuntimeException
{
}

function dist_bridge_log(string $msg): void
{
    fwrite(STDERR, '[dist-bridge] ' . $msg . PHP_EOL);
}

function dist_bridge_bool(mixed $v, bool $default): bool
{
    if ($v === null || $v === '') {
        return $default;
    }
    if (is_bool($v)) {
        return $v;
    }
    $s = strtolower(trim((string) $v));
    return in_array($s, ['1', 'true', 'yes', 'on'], true);
}

/**
 * @return array{zipUrl:string,targetDir:string,token:string,keepBackup:bool,workDir:?string}
 */
function dist_bridge_load_config(string $repoRoot): array
{
    $file = [];
    $local = $repoRoot . '/config.local.php';
    if (is_file($local)) {
        $loaded = require $local;
        if (!is_array($loaded)) {
            throw new DistBridgeException('config.local.php must return an array');
        }
        $file = $loaded;
    }

    $zipUrl = getenv('DIST_BRIDGE_ZIP_URL');
    $targetDir = getenv('DIST_BRIDGE_TARGET_DIR');
    $token = getenv('DIST_BRIDGE_TOKEN');
    $keepBackup = getenv('DIST_BRIDGE_KEEP_BACKUP');
    $workDir = getenv('DIST_BRIDGE_WORKDIR');

    $cfg = [
        'zipUrl' => ($zipUrl !== false && $zipUrl !== '') ? $zipUrl : (string) ($file['zipUrl'] ?? ''),
        'targetDir' => ($targetDir !== false && $targetDir !== '') ? $targetDir : (string) ($file['targetDir'] ?? ''),
        'token' => ($token !== false && $token !== '') ? $token : (string) ($file['token'] ?? ''),
        'keepBackup' => dist_bridge_bool(
            $keepBackup !== false ? $keepBackup : ($file['keepBackup'] ?? null),
            true
        ),
        'workDir' => null,
    ];

    $wd = ($workDir !== false && $workDir !== '')
        ? $workDir
        : ($file['workDir'] ?? null);
    if (is_string($wd) && $wd !== '') {
        $cfg['workDir'] = $wd;
    }

    if ($cfg['zipUrl'] === '' || $cfg['targetDir'] === '') {
        throw new DistBridgeException(
            'Missing zipUrl / targetDir. Set DIST_BRIDGE_ZIP_URL + DIST_BRIDGE_TARGET_DIR or config.local.php'
        );
    }

    if (!preg_match('#^https://#i', $cfg['zipUrl'])) {
        throw new DistBridgeException('zipUrl must be https:// (got: ' . $cfg['zipUrl'] . ')');
    }

    $target = realpath(dirname($cfg['targetDir']));
    if ($target === false) {
        throw new DistBridgeException('Parent of targetDir does not exist: ' . $cfg['targetDir']);
    }
    $cfg['targetDir'] = rtrim($cfg['targetDir'], "/\\");

    return $cfg;
}

function dist_bridge_download(string $url, string $destFile, string $token): void
{
    dist_bridge_log('download ' . $url);

    if (function_exists('curl_init')) {
        $fh = fopen($destFile, 'wb');
        if ($fh === false) {
            throw new DistBridgeException('Cannot write ' . $destFile);
        }
        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fh);
            throw new DistBridgeException('curl_init failed');
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
            CURLOPT_USERAGENT => 'hautoo-dist-bridge/' . DIST_BRIDGE_VERSION,
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        if ($ok === false || $code < 200 || $code >= 300) {
            @unlink($destFile);
            throw new DistBridgeException("Download failed (HTTP $code): $err");
        }
        return;
    }

    $ctxOpts = [
        'http' => [
            'method' => 'GET',
            'timeout' => 600,
            'header' => "User-Agent: hautoo-dist-bridge/" . DIST_BRIDGE_VERSION . "\r\n"
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
        throw new DistBridgeException('Download failed (file_get_contents); enable curl preferred');
    }
    if (file_put_contents($destFile, $data) === false) {
        throw new DistBridgeException('Cannot write ' . $destFile);
    }
}

/**
 * Extract ZIP so that every member stays under $destDir (Zip-Slip safe).
 */
function dist_bridge_extract(string $zipPath, string $destDir): void
{
    if (!class_exists('ZipArchive')) {
        throw new DistBridgeException('PHP extension zip (ZipArchive) required');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new DistBridgeException('Cannot open ZIP: ' . $zipPath);
    }

    $destReal = realpath($destDir);
    if ($destReal === false) {
        throw new DistBridgeException('Staging dir missing: ' . $destDir);
    }
    $destReal = $destReal . DIRECTORY_SEPARATOR;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) {
            continue;
        }
        // Normalize separators; reject absolute / traversal
        $name = str_replace('\\', '/', $name);
        if ($name === '' || str_ends_with($name, '/')) {
            $dir = $destReal . $name;
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                $zip->close();
                throw new DistBridgeException('Cannot mkdir ' . $dir);
            }
            continue;
        }
        if (str_starts_with($name, '/') || str_contains($name, '../')) {
            $zip->close();
            throw new DistBridgeException('Refusing unsafe ZIP path: ' . $name);
        }
        $target = $destReal . $name;
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
            $zip->close();
            throw new DistBridgeException('Cannot mkdir ' . $parent);
        }
        $parentReal = realpath($parent);
        $destBase = rtrim($destReal, DIRECTORY_SEPARATOR);
        if (
            $parentReal === false
            || ($parentReal !== $destBase && !str_starts_with($parentReal, $destBase . DIRECTORY_SEPARATOR))
        ) {
            $zip->close();
            throw new DistBridgeException('Zip-Slip blocked: ' . $name);
        }
        $stream = $zip->getStream($name);
        if ($stream === false) {
            $zip->close();
            throw new DistBridgeException('Cannot read ZIP member: ' . $name);
        }
        $out = fopen($target, 'wb');
        if ($out === false) {
            fclose($stream);
            $zip->close();
            throw new DistBridgeException('Cannot write ' . $target);
        }
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);
    }
    $zip->close();
}

function dist_bridge_rm_tree(string $dir): void
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

function dist_bridge_swap(string $staging, string $targetDir, bool $keepBackup): void
{
    $parent = dirname($targetDir);
    $base = basename($targetDir);
    $backup = $parent . DIRECTORY_SEPARATOR . $base . '.dist-bridge.bak';
    $tmpNew = $parent . DIRECTORY_SEPARATOR . $base . '.dist-bridge.new';

    // Move staging → *.new next to target (same filesystem preferred)
    if (is_dir($tmpNew) || is_file($tmpNew)) {
        dist_bridge_rm_tree($tmpNew);
        if (is_file($tmpNew)) {
            unlink($tmpNew);
        }
    }
    if (!rename($staging, $tmpNew)) {
        throw new DistBridgeException('Cannot move staging to ' . $tmpNew);
    }

    $hadTarget = is_dir($targetDir) || is_link($targetDir) || is_file($targetDir);

    if ($hadTarget) {
        if (is_dir($backup) || is_file($backup) || is_link($backup)) {
            dist_bridge_rm_tree($backup);
            if (is_file($backup) || is_link($backup)) {
                unlink($backup);
            }
        }
        if (!rename($targetDir, $backup)) {
            // rollback new
            rename($tmpNew, $staging);
            throw new DistBridgeException('Cannot move current target aside to ' . $backup);
        }
    }

    if (!rename($tmpNew, $targetDir)) {
        if ($hadTarget && is_dir($backup)) {
            rename($backup, $targetDir);
        }
        throw new DistBridgeException('Cannot promote new tree to ' . $targetDir);
    }

    if (!$keepBackup && is_dir($backup)) {
        dist_bridge_log('remove backup ' . $backup);
        dist_bridge_rm_tree($backup);
    } elseif ($keepBackup && is_dir($backup)) {
        dist_bridge_log('backup kept at ' . $backup);
    }
}

function dist_bridge_run(): int
{
    $repoRoot = dirname(__DIR__);
    dist_bridge_log('version ' . DIST_BRIDGE_VERSION);

    $cfg = dist_bridge_load_config($repoRoot);

    $workBase = $cfg['workDir'] ?? sys_get_temp_dir();
    $workBase = rtrim($workBase, "/\\");
    if (!is_dir($workBase) && !mkdir($workBase, 0755, true) && !is_dir($workBase)) {
        throw new DistBridgeException('Cannot create workDir ' . $workBase);
    }

    $runId = 'dist-bridge-' . gmdate('Ymd\THis') . '-' . bin2hex(random_bytes(4));
    $runDir = $workBase . DIRECTORY_SEPARATOR . $runId;
    $zipFile = $runDir . DIRECTORY_SEPARATOR . 'artifact.zip';
    $staging = $runDir . DIRECTORY_SEPARATOR . 'staging';

    if (!mkdir($staging, 0755, true) && !is_dir($staging)) {
        throw new DistBridgeException('Cannot create staging ' . $staging);
    }

    try {
        dist_bridge_download($cfg['zipUrl'], $zipFile, $cfg['token']);
        $size = filesize($zipFile);
        if ($size === false || $size < 64) {
            throw new DistBridgeException('Downloaded file too small or empty');
        }
        dist_bridge_log('downloaded ' . $size . ' bytes');

        dist_bridge_log('extract → staging');
        dist_bridge_extract($zipFile, $staging);

        // Sanity: expect index.html or similar at staging root or one nested folder
        $hasIndex = is_file($staging . '/index.html');
        if (!$hasIndex) {
            // Single top-level folder (accidental nest): unwrap if exactly one dir + index inside
            $entries = array_values(array_filter(scandir($staging) ?: [], static fn ($e) => $e !== '.' && $e !== '..'));
            if (count($entries) === 1 && is_dir($staging . '/' . $entries[0])
                && is_file($staging . '/' . $entries[0] . '/index.html')) {
                dist_bridge_log('unwrap nested folder ' . $entries[0]);
                $inner = $staging . '/' . $entries[0];
                $unwrap = $runDir . '/staging-flat';
                rename($inner, $unwrap);
                dist_bridge_rm_tree($staging);
                rename($unwrap, $staging);
                $hasIndex = true;
            }
        }
        if (!$hasIndex) {
            dist_bridge_log('warning: no index.html at ZIP root (continuing anyway)');
        }

        dist_bridge_log('swap into ' . $cfg['targetDir']);
        dist_bridge_swap($staging, $cfg['targetDir'], $cfg['keepBackup']);
        // staging moved away; mark gone
        $staging = '';

        dist_bridge_log('ok');
        return 0;
    } finally {
        if ($staging !== '' && is_dir($staging)) {
            // failed before swap
        }
        if (is_dir($runDir)) {
            dist_bridge_log('cleanup ' . $runDir);
            dist_bridge_rm_tree($runDir);
        }
    }
}

try {
    exit(dist_bridge_run());
} catch (DistBridgeException $e) {
    dist_bridge_log('ERROR: ' . $e->getMessage());
    exit(1);
} catch (Throwable $e) {
    dist_bridge_log('ERROR: ' . $e->getMessage());
    exit(1);
}
