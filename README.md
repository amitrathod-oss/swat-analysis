# Mha Magento Health Check

Read-only Magento Open Source health analyzer distributed as a Composer module.
It collects local Magento, PHP, Composer, MySQL, Redis, OpenSearch, filesystem,
HTTP, security-header, FPC, cron, indexer, patch, store, and application-log data.

The analyzer does not apply fixes. Its score is a transparent custom score and is
not Adobe's SWAT Health Index.

## Requirements

- Magento Open Source 2.4.7 or a compatible Magento version using framework 103.x
- PHP 8.1, 8.2, or 8.3
- Composer 2.x
- `mpdf/mpdf` and Symfony dependencies (installed automatically by Composer)
- Permission to run Magento CLI commands
- Write permission for Magento's `var/` directory

The module can run when Redis, OpenSearch, or other optional services are unavailable;
those services are reported as unavailable rather than treated as healthy.

This package is independent of any project-specific vendor module. Its Magento module
name is `Mha_HealthCheck` and its Composer package name is `mha/module-health-check`.

## Install with Composer

Run the commands from the root of the Magento project where the module should be
installed. Do not run the installation command from this package repository itself.

### Install the current Git branch

The current development branch is `swat-report`, so Composer must use the
`dev-swat-report` version:

```bash
composer config repositories.swat-analysis vcs https://github.com/amitrathod-oss/swat-analysis.git
composer require mha/module-health-check:dev-swat-report
```

Composer downloads the package and Magento's Composer installer places it at:

```text
app/code/Mha/HealthCheck
```

### Enable and register the module

After Composer finishes, run:

```bash
php bin/magento module:enable Mha_HealthCheck
php bin/magento setup:upgrade
```

For production mode, also compile dependency-injection metadata:

```bash
php bin/magento setup:di:compile
```

The module does not require a cache flush or reindex to run the scan. If your normal
deployment process requires cache or static-content handling, follow that process
separately.

### Install from a tagged release

Once a stable tag such as `1.0.0` is published, use:

```bash
composer config repositories.swat-analysis vcs https://github.com/amitrathod-oss/swat-analysis.git
composer require mha/module-health-check:^1.0
```

For a private repository, configure GitHub SSH authentication or a GitHub Personal
Access Token. Never put a token or password directly in a command-line URL.

### Migrate from an earlier package name

If an earlier installation used `sigma/module-health-check` or another vendor name,
remove that old package through Composer before installing this package. The module
identifier changed, so Magento treats `Mha_HealthCheck` as a separate module:

```bash
composer remove sigma/module-health-check
composer require mha/module-health-check:dev-swat-report
php bin/magento setup:upgrade
```

## Verify the installation

Check that Magento sees the module and command:

```bash
php bin/magento module:status Mha_HealthCheck
php bin/magento list --raw | grep '^health:scan$'
```

Expected command:

```text
health:scan    Run the read-only Magento Open Source health scan.
```

## Run a scan

Run a JSON scan:

```bash
php bin/magento health:scan --format=json
```

Generate HTML or PDF:

```bash
php bin/magento health:scan --format=html
php bin/magento health:scan --format=pdf
```

Default reports are written to:

```text
var/health-reports/latest.json
var/health-reports/latest.html
var/health-reports/latest.pdf
var/health-reports/history/
```

The PDF can also be downloaded from the Admin Health Check Dashboard when a PDF
has already been generated.

## Scan options

```text
--format=FORMAT             json, html, or pdf
--base-url=URL              URL used for representative HTTP checks
--output=PATH               Output file or directory
--only=COLLECTORS           Comma-separated collector list
--skip=COLLECTORS           Comma-separated collectors to skip
--no-history                Do not write a history snapshot
--magento-root=PATH         Magento root used for filesystem checks
```

Examples:

```bash
php bin/magento health:scan --format=json --base-url=https://example.com
php bin/magento health:scan --format=json --only=extensions,store,php
php bin/magento health:scan --format=pdf --skip=redis,opensearch
php bin/magento health:scan --format=json --output=var/reports/site-health.json
```

## Admin dashboard

After enabling the module, sign in to Magento Admin and open:

```text
System > Health Check Dashboard
```

The dashboard automatically generates a JSON and PDF report the first time it is opened when no report exists. Use the Run New Scan button to refresh it after an administrator action. If automatic generation fails, run `php bin/magento health:scan --format=json` from the Magento project root. The scan is performed by Magento PHP; `ddev exec` is not required and is intentionally not part of the module instructions.

### Representative URLs

Representative URLs are a small, real storefront sample used for read-only HTTP,
security-header, and full-page-cache checks. The analyzer starts with the active
store home and search URLs, then discovers one published category, product, and CMS
page from Magento's URL data when available. Values under `http.urls` or `fpc.urls`
in `etc/healthcheck.yaml` override those samples. The report shows both the count and
the page types tested; it does not claim that every storefront URL was checked.

