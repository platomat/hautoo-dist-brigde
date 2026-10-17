#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * hautoo dist-bridge CLI (hdb)
 *
 *   ./hdb.sh init
 *   ./hdb.sh list
 *   ./hdb.sh pull <project> [--force]
 *   ./hdb.sh check requirements
 *   ./hdb.sh check project <project>
 *   ./hdb.sh help
 */

require dirname(__DIR__) . '/lib/HautooDistBridge.php';

function hdb_usage(): void
{
    $v = HautooDistBridge::VERSION;
    $zip = HautooDistBridge::ZIP_NAME;
    fwrite(STDOUT, <<<TXT
hautoo dist-bridge (hdb) v{$v}

Usage:
  ./hdb.sh init                      Neue Config interaktiv anlegen
  ./hdb.sh list                      Projekte auflisten
  ./hdb.sh pull <project> [--force]  ZIP holen und Webroot tauschen
  ./hdb.sh check requirements        PHP / Extensions / Schreibrechte
  ./hdb.sh check project <project>   Config, ZIP, Ziel, Backups, Build-ID
  ./hdb.sh help

Pull überspringt den Download, wenn remote und lokale Build-ID
(meta build-id) gleich sind. Mit --force / -f immer ziehen
(z. B. nach versehentlichem Löschen im Webroot).

Config:   config/<project>.local.php
Backups:  backup/<project>/yyyy-mm-dd--hh-mm-ss--dist-bridge-<build-id>.zip
ZIP:      {$zip}
Beispiel: ./hdb.sh pull beispiel.de
          ./hdb.sh pull beispiel.de --force

TXT);
}

function hdb_print_check(array $result): int
{
    foreach ($result['lines'] as $line) {
        fwrite(STDOUT, $line . PHP_EOL);
    }
    return $result['ok'] ? 0 : 1;
}

/**
 * Strip --force / -f from argv; return [remainingArgs, force].
 *
 * @param list<string> $argv
 * @return array{0:list<string>,1:bool}
 */
function hdb_parse_force(array $argv): array
{
    $force = false;
    $out = [];
    foreach ($argv as $arg) {
        if ($arg === '--force' || $arg === '-f') {
            $force = true;
            continue;
        }
        $out[] = $arg;
    }
    return [$out, $force];
}

$argv = $_SERVER['argv'] ?? [];
array_shift($argv);
[$argv, $force] = hdb_parse_force($argv);
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
            HautooDistBridge::initInteractive();
            exit(0);

        case 'list':
        case 'ls':
            $projects = HautooDistBridge::listProjects();
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
                throw new HautooDistBridgeException(
                    'Usage: ./hdb.sh pull <project> [--force]'
                );
            }
            HautooDistBridge::pull(
                HautooDistBridge::loadConfig($project),
                $force
            );
            exit(0);

        case 'check':
            $what = $argv[1] ?? '';
            if ($what === 'requirements' || $what === 'req' || $what === 'reqs') {
                exit(hdb_print_check(HautooDistBridge::checkRequirements()));
            }
            if ($what === 'project' || $what === 'proj') {
                $project = $argv[2] ?? '';
                if ($project === '') {
                    throw new HautooDistBridgeException(
                        'Usage: ./hdb.sh check project <project>'
                    );
                }
                exit(hdb_print_check(HautooDistBridge::checkProject($project)));
            }
            throw new HautooDistBridgeException(
                "Usage:\n  ./hdb.sh check requirements\n  ./hdb.sh check project <project>"
            );

        default:
            if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $cmd)
                && is_file(HautooDistBridge::configPath($cmd))) {
                HautooDistBridge::pull(
                    HautooDistBridge::loadConfig($cmd),
                    $force
                );
                exit(0);
            }
            fwrite(STDERR, "Unknown command: {$cmd}\n\n");
            hdb_usage();
            exit(1);
    }
} catch (HautooDistBridgeException $e) {
    HautooDistBridge::log('ERROR: ' . $e->getMessage());
    exit(1);
} catch (Throwable $e) {
    HautooDistBridge::log('ERROR: ' . $e->getMessage());
    exit(1);
}
