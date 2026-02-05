<?php
/**
 * Bookurier config helper.
 */
namespace Bookurier\Shipping\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const XML_PATH_ACTIVE = 'carriers/bookurier/active';
    public const XML_PATH_API_USER = 'carriers/bookurier/api_user';
    public const XML_PATH_API_PWD = 'carriers/bookurier/api_pwd';
    public const XML_PATH_API_KEY = 'carriers/bookurier/api_key';
    public const XML_PATH_PICKUP_POINT = 'carriers/bookurier/pickup_point';
    public const XML_PATH_SERVICE = 'carriers/bookurier/service';
    public const XML_PATH_DEFAULT_PACKS = 'carriers/bookurier/default_packs';
    public const XML_PATH_DEFAULT_WEIGHT = 'carriers/bookurier/default_weight';
    public const XML_PATH_ENABLE_BULK_PRINT_BUTTON = 'carriers/bookurier/enable_bulk_print_button';
    public const XML_PATH_API_MOCK = 'carriers/bookurier/api_mock';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
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
        return (string)$this->scopeConfig->getValue(self::XML_PATH_API_PWD, ScopeInterface::SCOPE_STORE, $storeId);
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
}
