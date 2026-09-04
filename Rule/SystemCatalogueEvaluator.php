<?php
declare(strict_types=1);

namespace Mha\HealthCheck\Rule;

/**
 * Converts read-only collector output into the Magento Open Source SWAT system
 * catalogue checks. Unknown lifecycle data is reported as not_checked instead
 * of being treated as a failed compatibility check.
 */
class SystemCatalogueEvaluator
{
    /** @var array<string, array<string, mixed>> */
    private const MAGENTO_REQUIREMENTS = [
        '2.4.8' => [
            'php' => ['8.3', '8.4'],
            'composer_min' => '2.9.3',
            'mariadb' => ['11.4', '11.8'],
            'opensearch' => ['2', '3'],
            'redis' => ['7.2'],
            'valkey' => ['8', '8.1'],
        ],
        '2.4.7' => [
            'php' => ['8.2', '8.3'],
            'composer_min' => '2.9.3',
            'mariadb' => ['10.6', '10.11'],
            'opensearch' => ['2'],
            'redis' => ['7.2'],
            'valkey' => ['8'],
        ],
    ];

    /** @var array<string, string> */
    private const PHP_EOL = [
        '7.4' => '2022-11-28',
        '8.0' => '2023-11-26',
        '8.1' => '2025-12-31',
        '8.2' => '2026-12-31',
        '8.3' => '2027-12-31',
        '8.4' => '2028-12-31',
        '8.5' => '2029-12-31',
    ];

    /** @var array<string, string> */
    private const OS_EOL = [
        'ubuntu:20.04' => '2025-05-31',
        'ubuntu:22.04' => '2027-04-30',
        'ubuntu:24.04' => '2029-04-30',
        'debian:11' => '2026-08-31',
        'debian:12' => '2028-06-30',
    ];

