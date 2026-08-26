<?php
declare(strict_types=1);

namespace Asiamarket\HealthCheck\Test\Unit\Log;

use Asiamarket\HealthCheck\Log\ExceptionFingerprint;
use Asiamarket\HealthCheck\Security\SecretSanitizer;
use PHPUnit\Framework\TestCase;

class ExceptionFingerprintTest extends TestCase
{
    public function testCreateNormalizesDynamicIdsAndSanitizesSecrets(): void
    {
        $fingerprint = new ExceptionFingerprint(new SecretSanitizer());

        $first = $fingerprint->create('RuntimeException id=10001 password=first-secret');
        $second = $fingerprint->create('RuntimeException id=20002 password=second-secret');

        self::assertSame($first, $second);
    }
}
