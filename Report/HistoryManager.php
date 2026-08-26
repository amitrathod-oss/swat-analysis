<?php
declare(strict_types=1);

namespace Asiamarket\HealthCheck\Report;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Serialize\SerializerInterface;

class HistoryManager
{
    private const DIRECTORY = 'health-reports/history';
    private const LATEST = self::DIRECTORY . '/latest.json';
    private WriteInterface $writeDirectory;
    private ReadInterface $readDirectory;
    private SerializerInterface $serializer;

    public function __construct(Filesystem $filesystem, SerializerInterface $serializer)
    {
        $this->writeDirectory = $filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $this->readDirectory = $filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        $this->serializer = $serializer;
    }

    /** @param array<string, mixed> $report @return array<string, mixed> */
    public function comparison(array $report): array
    {
        $previous = $this->readPrevious();
        if ($previous === null) {
            return ['status' => 'no_previous_scan', 'new' => count($report['findings'] ?? []), 'resolved' => 0, 'regressed' => 0, 'unchanged' => 0, 'score_change' => null];
        }
        $currentIds = $this->findingIds($report);
        $previousIds = $this->findingIds($previous);
        return [
            'status' => 'compared',
            'new' => count(array_diff($currentIds, $previousIds)),
            'resolved' => count(array_diff($previousIds, $currentIds)),
            'regressed' => count(array_diff($currentIds, $previousIds)),
            'unchanged' => count(array_intersect($currentIds, $previousIds)),
            'score_change' => isset($report['health_score'], $previous['health_score'])
                ? (int)$report['health_score'] - (int)$previous['health_score'] : null,
            'previous_scan_id' => $previous['scan_id'] ?? null,
        ];
    }

    /** @param array<string, mixed> $report */
    public function record(array $report): void
    {
        $this->writeDirectory->create(self::DIRECTORY);
        $encoded = $this->serializer->serialize($report);
        $scanId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($report['scan_id'] ?? uniqid('scan_', true))) ?: uniqid('scan_', true);
        $this->writeDirectory->writeFile(self::DIRECTORY . '/' . $scanId . '.json', $encoded);
        $this->writeDirectory->writeFile(self::LATEST, $encoded);
    }

    /** @return array<string, mixed>|null */
    private function readPrevious(): ?array
    {
        if (!$this->readDirectory->isExist(self::LATEST)) {
            return null;
        }
        try {
            $data = $this->serializer->unserialize($this->readDirectory->readFile(self::LATEST));
            return is_array($data) ? $data : null;
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /** @param array<string, mixed> $report @return string[] */
    private function findingIds(array $report): array
    {
        $ids = [];
        foreach (($report['findings'] ?? []) as $finding) {
            if (is_array($finding)) {
                $ids[] = (string)($finding['rule_id'] ?? '') . ':' . (string)($finding['evidence']['metric'] ?? '');
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }
}
