<?php
/**
 * AWB form page for a single order.
 */
namespace Bookurier\Shipping\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Exception\NoSuchEntityException;

class AwbForm extends Action
{
    public const ADMIN_RESOURCE = 'Bookurier_Shipping::awb_create';

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var PageFactory
     */
    private $resultPageFactory;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        Registry $registry,
        PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
        $this->orderRepository = $orderRepository;
        $this->registry = $registry;
        $this->resultPageFactory = $resultPageFactory;
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
            $this->registry->register('current_order', $order);
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage(__('Order no longer exists.'));
            return $this->resultRedirectFactory->create()->setPath('sales/order/index');
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->prepend(__('Create Bookurier AWB'));
        return $resultPage;
    }
}
