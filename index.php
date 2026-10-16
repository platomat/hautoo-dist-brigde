<?php
/**
 * Fallback if .htaccess is ignored: never serve bridge files via browser.
 */
http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
echo "Forbidden\n";
exit(1);
