<?php
/**
 * Bookurier config helper.
 */
namespace Bookurier\Shipping\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const XML_PATH_ACTIVE = 'carriers/bookurier/active';
    public const XML_PATH_API_USER = 'carriers/bookurier/api_user';
    public const XML_PATH_API_PWD = 'carriers/bookurier/api_pwd';
    public const XML_PATH_API_KEY = 'carriers/bookurier/api_key';
    public const XML_PATH_PICKUP_POINT = 'carriers/bookurier/pickup_point';
    public const XML_PATH_SERVICE = 'carriers/bookurier/service';
    public const XML_PATH_PRINT_AWB_MODE = 'carriers/bookurier/print_awb_mode';
    public const XML_PATH_PRINT_AWB_FORMAT = 'carriers/bookurier/print_awb_format';
    public const XML_PATH_DEFAULT_PACKS = 'carriers/bookurier/default_packs';
    public const XML_PATH_DEFAULT_WEIGHT = 'carriers/bookurier/default_weight';
    public const XML_PATH_ENABLE_BULK_PRINT_BUTTON = 'carriers/bookurier/enable_bulk_print_button';
    public const XML_PATH_API_MOCK = 'carriers/bookurier/api_mock';
    public const XML_PATH_SALLOW_SPECIFIC = 'carriers/bookurier/sallowspecific';
    public const XML_PATH_SPECIFIC_COUNTRY = 'carriers/bookurier/specificcountry';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
    }

    public function isActive(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ACTIVE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getApiUser(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_API_USER, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getApiPassword(?int $storeId = null): string
    {
        $value = (string)$this->scopeConfig->getValue(self::XML_PATH_API_PWD, ScopeInterface::SCOPE_STORE, $storeId);
        if ($value === '' || preg_match('/^\*+$/', $value)) {
            return '';
        }

        // Legacy installs may still have plaintext credentials in DB.
        if (!$this->isEncryptedConfigValue($value)) {
            return $value;
        }

        return $this->encryptor->decrypt($value);
    }

    public function getApiKey(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getPickupPoint(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_PICKUP_POINT, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getServiceCode(?int $storeId = null): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_SERVICE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getPrintAwbMode(?int $storeId = null): string
    {
        $mode = (string)$this->scopeConfig->getValue(self::XML_PATH_PRINT_AWB_MODE, ScopeInterface::SCOPE_STORE, $storeId);
        return in_array($mode, ['s', 'm'], true) ? $mode : 'm';
    }

    public function getPrintAwbFormat(?int $storeId = null): string
    {
        $format = (string)$this->scopeConfig->getValue(self::XML_PATH_PRINT_AWB_FORMAT, ScopeInterface::SCOPE_STORE, $storeId);
        return in_array($format, ['pdf', 'html'], true) ? $format : 'pdf';
    }

    public function getDefaultPacks(?int $storeId = null): int
    {
        return (int)$this->scopeConfig->getValue(self::XML_PATH_DEFAULT_PACKS, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getDefaultWeight(?int $storeId = null): float
    {
        return (float)$this->scopeConfig->getValue(self::XML_PATH_DEFAULT_WEIGHT, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isApiMockEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_API_MOCK, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isBulkPrintButtonEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLE_BULK_PRINT_BUTTON, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Check if destination country is allowed by carrier config.
     */
    public function isCountryAllowed(string $countryId, ?int $storeId = null): bool
    {
        $countryId = strtoupper(trim($countryId));
        if ($countryId === '') {
            return false;
        }

        $allowSpecific = (string)$this->scopeConfig->getValue(
            self::XML_PATH_SALLOW_SPECIFIC,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        // "0" means all allowed; "1" means only configured countries.
        if ($allowSpecific !== '1') {
            return true;
        }

        $specific = (string)$this->scopeConfig->getValue(
            self::XML_PATH_SPECIFIC_COUNTRY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $allowedCountries = array_filter(array_map('trim', explode(',', strtoupper($specific))));
        if (empty($allowedCountries)) {
            return false;
        }

        return in_array($countryId, $allowedCountries, true);
    }

    /**
     * Check whether a config value follows Magento encrypted value format.
     */
    private function isEncryptedConfigValue(string $value): bool
    {
        return (bool)preg_match('/^\d+:\d+:.+$/', $value);
    }
}
