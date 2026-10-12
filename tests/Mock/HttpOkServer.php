<?php

// Router for `php -S`: answers every request with 200 "ok" ("/sse" as a one-event stream).
if ('/sse' === parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
    header('Content-Type: text/event-stream');
    echo "data: ok\n\n";

    return;
}
header('Content-Type: text/plain');
echo 'ok';
