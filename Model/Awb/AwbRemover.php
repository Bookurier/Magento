<?php
/**
 * Remove Bookurier AWB tracking from shipments.
 */
namespace Bookurier\Shipping\Model\Awb;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\ShipmentTrackRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;

class AwbRemover
{
    /**
     * @var ShipmentTrackRepositoryInterface
     */
    private $trackRepository;

    public function __construct(ShipmentTrackRepositoryInterface $trackRepository)
    {
        $this->trackRepository = $trackRepository;
    }

    /**
     * Remove Bookurier tracking entries from all shipments on an order.
     *
     * @param OrderInterface $order
     * @return int number of removed tracks
     * @throws LocalizedException
     */
    public function removeForOrder(OrderInterface $order): int
    {
        $removed = 0;
        foreach ($order->getShipmentsCollection() as $shipment) {
            foreach ($shipment->getTracksCollection() as $track) {
                if ($track->getCarrierCode() !== 'bookurier') {
                    continue;
                }
                $this->trackRepository->delete($track);
                $removed++;
            }
        }

        if ($removed === 0) {
            throw new LocalizedException(__('No Bookurier AWB found for this order.'));
        }

        return $removed;
    }
}
