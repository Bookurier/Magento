<?php
/**
 * Delete Bookurier AWB for a single order.
 */
namespace Bookurier\Shipping\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Sales\Api\OrderRepositoryInterface;
use Bookurier\Shipping\Model\Awb\AwbRemover;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class DeleteAwb extends Action
{
    public const ADMIN_RESOURCE = 'Bookurier_Shipping::awb_delete';

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var AwbRemover
     */
    private $awbRemover;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        AwbRemover $awbRemover
    ) {
        parent::__construct($context);
        $this->orderRepository = $orderRepository;
        $this->awbRemover = $awbRemover;
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
            $removed = $this->awbRemover->removeForOrder($order);
            $this->messageManager->addSuccessMessage(__('Deleted %1 Bookurier AWB(s).', $removed));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Failed to delete Bookurier AWB.'));
        }

        return $this->resultRedirectFactory->create()->setPath('sales/order/view', ['order_id' => $orderId]);
    }
}
