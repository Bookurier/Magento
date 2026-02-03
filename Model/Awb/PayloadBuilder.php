<?php
/**
 * Build Bookurier AWB payloads from Magento orders.
 */
namespace Bookurier\Shipping\Model\Awb;

use Bookurier\Shipping\Model\Config;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Address;

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
     * @return array
     */
    public function build(OrderInterface $order, array $overrides = []): array
    {
        /** @var Address|null $address */
        $address = $order->getShippingAddress();
        $streetLines = $address ? $address->getStreet() : [];

        $payload = [
            'pickup_point' => $this->config->getPickupPoint((int)$order->getStoreId()),
            'unq' => $order->getIncrementId(),
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
            'service' => $this->config->getServiceCode((int)$order->getStoreId()),
            'packs' => $this->config->getDefaultPacks((int)$order->getStoreId()),
            'weight' => $this->config->getDefaultWeight((int)$order->getStoreId()),
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

        if (isset($overrides['street'])) {
            $payload['street'] = $overrides['street'];
        }

        return $payload;
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
