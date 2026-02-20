<?php
/**
 * Populate shipment email context during email sender execution.
 */
declare(strict_types=1);

namespace Bookurier\Shipping\Plugin;

use Bookurier\Shipping\Model\Email\ShipmentEmailContext;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentCommentCreationInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Model\Order\Shipment\Sender\EmailSender;

class ShipmentEmailContextPlugin
{
    /**
     * @var ShipmentEmailContext
     */
    private $context;

    public function __construct(ShipmentEmailContext $context)
    {
        $this->context = $context;
    }

    /**
     * @param EmailSender $subject
     * @param callable $proceed
     * @param OrderInterface $order
     * @param ShipmentInterface $shipment
     * @param ShipmentCommentCreationInterface|null $comment
     * @param bool $forceSyncMode
     * @return bool
     */
    public function aroundSend(
        EmailSender $subject,
        callable $proceed,
        OrderInterface $order,
        ShipmentInterface $shipment,
        ?ShipmentCommentCreationInterface $comment = null,
        $forceSyncMode = false
    ): bool {
        $this->context->setOrder($order);
        try {
            return (bool)$proceed($order, $shipment, $comment, $forceSyncMode);
        } finally {
            $this->context->clear();
        }
    }
}
