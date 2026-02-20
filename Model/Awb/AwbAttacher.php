<?php
/**
 * Attach AWB codes to shipments.
 */
namespace Bookurier\Shipping\Model\Awb;

use Bookurier\Shipping\Model\Config;
use Magento\Framework\App\ObjectManager;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order\Shipment\NotifierInterface as ShipmentNotifierInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

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

    /**
     * @var ShipmentNotifierInterface
     */
    private $shipmentNotifier;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        TrackFactory $trackFactory,
        ShipmentRepositoryInterface $shipmentRepository,
        ?ShipmentNotifierInterface $shipmentNotifier = null,
        ?Config $config = null,
        ?LoggerInterface $logger = null
    ) {
        $objectManager = ObjectManager::getInstance();
        $this->trackFactory = $trackFactory;
        $this->shipmentRepository = $shipmentRepository;
        $this->shipmentNotifier = $shipmentNotifier ?: $objectManager->get(ShipmentNotifierInterface::class);
        $this->config = $config ?: $objectManager->get(Config::class);
        $this->logger = $logger ?: $objectManager->get(LoggerInterface::class);
    }

    /**
     * @param OrderInterface $order
     * @param string $awbCode
     * @param int|null $shipmentId
     * @param bool $notifyCustomer
     * @throws LocalizedException
     */
    public function attach(
        OrderInterface $order,
        string $awbCode,
        ?int $shipmentId = null,
        bool $notifyCustomer = true
    ): void
    {
        $shipment = $this->resolveShipment($order, $shipmentId);

        $track = $this->trackFactory->create();
        $track->setCarrierCode('bookurier');
        $track->setTitle('Bookurier');
        $track->setTrackNumber($awbCode);

        $shipment->addTrack($track);
        $this->shipmentRepository->save($shipment);
        if ($notifyCustomer) {
            $this->notifyCustomerIfEnabled($order, $shipment);
        }
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

    /**
     * Send shipment email using Magento notifier when AWB notifications are enabled.
     *
     * @param OrderInterface $order
     * @param ShipmentInterface $shipment
     * @return void
     */
    private function notifyCustomerIfEnabled(OrderInterface $order, ShipmentInterface $shipment): void
    {
        if (!$this->config->isCustomerNotificationOnAwbCreateEnabled((int)$order->getStoreId())) {
            return;
        }

        try {
            // Force sync to avoid relying on async email cron for AWB-created notifications.
            $this->shipmentNotifier->notify($order, $shipment, null, true);
        } catch (\Throwable $e) {
            $this->logger->warning('Bookurier AWB shipment notification failed.', [
                'order_id' => (int)$order->getEntityId(),
                'shipment_id' => (int)$shipment->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
