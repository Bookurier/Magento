<?php
/**
 * Build Bookurier AWB payloads from Magento orders.
 */
namespace Bookurier\Shipping\Model\Awb;

use Bookurier\Shipping\Model\Config;
use Magento\Sales\Api\Data\OrderInterface;

class PayloadBuilder
{
    /**
     * @var Config
     */
    private $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @param OrderInterface $order
     * @param array $overrides
     * @param int|null $shipmentId
     * @return array
     */
    public function build(OrderInterface $order, array $overrides = [], ?int $shipmentId = null): array
    {
        /** @var object|null $address */
        $address = $this->resolveAddress($order, $shipmentId);
        $streetLines = $address ? $address->getStreet() : [];
        $unq = (string)$order->getIncrementId();
        if ($shipmentId !== null && $shipmentId > 0) {
            $unq .= '-' . $shipmentId;
        }

        $payload = [
            'pickup_point' => $this->config->getPickupPoint((int)$order->getStoreId()),
            'unq' => $unq,
            'recv' => $address ? trim($address->getFirstname() . ' ' . $address->getLastname()) : '',
            'phone' => $address ? (string)$address->getTelephone() : '',
            'email' => $address ? (string)$address->getEmail() : (string)$order->getCustomerEmail(),
            'country' => $address ? $this->mapCountry((string)$address->getCountryId()) : '',
            'city' => $address ? (string)$address->getCity() : '',
            'zip' => $address ? (string)$address->getPostcode() : '',
            'district' => $address ? (string)$address->getRegion() : '',
            'street' => $streetLines[0] ?? '',
            'no' => $overrides['no'] ?? '',
            'bl' => $overrides['bl'] ?? '',
            'ent' => $overrides['ent'] ?? '',
            'floor' => $overrides['floor'] ?? '',
            'apt' => $overrides['apt'] ?? '',
            'service' => $overrides['service'] ?? $this->config->getServiceCode((int)$order->getStoreId()),
            'packs' => $overrides['packs'] ?? $this->config->getDefaultPacks((int)$order->getStoreId()),
            'weight' => $overrides['weight'] ?? $this->config->getDefaultWeight((int)$order->getStoreId()),
            'rbs_val' => $overrides['rbs_val'] ?? 0,
            'insurance_val' => $overrides['insurance_val'] ?? 0,
            'ret_doc' => $overrides['ret_doc'] ?? 0,
            'weekend' => $overrides['weekend'] ?? 0,
            'unpack' => $overrides['unpack'] ?? 0,
            'exchange_pack' => $overrides['exchange_pack'] ?? 0,
            'confirmation' => $overrides['confirmation'] ?? 0,
            'notes' => $overrides['notes'] ?? '',
            'ref1' => $overrides['ref1'] ?? '',
            'ref2' => $overrides['ref2'] ?? '',
        ];

        return $payload;
    }

    /**
     * Resolve destination address from order shipping address.
     *
     * @param OrderInterface $order
     * @param int|null $shipmentId
     * @return object|null
     */
    private function resolveAddress(OrderInterface $order, ?int $shipmentId): ?object
    {
        return $order->getShippingAddress() ?: null;
    }

    /**
     * Map Magento country code to Bookurier expected value.
     *
     * @param string $countryCode
     * @return string
     */
    private function mapCountry(string $countryCode): string
    {
        if (in_array($countryCode, ['RO', 'ROU'], true)) {
            return 'Romania';
        }
        return $countryCode;
    }
}
