<?php

// Router for `php -S`. Answers from route files written by TestServer, and logs every request so
// a test can see which headers reached which origin.

$dir = getenv('TARJIM_TEST_SERVER_DIR');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$headers = function_exists('getallheaders') ? getallheaders() : [];

file_put_contents($dir . '/requests.log', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $path,
    'query' => (string) ($_SERVER['QUERY_STRING'] ?? ''),
    'headers' => $headers,
]) . "\n", FILE_APPEND | LOCK_EX);

$file = $dir . '/routes/' . sha1($path) . '.json';
if (!is_file($file)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"code":"test.no_route"}';
    return true;
}

$route = json_decode((string) file_get_contents($file), true);
$body = str_repeat((string) base64_decode($route['body']), (int) $route['repeat']);
if ($route['gzip']) {
    $body = (string) gzencode($body);
    header('Content-Encoding: gzip');
}

http_response_code((int) $route['status']);
foreach ($route['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
if ($route['stallAfter'] === null) {
    header('Content-Length: ' . strlen($body));
}

while (ob_get_level() > 0) {
    ob_end_flush();
}
$sent = 0;
$stalled = false;
$length = strlen($body);
while ($sent < $length) {
    $piece = 65536;
    if (!$stalled && $route['stallAfter'] !== null) {
        $piece = min($piece, max(0, (int) $route['stallAfter'] - $sent));
        if ($piece === 0) {
            sleep((int) $route['stallSeconds']);
            $stalled = true;
            continue;
        }
    }
    echo substr($body, $sent, $piece);
    flush();
    $sent += $piece;
    if (connection_aborted()) {
        break;
    }
}
if (!$stalled && $route['stallAfter'] !== null && $sent >= (int) $route['stallAfter']) {
    sleep((int) $route['stallSeconds']);
}

return true;
