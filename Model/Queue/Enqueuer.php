<?php
/**
 * Enqueue orders for Bookurier AWB creation.
 */
namespace Bookurier\Shipping\Model\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderInterface;

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

    public function __construct(
        ResourceConnection $resource,
        DateTime $dateTime
    ) {
        $this->resource = $resource;
        $this->dateTime = $dateTime;
    }

    /**
     * @param OrderInterface[] $orders
     * @return array{enqueued:int, skipped:int}
     */
    public function enqueueOrders(array $orders): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('bookurier_awb_queue');
        $now = $this->dateTime->gmtDate();
        $enqueued = 0;
        $skipped = 0;

        foreach ($orders as $order) {
            if (!$order instanceof OrderInterface) {
                $skipped++;
                continue;
            }

            $orderId = (int)$order->getEntityId();
            if ($orderId <= 0) {
                $skipped++;
                continue;
            }

            $shippingMethod = (string)$order->getShippingMethod();
            if (strpos($shippingMethod, 'bookurier_') !== 0) {
                $skipped++;
                continue;
            }

            if ((int)$order->getShipmentsCollection()->getSize() === 0) {
                $skipped++;
                continue;
            }

            if ($this->orderHasBookurierAwb($order)) {
                $skipped++;
                continue;
            }

            if ($this->isAlreadyQueued($connection, $table, $orderId)) {
                $skipped++;
                continue;
            }

            $connection->insert($table, [
                'order_id' => $orderId,
                'status' => 'pending',
                'attempts' => 0,
                'scheduled_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $enqueued++;
        }

        return ['enqueued' => $enqueued, 'skipped' => $skipped];
    }

    /**
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param string $table
     * @param int $orderId
     * @return bool
     */
    private function isAlreadyQueued($connection, string $table, int $orderId): bool
    {
        $select = $connection->select()
            ->from($table, ['queue_id'])
            ->where('order_id = ?', $orderId)
            ->where('status IN (?)', ['pending', 'processing', 'failed'])
            ->limit(1);

        return (bool)$connection->fetchOne($select);
    }

    /**
     * @param OrderInterface $order
     * @return bool
     */
    private function orderHasBookurierAwb(OrderInterface $order): bool
    {
        foreach ($order->getShipmentsCollection() as $shipment) {
            foreach ($shipment->getTracksCollection() as $track) {
                if ($track->getCarrierCode() === 'bookurier') {
                    return true;
                }
            }
        }
        return false;
    }
}
