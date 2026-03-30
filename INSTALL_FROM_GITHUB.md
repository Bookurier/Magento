# Install Bookurier From GitHub

This guide installs the Bookurier Magento 2 module from the GitHub repository:

`https://github.com/Bookurier/Magento.git`

## Warning

Before installing this module, create a full backup of both the Magento files and the database.

Do not proceed unless you have a verified backup that can be restored if the installation, compilation, or database upgrade causes problems.

## Requirements

- Magento root access on the target server
- PHP CLI available
- Git installed
- Permission to write into `app/code`
- Run the installer as the same user that owns the Magento files, not as `root`
- `sudo` available if Magento CLI must run as a different user

## Quick Install

Switch to the Magento file owner first. Example:

```bash
sudo -u etest -H bash
```

Then run the installer directly from GitHub while you are in the Magento root:

```bash
curl -fsSL https://raw.githubusercontent.com/Bookurier/Magento/main/install-github.sh | bash
```

If Magento should generate files as `www-data`, set the runtime user explicitly:

```bash
BOOKURIER_RUN_USER=www-data curl -fsSL https://raw.githubusercontent.com/Bookurier/Magento/main/install-github.sh | bash
```

Or with `wget`:

```bash
wget -qO- https://raw.githubusercontent.com/Bookurier/Magento/main/install-github.sh | bash
```

If you are not inside the Magento root, pass the target path:

```bash
curl -fsSL https://raw.githubusercontent.com/Bookurier/Magento/main/install-github.sh | bash -s -- /path/to/magento
```

If you prefer, you can also download the script first, place it in the Magento root, and run:

```bash
bash install-github.sh
```

The installer will:

- clone `https://github.com/Bookurier/Magento.git`
- place the module in `app/code/Bookurier/Shipping`
- run Magento CLI as `BOOKURIER_RUN_USER` when provided
- enable `Bookurier_Shipping`
- run `setup:upgrade`
- run `setup:di:compile`
- flush cache

## Manual Install Steps

If you prefer to run each command yourself:

```bash
sudo -u etest -H bash
cd /path/to/magento
mkdir -p app/code/Bookurier
git clone --branch main https://github.com/Bookurier/Magento.git app/code/Bookurier/Shipping
php bin/magento module:enable Bookurier_Shipping
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

## Optional Branch Override

The script supports a custom branch and repository URL through environment variables:

```bash
BOOKURIER_BRANCH=main BOOKURIER_REPO_URL=https://github.com/Bookurier/Magento.git BOOKURIER_RUN_USER=www-data bash install-github.sh /path/to/magento
```

For a raw GitHub bootstrap call:

```bash
BOOKURIER_BRANCH=main BOOKURIER_RUN_USER=www-data curl -fsSL https://raw.githubusercontent.com/Bookurier/Magento/main/install-github.sh | bash -s -- /path/to/magento
```

## After Installation

After the install script or manual commands finish, set cache ownership correctly:

```bash
sudo chown -R etest:www-data var/cache
```

Configure the module in Magento Admin:

`Stores > Configuration > Sales > Shipping Methods > Bookurier`

## Uninstall And Retry

For a reinstall test, disable the module and remove the code as the same non-root Magento user:

```bash
cd /path/to/magento
php bin/magento module:disable Bookurier_Shipping
rm -rf app/code/Bookurier/Shipping
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

That removes the code and unregisters the module, but Magento will not fully clean up custom database artifacts for this `app/code` module automatically.

If you want a fully clean retry, also remove these database artifacts manually:

- tables `bookurier_awb_queue` and `bookurier_awb_status`
- row `bookurier_pending_awb` from `sales_order_status`
- related row from `sales_order_status_state`
- patch entry `Bookurier\\Shipping\\Setup\\Patch\\Data\\AddBookurierPendingAwbStatus` from `patch_list`

Do that only if you explicitly want a fresh reinstall from zero state.
