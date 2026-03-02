<?php
/**
 * Cron: Sync Bookurier AWB tracking history and append order comments.
 */
namespace Bookurier\Shipping\Model\Cron;

use Bookurier\Shipping\Logger\Logger;
use Bookurier\Shipping\Model\Api\Client;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status\HistoryFactory;

class SyncAwbTracking
{
    private const BATCH_SIZE = 25;
    private const MIN_QUERY_INTERVAL_MINUTES = 180;
    private const MAX_AWB_AGE_DAYS = 30;
    private const DELIVERED_STATUS_IDS = [4, 5];

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var Client
     */
    private $apiClient;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var HistoryFactory
     */
    private $historyFactory;

    /**
     * @var Logger
     */
    private $logger;

    public function __construct(
        ResourceConnection $resource,
        Client $apiClient,
        OrderRepositoryInterface $orderRepository,
        HistoryFactory $historyFactory,
        Logger $logger
    ) {
        $this->resource = $resource;
        $this->apiClient = $apiClient;
        $this->orderRepository = $orderRepository;
        $this->historyFactory = $historyFactory;
        $this->logger = $logger;
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $connection = $this->resource->getConnection();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $minQueryAt = $now->modify('-' . self::MIN_QUERY_INTERVAL_MINUTES . ' minutes')->format('Y-m-d H:i:s');
        $minCreatedAt = $now->modify('-' . self::MAX_AWB_AGE_DAYS . ' days')->format('Y-m-d H:i:s');

        $trackTable = $connection->getTableName('sales_shipment_track');
        $shipmentTable = $connection->getTableName('sales_shipment');
        $orderTable = $connection->getTableName('sales_order');
        $statusTable = $connection->getTableName('bookurier_awb_status');

        $select = $connection->select()
            ->from(['t' => $trackTable], [
                'awb' => 't.track_number',
            ])
            ->join(['s' => $shipmentTable], 's.entity_id = t.parent_id', [
                'order_id' => 's.order_id',
            ])
            ->join(['o' => $orderTable], 'o.entity_id = s.order_id', [
                'store_id' => 'o.store_id',
                'state' => 'o.state',
            ])
            ->joinLeft(['bs' => $statusTable], 'bs.awb = t.track_number', [
                'last_query_at' => 'bs.last_query_at',
                'last_sort_date' => 'bs.last_sort_date',
            ])
            ->where('t.carrier_code = ?', 'bookurier')
            ->where('t.created_at >= ?', $minCreatedAt)
            ->where('o.state IN (?)', ['processing'])
            ->where('(bs.last_query_at IS NULL OR bs.last_query_at < ?)', $minQueryAt)
            ->group(['t.track_number', 's.order_id', 'o.store_id', 'bs.last_query_at', 'bs.last_sort_date'])
            ->order('bs.last_query_at')
            ->limit(self::BATCH_SIZE);

        $rows = $connection->fetchAll($select);
        if (!$rows) {
            return;
        }

        foreach ($rows as $row) {
            $awb = (string)$row['awb'];
            $orderId = (int)$row['order_id'];
            $storeId = (int)$row['store_id'];
            $lastSortDate = $row['last_sort_date'] ?: null;

            try {
                $response = $this->apiClient->getAwbHistory($awb, $storeId);
            } catch (\Throwable $e) {
                $this->logger->error('Bookurier tracking fetch failed', [
                    'awb' => $awb,
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
                $this->upsertStatusRow($connection, $statusTable, [
                    'awb' => $awb,
                    'order_id' => $orderId,
                    'last_query_at' => $now->format('Y-m-d H:i:s'),
                    'payload' => $this->encodePayload([
                        'success' => false,
                        'status' => 'error',
                        'message' => 'Exception during API request.',
                    ]),
                ]);
                continue;
            }

            $nowStr = $now->format('Y-m-d H:i:s');
            $payload = $this->encodePayload($response);

            $update = [
                'awb' => $awb,
                'order_id' => $orderId,
                'last_query_at' => $nowStr,
                'payload' => $payload,
            ];
            $latestStatus = null;

            $items = $this->extractHistoryItems($response);
            if ($this->isHistorySuccess($response) && !empty($items)) {
                $newItems = $this->filterNewStatuses($items, $lastSortDate);
                if (!empty($newItems)) {
                    $this->appendOrderComments($orderId, $awb, $newItems);
                    $latest = end($newItems);
                    $latestStatus = is_array($latest) ? $latest : null;
                    $update['last_sort_date'] = $latest['sort_date'] ?? $lastSortDate;
                    $update['last_status_id'] = $latest['status_id'] ?? null;
                    $update['last_status_name'] = $latest['status_name'] ?? null;
                } else {
                    $latest = $this->getLatestStatus($items);
                    if ($latest !== null) {
                        $latestStatus = $latest;
                        $update['last_sort_date'] = $latest['sort_date'] ?? $lastSortDate;
                        $update['last_status_id'] = $latest['status_id'] ?? null;
                        $update['last_status_name'] = $latest['status_name'] ?? null;
                    }
                }
            }

            $this->upsertStatusRow($connection, $statusTable, $update);

            if ($latestStatus !== null) {
                $this->closeOrderIfBookurierDelivered(
                    $connection,
                    $statusTable,
                    $trackTable,
                    $shipmentTable,
                    $orderId,
                    $awb,
                    $latestStatus
                );
            }
        }
    }

    /**
     * @param array $payload
     * @return string
     */
    private function encodePayload(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return '{}';
        }
        return $encoded;
    }

    /**
     * @param array $items
     * @param string|null $lastSortDate
     * @return array
     */
    private function filterNewStatuses(array $items, ?string $lastSortDate): array
    {
        $sorted = $this->sortStatusesByDate($items);
        if ($lastSortDate === null) {
            return $sorted;
        }

        $last = new \DateTimeImmutable($lastSortDate);
        $newItems = [];
        foreach ($sorted as $item) {
            if (empty($item['sort_date'])) {
                continue;
            }
            try {
                $itemDate = new \DateTimeImmutable($item['sort_date']);
            } catch (\Exception $e) {
                continue;
            }
            if ($itemDate > $last) {
                $newItems[] = $item;
            }
        }

        return $newItems;
    }

    /**
     * @param array $items
     * @return array
     */
    private function sortStatusesByDate(array $items): array
    {
        $filtered = array_filter($items, static function ($item) {
            return is_array($item) && !empty($item['sort_date']);
        });

        usort($filtered, static function ($a, $b) {
            return strcmp((string)$a['sort_date'], (string)$b['sort_date']);
        });

        return array_values($filtered);
    }

    /**
     * @param array $items
     * @return array|null
     */
    private function getLatestStatus(array $items): ?array
    {
        $sorted = $this->sortStatusesByDate($items);
        if (!$sorted) {
            return null;
        }
        return end($sorted) ?: null;
    }

    /**
     * @param int $orderId
     * @param string $awb
     * @param array $items
     * @return void
     */
    private function appendOrderComments(int $orderId, string $awb, array $items): void
    {
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Throwable $e) {
            $this->logger->error('Bookurier tracking order load failed', [
                'order_id' => $orderId,
                'awb' => $awb,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        foreach ($items as $item) {
            $statusName = $item['status_name'] ?? 'Unknown';
            $dateText = $item['data'] ?? ($item['sort_date'] ?? '');
            $obs = $item['obs'] ?? null;

            $comment = 'Bookurier tracking update for AWB ' . $awb . ': ' . $statusName;
            if ($dateText !== '') {
                $comment .= ' (' . $dateText . ')';
            }
            if ($obs) {
                $comment .= ' - ' . $obs;
            }

            $history = $this->historyFactory->create();
            $history->setComment($comment)
                ->setStatus($order->getStatus())
                ->setIsCustomerNotified(false)
                ->setIsVisibleOnFront(true)
                ->setEntityName(Order::ENTITY);

            $order->addStatusHistory($history);
        }

        try {
            $this->orderRepository->save($order);
        } catch (\Throwable $e) {
            $this->logger->error('Bookurier tracking order save failed', [
                'order_id' => $orderId,
                'awb' => $awb,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param string $table
     * @param array $data
     * @return void
     */
    private function upsertStatusRow($connection, string $table, array $data): void
    {
        $connection->insertOnDuplicate(
            $table,
            $data,
            array_keys($data)
        );
    }

    /**
     * @param array $history
     * @return bool
     */
    private function isHistorySuccess(array $history): bool
    {
        if (!array_key_exists('success', $history)) {
            return false;
        }

        $success = $history['success'];
        if (is_bool($success)) {
            return $success;
        }
        if (is_numeric($success)) {
            return (int)$success === 1;
        }
        if (is_string($success)) {
            $normalized = strtolower(trim($success));
            return in_array($normalized, ['1', 'true', 'ok', 'success'], true);
        }

        return !empty($success);
    }

    /**
     * @param array $history
     * @return array
     */
    private function extractHistoryItems(array $history): array
    {
        foreach (['data', 'awb_histories', 'history'] as $key) {
            if (isset($history[$key]) && is_array($history[$key])) {
                return $history[$key];
            }
        }

        return [];
    }

    /**
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param string $statusTable
     * @param string $trackTable
     * @param string $shipmentTable
     * @param int $orderId
     * @param string $currentAwb
     * @param array $latestStatus
     * @return void
     */
    private function closeOrderIfBookurierDelivered(
        $connection,
        string $statusTable,
        string $trackTable,
        string $shipmentTable,
        int $orderId,
        string $currentAwb,
        array $latestStatus
    ): void {
        $currentStatusId = $this->normalizeStatusId($latestStatus['status_id'] ?? null);
        if ($currentStatusId === null || !in_array($currentStatusId, self::DELIVERED_STATUS_IDS, true)) {
            return;
        }

        if (!$this->allBookurierAwbsDelivered(
            $connection,
            $statusTable,
            $trackTable,
            $shipmentTable,
            $orderId,
            $currentAwb,
            $currentStatusId
        )) {
            return;
        }

        $statusName = (string)($latestStatus['status_name'] ?? '');
        $this->closeOrderAsDelivered($orderId, $currentAwb, $statusName);
    }

    /**
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param string $statusTable
     * @param string $trackTable
     * @param string $shipmentTable
     * @param int $orderId
     * @param string $currentAwb
     * @param int $currentStatusId
     * @return bool
     */
    private function allBookurierAwbsDelivered(
        $connection,
        string $statusTable,
        string $trackTable,
        string $shipmentTable,
        int $orderId,
        string $currentAwb,
        int $currentStatusId
    ): bool {
        $awbRows = $connection->fetchCol(
            $connection->select()
                ->from(['t' => $trackTable], ['track_number'])
                ->join(['s' => $shipmentTable], 's.entity_id = t.parent_id', [])
                ->where('s.order_id = ?', $orderId)
                ->where('t.carrier_code = ?', 'bookurier')
                ->group(['t.track_number'])
        );
        if (!$awbRows) {
            return false;
        }

        $awbList = array_values(array_filter(array_map('strval', $awbRows), static function (string $awb): bool {
            return $awb !== '';
        }));
        if (!$awbList) {
            return false;
        }

        $statusRows = $connection->fetchPairs(
            $connection->select()
                ->from($statusTable, ['awb', 'last_status_id'])
                ->where('awb IN (?)', $awbList)
        );

        foreach ($awbList as $awb) {
            if ($awb === $currentAwb) {
                $statusId = $currentStatusId;
            } else {
                $statusId = $this->normalizeStatusId($statusRows[$awb] ?? null);
            }

            if ($statusId === null || !in_array($statusId, self::DELIVERED_STATUS_IDS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param int $orderId
     * @param string $awb
     * @param string $statusName
     * @return void
     */
    private function closeOrderAsDelivered(int $orderId, string $awb, string $statusName): void
    {
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Throwable $e) {
            $this->logger->error('Bookurier delivered order load failed', [
                'order_id' => $orderId,
                'awb' => $awb,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $shippingMethod = (string)$order->getShippingMethod();
        if (strpos($shippingMethod, 'bookurier_') !== 0) {
            return;
        }

        if (in_array((string)$order->getState(), [Order::STATE_COMPLETE, Order::STATE_CLOSED], true)) {
            return;
        }

        if ((string)$order->getState() === Order::STATE_CANCELED) {
            return;
        }

        $closedStatus = (string)$order->getConfig()->getStateDefaultStatus(Order::STATE_CLOSED);
        if ($closedStatus === '') {
            $closedStatus = (string)$order->getStatus();
        }

        $order->setState(Order::STATE_CLOSED);
        $order->setStatus($closedStatus);
        $order->addCommentToStatusHistory(
            __('Bookurier delivery confirmed for AWB %1 (%2). Order closed automatically.', $awb, $statusName ?: 'Delivered'),
            $closedStatus
        );

        try {
            $this->orderRepository->save($order);
        } catch (\Throwable $e) {
            $this->logger->error('Bookurier delivered order close failed', [
                'order_id' => $orderId,
                'awb' => $awb,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param mixed $value
     * @return int|null
     */
    private function normalizeStatusId($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int)$value;
        }
        return null;
    }
}
