<?php
declare(strict_types=1);

namespace Asiamarket\HealthCheck\Collector;

use Asiamarket\HealthCheck\Config\HealthCheckConfig;
use Asiamarket\HealthCheck\Service\RepresentativeUrlProvider;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;

class HttpCollector implements CollectorInterface
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
        return 'http';
    }

    public function isSupported(array $context = []): bool
    {
        return true;
    }

    public function collect(array $context = []): array
    {
        $urls = $this->urlProvider->get('http', $context);
        if (isset($context['base_url']) && is_string($context['base_url']) && $context['base_url'] !== '') {
            $urls['home'] = rtrim($context['base_url'], '/') . '/';
        }
        $results = [];
        foreach ($urls as $type => $url) {
            if (is_string($url) && trim($url) !== '') {
                $results[(string)$type] = $this->probe($url);
            }
        }
        $measured = array_filter($results, static fn(array $result): bool => ($result['status'] ?? 0) > 0);
        $cacheable = array_filter($measured, static fn(array $result): bool => $result['cacheable'] === true);
        return ['metrics' => [
            'tested_urls' => count($results),
            'successful_responses' => count($measured),
            'cacheable_responses' => count($cacheable),
            'cacheable_rate_percent' => $measured === [] ? null : round(count($cacheable) / count($measured) * 100, 2),
            'results' => $results,
            'missing_page_types' => array_values(array_diff(
                $this->config->getStringList('http.required_page_types'),
                array_keys($urls)
            )),
            'sample_status' => $results === [] ? 'no_urls_configured' : 'measured',
        ]];
    }

    /** @return array<string, mixed> */
    private function probe(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user'], $parts['pass'])) {
            return ['status' => 0, 'outcome' => 'invalid_url', 'cacheable' => false, 'ttfb_ms' => null, 'headers' => []];
        }
        try {
            /** @var Curl $client */
            $client = $this->curlFactory->create();
            $client->setTimeout($this->config->getPositiveInt('scan.http_timeout_seconds', 10));
            $client->addHeader('User-Agent', 'Magento-HealthCheck/1.0');
            $started = microtime(true);
            $client->get($url);
            $headers = $this->headers($client->getHeaders());
            $outcome = $this->outcome($client->getStatus(), $headers);
            return [
                'status' => $client->getStatus(),
                'outcome' => $outcome,
                'cacheable' => !$this->hasNoStore($headers),
                'ttfb_ms' => round((microtime(true) - $started) * 1000, 2),
                'headers' => $headers,
            ];
        } catch (\Throwable $exception) {
            return ['status' => 0, 'outcome' => 'unavailable', 'cacheable' => false, 'ttfb_ms' => null, 'headers' => []];
        }
    }

    /** @param array<string, mixed> $headers @return array<string, string> */
    private function headers(array $headers): array
    {
        $selected = [];
        $wanted = ['X-Magento-Cache-Debug', 'X-Magento-Debug', 'X-Cache', 'X-Cache-Hits', 'Age', 'Cache-Control', 'Surrogate-Control'];
        foreach ($headers as $name => $value) {
            foreach ($wanted as $expected) {
                if (strcasecmp((string)$name, $expected) === 0) {
                    $selected[$expected] = is_array($value) ? implode(', ', $value) : (string)$value;
                }
            }
        }
        return $selected;
    }

    /** @param array<string, string> $headers */
    private function hasNoStore(array $headers): bool
    {
        $cacheControl = strtolower($headers['Cache-Control'] ?? '');
        return str_contains($cacheControl, 'no-store') || str_contains($cacheControl, 'private');
    }

    /** @param array<string, string> $headers */
    private function outcome(int $status, array $headers): string
    {
        if ($status >= 400) {
            return 'error';
        }
        $value = strtoupper(implode(' ', $headers));
        if (str_contains($value, 'HIT')) {
            return 'hit';
        }
        if (str_contains($value, 'MISS')) {
            return 'miss';
        }
        if (str_contains($value, 'PASS') || str_contains($value, 'BYPASS')) {
            return 'bypass';
        }
        return 'unknown';
    }
}
