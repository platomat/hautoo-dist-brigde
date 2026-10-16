<?php
/**
 * Template for config/<project>.local.php
 * Create via: ./hdb.sh init
 * Never commit *.local.php
 */
return [
    // Full HTTPS URL to the ZIP, or base URL (script appends /dist-bridge.zip)
    'zipUrl' => 'https://projekt.pages.dev/dist-bridge.zip',

    // Absolute path to the live web document root
    'targetDir' => '/var/www/beispiel.de/httpdocs',

    // Optional Bearer token for ZIP download (empty = public URL)
    'token' => '',

    // How many downloaded ZIPs to keep under backup/<project>/
    // Filename: yyyy-mm-dd--hh-mm-ss--dist-bridge.zip (0 = none)
    'keepBackups' => 1,

    // Working directory for download + staging (null = system temp)
    'workDir' => null,
];