    /**
     * @param array<string, mixed> $metrics
     * @return array<string, array<string, mixed>>
     */
    public function evaluate(array $metrics): array
    {
        $magento = $this->array($metrics, 'magento');
        $php = $this->array($metrics, 'php');
        $composer = $this->array($metrics, 'composer');
        $database = $this->array($metrics, 'database');
        $search = $this->array($metrics, 'opensearch');
        $redis = $this->array($metrics, 'redis');
        $system = $this->array($metrics, 'system');
        $cron = $this->array($metrics, 'cron');
        $indexer = $this->array($metrics, 'indexer');
        $extensions = $this->array($metrics, 'extensions');
        $security = $this->array($metrics, 'security');
        $securityHeaders = $this->array($metrics, 'security_headers');
        $databaseAdvanced = $this->array($metrics, 'database_advanced');
        $store = $this->array($metrics, 'store');
        $statuses = $this->array($metrics, 'collector_status');
        $version = (string)($magento['version'] ?? '');
        $releaseLine = $this->releaseLine($version);
        $requirements = self::MAGENTO_REQUIREMENTS[$releaseLine] ?? null;

        $catalogue = [];
        $catalogue['SYS-001'] = $this->check($version !== '', $version === '' ? 'Magento version could not be detected.' : 'Magento version detected.', ['version' => $version, 'edition' => $magento['edition'] ?? null]);
        $catalogue['SYS-002'] = $requirements === null
            ? $this->notChecked('No shipped lifecycle manifest entry exists for Magento ' . ($releaseLine ?: 'unknown') . '.', ['version' => $version])
            : $this->check(true, 'Magento release line is covered by the shipped lifecycle manifest.', ['release_line' => $releaseLine]);

        $phpVersion = (string)($php['version'] ?? '');
        $phpBranch = $this->majorMinor($phpVersion);
        $phpEol = self::PHP_EOL[$phpBranch] ?? null;
        $phpCompatible = $requirements !== null && in_array($phpBranch, $requirements['php'], true);
        $phpSupported = $phpEol !== null && $phpEol >= gmdate('Y-m-d');
        $catalogue['SYS-003'] = $requirements === null || $phpEol === null
            ? $this->notChecked('PHP compatibility or lifecycle data is unavailable for the detected version.', ['php_version' => $phpVersion, 'magento_release_line' => $releaseLine])
            : $this->check($phpCompatible && $phpSupported, 'PHP must be Magento-compatible and within PHP security support.', ['php_version' => $phpVersion, 'php_eol' => $phpEol, 'magento_compatible' => $phpCompatible]);

        $composerVersion = $this->versionFromComposerOutput((string)($composer['version'] ?? ''));
        $catalogue['SYS-004'] = $requirements === null || $composerVersion === ''
            ? $this->notChecked('Composer version or Magento compatibility data is unavailable.', ['composer_version' => $composer['version'] ?? null])
            : $this->check(version_compare($composerVersion, (string)$requirements['composer_min'], '>='), 'Composer version meets the minimum for this Magento release line.', ['composer_version' => $composerVersion, 'minimum_version' => $requirements['composer_min']]);

        $databaseVersion = (string)($database['version'] ?? '');
        $databaseEngine = stripos($databaseVersion, 'mariadb') !== false ? 'mariadb' : (stripos($databaseVersion, 'mysql') !== false ? 'mysql' : 'unknown');
        $databaseMajorMinor = $this->majorMinor($databaseVersion);
        $catalogue['SYS-005'] = $requirements === null || $databaseEngine !== 'mariadb'
            ? $this->notChecked('The shipped compatibility manifest has no matching database engine entry.', ['database_version' => $databaseVersion, 'engine' => $databaseEngine])
            : $this->check(in_array($databaseMajorMinor, $requirements['mariadb'], true), 'MariaDB version is compatible with the detected Magento release line.', ['database_version' => $databaseVersion, 'supported_versions' => $requirements['mariadb']]);

        $searchStatus = (string)($statuses['opensearch']['status'] ?? 'unavailable');
        $searchVersion = $this->majorMinor((string)($search['version'] ?? ''));
        $catalogue['SYS-006'] = $searchStatus === 'not_applicable'
            ? $this->notChecked('No OpenSearch or Elasticsearch service is configured.', [])
            : ($searchStatus === 'unavailable'
                ? $this->check(false, 'Configured search service could not be reached.', ['collector_status' => $searchStatus])
                : ($requirements === null || $searchVersion === ''
                ? $this->notChecked('Search compatibility data is unavailable.', ['collector_status' => $searchStatus])
                : $this->check($searchStatus === 'success' && in_array(explode('.', $searchVersion)[0], $requirements['opensearch'], true), 'Configured search service is reachable and compatible.', ['collector_status' => $searchStatus, 'search_version' => $searchVersion, 'supported_majors' => $requirements['opensearch']])));

        $redisStatus = (string)($statuses['redis']['status'] ?? 'unavailable');
        $redisVersion = $this->majorMinor((string)($redis['version'] ?? ''));
        $isValkey = isset($redis['version']) && stripos((string)$redis['version'], 'valkey') !== false;
        $supportedCacheVersions = $requirements === null ? [] : ($isValkey ? $requirements['valkey'] : $requirements['redis']);
        $catalogue['SYS-007'] = $redisStatus === 'not_applicable'
            ? $this->notChecked('Redis or Valkey is not configured for Magento cache or sessions.', [])
            : ($redisStatus === 'unavailable'
                ? $this->check(false, 'Configured Redis or Valkey service could not be reached.', ['collector_status' => $redisStatus])
                : ($requirements === null || $redisVersion === ''
                ? $this->notChecked('Cache-service compatibility data is unavailable.', ['collector_status' => $redisStatus])
                : $this->check($redisStatus === 'success' && in_array($redisVersion, $supportedCacheVersions, true), 'Configured Redis or Valkey service is reachable and compatible.', ['collector_status' => $redisStatus, 'cache_version' => $redisVersion, 'supported_versions' => $supportedCacheVersions])));

        $webServer = $this->array($system, 'web_server');
        $catalogue['SYS-008'] = ($webServer['status'] ?? '') === 'not_checked'
            ? $this->notChecked((string)($webServer['reason'] ?? 'No supported web-server binary was detected.'), $webServer)
            : $this->check(($webServer['version'] ?? '') !== '', 'A supported web-server binary was detected.', $webServer);

        $os = $this->array($system, 'operating_system');
        $osKey = strtolower((string)($os['id'] ?? '')) . ':' . (string)($os['version_id'] ?? '');
        $osEol = self::OS_EOL[$osKey] ?? null;
        $catalogue['SYS-009'] = $osEol === null
            ? $this->notChecked('No shipped OS lifecycle entry exists for the detected operating system.', $os)
            : $this->check($osEol >= gmdate('Y-m-d'), 'Operating system is within the shipped lifecycle policy.', $os + ['eol_date' => $osEol]);

        $inventory = $this->array($magento, 'module_inventory');
        $catalogue['SYS-010'] = $this->check($inventory !== [] && isset($inventory['modules']), 'Enabled and disabled Magento modules were inventoried and classified.', ['module_count' => $inventory['count'] ?? 0, 'enabled_count' => $inventory['enabled_count'] ?? 0, 'disabled_count' => $inventory['disabled_count'] ?? 0]);

        $deploymentMode = strtolower((string)($magento['deployment_mode'] ?? ''));
        $catalogue['SYS-011'] = $deploymentMode === ''
            ? $this->notChecked('Magento deployment mode could not be detected.', [])
            : ($deploymentMode === 'developer'
                ? $this->check(false, 'Developer deployment mode is not suitable for a production-targeted scan.', ['deployment_mode' => $deploymentMode])
                : ($deploymentMode === 'production'
                    ? $this->check(true, 'Magento is in production deployment mode.', ['deployment_mode' => $deploymentMode])
                    : $this->notChecked('Default deployment mode requires an explicit environment policy decision.', ['deployment_mode' => $deploymentMode])));

        $cronInstallation = $this->array($cron, 'installation');
        $catalogue['SYS-013'] = ($cronInstallation['status'] ?? '') === 'not_checked'
            ? $this->notChecked((string)($cronInstallation['reason'] ?? 'Magento cron installation could not be inspected.'), $cronInstallation)
            : $this->check(($cronInstallation['status'] ?? '') === 'installed', 'A Magento cron entry is present for the deployment user.', $cronInstallation);

        $indexers = $this->array($indexer, 'indexers');
        if ($indexers === []) {
            $catalogue['SYS-015'] = $this->notChecked('Indexer status data is unavailable.', []);
        } else {
            $unhealthyIndexers = [];
            foreach ($indexers as $indexerId => $indexerData) {
                $status = strtolower((string)(is_array($indexerData) ? ($indexerData['status'] ?? '') : ''));
                if (in_array($status, ['invalid', 'suspended', 'unavailable'], true)) {
                    $unhealthyIndexers[(string)$indexerId] = $status;
                }
            }
            $catalogue['SYS-015'] = $this->check($unhealthyIndexers === [], $unhealthyIndexers === []
                ? 'No indexer is invalid, suspended, or unavailable.'
                : sprintf('Unhealthy indexers detected (%d): %s.', count($unhealthyIndexers), implode(', ', array_keys($unhealthyIndexers))),
                ['unhealthy_indexers' => $unhealthyIndexers, 'indexer_count' => count($indexers)]);
        }

        $writableDirectories = $this->array($system, 'writable_directories');
        $unsafeScripts = $this->array($system, 'unsafe_scripts');
        if ($writableDirectories === [] || ($unsafeScripts['status'] ?? '') === 'not_checked') {
            $catalogue['SYS-017'] = $this->notChecked('Magento writable-directory or executable permission metadata is unavailable.', ['directories' => $writableDirectories, 'scripts' => $unsafeScripts]);
        } else {
            $missingWritable = [];
            foreach (['var', 'pub/static', 'generated'] as $directory) {
                $metadata = $this->array($writableDirectories, $directory);
                if (($metadata['exists'] ?? false) !== true || ($metadata['writable'] ?? false) !== true) {
                    $missingWritable[] = $directory;
                }
            }
            $unsafe = is_array($unsafeScripts['matches'] ?? null) ? $unsafeScripts['matches'] : [];
            $catalogue['SYS-017'] = $this->check($missingWritable === [] && $unsafe === [] && ($unsafeScripts['status'] ?? '') !== 'truncated',
                $missingWritable === [] && $unsafe === []
                    ? 'Required Magento directories are writable and no unsafe writable PHP or shell script was found.'
                    : sprintf('Filesystem permission issues: Not writable [%s], Unsafe scripts [%s].', $this->formatList($missingWritable), $this->formatList($unsafe)),
                ['not_writable' => $missingWritable, 'unsafe_scripts' => $unsafe, 'scan_status' => $unsafeScripts['status'] ?? 'unknown']);
        }

        $diskUsed = $system['disk']['used_percent'] ?? null;
        $inode = $this->array($system, 'inode');
        $inodeUsed = $inode['used_percent'] ?? null;
        $catalogue['SYS-018'] = !is_numeric($diskUsed) || ($inode['status'] ?? '') !== 'success' || !is_numeric($inodeUsed)
            ? $this->notChecked('Disk or inode utilization is unavailable.', ['disk_used_percent' => $diskUsed, 'inode' => $inode])
            : $this->check((float)$diskUsed < 80.0 && (float)$inodeUsed < 80.0, 'Disk and inode utilization are below the 80% warning threshold.', ['disk_used_percent' => (float)$diskUsed, 'inode_used_percent' => (float)$inodeUsed, 'warning_threshold_percent' => 80]);

        $opcacheEnabled = $php['opcache_ini_enabled'] ?? null;
        $limitsAvailable = isset($php['memory_limit'], $php['max_execution_time'], $php['max_input_time'], $php['upload_max_filesize'], $php['post_max_size']);
        $catalogue['SYS-020'] = strtoupper((string)($php['sapi'] ?? '')) === 'CLI'
            ? $this->notChecked('CLI PHP settings do not prove the web-SAPI limits and OPcache state.', ['sapi' => $php['sapi'] ?? null, 'opcache_ini_enabled' => $opcacheEnabled])
            : (!$limitsAvailable || $opcacheEnabled === null
                ? $this->notChecked('PHP limits or OPcache settings are unavailable.', [])
                : $this->check((bool)$opcacheEnabled && $this->phpLimitsAreUsable($php), 'PHP limits are usable and OPcache is enabled for the scanned SAPI.', ['memory_limit' => $php['memory_limit'], 'max_execution_time' => $php['max_execution_time'], 'max_input_time' => $php['max_input_time'], 'upload_max_filesize' => $php['upload_max_filesize'], 'post_max_size' => $php['post_max_size'], 'opcache_enabled' => $opcacheEnabled]));

        $modulePackages = [];
        foreach ((is_array($extensions['modules'] ?? null) ? $extensions['modules'] : []) as $module) {
            if (is_array($module)) {
                $modulePackages[(string)($module['name'] ?? '')] = $module['package'] ?? null;
            }
        }
        $unprovenancedModules = [];
        foreach ((is_array($inventory['modules'] ?? null) ? $inventory['modules'] : []) as $moduleName => $moduleData) {
            if (is_array($moduleData) && ($moduleData['status'] ?? '') === 'enabled' && ($moduleData['source'] ?? '') === 'app_code_custom' && empty($modulePackages[(string)$moduleName])) {
                $unprovenancedModules[] = (string)$moduleName;
            }
        }
        $catalogue['SEC-004'] = $inventory === [] || $extensions === []
            ? $this->notChecked('Module provenance data is unavailable.', [])
            : $this->check($unprovenancedModules === [], $unprovenancedModules === []
                ? 'Every enabled custom module has Composer package provenance or requires an approved documented exception.'
                : sprintf('Custom modules lacking Composer package provenance (%d): %s.', count($unprovenancedModules), $this->formatList($unprovenancedModules)),
                ['modules_without_composer_provenance' => $unprovenancedModules]);

        $adminRoles = $this->array($security, 'admin_roles');
        $unrestrictedRoles = is_array($adminRoles['unrestricted'] ?? null) ? $adminRoles['unrestricted'] : [];
        $catalogue['SEC-008'] = ($adminRoles['status'] ?? '') !== 'success'
            ? $this->notChecked((string)($adminRoles['reason'] ?? 'Admin role privileges could not be inspected.'), $adminRoles)
            : $this->check($unrestrictedRoles === [], $unrestrictedRoles === []
                ? 'No unrestricted admin role is present without an explicit policy exception.'
                : sprintf('Unrestricted admin roles detected (%d): %s.', count($unrestrictedRoles), $this->formatList($unrestrictedRoles)),
                ['unrestricted_roles' => $unrestrictedRoles]);

        $adminConfig = $this->array($security, 'admin_security_config');
        $adminValues = $this->configValues($adminConfig);
        $weakAdminSettings = [];
        foreach (['admin/security/lockout_failures', 'admin/security/lockout_threshold', 'admin/security/session_lifetime'] as $path) {
            foreach ($adminValues[$path] ?? [] as $value) {
                if (!is_numeric($value) || (int)$value <= 0) {
                    $weakAdminSettings[] = $path;
                    break;
                }
            }
        }
        $catalogue['SEC-010'] = ($adminConfig['status'] ?? '') !== 'success' || $adminValues === []
            ? $this->notChecked((string)($adminConfig['reason'] ?? 'Admin security configuration could not be inspected.'), $adminConfig)
            : $this->check($weakAdminSettings === [], 'Configured lockout and admin session values meet the minimum positive-value policy.', ['weak_settings' => $weakAdminSettings]);

        $twoFactorModule = $this->array($inventory, 'modules')['Magento_TwoFactorAuth'] ?? null;
        $catalogue['SEC-011'] = $inventory === []
            ? $this->notChecked('Magento module status is unavailable for two-factor authentication.', [])
            : $this->check(is_array($twoFactorModule) && ($twoFactorModule['status'] ?? '') === 'enabled',
                is_array($twoFactorModule) && ($twoFactorModule['status'] ?? '') === 'enabled'
                    ? 'Magento_TwoFactorAuth is enabled for this Magento release.'
                    : 'Magento_TwoFactorAuth module is disabled or missing from current installation.',
                ['module' => $twoFactorModule]);

        $cookieConfig = $this->array($security, 'cookie_secure_config');
        $cookieValues = $this->configValues($cookieConfig)['web/cookie/cookie_secure'] ?? [];
        $isHttps = ($securityHeaders['tls'] ?? '') === 'https' || (($security['tls']['status'] ?? '') === 'valid');
        $catalogue['SEC-014'] = ($cookieConfig['status'] ?? '') !== 'success' || $cookieValues === []
            ? $this->notChecked((string)($cookieConfig['reason'] ?? 'Cookie security configuration could not be inspected.'), $cookieConfig)
            : (!$isHttps
                ? $this->notChecked('The configured public URL is not HTTPS, so the HTTPS cookie policy is not applicable.', ['values' => $cookieValues])
                : $this->check(!in_array('0', $cookieValues, true), 'Secure cookies are enabled for configured HTTPS scopes.', ['values' => $cookieValues]));

        $setCookie = strtolower((string)($securityHeaders['headers']['set-cookie'] ?? ''));
        $catalogue['SEC-015'] = ($securityHeaders['status'] ?? 'success') !== 'success' || $setCookie === ''
            ? $this->notChecked('No Set-Cookie response header was available for inspection.', [])
            : $this->check(str_contains($setCookie, 'httponly') && str_contains($setCookie, 'samesite='), 'Response cookies include HttpOnly and SameSite attributes.', ['has_httponly' => str_contains($setCookie, 'httponly'), 'has_samesite' => str_contains($setCookie, 'samesite=')]);

        $displayErrors = $php['display_errors'] ?? null;
        $xdebugLoaded = $php['xdebug_loaded'] ?? null;
        $catalogue['SEC-019'] = $deploymentMode !== 'production'
            ? ($deploymentMode === '' ? $this->notChecked('Deployment mode is unavailable.', []) : $this->check(false, 'Production-targeted scans require Magento production mode.', ['deployment_mode' => $deploymentMode]))
            : ($displayErrors === null || $xdebugLoaded === null
                ? $this->notChecked('PHP runtime exposure settings are unavailable.', [])
                : $this->check($displayErrors === false && $xdebugLoaded === false,
                    $displayErrors === false && $xdebugLoaded === false
                        ? 'Production mode has no PHP error display or Xdebug exposure.'
                        : sprintf('Production mode exposes debug settings (display_errors=%s, xdebug_loaded=%s).', $displayErrors ? 'true' : 'false', $xdebugLoaded ? 'true' : 'false'),
                    ['display_errors' => $displayErrors, 'xdebug_loaded' => $xdebugLoaded]));

        $errorDisclosure = $this->array($security, 'error_disclosure');
        $catalogue['SEC-020'] = ($errorDisclosure['status'] ?? '') !== 'success'
            ? $this->notChecked((string)($errorDisclosure['reason'] ?? 'Public error response could not be inspected.'), $errorDisclosure)
            : $this->check(($errorDisclosure['indicators'] ?? []) === [],
                ($errorDisclosure['indicators'] ?? []) === []
                    ? 'A deliberately missing public route did not disclose a stack trace, filesystem path, SQLSTATE, or exception class.'
                    : sprintf('Public error route disclosed sensitive indicators (%d): %s.', count($errorDisclosure['indicators'] ?? []), $this->formatList($errorDisclosure['indicators'] ?? [])),
                ['http_status' => $errorDisclosure['http_status'] ?? null, 'indicators' => $errorDisclosure['indicators'] ?? []]);

        $unsafeScripts = $this->array($system, 'unsafe_scripts');
        $unsafeMatches = is_array($unsafeScripts['matches'] ?? null) ? $unsafeScripts['matches'] : [];
        $catalogue['SEC-022'] = ($unsafeScripts['status'] ?? '') === 'not_checked'
            ? $this->notChecked((string)($unsafeScripts['reason'] ?? 'Executable script permissions could not be inspected.'), $unsafeScripts)
            : $this->check($unsafeMatches === [] && ($unsafeScripts['status'] ?? '') !== 'truncated',
                $unsafeMatches === []
                    ? 'No group- or world-writable PHP or shell script was found in Magento executable paths.'
                    : sprintf('Unsafe writable executable scripts found (%d): %s.', count($unsafeMatches), $this->formatList($unsafeMatches)),
                ['matches' => $unsafeMatches, 'scan_status' => $unsafeScripts['status'] ?? 'unknown']);

        $suspiciousCode = $this->array($security, 'suspicious_code');
        $suspiciousMatches = is_array($suspiciousCode['matches'] ?? null) ? $suspiciousCode['matches'] : [];
        $catalogue['SEC-024'] = ($suspiciousCode['status'] ?? '') === 'not_checked'
            ? $this->notChecked((string)($suspiciousCode['reason'] ?? 'Custom and writable PHP code could not be inspected.'), $suspiciousCode)
            : $this->check($suspiciousMatches === [] && ($suspiciousCode['status'] ?? '') !== 'truncated',
                $suspiciousMatches === []
                    ? 'No unreviewed high-risk PHP execution pattern was found in custom or writable code.'
                    : sprintf('Suspicious PHP execution patterns matched (%d): %s.', count($suspiciousMatches), $this->formatList($suspiciousMatches)),
                ['matches' => $suspiciousMatches, 'scan_status' => $suspiciousCode['status'] ?? 'unknown']);

        $databasePrivileges = $this->array($security, 'database_privileges');
        $catalogue['SEC-025'] = ($databasePrivileges['status'] ?? '') !== 'success' ? $this->notChecked((string)($databasePrivileges['reason'] ?? 'Database privilege metadata is unavailable.'), $databasePrivileges) : $this->check(($databasePrivileges['dangerous_privileges'] ?? []) === [], 'The Magento runtime account has no dangerous global database privilege.', $databasePrivileges);
        $sensitiveFiles = $this->array($security, 'sensitive_file_permissions');
        $worldReadable = is_array($sensitiveFiles['world_readable'] ?? null) ? $sensitiveFiles['world_readable'] : [];
        $catalogue['SEC-027'] = ($sensitiveFiles['status'] ?? '') !== 'success'
            ? $this->notChecked((string)($sensitiveFiles['reason'] ?? 'Backup and log permissions are unavailable.'), $sensitiveFiles)
            : $this->check($worldReadable === [], $worldReadable === []
                ? 'Backup and log files are not world-readable.'
                : sprintf('World-readable sensitive backup/log files found (%d): %s.', count($worldReadable), $this->formatList($worldReadable)),
                ['world_readable' => $worldReadable]);
        $catalogue['SEC-029'] = ($adminConfig['status'] ?? '') !== 'success' || $adminValues === [] ? $this->notChecked('Admin lockout configuration or edge rate-limit evidence is unavailable.', []) : ($weakAdminSettings === [] ? $this->notChecked('Magento lockout is configured, but WAF or edge rate-limit evidence was not supplied.', ['lockout_configured' => true]) : $this->check(false, 'Admin lockout configuration contains a non-positive protection value.', ['weak_settings' => $weakAdminSettings]));

        $deployment = $this->array($security, 'deployment_configuration');
        $configCatalogue = $this->array($security, 'catalogue_configuration');
        $configValues = $this->configValues($configCatalogue);
        $cacheTypes = is_array($magento['cache_types'] ?? null) ? $magento['cache_types'] : [];
        $catalogue['CFG-004'] = ($deployment['status'] ?? '') !== 'success' ? $this->notChecked((string)($deployment['reason'] ?? 'Session deployment configuration is unavailable.'), $deployment) : $this->check(in_array(strtolower((string)($deployment['session_backend'] ?? '')), ['files', 'redis', 'db', 'database'], true), 'Session backend is a recognized Magento backend.', ['backend' => $deployment['session_backend'] ?? null]);
        $catalogue['CFG-005'] = $cacheTypes === [] ? $this->notChecked('Magento cache status is unavailable.', []) : $this->check(in_array(true, $cacheTypes, true), 'At least one Magento application cache type is enabled.', ['enabled_cache_types' => array_keys(array_filter($cacheTypes))]);
        $fpcValues = $configValues['system/full_page_cache/caching_application'] ?? [];
        $catalogue['CFG-006'] = $fpcValues === [] ? $this->notChecked('Full-page cache application setting is not explicitly configured.', []) : $this->check(!array_diff($fpcValues, ['1', '2']), 'Full-page cache uses a supported built-in or Varnish application setting.', ['values' => $fpcValues]);
        $varnishValues = array_filter($configValues, static fn(array $values, string $path): bool => str_starts_with($path, 'system/full_page_cache/varnish/'), ARRAY_FILTER_USE_BOTH);
        $catalogue['CFG-007'] = in_array('2', $fpcValues, true) ? $this->check($varnishValues !== [], 'Varnish is selected and has Magento configuration evidence.', ['configured_paths' => array_keys($varnishValues)]) : $this->notChecked('Varnish is not selected for full-page cache.', []);
        $usesRedis = str_contains(strtolower((string)($deployment['session_backend'] ?? '')), 'redis') || str_contains(strtolower((string)($deployment['default_cache_backend'] ?? '')), 'redis');
        $catalogue['CFG-009'] = !$usesRedis ? $this->notChecked('Redis or Valkey is not selected as a session/cache backend.', []) : $this->check($redisStatus === 'success', 'Configured Redis or Valkey backend is reachable.', ['collector_status' => $redisStatus]);
        $catalogue['CFG-010'] = ($deployment['status'] ?? '') !== 'success' ? $this->notChecked('Database deployment configuration is unavailable.', []) : $this->check(($deployment['database_configured'] ?? false) === true && (string)($statuses['database']['status'] ?? 'success') === 'success', 'Database configuration is present and the Magento connection was readable.', ['database_configured' => $deployment['database_configured'] ?? false]);
        $searchConfig = $configValues['catalog/search/engine'] ?? [];
        $catalogue['CFG-011'] = $searchConfig === [] ? $this->notChecked('Catalog search engine configuration is not explicitly stored.', []) : $this->check($searchStatus === 'success', 'Configured catalog search has a reachable search-service collector.', ['engines' => $searchConfig, 'collector_status' => $searchStatus]);
        $stores = is_array($store['stores'] ?? null) ? $store['stores'] : [];
        $invalidStores = array_values(array_filter($stores, static fn($row): bool => !is_array($row) || (($row['is_active'] ?? false) && filter_var($row['base_url'] ?? '', FILTER_VALIDATE_URL) === false)));
        $catalogue['CFG-013'] = $stores === [] ? $this->notChecked('Store hierarchy metadata is unavailable.', []) : $this->check($invalidStores === [], 'Active stores have valid base URLs and were inventoried.', ['store_count' => count($stores), 'invalid_store_count' => count($invalidStores)]);
        $catalogue['CFG-017'] = !isset($configValues['tax/defaults/country']) ? $this->notChecked('Tax defaults are not explicitly configured.', []) : $this->check(!in_array('', $configValues['tax/defaults/country'], true), 'Tax default country values are populated.', ['values' => $configValues['tax/defaults/country']]);
        $carrierPaths = array_filter($configValues, static fn(array $values, string $path): bool => preg_match('#^carriers/.+/active$#', $path) === 1, ARRAY_FILTER_USE_BOTH);
        $activeCarrier = false; foreach ($carrierPaths as $values) if (in_array('1', $values, true)) $activeCarrier = true;
        $catalogue['CFG-018'] = $carrierPaths === [] ? $this->notChecked('No explicit carrier configuration is stored; quote-only policy may apply.', []) : $this->check($activeCarrier, 'At least one configured shipping carrier is active.', ['configured_carriers' => array_keys($carrierPaths)]);
        $paymentPaths = array_filter($configValues, static fn(array $values, string $path): bool => preg_match('#^payment/.+/active$#', $path) === 1, ARRAY_FILTER_USE_BOTH);
        $catalogue['CFG-019'] = $paymentPaths === [] ? $this->notChecked('No explicit payment-method configuration is stored.', []) : $this->check(true, 'Payment method configuration was inventoried without exposing credentials.', ['configured_methods' => array_keys($paymentPaths)]);
        $customerPaths = array_filter($configValues, static fn(array $values, string $path): bool => str_starts_with($path, 'customer/'), ARRAY_FILTER_USE_BOTH);
        $catalogue['CFG-025'] = $customerPaths === [] ? $this->notChecked('Customer account and reset configuration is not explicitly stored.', []) : $this->check(true, 'Customer account configuration was inventoried.', ['configured_paths' => array_keys($customerPaths)]);
        $inventoryPaths = array_filter($configValues, static fn(array $values, string $path): bool => str_starts_with($path, 'cataloginventory/'), ARRAY_FILTER_USE_BOTH);
        $catalogue['CFG-030'] = $inventoryPaths === [] ? $this->notChecked('Inventory configuration is not explicitly stored.', []) : $this->check(true, 'Inventory configuration was inventoried.', ['configured_paths' => array_keys($inventoryPaths)]);
        $oauth = $this->array($security, 'oauth_integrations');
        $catalogue['CFG-032'] = ($oauth['status'] ?? '') !== 'success' ? $this->notChecked((string)($oauth['reason'] ?? 'OAuth integration metadata is unavailable.'), $oauth) : $this->check(true, 'REST API integrations were inventoried without token values.', ['active_integrations' => $oauth['active'] ?? []]);
        $developerPaths = array_filter($configValues, static fn(array $values, string $path): bool => str_starts_with($path, 'dev/'), ARRAY_FILTER_USE_BOTH);
        $debugEnabled = false; foreach ($developerPaths as $path => $values) if (str_contains($path, '/debug') && in_array('1', $values, true)) $debugEnabled = true;
        $catalogue['CFG-034'] = $deploymentMode === '' ? $this->notChecked('Deployment mode is unavailable for developer configuration review.', []) : $this->check($deploymentMode === 'production' && !$debugEnabled, 'Production deployment mode has no enabled developer debug configuration.', ['deployment_mode' => $deploymentMode, 'debug_enabled' => $debugEnabled]);

        $withoutPk = $this->array($databaseAdvanced, 'tables_without_primary_key');
        $missingTables = is_array($withoutPk['tables'] ?? null) ? $withoutPk['tables'] : [];
        $catalogue['DB-006'] = ($withoutPk['status'] ?? '') !== 'success'
            ? $this->notChecked((string)($withoutPk['reason'] ?? 'Primary-key metadata is unavailable.'), $withoutPk)
            : $this->check($missingTables === [], $missingTables === []
                ? 'All Magento database tables have a primary key.'
                : sprintf('Database tables missing primary keys (%d): %s.', count($missingTables), implode(', ', $missingTables)), $withoutPk);
        $changelog = $this->array($databaseAdvanced, 'changelog_tables');
        $catalogue['DB-011'] = ($changelog['status'] ?? '') !== 'success' ? $this->notChecked('Changelog table size data is unavailable.', []) : $this->check((float)($changelog['largest_size_mb'] ?? 0) < 1024.0, 'Largest changelog table is below the 1 GB review threshold.', $changelog);
        $longQueries = $this->array($databaseAdvanced, 'long_running_queries');
        $catalogue['DB-014'] = ($longQueries['status'] ?? '') === 'unavailable' ? $this->notChecked('Database process-list access is unavailable.', $longQueries) : $this->check((int)($longQueries['count'] ?? 0) === 0, 'No non-idle database query exceeds the configured observation threshold.', $longQueries);
        $slowQuery = $this->array($databaseAdvanced, 'slow_query_evidence');
        $catalogue['DB-015'] = ($slowQuery['status'] ?? '') !== 'success' ? $this->notChecked((string)($slowQuery['reason'] ?? 'Slow-query evidence is unavailable.'), $slowQuery) : $this->check((float)($slowQuery['max_average_seconds'] ?? 0) < 1.0, 'No sampled query digest exceeds the one-second average review threshold.', $slowQuery);

        $integrity = $this->array($databaseAdvanced, 'integrity_checks');
        $integrityRule = function (string $key, string $message) use ($integrity): array {
            $result = $this->array($integrity, $key);
            $count = (int)($result['count'] ?? 0);
            $reason = ($result['status'] ?? '') !== 'success'
                ? (string)($result['reason'] ?? 'Required integrity evidence is unavailable.')
                : ($count === 0 ? $message : sprintf('%s (Detected %d orphaned/invalid references).', $message, $count));
            return ($result['status'] ?? '') !== 'success' ? $this->notChecked($reason, $result) : $this->check($count === 0, $reason, $result);
        };
        $catalogue['DB-016'] = $databaseAdvanced === [] ? $this->notChecked('Database deadlock and lock-wait evidence is unavailable.', []) : $this->check((int)($databaseAdvanced['deadlocks'] ?? 0) === 0 && (int)($databaseAdvanced['row_lock_waits'] ?? 0) === 0, 'No InnoDB deadlock or active lock-wait indicator was observed.', ['deadlocks' => $databaseAdvanced['deadlocks'] ?? null, 'row_lock_waits' => $databaseAdvanced['row_lock_waits'] ?? null]);
        $catalogue['DB-017'] = $integrityRule('foreign_keys', 'Every declared foreign-key target exists.');
        $catalogue['DB-018'] = $integrityRule('eav_linkage', 'No sampled mandatory EAV relation points to a missing record.');
        $catalogue['DB-019'] = $integrityRule('duplicate_sku', 'No duplicate product SKU was found.');
        $catalogue['DB-020'] = $integrityRule('eav_linkage', 'No EAV row references a missing entity or attribute.');
        $catalogue['DB-021'] = $integrityRule('attribute_metadata', 'Every sampled attribute has a valid entity type.');
        $catalogue['DB-022'] = $integrityRule('category_relationships', 'No category-product relation references a missing record.');
        $catalogue['DB-023'] = $integrityRule('website_assignments', 'No product-website assignment references a missing record.');
        $catalogue['DB-025'] = $integrityRule('quote_order', 'No sampled order references a missing quote.');
        $catalogue['DB-026'] = $integrityRule('msi_reservations', 'No MSI reservation references a missing stock.');
        $catalogue['DB-028'] = $this->notChecked('Backup freshness requires operator-supplied backup metadata or infrastructure evidence.', []);
        $connectionUse = $this->array($databaseAdvanced, 'connection_utilization');
        $catalogue['DB-031'] = ($connectionUse['status'] ?? '') !== 'success' ? $this->notChecked((string)($connectionUse['reason'] ?? 'Database connection utilization is unavailable.'), $connectionUse) : $this->check(is_numeric($connectionUse['utilization_percent'] ?? null) && (float)$connectionUse['utilization_percent'] < 80.0, 'Database connections are below the 80% warning threshold.', $connectionUse);
        $schemaMetadata = $this->array($databaseAdvanced, 'schema_metadata');
        $catalogue['DB-033'] = ($schemaMetadata['status'] ?? '') !== 'success' ? $this->notChecked((string)($schemaMetadata['reason'] ?? 'Schema metadata is unavailable.'), $schemaMetadata) : $this->check((int)($schemaMetadata['module_count'] ?? 0) > 0, 'Magento setup-module metadata is present.', $schemaMetadata);
        $remoteGrant = $this->array($databaseAdvanced, 'remote_grant_evidence');
        $catalogue['DB-034'] = ($remoteGrant['status'] ?? '') !== 'success' ? $this->notChecked((string)($remoteGrant['reason'] ?? 'Runtime database grant evidence is unavailable.'), $remoteGrant) : $this->check(!in_array('%', $remoteGrant['hosts'] ?? [], true), 'Runtime database user does not have an unrestricted wildcard host grant.', $remoteGrant);

        $disabledCacheTypes = array_keys(array_filter($cacheTypes, static fn($enabled): bool => !$enabled));
        $catalogue['PERF-001'] = $cacheTypes === []
            ? $this->notChecked('Magento cache type status is unavailable.', [])
            : $this->check($disabledCacheTypes === [], $disabledCacheTypes === []
                ? 'All discovered Magento cache types are enabled.'
                : sprintf('Disabled Magento cache types (%d): %s.', count($disabledCacheTypes), implode(', ', $disabledCacheTypes)),
                ['disabled_types' => $disabledCacheTypes]);
        $catalogue['PERF-002'] = $usesRedis ? $this->check($redisStatus === 'success', 'Configured Redis or Valkey cache backend is reachable.', ['collector_status' => $redisStatus]) : $this->notChecked('No Redis or Valkey backend is configured for the scanned deployment.', []);
        $fpc = $this->array($metrics, 'fpc');
        $catalogue['PERF-003'] = !isset($fpc['enabled']) ? $this->notChecked('Full-page cache status is unavailable.', []) : $this->check(($fpc['enabled'] ?? false) === true, 'Magento full-page cache is enabled.', ['tested_urls' => $fpc['tested_urls'] ?? 0, 'hit_rate_status' => $fpc['hit_rate_status'] ?? null]);
        $catalogue['PERF-004'] = $searchStatus === 'not_applicable' ? $this->notChecked('No search service is configured.', []) : $this->check($searchStatus === 'success', 'Configured search cluster is reachable.', ['collector_status' => $searchStatus]);
        $catalogue['PERF-005'] = $catalogue['SYS-015'];
        $cronCounts = $this->array($cron, 'status_counts');
        $catalogue['PERF-006'] = $cronCounts === [] ? $this->notChecked('Cron performance data is unavailable.', []) : $this->check((int)($cronCounts['error'] ?? 0) === 0 && (int)($cronCounts['missed'] ?? 0) === 0 && (int)($cron['stale_running_count'] ?? 0) === 0, 'No missed, errored, or stale running cron job was observed in the configured window.', ['status_counts' => $cronCounts, 'stale_running_count' => $cron['stale_running_count'] ?? 0]);
        $catalogue['PERF-007'] = $this->notChecked('Queue consumer process and backlog evidence requires an allow-listed queue integration.', []);
        $catalogue['PERF-008'] = $this->notChecked('PHP-FPM pool telemetry requires allow-listed server access.', []);
        $catalogue['PERF-009'] = ($php['opcache_ini_enabled'] ?? null) === null ? $this->notChecked('OPcache configuration is unavailable.', []) : $this->check(($php['opcache_ini_enabled'] ?? false) === true, 'OPcache is enabled for the scanned PHP configuration.', ['opcache_enabled' => $php['opcache_ini_enabled']]);
        $catalogue['PERF-011'] = $this->notChecked('Static deployment consistency requires a configured asset sample and deployment metadata inspection.', []);
        $catalogue['PERF-012'] = $catalogue['SYS-011'];
        $catalogue['PERF-018'] = ($slowQuery['status'] ?? '') !== 'success' ? $this->notChecked('Database query performance evidence is unavailable.', []) : $this->check((float)($slowQuery['max_average_seconds'] ?? 0) < 1.0 && (int)($longQueries['count'] ?? 0) === 0, 'No sampled database query exceeds the configured performance evidence threshold.', ['slow_query' => $slowQuery, 'long_running_queries' => $longQueries]);
        $memory = $this->array($system, 'memory');
        $catalogue['PERF-022'] = !is_numeric($memory['used_percent'] ?? null) || !is_numeric($system['disk']['used_percent'] ?? null) ? $this->notChecked('Host memory or disk utilization is unavailable.', []) : $this->check((float)$memory['used_percent'] < 80.0 && (float)$system['disk']['used_percent'] < 80.0, 'Current host memory and disk utilization are below the 80% warning threshold.', ['memory_used_percent' => $memory['used_percent'], 'disk_used_percent' => $system['disk']['used_percent'], 'load_average' => $system['load_average'] ?? null]);

        $logs = $this->array($metrics, 'logs');
        $logEntries = is_array($logs['exceptions'] ?? null) ? $logs['exceptions'] : [];
        $hasLogEvidence = $logEntries !== [] || in_array('read', is_array($logs['files'] ?? null) ? $logs['files'] : [], true);
        $logRule = function (array $needles, string $message) use ($logEntries, $hasLogEvidence): array {
            if (!$hasLogEvidence) return $this->notChecked('Relevant Magento log evidence is unavailable.', []);
            $count = 0;
            foreach ($logEntries as $entry) {
                $text = strtolower((string)(is_array($entry) ? (($entry['exception_type'] ?? '') . ' ' . ($entry['sample'] ?? '') . ' ' . ($entry['source'] ?? '')) : ''));
                foreach ($needles as $needle) if (str_contains($text, $needle)) { $count += (int)($entry['count'] ?? 1); break; }
            }
            return $this->check($count === 0, $message, ['matching_entry_count' => $count]);
        };
        $catalogue['LOG-001'] = $hasLogEvidence ? $this->check((int)($logs['exception_count'] ?? 0) === 0, 'No actionable Magento exception signature was observed.', ['exception_count' => $logs['exception_count'] ?? 0]) : $this->notChecked('Magento exception log evidence is unavailable.', []);
        $catalogue['LOG-002'] = $logRule(['critical', 'error'], 'No critical system-log error signature was observed.');
        $catalogue['LOG-004'] = $this->notChecked('var/report evidence is not collected by the bounded log reader.', []);
        $catalogue['LOG-006'] = $logRule(['graphql', 'webapi', 'oauth', 'http 5', 'http 4'], 'No GraphQL or REST API error signature was observed.');
        $catalogue['LOG-007'] = $logRule(['sqlstate', 'deadlock', 'lock wait', 'mysql server'], 'No SQL or database error signature was observed.');
        $catalogue['LOG-010'] = $this->check((int)($cronCounts['error'] ?? 0) === 0 && (int)($cronCounts['missed'] ?? 0) === 0 && ($catalogue['SYS-015']['compliant'] ?? false) === true, 'No cron or indexer failure was observed.', ['cron' => $cronCounts]);
        $catalogue['LOG-011'] = $logRule(['consumer', 'queue', 'amqp', 'dead letter', 'message rejected'], 'No queue failure signature was observed.');
        $catalogue['LOG-012'] = $logRule(['shipping', 'carrier', 'payment', 'gateway', 'declined', 'capture'], 'No shipping or payment failure signature was observed.');
        $catalogue['LOG-016'] = $logRule(['elasticsearch', 'opensearch', 'nonodesavailable', 'index_not_found'], 'No search-service error signature was observed.');
        $catalogue['LOG-017'] = $logRule(['redis', 'valkey', 'cache backend', 'connection refused', 'oom'], 'No Redis or cache error signature was observed.');
        $catalogue['LOG-018'] = $logRule(['setup:upgrade', 'setup:di:compile', 'static-content:deploy', 'composer', 'deploy failure'], 'No deployment failure signature was observed.');

        $catalogue['EXT-004'] = !isset($composer['validation_exit_code']) ? $this->notChecked('Composer validation evidence is unavailable.', []) : $this->check((int)$composer['validation_exit_code'] === 0, 'composer.json and composer.lock validate successfully.', ['validation_exit_code' => $composer['validation_exit_code']]);
        $catalogue['EXT-008'] = $this->notChecked('Vendor file modification evidence requires Composer status or repository metadata.', []);
        $catalogue['EXT-009'] = $this->notChecked('Custom-code PHP lint evidence requires an explicit bounded lint scan.', []);
        $catalogue['EXT-013'] = $this->notChecked('Magento XML schema validation requires an explicit URN-aware validation scan.', []);
        $catalogue['JOB-001'] = $catalogue['SYS-015'];
        $catalogue['JOB-003'] = $catalogue['SYS-013'];
        $catalogue['JOB-008'] = $this->notChecked('Queue consumer process status requires allow-listed process supervisor access.', []);
        $catalogue['JOB-009'] = $this->notChecked('Queue backlog evidence requires configured read-only queue management access.', []);
        $catalogue['STO-002'] = $this->notChecked('Category sample URL is not configured for a non-destructive storefront request.', []);
        $catalogue['STO-003'] = $this->notChecked('Product sample URL is not configured for a non-destructive storefront request.', []);
        $catalogue['STO-007'] = $this->notChecked('Checkout route availability requires a configured public request policy.', []);
        $catalogue['OPS-002'] = $this->notChecked('Restore-test evidence requires an operator-supplied backup or CI artifact record.', []);
        return $catalogue;
    }

