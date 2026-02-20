<?php
/**
 * Bookurier tracking history provider with cache.
 */
namespace Bookurier\Shipping\Model\Tracking;

use Bookurier\Shipping\Model\Api\Client;
use Magento\Framework\App\ResourceConnection;

class HistoryProvider
{
    private const MIN_QUERY_INTERVAL_MINUTES = 180;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var Client
     */
    private $apiClient;

    public function __construct(ResourceConnection $resource, Client $apiClient)
    {
        $this->resource = $resource;
        $this->apiClient = $apiClient;
    }

    /**
     * @param string $awb
     * @param int|null $storeId
     * @param int|null $orderId
     * @param bool $allowFetch
     * @return array{response: array, last_query_at: string|null, from_cache: bool}
     */
    public function getHistoryWithMeta(
        string $awb,
        ?int $storeId = null,
        ?int $orderId = null,
        bool $allowFetch = true
    ): array {
        $connection = $this->resource->getConnection();
        $table = $connection->getTableName('bookurier_awb_status');

        $row = $connection->fetchRow(
            $connection->select()->from($table)->where('awb = ?', $awb)->limit(1)
        );

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $minQueryAt = $now->modify('-' . self::MIN_QUERY_INTERVAL_MINUTES . ' minutes');

        if ($row && !empty($row['last_query_at'])) {
            try {
                $lastQueryAt = new \DateTimeImmutable($row['last_query_at']);
                if ($lastQueryAt >= $minQueryAt) {
                    $payload = $this->decodePayload($row['payload'] ?? null);
                    if ($payload !== null) {
                        return [
                            'response' => $payload,
                            'last_query_at' => $row['last_query_at'],
                            'from_cache' => true,
                        ];
                    }
                }
            } catch (\Exception $e) {
                // fall through to fetch
            }
        }

        if (!$allowFetch) {
            return [
                'response' => [
                    'success' => false,
                    'status' => 'error',
                    'message' => 'Tracking data not available in cache.',
                ],
                'last_query_at' => $row['last_query_at'] ?? null,
                'from_cache' => true,
            ];
        }

        $response = $this->apiClient->getAwbHistory($awb, $storeId);
        $this->upsertStatusRow($connection, $table, $awb, $orderId, $response, $now);

        return [
            'response' => $response,
            'last_query_at' => $now->format('Y-m-d H:i:s'),
            'from_cache' => false,
        ];
    }

    /**
     * @param string $awb
     * @param int|null $storeId
     * @param int|null $orderId
     * @return array
     */
    public function getHistory(string $awb, ?int $storeId = null, ?int $orderId = null): array
    {
        $result = $this->getHistoryWithMeta($awb, $storeId, $orderId, true);
        return $result['response'];
    }

    /**
     * @param string|null $payload
     * @return array|null
     */
    private function decodePayload(?string $payload): ?array
    {
        if ($payload === null || $payload === '') {
            return null;
        }
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return null;
        }
        return $decoded;
    }

    /**
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param string $table
     * @param string $awb
     * @param int|null $orderId
     * @param array $response
     * @param \DateTimeImmutable $now
     * @return void
     */
    private function upsertStatusRow($connection, string $table, string $awb, ?int $orderId, array $response, \DateTimeImmutable $now): void
    {
        $payload = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            $payload = '{}';
        }

        $update = [
            'awb' => $awb,
            'last_query_at' => $now->format('Y-m-d H:i:s'),
            'payload' => $payload,
        ];
        if ($orderId !== null) {
            $update['order_id'] = $orderId;
        }

        if (!empty($response['success']) && !empty($response['data']) && is_array($response['data'])) {
            $latest = $this->getLatestStatus($response['data']);
            if ($latest !== null) {
                $update['last_sort_date'] = $latest['sort_date'] ?? null;
                $update['last_status_id'] = $latest['status_id'] ?? null;
                $update['last_status_name'] = $latest['status_name'] ?? null;
            }
        }

        $connection->insertOnDuplicate($table, $update, array_keys($update));
    }

    /**
     * @param array $items
     * @return array|null
     */
    private function getLatestStatus(array $items): ?array
    {
        $filtered = array_filter($items, static function ($item) {
            return is_array($item) && !empty($item['sort_date']);
        });

        if (!$filtered) {
            return null;
        }

        usort($filtered, static function ($a, $b) {
            return strcmp((string)$a['sort_date'], (string)$b['sort_date']);
        });

        return end($filtered) ?: null;
    }
}
