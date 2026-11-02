<?php

namespace AlibabaCloud\Dara\Internal;

use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

/**
 * Waits for a WebSocket handshake on its own loop, without changing the global
 * React loop. Kept internal so the SDK does not expose a general blocking API.
 *
 * @internal
 */
final class PromiseAwaiter
{
    public static function await(PromiseInterface $promise, LoopInterface $loop, $timeout)
    {
        $settled = false;
        $rejected = false;
        $result = null;
        $reason = null;
        $timer = null;

        $promise->then(
            function ($value) use (&$settled, &$result, $loop) {
                if (!$settled) {
                    $settled = true;
                    $result = $value;
                    $loop->stop();
                }
            },
            function ($error) use (&$settled, &$rejected, &$reason, $loop) {
                if (!$settled) {
                    $settled = true;
                    $rejected = true;
                    $reason = $error;
                    $loop->stop();
                }
            }
        );

        if (!$settled && $timeout !== null) {
            $timer = $loop->addTimer(max(0, $timeout), function () use (&$settled, &$rejected, &$reason, $promise, $loop) {
                if (!$settled) {
                    // Set the outcome before cancellation, which may reject the
                    // original promise synchronously with a different exception.
                    $settled = true;
                    $rejected = true;
                    $reason = new \RuntimeException('WebSocket handshake timed out');
                    $loop->stop();
                    if (method_exists($promise, 'cancel')) {
                        $promise->cancel();
                    }
                }
            });
        }

        try {
            while (!$settled) {
                $loop->run();
            }
        } finally {
            if ($timer !== null) {
                $loop->cancelTimer($timer);
            }
        }

        if ($rejected) {
            if ($reason instanceof \Exception) {
                throw $reason;
            }
            if ($reason instanceof \Throwable) {
                throw new \UnexpectedValueException($reason->getMessage(), 0, $reason);
            }
            throw new \UnexpectedValueException('WebSocket handshake promise rejected with a non-exception value');
        }

        return $result;
    }
}
