<?php
/**
 * Attach AWB codes to shipments.
 */
namespace Bookurier\Shipping\Model\Awb;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
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
     * @param int|null $shipmentId
     * @throws LocalizedException
     */
    public function attach(OrderInterface $order, string $awbCode, ?int $shipmentId = null): void
    {
        $shipment = $this->resolveShipment($order, $shipmentId);

        $track = $this->trackFactory->create();
        $track->setCarrierCode('bookurier');
        $track->setTitle('Bookurier');
        $track->setTrackNumber($awbCode);

        $shipment->addTrack($track);
        $this->shipmentRepository->save($shipment);
    }

    /**
     * Resolve target shipment. If shipment ID is missing, keep current behavior and use first shipment.
     *
     * @param OrderInterface $order
     * @param int|null $shipmentId
     * @return ShipmentInterface
     * @throws LocalizedException
     */
    private function resolveShipment(OrderInterface $order, ?int $shipmentId): ShipmentInterface
    {
        if ($shipmentId === null || $shipmentId <= 0) {
            $shipment = $order->getShipmentsCollection()->getFirstItem();
            if (!$shipment || !$shipment->getId()) {
                throw new LocalizedException(__('Order has no shipment to attach AWB. Create a shipment first.'));
            }
            return $shipment;
        }

        try {
            $shipment = $this->shipmentRepository->get($shipmentId);
        } catch (\Exception $e) {
            throw new LocalizedException(__('Shipment no longer exists.'));
        }

        if ((int)$shipment->getOrderId() !== (int)$order->getEntityId()) {
            throw new LocalizedException(__('Selected shipment does not belong to this order.'));
        }

        return $shipment;
    }
}
