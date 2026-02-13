<?php
/**
 * Enqueue orders for Bookurier AWB creation.
 */
namespace Bookurier\Shipping\Model\Queue;

use Bookurier\Shipping\Model\Config;
use Bookurier\Shipping\Model\Cron\ProcessAwbQueue;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

class Enqueuer
{
    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var Config
     */
    private $config;

    public function __construct(
        ResourceConnection $resource,
        DateTime $dateTime,
        OrderRepositoryInterface $orderRepository,
        Config $config
    ) {
        $this->resource = $resource;
        $this->dateTime = $dateTime;
        $this->orderRepository = $orderRepository;
        $this->config = $config;
    }

    /**
     * @param OrderInterface[] $orders
     * @return array{enqueued:int, skipped:int, skipped_items:array}
     */
    public function enqueueOrders(array $orders): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('bookurier_awb_queue');
        $now = $this->dateTime->gmtDate();
        $enqueued = 0;
        $skipped = 0;
        $skippedItems = [];

        foreach ($orders as $order) {
            if (!$order instanceof OrderInterface) {
                $skipped++;
                $skippedItems[] = [
                    'reason' => 'invalid_order',
                    'order_id' => null,
                    'shipment_id' => null,
                ];
                continue;
            }

            $orderId = (int)$order->getEntityId();
            if ($orderId <= 0) {
                $skipped++;
                $skippedItems[] = [
                    'reason' => 'invalid_order',
                    'order_id' => $orderId,
                    'shipment_id' => null,
                ];
                continue;
            }

            $shippingMethod = (string)$order->getShippingMethod();
            if (strpos($shippingMethod, 'bookurier_') !== 0) {
                $skipped++;
                $skippedItems[] = [
                    'reason' => 'not_bookurier',
                    'order_id' => $orderId,
                    'order_increment_id' => (string)$order->getIncrementId(),
                    'shipment_id' => null,
                ];
                continue;
            }

            if ((int)$order->getShipmentsCollection()->getSize() === 0) {
                $skipped++;
                $skippedItems[] = [
                    'reason' => 'missing_shipment',
                    'order_id' => $orderId,
                    'order_increment_id' => (string)$order->getIncrementId(),
                    'shipment_id' => null,
                ];
                continue;
            }

            $queuedForOrder = false;
            foreach ($order->getShipmentsCollection() as $shipment) {
                $shipmentId = (int)$shipment->getId();
                if ($shipmentId <= 0) {
                    $skipped++;
                    $skippedItems[] = $this->buildSkippedShipmentItem($order, $shipment, 'invalid_shipment');
                    continue;
                }

                if ($this->shipmentHasBookurierAwb($shipment)) {
                    $skipped++;
                    $skippedItems[] = $this->buildSkippedShipmentItem($order, $shipment, 'already_has_awb');
                    continue;
                }

                if (!$this->shipmentCountryAllowed($order, $shipment)) {
                    $skipped++;
                    $skippedItems[] = $this->buildSkippedShipmentItem($order, $shipment, 'not_allowed_country');
                    continue;
                }

                if ($this->isAlreadyQueued($connection, $table, $orderId, $shipmentId)) {
                    $skipped++;
                    $skippedItems[] = $this->buildSkippedShipmentItem($order, $shipment, 'already_queued');
                    continue;
                }

                $connection->insert($table, [
                    'order_id' => $orderId,
                    'shipment_id' => $shipmentId,
                    'status' => 'pending',
                    'attempts' => 0,
                    'prev_state' => (string)$order->getState(),
                    'prev_status' => (string)$order->getStatus(),
                    'scheduled_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $queuedForOrder = true;
                $enqueued++;
            }

            if (!$queuedForOrder) {
                continue;
            }

            $this->markQueued($order);
        }

        return ['enqueued' => $enqueued, 'skipped' => $skipped, 'skipped_items' => $skippedItems];
    }

    /**
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param string $table
     * @param int $orderId
     * @param int $shipmentId
     * @return bool
     */
    private function isAlreadyQueued($connection, string $table, int $orderId, int $shipmentId): bool
    {
        $select = $connection->select()
            ->from($table, ['queue_id'])
            ->where('order_id = ?', $orderId)
            ->where('(shipment_id = ? OR shipment_id IS NULL)', $shipmentId)
            ->where(
                "(status IN ('pending', 'processing') OR (status = 'failed' AND attempts < ?))",
                ProcessAwbQueue::MAX_ATTEMPTS
            )
            ->limit(1);

        return (bool)$connection->fetchOne($select);
    }

    /**
     * @param OrderInterface $order
     * @param ShipmentInterface $shipment
     * @param string $reason
     * @return array
     */
    private function buildSkippedShipmentItem(OrderInterface $order, ShipmentInterface $shipment, string $reason): array
    {
        return [
            'reason' => $reason,
            'order_id' => (int)$order->getEntityId(),
            'order_increment_id' => (string)$order->getIncrementId(),
            'shipment_id' => (int)$shipment->getId(),
            'shipment_increment_id' => (string)$shipment->getIncrementId(),
        ];
    }

    /**
     * @param ShipmentInterface $shipment
     * @return bool
     */
    private function shipmentHasBookurierAwb(ShipmentInterface $shipment): bool
    {
        foreach ($shipment->getTracksCollection() as $track) {
            if ($track->getCarrierCode() === 'bookurier') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param OrderInterface $order
     * @param ShipmentInterface $shipment
     * @return bool
     */
    private function shipmentCountryAllowed(OrderInterface $order, ShipmentInterface $shipment): bool
    {
        $address = $order->getShippingAddress();
        $countryId = strtoupper(trim((string)($address ? $address->getCountryId() : '')));
        if ($countryId === '') {
            return false;
        }

        return $this->config->isCountryAllowed($countryId, (int)$order->getStoreId());
    }

    /**
     * @param OrderInterface $order
     * @return void
     */
    private function markQueued(OrderInterface $order): void
    {
        if ($order->getStatus() === 'bookurier_pending_awb') {
            return;
        }

        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus('bookurier_pending_awb');
        $order->addCommentToStatusHistory(__('Queued for Bookurier AWB creation.'));
        $this->orderRepository->save($order);
    }
}
