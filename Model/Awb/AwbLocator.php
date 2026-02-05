<?php
/**
 * Locate Bookurier AWB codes from orders.
 */
namespace Bookurier\Shipping\Model\Awb;

use Magento\Sales\Api\Data\OrderInterface;

class AwbLocator
{
    /**
     * @param OrderInterface $order
     * @return string[]
     */
    public function getBookurierAwbs(OrderInterface $order): array
    {
        $awbs = [];
        foreach ($order->getShipmentsCollection() as $shipment) {
            foreach ($shipment->getTracksCollection() as $track) {
                if ($track->getCarrierCode() === 'bookurier' && $track->getTrackNumber()) {
                    $awbs[] = (string)$track->getTrackNumber();
                }
            }
        }
        return array_values(array_unique($awbs));
    }
}
