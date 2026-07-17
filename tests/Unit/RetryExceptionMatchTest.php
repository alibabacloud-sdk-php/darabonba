<?php

namespace AlibabaCloud\Dara\Tests;

use AlibabaCloud\Dara\Dara;
use AlibabaCloud\Dara\Exception\DaraRespException;
use AlibabaCloud\Dara\RetryPolicy\BackoffPolicy;
use AlibabaCloud\Dara\RetryPolicy\RetryCondition;
use AlibabaCloud\Dara\RetryPolicy\RetryOptions;
use AlibabaCloud\Dara\RetryPolicy\RetryPolicyContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AlibabaCloud\Dara\Dara::shouldRetry
 * @covers \AlibabaCloud\Dara\Dara::getBackoffDelay
 */
class RetryExceptionMatchTest extends TestCase
{
    /**
     * shouldRetry and getBackoffDelay must use the same exception identity field
     * (logical name from getName(), e.g. ResponseError), not PHP class basename.
     */
    public function testShouldRetryAndGetBackoffDelayUseSameExceptionName()
    {
        $fixedPolicy = BackoffPolicy::newBackoffPolicy([
            'policy' => 'Fixed',
            'period' => 2000,
        ]);
        $condition = new RetryCondition([
            'maxAttempts' => 3,
            'exception' => ['ResponseError'],
            'backoff' => $fixedPolicy,
        ]);
        $options = new RetryOptions([
            'retryable' => true,
            'retryCondition' => [$condition],
        ]);
        $context = new RetryPolicyContext([
            'retriesAttempted' => 1,
            'exception' => new DaraRespException([
                'errCode' => 'Throttling',
                'message' => 'throttled',
            ]),
        ]);

        $this->assertEquals('ResponseError', $context->getException()->getName());
        $this->assertTrue(Dara::shouldRetry($options, $context));
        $this->assertEquals(2000, Dara::getBackoffDelay($options, $context));

        // Class basename must not be required; config keyed by PHP class would miss shouldRetry.
        $classNameCondition = new RetryCondition([
            'maxAttempts' => 3,
            'exception' => ['DaraRespException'],
            'backoff' => $fixedPolicy,
        ]);
        $classNameOptions = new RetryOptions([
            'retryable' => true,
            'retryCondition' => [$classNameCondition],
        ]);
        $this->assertFalse(Dara::shouldRetry($classNameOptions, $context));
        $this->assertEquals(100, Dara::getBackoffDelay($classNameOptions, $context));
    }
}
