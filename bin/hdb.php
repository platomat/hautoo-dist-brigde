#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * hautoo dist-bridge CLI (hdb)
 *
 *   ./hdb.sh init
 *   ./hdb.sh list
 *   ./hdb.sh pull <project>
 *   ./hdb.sh check requirements
 *   ./hdb.sh check project <project>
 *   ./hdb.sh help
 */

require dirname(__DIR__) . '/lib/DistBridge.php';

function hdb_usage(): void
{
    $v = DistBridge::VERSION;
    $zip = DistBridge::ZIP_NAME;
    fwrite(STDOUT, <<<TXT
hautoo dist-bridge (hdb) v{$v}

Usage:
  ./hdb.sh init                      Neue Config interaktiv anlegen
  ./hdb.sh list                      Projekte auflisten
  ./hdb.sh pull <project>            ZIP holen und Webroot tauschen
  ./hdb.sh check requirements        PHP / Extensions / Schreibrechte
  ./hdb.sh check project <project>   Config, ZIP-URL, Zielpfad, Backups
  ./hdb.sh help

Config:   config/<project>.local.php
Backups:  backup/<project>/yyyy-mm-dd--hh-mm-ss--{$zip}
Beispiel: ./hdb.sh pull beispiel.de

TXT);
}

function hdb_print_check(array $result): int
{
    foreach ($result['lines'] as $line) {
        fwrite(STDOUT, $line . PHP_EOL);
    }
    return $result['ok'] ? 0 : 1;
}

$argv = $_SERVER['argv'] ?? [];
array_shift($argv);
$cmd = $argv[0] ?? 'help';

try {
    switch ($cmd) {
        case 'help':
        case '-h':
        case '--help':
            hdb_usage();
            exit(0);

        case 'init':
        case 'config':
            DistBridge::initInteractive();
            exit(0);

        case 'list':
        case 'ls':
            $projects = DistBridge::listProjects();
            if ($projects === []) {
                fwrite(STDOUT, "Keine Projekte. Anlegen mit: ./hdb.sh init\n");
                exit(0);
            }
            foreach ($projects as $id) {
                fwrite(STDOUT, $id . PHP_EOL);
            }
            exit(0);

        case 'pull':
        case 'deploy':
        case 'live':
            $project = $argv[1] ?? '';
            if ($project === '') {
                throw new DistBridgeException('Usage: ./hdb.sh pull <project>');
            }
            DistBridge::pull(DistBridge::loadConfig($project));
            exit(0);

        case 'check':
            $what = $argv[1] ?? '';
            if ($what === 'requirements' || $what === 'req' || $what === 'reqs') {
                exit(hdb_print_check(DistBridge::checkRequirements()));
            }
            if ($what === 'project' || $what === 'proj') {
                $project = $argv[2] ?? '';
                if ($project === '') {
                    throw new DistBridgeException('Usage: ./hdb.sh check project <project>');
                }
                exit(hdb_print_check(DistBridge::checkProject($project)));
            }
            throw new DistBridgeException(
                "Usage:\n  ./hdb.sh check requirements\n  ./hdb.sh check project <project>"
            );

        default:
            if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $cmd)
                && is_file(DistBridge::configPath($cmd))) {
                DistBridge::pull(DistBridge::loadConfig($cmd));
                exit(0);
            }
            fwrite(STDERR, "Unknown command: {$cmd}\n\n");
            hdb_usage();
            exit(1);
    }
} catch (DistBridgeException $e) {
    DistBridge::log('ERROR: ' . $e->getMessage());
    exit(1);
} catch (Throwable $e) {
    DistBridge::log('ERROR: ' . $e->getMessage());
    exit(1);
}
