<?php
/**
 * Attach AWB codes to shipments.
 */
namespace Bookurier\Shipping\Model\Awb;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;

class AwbAttacher
{
    /**
     * @var TrackFactory
     */
    private $trackFactory;

    /**
     * @var ShipmentRepositoryInterface
     */
    private $shipmentRepository;

    public function __construct(
        TrackFactory $trackFactory,
        ShipmentRepositoryInterface $shipmentRepository
    ) {
        $this->trackFactory = $trackFactory;
        $this->shipmentRepository = $shipmentRepository;
    }

    /**
     * @param OrderInterface $order
     * @param string $awbCode
     * @throws LocalizedException
     */
    public function attach(OrderInterface $order, string $awbCode): void
    {
        $shipment = $order->getShipmentsCollection()->getFirstItem();

        if (!$shipment || !$shipment->getId()) {
            throw new LocalizedException(__('Order has no shipment to attach AWB. Create a shipment first.'));
        }

        $track = $this->trackFactory->create();
        $track->setCarrierCode('bookurier');
        $track->setTitle('Bookurier');
        $track->setTrackNumber($awbCode);

        $shipment->addTrack($track);
        $this->shipmentRepository->save($shipment);
    }
}
