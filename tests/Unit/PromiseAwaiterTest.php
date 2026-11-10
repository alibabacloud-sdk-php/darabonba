<?php

namespace AlibabaCloud\Dara\Tests;

use AlibabaCloud\Dara\Internal\PromiseAwaiter;
use AlibabaCloud\Dara\WebSocketConnector;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;
use React\Promise\Deferred;
use React\Promise\Timer\TimeoutException;

class PromiseAwaiterTest extends TestCase
{
    public function testAlreadyFulfilledIncludingNull()
    {
        foreach (array(null, false, 'connected') as $value) {
            $deferred = new Deferred();
            $deferred->resolve($value);
            $this->assertSame($value, PromiseAwaiter::await($deferred->promise(), Factory::create(), 1));
        }
    }

    public function testRunsTheSuppliedLoopAndCancelsDeadline()
    {
        $loop = Factory::create();
        $deferred = new Deferred();
        $loop->futureTick(function () use ($deferred) {
            $deferred->resolve('connected');
        });
        $this->assertSame('connected', PromiseAwaiter::await($deferred->promise(), $loop, 30));
        // A leaked 30-second deadline would keep the loop running here.
        $before = microtime(true);
        $loop->run();
        $this->assertLessThan(1, microtime(true) - $before);
    }

    public function testPreservesRejectionAndCleansDeadline()
    {
        $loop = Factory::create();
        $deferred = new Deferred();
        $error = new \RuntimeException('handshake rejected');
        $loop->futureTick(function () use ($deferred, $error) {
            $deferred->reject($error);
        });
        try {
            PromiseAwaiter::await($deferred->promise(), $loop, 30);
            $this->fail('Expected handshake rejection');
        } catch (\RuntimeException $actual) {
            $this->assertSame($error, $actual);
        }
        $before = microtime(true);
        $loop->run();
        $this->assertLessThan(1, microtime(true) - $before);
    }

    public function testDeadlineCancelsPendingConnection()
    {
        $cancelled = false;
        $deferred = new Deferred(function () use (&$cancelled) {
            $cancelled = true;
        });
        try {
            PromiseAwaiter::await($deferred->promise(), Factory::create(), 0.001);
            $this->fail('Expected handshake timeout');
        } catch (TimeoutException $error) {
            $this->assertSame(0.001, $error->getTimeout());
        }
        $this->assertTrue($cancelled);
        // A late completion cannot replace the timeout or restart its loop.
        $deferred->resolve('too late');
    }

    public function testAlreadyRejectedDoesNotRunLoop()
    {
        $loop = Factory::create();
        $ran = false;
        $loop->futureTick(function () use (&$ran) { $ran = true; });
        $deferred = new Deferred();
        $error = new \RuntimeException('already rejected');
        $deferred->reject($error);
        try {
            PromiseAwaiter::await($deferred->promise(), $loop, null);
            $this->fail('Expected rejection');
        } catch (\RuntimeException $actual) {
            $this->assertSame($error, $actual);
        }
        $this->assertFalse($ran);
    }

    public function testConnectorAcceptsOptionalArguments()
    {
        new WebSocketConnector();
        new WebSocketConnector(Factory::create());
        $this->assertInstanceOf(WebSocketConnector::class, new WebSocketConnector(null, null));
    }

    /** @dataProvider invalidConnectorArguments */
    public function testConnectorPreservesArgumentErrorType($loop, $connector)
    {
        $this->expectException(PHP_VERSION_ID >= 70000 ? \TypeError::class : \InvalidArgumentException::class);
        new WebSocketConnector($loop, $connector);
    }

    public static function invalidConnectorArguments()
    {
        return [
            'invalid loop' => [new \stdClass(), null],
            'invalid socket connector' => [null, new \stdClass()],
        ];
    }

    public function testWrapsThrowableForExceptionBasedCallers()
    {
        if (PHP_VERSION_ID < 70000) {
            $this->markTestSkipped('Throwable requires PHP 7');
        }
        $deferred = new Deferred();
        $reason = new \Error('handshake error');
        $deferred->reject($reason);
        try {
            PromiseAwaiter::await($deferred->promise(), Factory::create(), null);
            $this->fail('Expected wrapped rejection');
        } catch (\UnexpectedValueException $error) {
            $this->assertSame($reason, $error->getPrevious());
        }
    }

    public function testNormalizesUnexpectedRejectionValues()
    {
        $promise = $this->getMockBuilder('React\\Promise\\PromiseInterface')->getMock();
        $promise->method('then')->willReturnCallback(function ($resolve, $reject) use ($promise) {
            $reject('not an exception');
            return $promise;
        });
        try {
            PromiseAwaiter::await($promise, Factory::create(), null);
            $this->fail('Expected normalized rejection');
        } catch (\UnexpectedValueException $error) {
            $this->assertSame('WebSocket handshake promise rejected with a non-exception value', $error->getMessage());
        }
    }

    public function testLoopExceptionAlsoCleansDeadline()
    {
        $loop = Factory::create();
        $deferred = new Deferred();
        $loop->futureTick(function () { throw new \RuntimeException('loop failed'); });
        try {
            PromiseAwaiter::await($deferred->promise(), $loop, 30);
            $this->fail('Expected loop error');
        } catch (\RuntimeException $error) {
            $this->assertSame('loop failed', $error->getMessage());
        }
        $before = microtime(true);
        $loop->run();
        $this->assertLessThan(1, microtime(true) - $before);
    }
}
