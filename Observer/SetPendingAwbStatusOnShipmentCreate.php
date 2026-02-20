<?php
/**
 * Set order status to pending AWB after Bookurier shipment creation and notify customer.
 */
namespace Bookurier\Shipping\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderCommentSender;
use Psr\Log\LoggerInterface;

class SetPendingAwbStatusOnShipmentCreate implements ObserverInterface
{
    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var OrderCommentSender
     */
    private $orderCommentSender;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        OrderCommentSender $orderCommentSender,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderCommentSender = $orderCommentSender;
        $this->logger = $logger;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $shipment = $observer->getEvent()->getShipment();
        if (!$shipment) {
            return;
        }

        // Only react when a shipment is first created.
        if ((int)$shipment->getOrigData('entity_id') > 0) {
            return;
        }

        $order = $shipment->getOrder();
        if (!$order || (int)$order->getEntityId() <= 0) {
            return;
        }

        $shippingMethod = (string)$order->getShippingMethod();
        if (strpos($shippingMethod, 'bookurier_') !== 0) {
            return;
        }

        if ((string)$order->getStatus() === 'bookurier_pending_awb') {
            return;
        }

        $comment = (string)__('Order status updated to Pending Bookurier AWB.');

        try {
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus('bookurier_pending_awb');
            $history = $order->addCommentToStatusHistory($comment, 'bookurier_pending_awb');
            $history->setIsCustomerNotified(true);
            $this->orderRepository->save($order);
            $this->orderCommentSender->send($order, true, $comment);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to set Pending Bookurier AWB status after shipment creation.', [
                'order_id' => (int)$order->getEntityId(),
                'shipment_id' => (int)$shipment->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
