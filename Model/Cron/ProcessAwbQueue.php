<?php
/**
 * Process Bookurier AWB queue.
 */
namespace Bookurier\Shipping\Model\Cron;

use Bookurier\Shipping\Model\Awb\AwbCreator;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

class ProcessAwbQueue
{
    private const BATCH_SIZE = 25;
    public const MAX_ATTEMPTS = 3;
    private const RETRY_DELAY_MINUTES = 1;

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
     * @var AwbCreator
     */
    private $awbCreator;

    public function __construct(
        ResourceConnection $resource,
        DateTime $dateTime,
        OrderRepositoryInterface $orderRepository,
        AwbCreator $awbCreator
    ) {
        $this->resource = $resource;
        $this->dateTime = $dateTime;
        $this->orderRepository = $orderRepository;
        $this->awbCreator = $awbCreator;
    }

    public function execute(): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('bookurier_awb_queue');
        $now = $this->dateTime->gmtDate();

        $items = $this->fetchBatch($connection, $table, $now);
        if (!$items) {
            return;
        }

        $queueIds = array_column($items, 'queue_id');
        $connection->update(
            $table,
            ['status' => 'processing', 'updated_at' => $now],
            ['queue_id IN (?)' => $queueIds]
        );

        foreach ($items as $item) {
            $this->processItem($connection, $table, $item);
        }
    }

    /**
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param string $table
     * @param string $now
     * @return array
     */
    private function fetchBatch($connection, string $table, string $now): array
    {
        $select = $connection->select()
            ->from($table, ['queue_id', 'order_id', 'shipment_id', 'attempts', 'prev_state', 'prev_status'])
            ->where('status IN (?)', ['pending', 'failed'])
            ->where('attempts < ?', self::MAX_ATTEMPTS)
            ->where('scheduled_at IS NULL OR scheduled_at <= ?', $now)
            ->order('queue_id ASC')
            ->limit(self::BATCH_SIZE);

        return $connection->fetchAll($select);
    }

    /**
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param string $table
     * @param array $item
     * @return void
     */
    private function processItem($connection, string $table, array $item): void
    {
        $queueId = (int)$item['queue_id'];
        $orderId = (int)$item['order_id'];
        $shipmentId = isset($item['shipment_id']) ? (int)$item['shipment_id'] : 0;
        $attempts = (int)$item['attempts'];
        $now = $this->dateTime->gmtDate();

        try {
            $order = $this->orderRepository->get($orderId);
            $shippingMethod = (string)$order->getShippingMethod();
            if (strpos($shippingMethod, 'bookurier_') !== 0) {
                throw new LocalizedException(__('Order does not use Bookurier shipping.'));
            }

            if ((int)$order->getShipmentsCollection()->getSize() === 0) {
                throw new LocalizedException(__('Order has no shipment to attach AWB. Create a shipment first.'));
            }

            if ($shipmentId > 0 && !$this->orderHasShipment($order, $shipmentId)) {
                throw new LocalizedException(__('Shipment no longer exists for this order.'));
            }

            $this->awbCreator->createForOrder(
                $order,
                [],
                $shipmentId > 0 ? $shipmentId : null,
                $queueId
            );
            if ($order->getStatus() === 'bookurier_pending_awb') {
                $this->restoreOrderStatus($order, $item);
            }
            $connection->update(
                $table,
                [
                    'status' => 'done',
                    'processed_at' => $now,
                    'last_error' => null,
                    'updated_at' => $now,
                ],
                ['queue_id = ?' => $queueId]
            );
        } catch (\Exception $e) {
            $attempts++;
            $nextRun = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('+' . self::RETRY_DELAY_MINUTES . ' minutes')
                ->format('Y-m-d H:i:s');

            $connection->update(
                $table,
                [
                    'status' => 'failed',
                    'attempts' => $attempts,
                    'last_error' => substr($e->getMessage(), 0, 1000),
                    'scheduled_at' => $nextRun,
                    'updated_at' => $now,
                ],
                ['queue_id = ?' => $queueId]
            );
        }
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param int $shipmentId
     * @return bool
     */
    private function orderHasShipment($order, int $shipmentId): bool
    {
        foreach ($order->getShipmentsCollection() as $shipment) {
            if ((int)$shipment->getId() === $shipmentId) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param array $item
     * @return void
     */
    private function restoreOrderStatus($order, array $item): void
    {
        $prevState = isset($item['prev_state']) ? (string)$item['prev_state'] : '';
        $prevStatus = isset($item['prev_status']) ? (string)$item['prev_status'] : '';

        if ($prevState !== '' && $prevStatus !== '') {
            $order->setState($prevState);
            $order->setStatus($prevStatus);
        } else {
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus(Order::STATE_PROCESSING);
        }

        $order->addCommentToStatusHistory(__('Bookurier AWB created.'));
        $this->orderRepository->save($order);
    }
}
