<?php
/**
 * AWB form block.
 */
namespace Bookurier\Shipping\Block\Adminhtml\Order\Awb;

use Magento\Sales\Block\Adminhtml\Order\AbstractOrder;
use Magento\Sales\Api\Data\ShipmentInterface;

class Form extends AbstractOrder
{
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
     * @return int|null
     */
    public function getSelectedShipmentId(): ?int
    {
        $requested = (int)$this->getRequest()->getParam('shipment_id');
        if ($requested > 0) {
            foreach ($this->getShipments() as $shipment) {
                if ((int)$shipment->getId() === $requested) {
                    return $requested;
                }
            }
        }

        foreach ($this->getShipments() as $shipment) {
            return (int)$shipment->getId();
        }

        return null;
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
     * @param ShipmentInterface $shipment
     * @return string[]
     */
    public function getShipmentAddressLines(ShipmentInterface $shipment): array
    {
        $address = $this->getOrder()->getShippingAddress();
        if (!$address) {
            return [];
        }

        $lines = [];
        $name = trim((string)$address->getFirstname() . ' ' . (string)$address->getLastname());
        if ($name !== '') {
            $lines[] = $name;
        }

        foreach ((array)$address->getStreet() as $streetLine) {
            $streetLine = trim((string)$streetLine);
            if ($streetLine !== '') {
                $lines[] = $streetLine;
            }
        }

        $cityLine = trim(implode(', ', array_filter([
            (string)$address->getCity(),
            (string)$address->getRegion(),
            (string)$address->getPostcode(),
            (string)$address->getCountryId(),
        ])));
        if ($cityLine !== '') {
            $lines[] = $cityLine;
        }

        $phone = trim((string)$address->getTelephone());
        if ($phone !== '') {
            $lines[] = __('Phone: %1', $phone);
        }

        return $lines;
    }

    /**
     * @return float
     */
    public function getDefaultRbsValue(): float
    {
        $selectedShipmentId = $this->getSelectedShipmentId();
        if ($selectedShipmentId !== null) {
            foreach ($this->getShipments() as $shipment) {
                if ((int)$shipment->getId() === $selectedShipmentId) {
                    return $this->getShipmentRbsValue($shipment);
                }
            }
        }

        foreach ($this->getShipments() as $shipment) {
            return $this->getShipmentRbsValue($shipment);
        }

        return 0.0;
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
}
