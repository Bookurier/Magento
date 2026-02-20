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
                'track_created_at' => 't.created_at',
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
            ->where('o.state NOT IN (?)', ['complete', 'closed'])
            ->where('(bs.last_query_at IS NULL OR bs.last_query_at < ?)', $minQueryAt)
            ->group(['t.track_number', 's.order_id', 'o.store_id', 'bs.last_query_at', 'bs.last_sort_date'])
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

            if (!empty($response['success']) && !empty($response['data']) && is_array($response['data'])) {
                $newItems = $this->filterNewStatuses($response['data'], $lastSortDate);
                if (!empty($newItems)) {
                    $this->appendOrderComments($orderId, $awb, $newItems);
                    $latest = end($newItems);
                    $update['last_sort_date'] = $latest['sort_date'] ?? $lastSortDate;
                    $update['last_status_id'] = $latest['status_id'] ?? null;
                    $update['last_status_name'] = $latest['status_name'] ?? null;
                } else {
                    $latest = $this->getLatestStatus($response['data']);
                    if ($latest !== null) {
                        $update['last_sort_date'] = $latest['sort_date'] ?? $lastSortDate;
                        $update['last_status_id'] = $latest['status_id'] ?? null;
                        $update['last_status_name'] = $latest['status_name'] ?? null;
                    }
                }
            }

            $this->upsertStatusRow($connection, $statusTable, $update);
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
}
