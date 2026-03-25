<?php
/**
 * Fulfillment-specific config wrapper.
 */
namespace Bookurier\Shipping\Model\Fulfillment;

use Bookurier\Shipping\Model\Config as ShippingConfig;

class Config
{
    /**
     * @var ShippingConfig
     */
    private $shippingConfig;

    public function __construct(ShippingConfig $shippingConfig)
    {
        $this->shippingConfig = $shippingConfig;
    }

    public function getApiUrl(?int $storeId = null): string
    {
        return $this->shippingConfig->getFulfillmentApiUrl($storeId);
    }

    public function getApiUser(?int $storeId = null): string
    {
        return $this->shippingConfig->getFulfillmentApiUser($storeId);
    }

    public function getApiPassword(?int $storeId = null): string
    {
        return $this->shippingConfig->getFulfillmentApiPassword($storeId);
    }

    public function getPacktype(?int $storeId = null): int
    {
        return $this->shippingConfig->getFulfillmentPacktype($storeId);
    }

    public function getCourierOverride(?int $storeId = null): string
    {
        return $this->shippingConfig->getFulfillmentCourierOverride($storeId);
    }
}