The dashboard includes:

- Custom health score and risk totals
- Recommendations and evidence-backed findings
- Sanitized application exceptions
- Magento module and Composer package inventory with versions
- Store, PHP, database, Redis, OpenSearch, filesystem, and HTTP information
- Configured Composer patches and local source-file status
- PDF download
- Collector status and scan errors
- Explicit status for unavailable external integrations

The Admin role must have the `Health Check Dashboard` ACL permission under the
System menu.

## Configuration

The default read-only configuration is:

```text
app/code/Mha/HealthCheck/etc/healthcheck.yaml
```

The file contains scan windows, thresholds, required PHP extensions, HTTP/FPC
settings, security headers, report profile settings, and optional integration flags.

URLs support `{base_url}`. The analyzer resolves the active Magento store URL and
automatically discovers representative category, product, CMS, search, and home
paths from local Magento data. You may provide safe environment-specific URLs when
automatic discovery is not suitable.

Example:

```yaml
http:
  urls:
    home: '{base_url}/'
    search: '{base_url}/catalogsearch/result/?q=healthcheck'
```

Do not put passwords, API tokens, database credentials, or private keys in this file.

## What is measured

Local collectors include:

- Magento version, edition, deployment mode, cache types, and module counts
- All enabled Magento modules and installed Composer package metadata
- PHP version, SAPI, settings, loaded extensions, required-extension coverage, and OPcache
- Composer version, security audit result, vulnerable packages, and abandoned packages
- MySQL/MariaDB version, table sizes, attribute option counts, buffer-pool information,
  triggers, deadlocks, long-running queries, and catalog depth
- Cron failures, missed jobs, pending jobs, stale running jobs, and recent errors
- Indexer status and unexpected real-time indexers
- Redis and OpenSearch connectivity and available service metrics
- Filesystem capacity, memory, CPU/load information, and sensitive-path checks
- Representative HTTP status, timing, cacheability, and response headers
- FPC enabled state and measurable HIT/MISS rate
- TLS certificate availability and expiry where the certificate can be verified
- Required security headers
- Sanitized and fingerprinted `var/log` exceptions within the configured window
- Composer patch declarations and local patch-file presence
- Magento store codes, names, URLs, currencies, and time zones

## External data limitations

This package is not Adobe's cloud SWAT service and does not pretend to reproduce
Adobe-only data. The following remain unavailable unless separate adapters and
credentials are implemented:

- Adobe SWAT cloud recommendations and exact Health Index
- Adobe Security Scan results
- Quality Patches Tool recommendation feed
- New Relic Managed Alerts and Datadog data
- Fastly analytics
- Adobe Marketplace latest-version comparison data
- Adobe customer/support ticket data
- Upgrade Compatibility Tool results

Unavailable or unconfigured external sources are displayed explicitly and are not
converted into dummy healthy values or score penalties.

## Safety and read-only behavior

The analyzer:

- Does not flush caches
- Does not trigger reindexing
- Does not modify Magento configuration
- Does not modify database tables or records
- Does not modify Redis or OpenSearch
- Does not apply or remove patches
- Does not restart services
- Sanitizes report values to avoid exposing secrets
- Continues scanning when an individual collector is unavailable

Installing or enabling a Magento module is a normal deployment change, but the scan
itself is read-only.

## Updating the module

Update the branch version:

```bash
composer update mha/module-health-check
php bin/magento setup:upgrade
php bin/magento setup:di:compile
```

Update to a tagged release when available:

```bash
composer require mha/module-health-check:^1.0
```

## Troubleshooting

### Composer cannot find the package

Confirm the VCS repository and branch version:

```bash
composer config repositories.swat-analysis vcs https://github.com/amitrathod-oss/swat-analysis.git
composer show mha/module-health-check --all
composer require mha/module-health-check:dev-swat-report
```

### `health:scan` is not defined

Run:

```bash
php bin/magento module:status Mha_HealthCheck
php bin/magento module:enable Mha_HealthCheck
php bin/magento setup:upgrade
php bin/magento setup:di:compile
```

### The Admin page is missing

Confirm the module is enabled, clear only the generated dependency metadata through
your normal deployment process, and verify that the Admin role has the module ACL
permission. The route is:

```text
healthcheck/dashboard/index
```

### The report says no PDF is available

Generate one first:

```bash
php bin/magento health:scan --format=pdf
```

### FPC hit rate is `N/A`

This means the URLs were tested but no response exposed a recognizable HIT/MISS
cache marker. It is not treated as a 0% hit rate. Configure an observable cache
header or review the raw URL results in the JSON report.

## Development validation

Validate Composer metadata:

```bash
composer validate --no-check-publish
```

Check PHP syntax:

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
```

The package includes unit-test sources under `Test/Unit`. Run them with the PHPUnit
version supplied by the consuming Magento project when `vendor/bin/phpunit` is
available.
