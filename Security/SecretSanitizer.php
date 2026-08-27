<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Security;

class SecretSanitizer
{
    private const REDACTED = '[REDACTED]';

    /**
     * @param mixed $value
     * @return mixed
     */
    public function sanitize($value)
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $sanitized[$key] = $this->isSensitiveKey((string)$key) ? self::REDACTED : $this->sanitize($item);
            }
            return $sanitized;
        }

        if (is_string($value)) {
            return $this->sanitizeString($value);
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower(str_replace(['-', '/'], '_', $key));
        if (in_array($key, [
            'password',
            'passwd',
            'secret',
            'token',
            'api_key',
            'apikey',
            'authorization',
            'crypt_key',
            'session_id',
            'customer_email',
            'customer_phone',
        ], true)) {
            return true;
        }

        return (bool)preg_match('/(?:^|_)(?:password|passwd|secret|token|api_key|crypt_key|session_id)$/', $key);
    }

    private function sanitizeString(string $value): string
    {
        $value = preg_replace_callback(
            '/((?:password|passwd|secret|token|api[_-]?key|authorization|crypt[\\/_-]?key)\\s*[:=]\\s*)([^,\\r\\n]+)/i',
            static function (array $matches): string {
                return $matches[1] . self::REDACTED;
            },
            $value
        ) ?? $value;
        $value = preg_replace('/\\bBearer\\s+[^\\s,]+/i', 'Bearer ' . self::REDACTED, $value) ?? $value;
        $value = preg_replace('/([a-z][a-z0-9+.-]*:\\/\\/[^:\\/\\s]+:)[^@\\/\\s]+@/i', '$1' . self::REDACTED . '@', $value) ?? $value;
        $value = preg_replace('/\\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}\\b/i', '[REDACTED_EMAIL]', $value) ?? $value;

        return $value;
    }
}
