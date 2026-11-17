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

$respond = static function (int $status, string $body, string ...$extra): bool {
    http_response_code($status);
    foreach ($extra as $line) {
        header($line);
    }
    header('Content-Length: ' . strlen($body));
    echo $body;

    return true;
};

return match ($path) {
    '/v1/config' => $respond(
        200,
        (string) json_encode(['method' => $_SERVER['REQUEST_METHOD'] ?? '', 'headers' => $headers]),
        'ETag: "v9"',
        'Leverprice-Config-Version: 9',
        'Content-Type: application/json',
    ),
    '/v1/events' => $respond(202, (string) json_encode(['body' => file_get_contents('php://input'), 'headers' => $headers])),
    '/redirect' => $respond(302, '', 'Location: /v1/config'),
    default => $respond(404, '{"error":{"code":"not_found"}}'),
};
