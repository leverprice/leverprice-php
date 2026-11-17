<?php

declare(strict_types=1);

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);
$headers = [];
foreach ($_SERVER as $name => $value) {
    if (is_string($name) && str_starts_with($name, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
    }
}

if ($path === '/v1/config') {
    header('ETag: "v9"');
    header('Leverprice-Config-Version: 9');
    header('Content-Type: application/json');
    echo json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'headers' => $headers]);

    return true;
}
if ($path === '/v1/events') {
    http_response_code(202);
    echo json_encode(['body' => file_get_contents('php://input'), 'headers' => $headers]);

    return true;
}
if ($path === '/redirect') {
    header('Location: /v1/config', true, 302);

    return true;
}
http_response_code(404);
echo '{"error":{"code":"not_found"}}';

return true;
