<?php

// Router for `php -S`: answers every request with 200 "ok" ("/sse" as a one-event stream).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ('/sse' === $path) {
    header('Content-Type: text/event-stream');
    echo "data: ok\n\n";

    return;
}
// First SSE event immediately; delay 1.5s before closing — used to assert stream/async
// return timing under ipv4Only (must not block on CurlHandler curl_exec).
if ('/sse-slow' === $path) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    echo "data: hello\n\n";
    if (\function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
    usleep(1500000);
    echo "data: bye\n\n";

    return;
}
header('Content-Type: text/plain');
echo 'ok';
