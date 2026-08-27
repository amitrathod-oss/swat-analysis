<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Serialize\SerializerInterface;

class Dashboard extends Template
{
    private ReadInterface $varDirectory;
    private SerializerInterface $serializer;
    /** @var array<string, mixed>|null */
    private ?array $report = null;

    public function __construct(
        Template\Context $context,
        Filesystem $filesystem,
        SerializerInterface $serializer,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->varDirectory = $filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
        $this->serializer = $serializer;
    }

    /** @return array<string, mixed> */
    public function getReport(): array
    {
        if ($this->report !== null) {
            return $this->report;
        }
        $path = 'health-reports/latest.json';
        if (!$this->varDirectory->isExist($path)) {
            return $this->report = [];
        }
        try {
            $data = $this->serializer->unserialize($this->varDirectory->readFile($path));
            return $this->report = is_array($data) ? $data : [];
        } catch (\Throwable $exception) {
            return $this->report = [];
        }
    }

    /** @param mixed $value */
    public function displayValue($value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(static fn($item): string => is_scalar($item) ? (string)$item : '[data]', $value));
        }
        return $value === null || $value === '' ? 'N/A' : (string)$value;
    }

    /** @param array<string, mixed> $finding */
    public function findingClass(array $finding): string
    {
        return 'risk-' . strtolower((string)($finding['risk_level'] ?? 'info'));
    }
}
