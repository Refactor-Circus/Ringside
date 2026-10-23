<?php

/**
 * Router for `php -S` started by `ringside dashboard`. Serves the page and
 * a read-only JSON API over the projects' spec folders. Bound to 127.0.0.1.
 */

declare(strict_types=1);

use RefactorCircus\Ringside\Dashboard\Board;
use RefactorCircus\Ringside\Project;

require (string) getenv('RINGSIDE_AUTOLOAD');

$roots = json_decode((string) getenv('RINGSIDE_PROJECTS'), true);
$board = new Board(array_map(static fn (string $root): Project => new Project($root), is_array($roots) ? array_values(array_filter($roots, is_string(...))) : []));
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if ($path === '/api/board') {
    header('Content-Type: application/json');
    echo json_encode($board->data(), JSON_UNESCAPED_SLASHES);

    return true;
}

if ($path === '/api/file') {
    $contents = $board->file((int) ($_GET['project'] ?? -1), (string) ($_GET['path'] ?? ''));

    if ($contents === null) {
        http_response_code(404);
        echo 'Not found';

        return true;
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo $contents;

    return true;
}

if ($path === '/' || $path === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__.'/index.html');

    return true;
}

http_response_code(404);
echo 'Not found';

return true;
