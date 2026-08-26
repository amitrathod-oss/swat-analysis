# Asiamarket Magento Health Check

Read-only Magento Open Source health analyzer distributed as a Composer module.

## Install in a Magento project

Add this repository to the Magento project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:Sigma-Infolutions/swat-analysis.git"
        }
    ]
}
```

Then install and enable the module:

```bash
composer require asiamarket/module-health-check:^1.0
php bin/magento module:enable Asiamarket_HealthCheck
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/health scan --format=pdf
```

The module does not flush caches, reindex, modify application data, or apply patches.
Its health score is a custom local score and is not Adobe's SWAT Health Index.
