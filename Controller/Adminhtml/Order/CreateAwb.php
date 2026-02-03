<?php
/**
 * Handle AWB creation for a single order.
 */
namespace Bookurier\Shipping\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Sales\Api\OrderRepositoryInterface;
use Bookurier\Shipping\Model\Awb\AwbCreator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class CreateAwb extends Action
{
    public const ADMIN_RESOURCE = 'Bookurier_Shipping::awb_create';

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var AwbCreator
     */
    private $awbCreator;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        AwbCreator $awbCreator
    ) {
        parent::__construct($context);
        $this->orderRepository = $orderRepository;
        $this->awbCreator = $awbCreator;
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

        $overrides = [
            'street' => (string)$this->getRequest()->getParam('street'),
            'no' => (string)$this->getRequest()->getParam('no'),
            'bl' => (string)$this->getRequest()->getParam('bl'),
            'ent' => (string)$this->getRequest()->getParam('ent'),
            'floor' => (string)$this->getRequest()->getParam('floor'),
            'apt' => (string)$this->getRequest()->getParam('apt'),
            'notes' => (string)$this->getRequest()->getParam('notes'),
            'ref1' => (string)$this->getRequest()->getParam('ref1'),
            'ref2' => (string)$this->getRequest()->getParam('ref2'),
        ];

        try {
            $awbCode = $this->awbCreator->createForOrder($order, $overrides);
            $this->messageManager->addSuccessMessage(__('AWB created: %1', $awbCode));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Failed to create AWB.'));
        }

        return $this->resultRedirectFactory->create()->setPath('sales/order/view', ['order_id' => $orderId]);
    }
}
