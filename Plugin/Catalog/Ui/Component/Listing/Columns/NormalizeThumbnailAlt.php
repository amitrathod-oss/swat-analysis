<?php
declare(strict_types=1);

namespace Sigma\HealthCheck\Plugin\Catalog\Ui\Component\Listing\Columns;

use Magento\Catalog\Ui\Component\Listing\Columns\Thumbnail as Subject;

class NormalizeThumbnailAlt
{
    /**
     * Prevent Magento's thumbnail column from passing a NULL product name to
     * html_entity_decode() on PHP 8.1+.
     *
     * @param Subject $subject
     * @param array<string, mixed> $dataSource
     * @return array{0: array<string, mixed>}
     */
    public function beforePrepareDataSource(Subject $subject, array $dataSource): array
    {
        if (!isset($dataSource['data']['items']) || !is_array($dataSource['data']['items'])) {
            return [$dataSource];
        }

        foreach ($dataSource['data']['items'] as &$item) {
            if (is_array($item) && (!array_key_exists('name', $item) || $item['name'] === null)) {
                $item['name'] = '';
            }
        }
        unset($item);

        return [$dataSource];
    }
}
