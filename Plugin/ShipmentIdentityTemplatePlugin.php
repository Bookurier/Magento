<?php
/**
 * Select Bookurier multi-shipment email templates dynamically.
 */
declare(strict_types=1);

namespace Bookurier\Shipping\Plugin;

use Bookurier\Shipping\Model\Email\ShipmentEmailContext;
use Magento\Sales\Model\Order\Email\Container\ShipmentIdentity;

class ShipmentIdentityTemplatePlugin
{
    private const TEMPLATE_MULTI = 'bookurier_sales_email_shipment_multi_template';
    private const TEMPLATE_MULTI_GUEST = 'bookurier_sales_email_shipment_multi_guest_template';

    /**
     * @var ShipmentEmailContext
     */
    private $context;

    public function __construct(ShipmentEmailContext $context)
    {
        $this->context = $context;
    }

    /**
     * @param ShipmentIdentity $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterGetTemplateId(ShipmentIdentity $subject, $result)
    {
        if ($this->shouldUseBookurierMultiTemplate()) {
            return self::TEMPLATE_MULTI;
        }
        return $result;
    }

    /**
     * @param ShipmentIdentity $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterGetGuestTemplateId(ShipmentIdentity $subject, $result)
    {
        if ($this->shouldUseBookurierMultiTemplate()) {
            return self::TEMPLATE_MULTI_GUEST;
        }
        return $result;
    }

    /**
     * @return bool
     */
    private function shouldUseBookurierMultiTemplate(): bool
    {
        $order = $this->context->getOrder();
        if ($order === null) {
            return false;
        }

        $shippingMethod = (string)$order->getShippingMethod();
        if (strpos($shippingMethod, 'bookurier_') !== 0) {
            return false;
        }

        return (int)$order->getShipmentsCollection()->getSize() > 1;
    }
}
