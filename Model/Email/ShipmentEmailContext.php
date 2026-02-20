<?php
/**
 * Request-scoped context for shipment email template selection.
 */
declare(strict_types=1);

namespace Bookurier\Shipping\Model\Email;

use Magento\Sales\Api\Data\OrderInterface;

class ShipmentEmailContext
{
    /**
     * @var OrderInterface|null
     */
    private $order;

    /**
     * @param OrderInterface $order
     * @return void
     */
    public function setOrder(OrderInterface $order): void
    {
        $this->order = $order;
    }

    /**
     * @return OrderInterface|null
     */
    public function getOrder(): ?OrderInterface
    {
        return $this->order;
    }

    /**
     * @return void
     */
    public function clear(): void
    {
        $this->order = null;
    }
}
