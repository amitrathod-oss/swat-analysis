<?php
declare(strict_types=1);

namespace Sigma\HealthCheck\Collector;

use Sigma\HealthCheck\Config\HealthCheckConfig;
use Sigma\HealthCheck\Service\RepresentativeUrlProvider;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;

class SecurityHeadersCollector implements CollectorInterface
{
    private CurlFactory $curlFactory;
    private HealthCheckConfig $config;
    private RepresentativeUrlProvider $urlProvider;

    public function __construct(
        CurlFactory $curlFactory,
        HealthCheckConfig $config,
        RepresentativeUrlProvider $urlProvider
    )
    {
        $this->curlFactory = $curlFactory;
        $this->config = $config;
        $this->urlProvider = $urlProvider;
    }

    public function getCode(): string
    {
        return 'security_headers';
    }

    public function isSupported(array $context = []): bool
    {
        return true;
    }

    public function collect(array $context = []): array
    {
        $url = (string)$this->config->getResolved('security.base_url', '', $context);
        if (isset($context['base_url']) && is_string($context['base_url']) && trim($context['base_url']) !== '') {
            $url = trim($context['base_url']);
        }
        if ($url === '') {
            $urls = $this->urlProvider->get('fpc', $context);
            $url = (string)($urls['home'] ?? '');
        }
        if ($url === '') {
            return ['metrics' => ['status' => 'not_configured', 'missing_data' => ['base_url']]];
        }
        try {
            /** @var Curl $client */
            $client = $this->curlFactory->create();
            $client->setTimeout($this->config->getPositiveInt('scan.http_timeout_seconds', 10));
            $client->addHeader('User-Agent', 'Magento-HealthCheck/1.0');
            $client->get($url);
            $rawHeaders = $client->getHeaders();
            $headers = [];
            foreach ($rawHeaders as $name => $value) {
                $headers[strtolower((string)$name)] = is_array($value) ? implode(', ', $value) : (string)$value;
            }
            $required = $this->config->getStringList('security.required_headers');
            $missing = [];
            foreach ($required as $header) {
                if (!array_key_exists(strtolower($header), $headers)) {
                    $missing[] = $header;
                }
            }
            return ['metrics' => [
                'url' => $url,
                'status_code' => $client->getStatus(),
                'headers' => $headers,
                'required_headers' => $required,
                'missing_headers' => $missing,
                'tls' => strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https' ? 'https' : 'not_https',
            ]];
        } catch (\Throwable $exception) {
            return ['metrics' => ['status' => 'unavailable', 'url' => $url, 'error' => $exception->getMessage()]];
        }
    }
}
