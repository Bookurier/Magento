<?php
/**
 * AWB form block.
 */
namespace Bookurier\Shipping\Block\Adminhtml\Order\Awb;

use Magento\Sales\Block\Adminhtml\Order\AbstractOrder;

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
     * @return array
     */
    public function getStreetLines(): array
    {
        $address = $this->getOrder()->getShippingAddress();
        return $address ? (array)$address->getStreet() : [];
    }

    /**
     * @return string
     */
    public function getDefaultStreet(): string
    {
        $lines = $this->getStreetLines();
        return $lines[0] ?? '';
    }
}
