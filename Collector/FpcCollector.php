<?php
declare(strict_types=1);

namespace Asiamarket\HealthCheck\Collector;

use Asiamarket\HealthCheck\Config\HealthCheckConfig;
use Asiamarket\HealthCheck\Service\RepresentativeUrlProvider;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;

class FpcCollector implements CollectorInterface
{
    private TypeListInterface $cacheTypeList;
    private CurlFactory $curlFactory;
    private HealthCheckConfig $config;
    private RepresentativeUrlProvider $urlProvider;

    public function __construct(
        TypeListInterface $cacheTypeList,
        CurlFactory $curlFactory,
        HealthCheckConfig $config,
        RepresentativeUrlProvider $urlProvider
    )
    {
        $this->cacheTypeList = $cacheTypeList;
        $this->curlFactory = $curlFactory;
        $this->config = $config;
        $this->urlProvider = $urlProvider;
    }

    public function getCode(): string
    {
        return 'fpc';
    }

    public function isSupported(array $context = []): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(array $context = []): array
    {
        $cacheTypes = $this->cacheTypeList->getTypes();
        $fullPageCacheEnabled = isset($cacheTypes['full_page']) && (bool)$cacheTypes['full_page']->getData('status');
        $urls = $this->urlProvider->get('fpc', $context);
        $results = [];
        $hits = 0;
        $misses = 0;
        $unknown = 0;
        foreach ($urls as $pageType => $url) {
            if (!is_string($url) || trim($url) === '') {
                continue;
            }
            $results[(string)$pageType] = $this->probeUrl($url);
            $outcome = $results[(string)$pageType]['outcome'];
            $hits += $outcome === 'hit' ? 1 : 0;
            $misses += $outcome === 'miss' ? 1 : 0;
            $unknown += $outcome === 'unknown' ? 1 : 0;
        }
        $testedUrls = count($results);
        $metrics = [
            'enabled' => $fullPageCacheEnabled,
            'tested_urls' => $testedUrls,
            'tested_page_types' => array_keys($results),
            'hit' => $hits,
            'miss' => $misses,
            'unknown' => $unknown,
            'results' => $results,
        ];
        $measurableUrls = $hits + $misses;
        if ($measurableUrls > 0) {
            $metrics['hit_rate_percent'] = round(($hits / $measurableUrls) * 100, 2);
            $metrics['hit_rate_status'] = 'measurable';
        } else {
            $metrics['hit_rate_percent'] = null;
            $metrics['hit_rate_status'] = 'no_comparable_sample';
        }

        return ['metrics' => $metrics];
    }

    /**
     * @return array<string, int|string|array<string, string>>
     */
    private function probeUrl(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user'], $parts['pass'])) {
            return ['status' => 0, 'outcome' => 'invalid_url', 'headers' => []];
        }
        try {
            /** @var Curl $client */
            $client = $this->curlFactory->create();
            $client->setTimeout($this->config->getPositiveInt('scan.http_timeout_seconds', 10));
            $client->addHeader('User-Agent', 'Magento-HealthCheck/1.0');
            $client->get($url);
            $headers = $this->selectedHeaders($client->getHeaders());
            return [
                'status' => $client->getStatus(),
                'outcome' => $this->detectCacheOutcome($headers),
                'headers' => $headers,
            ];
        } catch (\Throwable $exception) {
            return ['status' => 0, 'outcome' => 'unavailable', 'headers' => []];
        }
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, string>
     */
    private function selectedHeaders(array $headers): array
    {
        $selected = [];
        foreach ($this->config->getStringList('fpc.cache_headers') as $expectedHeader) {
            foreach ($headers as $header => $value) {
                if (strcasecmp($header, $expectedHeader) === 0) {
                    $selected[$header] = is_array($value) ? implode(', ', $value) : (string)$value;
                }
            }
        }

        return $selected;
    }

    /**
     * @param array<string, string> $headers
     */
    private function detectCacheOutcome(array $headers): string
    {
        $values = strtoupper(implode(' ', $headers));
        if (str_contains($values, 'HIT')) {
            return 'hit';
        }
        if (str_contains($values, 'MISS') || str_contains($values, 'PASS') || str_contains($values, 'BYPASS')) {
            return 'miss';
        }

        return 'unknown';
    }
}
