<?php
/**
 * Create Bookurier AWB for orders.
 */
namespace Bookurier\Shipping\Model\Awb;

use Bookurier\Shipping\Model\Api\Client;
use Bookurier\Shipping\Model\Config;
use Bookurier\Shipping\Model\Cron\ProcessAwbQueue;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Framework\Exception\LocalizedException;

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

    public function __construct(
        Client $client,
        PayloadBuilder $payloadBuilder,
        AwbAttacher $awbAttacher,
        ResourceConnection $resource,
        Config $config
    ) {
        $this->client = $client;
        $this->payloadBuilder = $payloadBuilder;
        $this->awbAttacher = $awbAttacher;
        $this->resource = $resource;
        $this->config = $config;
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
        $countryId = $this->resolveCountryId($order, $shipment);

        if ($this->shipmentHasBookurierAwb($shipment)) {
            throw new LocalizedException(
                __('Shipment #%1 already has a Bookurier AWB.', $resolvedShipmentId)
            );
        }

        if ($this->isShipmentQueueActive((int)$order->getEntityId(), $resolvedShipmentId, $currentQueueId)) {
            throw new LocalizedException(
                __('AWB creation is already queued for shipment #%1.', $resolvedShipmentId)
            );
        }

        if (!$this->config->isCountryAllowed($countryId, (int)$order->getStoreId())) {
            throw new LocalizedException(
                __(
                    'Shipment #%1 destination country (%2) is not allowed for Bookurier.',
                    $resolvedShipmentId,
                    $countryId
                )
            );
        }

        if (!array_key_exists('rbs_val', $overrides)) {
            $overrides['rbs_val'] = $this->getCodAmount($order, $shipment);
        }

        $payload = $this->payloadBuilder->build($order, $overrides, $resolvedShipmentId);
        $result = $this->client->addCommands([$payload], (int)$order->getStoreId());

        if (($result['status'] ?? '') !== 'success') {
            throw new LocalizedException(__($result['message'] ?? 'Failed to create AWB.'));
        }

        $awbCode = $result['data'][0] ?? null;
        if (!$awbCode) {
            throw new LocalizedException(__('No AWB code returned by Bookurier.'));
        }

        $this->awbAttacher->attach($order, (string)$awbCode, $resolvedShipmentId);
        return (string)$awbCode;
    }

    /**
     * @param OrderInterface $order
     * @param ShipmentInterface $shipment
     * @return float
     */
    private function getCodAmount(OrderInterface $order, ShipmentInterface $shipment): float
    {
        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== 'cashondelivery') {
            return 0.0;
        }

        $value = 0.0;
        foreach ($shipment->getItemsCollection() as $shipmentItem) {
            $qtyShipped = (float)$shipmentItem->getQty();
            $orderItem = $shipmentItem->getOrderItem();
            if (!$orderItem || $qtyShipped <= 0.0) {
                continue;
            }

            $qtyOrdered = (float)$orderItem->getQtyOrdered();
            if ($qtyOrdered <= 0.0) {
                continue;
            }

            $rowTotalInclTax = (float)$orderItem->getRowTotalInclTax();
            if ($rowTotalInclTax <= 0.0) {
                $rowTotalInclTax = (float)$orderItem->getRowTotal();
            }

            $unitValue = $rowTotalInclTax / $qtyOrdered;
            $value += $unitValue * $qtyShipped;
        }

        return (float)round($value, 2);
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
}
