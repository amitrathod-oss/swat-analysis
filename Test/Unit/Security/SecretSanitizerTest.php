<?php
declare(strict_types=1);

namespace Asiamarket\HealthCheck\Test\Unit\Security;

use Asiamarket\HealthCheck\Security\SecretSanitizer;
use PHPUnit\Framework\TestCase;

class SecretSanitizerTest extends TestCase
{
    public function testSanitizeRedactsSensitiveKeysAndValues(): void
    {
        $sanitized = (new SecretSanitizer())->sanitize([
            'password' => 'database-secret',
            'nested' => ['authorization' => 'Bearer private-token'],
            'message' => 'Authorization: Bearer private-token; customer@example.com connected.',
        ]);

        self::assertSame('[REDACTED]', $sanitized['password']);
        self::assertSame('[REDACTED]', $sanitized['nested']['authorization']);
        self::assertStringNotContainsString('private-token', $sanitized['message']);
        self::assertStringNotContainsString('customer@example.com', $sanitized['message']);
    }

    public function testSanitizeRedactsCredentialsEmbeddedInUrl(): void
    {
        $sanitized = (new SecretSanitizer())->sanitize('redis://user:secret@redis.example.test:6379/0');

        self::assertSame('redis://user:[REDACTED]@redis.example.test:6379/0', $sanitized);
    }

    public function testSanitizeDoesNotRedactNonSensitiveAuthorizationNamedData(): void
    {
        $sanitized = (new SecretSanitizer())->sanitize([
            'authorization_rule' => ['total_size_mb' => 1.5],
        ]);

        self::assertSame(['total_size_mb' => 1.5], $sanitized['authorization_rule']);
    }
}
