<?php
/**
 * Send fulfillment for a single order.
 */
namespace Bookurier\Shipping\Controller\Adminhtml\Order;

use Bookurier\Shipping\Model\Fulfillment\Processor;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;

class Fulfillment extends Action
{
    public const ADMIN_RESOURCE = 'Bookurier_Shipping::fulfillment';

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var Processor
     */
    private $processor;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        Processor $processor
    ) {
        parent::__construct($context);
        $this->orderRepository = $orderRepository;
        $this->processor = $processor;
    }

    public function execute()
    {
        $orderId = (int)$this->getRequest()->getParam('order_id');
        if (!$orderId) {
            $this->messageManager->addErrorMessage(__('Order ID is missing.'));
            return $this->resultRedirectFactory->create()->setPath('sales/order/index');
        }

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage(__('Order no longer exists.'));
            return $this->resultRedirectFactory->create()->setPath('sales/order/index');
        }

        try {
            $result = $this->processor->process($order);
            if ($result['number'] !== '') {
                $this->messageManager->addSuccessMessage(
                    __('Fulfillment sent. Reference: %1', $result['number'])
                );
            } else {
                $this->messageManager->addSuccessMessage(
                    __('Fulfillment sent using courier %1.', $result['courier'])
                );
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Failed to send fulfillment.'));
        }

        return $this->resultRedirectFactory->create()->setPath('sales/order/view', ['order_id' => $orderId]);
    }
}
