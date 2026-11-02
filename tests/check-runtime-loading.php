<?php

// Install the handler BEFORE Composer loads dependency function files. PHPUnit's
// bootstrap can otherwise miss deprecations emitted while loading vendor code.
set_error_handler(function ($severity, $message, $file, $line) {
    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});

require dirname(__DIR__) . '/vendor/autoload.php';
new ReflectionClass('AlibabaCloud\\Dara\\WebSocketConnector');
new ReflectionClass('AlibabaCloud\\Dara\\DefaultWebSocketClient');
new ReflectionClass('AlibabaCloud\\Dara\\Internal\\PromiseAwaiter');
restore_error_handler();
echo "Runtime loaded without deprecations\n";
