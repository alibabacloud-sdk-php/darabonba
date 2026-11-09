<?php

namespace AlibabaCloud\Dara;

use AlibabaCloud\Dara\Internal\WebSocketClientNegotiator;
use Ratchet\Client\Connector;
use Ratchet\RFC6455\Handshake\ClientNegotiator;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectorInterface;

class WebSocketConnector extends Connector
{
    /**
     * Untyped nullable arguments retain PHP 5.5 compatibility without the
     * implicit-nullability deprecation introduced in PHP 8.4.
     *
     * @param LoopInterface|null      $loop
     * @param ConnectorInterface|null $connector
     */
    public function __construct($loop = null, $connector = null)
    {
        if ($loop !== null && !$loop instanceof LoopInterface) {
            throw new \InvalidArgumentException('loop must implement LoopInterface');
        }
        if ($connector !== null && !$connector instanceof ConnectorInterface) {
            throw new \InvalidArgumentException('connector must implement ConnectorInterface');
        }
        $this->_loop = $loop ?: Loop::get();

        if (null === $connector) {
            $connector = new \React\Socket\Connector([
                'timeout' => 20,
            ], $this->_loop);
        }

        $this->_connector = $connector;
        // Keep the legacy negotiator on RFC6455 0.3 for older PHP releases.
        $constructor = new \ReflectionMethod(ClientNegotiator::class, '__construct');
        $this->_negotiator = $constructor->getNumberOfRequiredParameters() === 0
            ? new ClientNegotiator()
            : new WebSocketClientNegotiator();
    }
}
