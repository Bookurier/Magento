# Bookurier Shipping for Magento 2

## Install (Composer)
```
composer require bookurier/magento2-shipping
bin/magento module:enable Bookurier_Shipping
bin/magento setup:upgrade
bin/magento cache:flush
```

## Install (app/code)
1. Copy this module to `app/code/Bookurier/Shipping`.
2. Run:
```
bin/magento module:enable Bookurier_Shipping
bin/magento setup:upgrade
bin/magento cache:flush
```

## Configuration
Go to `Stores > Configuration > Sales > Shipping Methods > Bookurier` and fill in the API settings.

## Notes
- This module provides the Bookurier carrier at checkout and lays the foundation for AWB creation, PDF printing, and tracking integration.
