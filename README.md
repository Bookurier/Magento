# Bookurier Shipping for Magento 2

## Install (app/code)
1. Copy this module to `app/code/Bookurier/Shipping`.
2. Run:
```
bin/magento module:enable Bookurier_Shipping
bin/magento setup:upgrade
bin/magento cache:flush
```

## Install (GitHub)
From the Magento root, bootstrap the install directly from GitHub:

```bash
curl -fsSL https://raw.githubusercontent.com/Bookurier/Magento/main/install-github.sh | bash
```

Run it as a non-`root` user. The script will execute Magento CLI commands as `BOOKURIER_RUN_USER` if provided, otherwise as the Magento root owner.

Example:

```bash
BOOKURIER_RUN_USER=www-data curl -fsSL https://raw.githubusercontent.com/Bookurier/Magento/main/install-github.sh | bash
```

See [INSTALL_FROM_GITHUB.md](INSTALL_FROM_GITHUB.md) for the full GitHub flow and the manual fallback commands.

## Configuration
Go to `Stores > Configuration > Sales > Shipping Methods > Bookurier` and fill in the API settings.

## Notes
- This module provides the Bookurier carrier at checkout and lays the foundation for AWB creation, PDF printing, and tracking integration.