    /** @param array<string, mixed> $details @return array<string, mixed> */
    private function check(bool $compliant, string $reason, array $details): array { return ['status' => $compliant ? 'pass' : 'fail', 'compliant' => $compliant, 'reason' => $reason, 'details' => $details]; }
    /** @param array<string, mixed> $details @return array<string, mixed> */
    private function notChecked(string $reason, array $details): array { return ['status' => 'not_checked', 'compliant' => true, 'reason' => $reason, 'details' => $details]; }
    /** @param array<string, mixed> $value @return array<string, mixed> */
    private function array(array $value, string $key): array { return is_array($value[$key] ?? null) ? $value[$key] : []; }
    private function releaseLine(string $version): string { return preg_match('/^(2\.4\.[0-9]+)/', $version, $match) === 1 ? $match[1] : ''; }
    private function majorMinor(string $version): string { return preg_match('/(\d+\.\d+)/', $version, $match) === 1 ? $match[1] : ''; }
    private function versionFromComposerOutput(string $value): string { return preg_match('/(\d+\.\d+(?:\.\d+)?)/', $value, $match) === 1 ? $match[1] : ''; }
    /** @param array<string, mixed> $config @return array<string, array<int, string>> */
    private function configValues(array $config): array
    {
        $values = [];
        foreach ((is_array($config['values'] ?? null) ? $config['values'] : []) as $row) {
            if (is_array($row) && isset($row['path'])) {
                $values[(string)$row['path']][] = (string)($row['value'] ?? '');
            }
        }
        return $values;
    }
    /** @param array<string, mixed> $php */
    private function phpLimitsAreUsable(array $php): bool
    {
        return (string)$php['memory_limit'] !== '0'
            && (int)$php['max_execution_time'] >= 0
            && (int)$php['max_input_time'] >= -1
            && (string)$php['upload_max_filesize'] !== '0'
            && (string)$php['post_max_size'] !== '0';
    }
    /** @param array<int|string, mixed> $items */
    private function formatList(array $items): string
    {
        $strings = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $strings[] = (string)($item['path'] ?? $item['file'] ?? $item['name'] ?? json_encode($item));
            } else {
                $strings[] = (string)$item;
            }
        }
        return implode(', ', $strings);
    }
}
