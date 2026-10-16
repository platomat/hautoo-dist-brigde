<?php
/**
 * Copy to config.local.php and fill in. Never commit config.local.php.
 *
 * ENV vars (if set) override these values:
 *   DIST_BRIDGE_ZIP_URL, DIST_BRIDGE_TARGET_DIR, DIST_BRIDGE_TOKEN,
 *   DIST_BRIDGE_KEEP_BACKUP, DIST_BRIDGE_WORKDIR
 */
return [
    // HTTPS URL of the packed Astro dist/ ZIP
    'zipUrl' => 'https://example.com/releases/site.zip',

    // Absolute path to the live web document root
    'targetDir' => '/var/www/example.com/httpdocs',

    // Optional Bearer token for ZIP download (empty = public URL)
    'token' => '',

    // Keep previous webroot as targetDir.dist-bridge.bak
    'keepBackup' => true,

    // Working directory for download + staging (null = system temp)
    'workDir' => null,
];
