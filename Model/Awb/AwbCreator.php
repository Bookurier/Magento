<?php
/**
 * Create Bookurier AWB for orders.
 */
namespace Bookurier\Shipping\Model\Awb;

use Bookurier\Shipping\Model\Api\Client;
use Bookurier\Shipping\Model\Config;
use Bookurier\Shipping\Model\Cron\ProcessAwbQueue;
use Magento\Sales\Model\Order;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Bookurier\Shipping\Logger\Logger;

class AwbCreator
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var PayloadBuilder
     */
    private $payloadBuilder;

    /**
     * @var AwbAttacher
     */
    private $awbAttacher;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var Logger
     */
    private $logger;

    public function __construct(
        Client $client,
        PayloadBuilder $payloadBuilder,
        AwbAttacher $awbAttacher,
        ResourceConnection $resource,
        Config $config,
        OrderRepositoryInterface $orderRepository,
        Logger $logger
    ) {
        $this->client = $client;
        $this->payloadBuilder = $payloadBuilder;
        $this->awbAttacher = $awbAttacher;
        $this->resource = $resource;
        $this->config = $config;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
    }

    /**
     * @param OrderInterface $order
     * @param array $overrides
     * @param int|null $shipmentId
     * @param int|null $currentQueueId
     * @return string
     * @throws LocalizedException
     */
    public function createForOrder(
        OrderInterface $order,
        array $overrides = [],
        ?int $shipmentId = null,
        ?int $currentQueueId = null
    ): string
    {
        $shippingMethod = (string)$order->getShippingMethod();
        if (strpos($shippingMethod, 'bookurier_') !== 0) {
            throw new LocalizedException(__('Order is not shipped with Bookurier.'));
        }

        $shipment = $this->resolveShipment($order, $shipmentId);
        $resolvedShipmentId = (int)$shipment->getId();
        $orderId = (int)$order->getEntityId();

        // Queue jobs are still shipment-scoped. Manual/sync flow processes all eligible shipments in one request.
        $targetShipments = [];
        if ($currentQueueId !== null && $currentQueueId > 0) {
            if ($this->shipmentHasBookurierAwb($shipment)) {
                throw new LocalizedException(
                    __('Shipment #%1 already has a Bookurier AWB.', $resolvedShipmentId)
                );
            }
            if ($this->isShipmentQueueActive($orderId, $resolvedShipmentId, $currentQueueId)) {
                throw new LocalizedException(
                    __('AWB creation is already queued for shipment #%1.', $resolvedShipmentId)
                );
            }
            $targetShipments = [$shipment];
        } else {
            $targetShipments = $this->getEligibleShipments($order);
            if (empty($targetShipments)) {
                throw new LocalizedException(__('All shipments on this order already have a Bookurier AWB.'));
            }
            foreach ($targetShipments as $targetShipment) {
                $targetShipmentId = (int)$targetShipment->getId();
                if ($this->isShipmentQueueActive($orderId, $targetShipmentId)) {
                    throw new LocalizedException(
                        __('AWB creation is already queued for shipment #%1.', $targetShipmentId)
                    );
                }
            }
        }

        $primaryShipment = $targetShipments[0];
        $countryId = $this->resolveCountryId($order, $primaryShipment);

        if (!$this->config->isCountryAllowed($countryId, (int)$order->getStoreId())) {
            throw new LocalizedException(
                __(
                    'Shipment #%1 destination country (%2) is not allowed for Bookurier.',
                    (int)$primaryShipment->getId(),
                    $countryId
                )
            );
        }

        // Enforce COD return amount server-side so UI/manual overrides cannot send inconsistent values.
        $overrides['rbs_val'] = $this->getCodAmountForOrder($order);
        $overrides['packs'] = count($targetShipments);

        $payload = $this->payloadBuilder->build($order, $overrides, (int)$primaryShipment->getId());
        $result = $this->client->addCommands([$payload], (int)$order->getStoreId());

        if (($result['status'] ?? '') !== 'success') {
            throw new LocalizedException(__($result['message'] ?? 'Failed to create AWB.'));
        }

        $rawCodes = isset($result['data']) && is_array($result['data']) ? array_values($result['data']) : [];
        $awbCodes = [];
        foreach ($rawCodes as $code) {
            $code = trim((string)$code);
            if ($code !== '') {
                $awbCodes[] = $code;
            }
        }
        if (empty($awbCodes)) {
            throw new LocalizedException(__('No AWB code returned by Bookurier.'));
        }

        $requestedCount = count($targetShipments);
        $returnedCount = count($awbCodes);
        $attachCount = min($requestedCount, $returnedCount);
        $attachedCodes = [];

        for ($idx = 0; $idx < $attachCount; $idx++) {
            $awbCode = $awbCodes[$idx];
            $targetShipmentId = (int)$targetShipments[$idx]->getId();
            $shouldNotifyCustomer = ($idx === ($attachCount - 1));
            $this->awbAttacher->attach($order, $awbCode, $targetShipmentId, $shouldNotifyCustomer);
            $attachedCodes[] = $awbCode;
        }

        if ($requestedCount !== $returnedCount) {
            throw new LocalizedException(
                __(
                    'Bookurier AWB mismatch: requested %1 shipment(s), received %2 AWB code(s), attached %3.',
                    $requestedCount,
                    $returnedCount,
                    $attachCount
                )
            );
        }

        $this->moveToProcessingIfPending($order);

        return implode(', ', $attachedCodes);
    }

    /**
     * @param OrderInterface $order
     * @return float
     */
    private function getCodAmountForOrder(OrderInterface $order): float
    {
        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== 'cashondelivery') {
            return 0.0;
        }

        $value = (float)$order->getTotalDue();
        if ($value <= 0.0) {
            $value = (float)$order->getGrandTotal();
        }
        if ($value <= 0.0) {
            $value = (float)$order->getSubtotalInclTax();
        }
        if ($value <= 0.0) {
            $value = (float)$order->getSubtotal();
        }

        if ($value <= 0.0) {
            foreach ($order->getAllItems() as $orderItem) {
                if ($orderItem->getParentItemId()) {
                    continue;
                }
                $rowTotalInclTax = (float)$orderItem->getRowTotalInclTax();
                if ($rowTotalInclTax > 0.0) {
                    $value += $rowTotalInclTax;
                    continue;
                }
                $value += (float)$orderItem->getRowTotal();
            }
        }

        return (float)round($value, 2);
    }

    /**
     * @param OrderInterface $order
     * @return ShipmentInterface[]
     */
    private function getEligibleShipments(OrderInterface $order): array
    {
        $shipments = [];
        foreach ($order->getShipmentsCollection() as $shipment) {
            if (!$shipment instanceof ShipmentInterface) {
                continue;
            }
            if ($this->shipmentHasBookurierAwb($shipment)) {
                continue;
            }
            $shipments[] = $shipment;
        }

        usort($shipments, static function (ShipmentInterface $a, ShipmentInterface $b): int {
            return (int)$a->getId() <=> (int)$b->getId();
        });

        return $shipments;
    }

    /**
     * @param OrderInterface $order
     * @param int|null $shipmentId
     * @return ShipmentInterface
     * @throws LocalizedException
     */
    private function resolveShipment(OrderInterface $order, ?int $shipmentId): ShipmentInterface
    {
        if ((int)$order->getShipmentsCollection()->getSize() === 0) {
            throw new LocalizedException(__('Order has no shipment to attach AWB. Create a shipment first.'));
        }

        if ($shipmentId === null || $shipmentId <= 0) {
            $shipment = $order->getShipmentsCollection()->getFirstItem();
            if (!$shipment || !$shipment->getId()) {
                throw new LocalizedException(__('Order has no shipment to attach AWB. Create a shipment first.'));
            }
            return $shipment;
        }

        foreach ($order->getShipmentsCollection() as $shipment) {
            if ((int)$shipment->getId() === $shipmentId) {
                return $shipment;
            }
        }

        throw new LocalizedException(__('Selected shipment does not belong to this order.'));
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
     * @param int $orderId
     * @param int $shipmentId
     * @param int|null $excludeQueueId
     * @return bool
     */
    private function isShipmentQueueActive(int $orderId, int $shipmentId, ?int $excludeQueueId = null): bool
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('bookurier_awb_queue');
        $select = $connection->select()
            ->from($table, ['queue_id'])
            ->where('order_id = ?', $orderId)
            ->where('(shipment_id = ? OR shipment_id IS NULL)', $shipmentId)
            ->where(
                "(status IN ('pending', 'processing') OR (status = 'failed' AND attempts < ?))",
                ProcessAwbQueue::MAX_ATTEMPTS
            )
            ->limit(1);

        if ($excludeQueueId !== null && $excludeQueueId > 0) {
            $select->where('queue_id != ?', $excludeQueueId);
        }

        return (bool)$connection->fetchOne($select);
    }

    /**
     * @param OrderInterface $order
     * @param ShipmentInterface $shipment
     * @return string
     * @throws LocalizedException
     */
    private function resolveCountryId(OrderInterface $order, ShipmentInterface $shipment): string
    {
        $address = $order->getShippingAddress();
        $countryId = strtoupper(trim((string)($address ? $address->getCountryId() : '')));
        if ($countryId === '') {
            throw new LocalizedException(__('Unable to determine destination country for shipment #%1.', (int)$shipment->getId()));
        }

        return $countryId;
    }

    private function moveToProcessingIfPending(OrderInterface $order): void
    {
        // Check if the order is Pending Bookurier AWB, because we only mov to
        // Prcessing status if we were in that status
        if ((string)$order->getStatus() !== 'bookurier_pending_awb') {
            return;
        }

        // Check with a DB query to avoid stale in-memory shipment/track collections.
        $orderId = (int)$order->getEntityId();
        $missingShipmentIds = $this->getMissingBookurierAwbShipmentIdsFromDb($orderId);
        /*$this->logger->info('Bookurier eligible shipments check', [
            'order_id' => $orderId,
            'eligible_count' => count($missingShipmentIds),
            'eligible_shipment_ids' => $missingShipmentIds,
        ]);*/
        if (!empty($missingShipmentIds)) {
            return;
        }

        $freshOrder = $this->orderRepository->get($orderId);
        $defaultProcessingStatus = (string)$freshOrder->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING);
        $freshOrder->setState(Order::STATE_PROCESSING);
        $freshOrder->setStatus($defaultProcessingStatus ?: Order::STATE_PROCESSING);
        $freshOrder->addCommentToStatusHistory(__('Bookurier AWB created. Order moved to Processing.'));
        $this->orderRepository->save($freshOrder);
    }

    /**
     * Return shipment IDs for an order that still do not have a Bookurier track.
     *
     * @param int $orderId
     * @return int[]
     */
    private function getMissingBookurierAwbShipmentIdsFromDb(int $orderId): array
    {
        $connection = $this->resource->getConnection();
        $shipmentTable = $this->resource->getTableName('sales_shipment');
        $trackTable = $this->resource->getTableName('sales_shipment_track');

        $select = $connection->select()
            ->from(['s' => $shipmentTable], ['entity_id'])
            ->joinLeft(
                ['t' => $trackTable],
                "t.parent_id = s.entity_id AND t.carrier_code = 'bookurier'",
                []
            )
            ->where('s.order_id = ?', $orderId)
            ->group('s.entity_id')
            ->having('COUNT(t.entity_id) = 0')
            ->order('s.entity_id ASC');

        $ids = $connection->fetchCol($select);
        return array_map('intval', $ids);
    }
}
