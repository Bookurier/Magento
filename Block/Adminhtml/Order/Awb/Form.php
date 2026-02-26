<?php
/**
 * AWB form block.
 */
namespace Bookurier\Shipping\Block\Adminhtml\Order\Awb;

use Bookurier\Shipping\Model\Config;
use Bookurier\Shipping\Model\Config\Source\Service;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Magento\Sales\Block\Adminhtml\Order\AbstractOrder;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Helper\Admin as AdminHelper;

class Form extends AbstractOrder
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var Service
     */
    private $serviceSource;

    public function __construct(
        Context $context,
        Registry $registry,
        AdminHelper $adminHelper,
        Config $config,
        Service $serviceSource,
        array $data = []
    ) {
        parent::__construct($context, $registry, $adminHelper, $data);
        $this->config = $config;
        $this->serviceSource = $serviceSource;
    }

    /**
     * @return string
     */
    public function getSubmitUrl(): string
    {
        return $this->getUrl('bookurier/order/createAwb', ['order_id' => $this->getOrder()->getId()]);
    }

    /**
     * @return ShipmentInterface[]
     */
    public function getShipments(): array
    {
        $shipments = [];
        foreach ($this->getOrder()->getShipmentsCollection() as $shipment) {
            if ($this->shipmentHasBookurierAwb($shipment)) {
                continue;
            }
            $shipments[] = $shipment;
        }

        return $shipments;
    }

    /**
     * @param ShipmentInterface $shipment
     * @return bool
     */
    public function shipmentHasBookurierAwb(ShipmentInterface $shipment): bool
    {
        foreach ($shipment->getTracksCollection() as $track) {
            if ($track->getCarrierCode() === 'bookurier') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ShipmentInterface $shipment
     * @return string
     */
    public function getShipmentLabel(ShipmentInterface $shipment): string
    {
        $increment = (string)$shipment->getIncrementId();
        if ($increment !== '') {
            return $increment;
        }

        return '#' . (int)$shipment->getId();
    }

    /**
     * @return float
     */
    public function getDefaultRbsValue(): float
    {
        $value = 0.0;
        foreach ($this->getShipments() as $shipment) {
            $value += $this->getShipmentRbsValue($shipment);
        }

        return (float)round($value, 2);
    }

    /**
     * @param ShipmentInterface $shipment
     * @return float
     */
    public function getShipmentRbsValue(ShipmentInterface $shipment): float
    {
        $payment = $this->getOrder()->getPayment();
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
     * @return array
     */
    public function getServiceOptions(): array
    {
        return $this->serviceSource->toOptionArray();
    }

    /**
     * @return string
     */
    public function getDefaultServiceCode(): string
    {
        return $this->config->getServiceCode((int)$this->getOrder()->getStoreId());
    }
}
