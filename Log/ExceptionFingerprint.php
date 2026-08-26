<?php
declare(strict_types=1);

namespace Asiamarket\HealthCheck\Log;

use Asiamarket\HealthCheck\Security\SecretSanitizer;

class ExceptionFingerprint
{
    private SecretSanitizer $secretSanitizer;

    public function __construct(SecretSanitizer $secretSanitizer)
    {
        $this->secretSanitizer = $secretSanitizer;
    }

    public function create(string $entry): string
    {
        $normalized = (string)$this->secretSanitizer->sanitize($entry);
        $normalized = preg_replace('/\b[0-9a-f]{8}-[0-9a-f-]{27,}\b/i', '{uuid}', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b\d{4,}\b/', '{number}', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return hash('sha256', trim($normalized));
    }
}
