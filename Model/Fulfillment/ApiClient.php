<?php
/**
 * Fulfillment API client wrapper.
 */
namespace Bookurier\Shipping\Model\Fulfillment;

use Bookurier\Shipping\Model\Api\Client as ShippingApiClient;

class ApiClient
{
    /**
     * @var ShippingApiClient
     */
    private $shippingApiClient;

    public function __construct(ShippingApiClient $shippingApiClient)
    {
        $this->shippingApiClient = $shippingApiClient;
    }

    /**
     * @param string $messageXml
     * @param int|null $storeId
     * @return array{status:string,number:string,description:string,raw:string}
     */
    public function addOrder(string $messageXml, ?int $storeId = null): array
    {
        return $this->shippingApiClient->addFulfillmentOrder($messageXml, $storeId);
    }
}
