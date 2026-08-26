<?php
declare(strict_types=1);

namespace Sigma\HealthCheck\Rule;

use Sigma\HealthCheck\Config\HealthCheckConfig;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use Symfony\Component\Yaml\Yaml;

class RuleLoader
{
    private const MODULE_NAME = 'Sigma_HealthCheck';

    private ModuleDirReader $moduleDirReader;
    private File $fileDriver;
    private HealthCheckConfig $config;

    public function __construct(ModuleDirReader $moduleDirReader, File $fileDriver, HealthCheckConfig $config)
    {
        $this->moduleDirReader = $moduleDirReader;
        $this->fileDriver = $fileDriver;
        $this->config = $config;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function load(): array
    {
        $definitionDirectory = $this->moduleDirReader->getModuleDir('', self::MODULE_NAME) . '/Rule/definitions';
        if (!$this->fileDriver->isDirectory($definitionDirectory)) {
            return [];
        }

        $rules = [];
        foreach ($this->fileDriver->readDirectory($definitionDirectory) as $path) {
            if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['yaml', 'yml'], true)) {
                continue;
            }

            $definition = Yaml::parse($this->fileDriver->fileGetContents($path));
            if ($definition === null) {
                continue;
            }
            if (!is_array($definition)) {
                throw new \UnexpectedValueException(sprintf('Rule definition "%s" must be an array.', $path));
            }

            $definitionRules = isset($definition['rules']) ? $definition['rules'] : [$definition];
            if (!is_array($definitionRules)) {
                throw new \UnexpectedValueException(sprintf('Rule definition "%s" must contain a rules array.', $path));
            }

            foreach ($definitionRules as $rule) {
                if (!is_array($rule)) {
                    throw new \UnexpectedValueException(sprintf('Rule definition "%s" contains an invalid rule.', $path));
                }
                $rule = $this->resolveConfigValues($rule);
                $rule = $this->applyDomainDefaults($rule, (string)$path);
                $this->validateRule($rule, $path);
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /** @param array<string, mixed> $rule @return array<string, mixed> */
    private function applyDomainDefaults(array $rule, string $path): array
    {
        $file = strtolower(pathinfo($path, PATHINFO_FILENAME));
        $defaults = [
            'database' => ['category' => 'Database', 'domain' => 'Database'],
            'database_advanced' => ['category' => 'Database', 'domain' => 'Database'],
            'cron' => ['category' => 'Magento', 'domain' => 'Availability'],
            'indexer' => ['category' => 'Magento', 'domain' => 'Performance'],
            'fpc' => ['category' => 'Cache/FPC', 'domain' => 'Performance'],
            'logs' => ['category' => 'Application/Exceptions', 'domain' => 'Application'],
            'security' => ['category' => 'Security', 'domain' => 'Security'],
            'services' => ['category' => 'Infrastructure', 'domain' => 'Availability'],
            'system' => ['category' => 'Infrastructure', 'domain' => 'Infrastructure'],
            'php' => ['category' => 'Application', 'domain' => 'Application'],
            'http' => ['category' => 'Performance', 'domain' => 'Performance'],
            'security_headers' => ['category' => 'Security', 'domain' => 'Security'],
        ];
        $rule += $defaults[$file] ?? ['category' => 'General', 'domain' => 'Application'];
        return $rule;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function validateRule(array $rule, string $path): void
    {
        foreach (['id', 'title', 'issue_type', 'risk_level', 'metric', 'operator'] as $field) {
            if (!isset($rule[$field]) || $rule[$field] === '') {
                throw new \UnexpectedValueException(
                    sprintf('Rule definition "%s" is missing required field "%s".', $path, $field)
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private function resolveConfigValues(array $rule): array
    {
        foreach ($rule as $key => $value) {
            if (is_array($value)) {
                $rule[$key] = $this->resolveConfigValues($value);
                continue;
            }
            if (is_string($value) && preg_match('/^\{\{\s*([^}]+?)\s*\}\}$/', $value, $matches)) {
                $rule[$key] = $this->config->get($matches[1], $value);
            }
        }

        return $rule;
    }
}
