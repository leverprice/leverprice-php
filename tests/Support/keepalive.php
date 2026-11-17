<?php

declare(strict_types=1);

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    exit(1);
}
fwrite(STDOUT, (string) stream_socket_get_name($server, false) . "\n");
$open = [];
while ($connection = @stream_socket_accept($server, 20)) {
    $request = '';
    while (!str_contains($request, "\r\n\r\n") && !feof($connection)) {
        $request .= (string) fread($connection, 8192);
    }
    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 11\r\nConnection: keep-alive\r\n\r\n{\"ok\":true}");
    fflush($connection);
    $open[] = $connection;
}
