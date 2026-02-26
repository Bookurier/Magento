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
            'service' => $this->toNullableInt($this->getRequest()->getParam('service')),
            'weight' => $this->toNullableFloat($this->getRequest()->getParam('weight')),
            'rbs_val' => $this->toNullableFloat($this->getRequest()->getParam('rbs_val')),
            'insurance_val' => $this->toNullableFloat($this->getRequest()->getParam('insurance_val')),
            'ret_doc' => $this->toFlag($this->getRequest()->getParam('ret_doc')),
            'weekend' => $this->toFlag($this->getRequest()->getParam('weekend')),
            'unpack' => $this->toFlag($this->getRequest()->getParam('unpack')),
            'exchange_pack' => $this->toFlag($this->getRequest()->getParam('exchange_pack')),
            'confirmation' => $this->toFlag($this->getRequest()->getParam('confirmation')),
            'notes' => (string)$this->getRequest()->getParam('notes'),
            'ref1' => (string)$this->getRequest()->getParam('ref1'),
            'ref2' => (string)$this->getRequest()->getParam('ref2'),
        ];
        $overrides = array_filter($overrides, static function ($value) {
            return $value !== null;
        });
        try {
            $awbCode = $this->awbCreator->createForOrder($order, $overrides, null);
            $this->messageManager->addSuccessMessage(__('AWB created: %1', $awbCode));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Failed to create AWB.'));
        }

        return $this->resultRedirectFactory->create()->setPath('sales/order/view', ['order_id' => $orderId]);
    }

    /**
     * @param mixed $value
     * @return float|null
     */
    private function toNullableFloat($value): ?float
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        return (float)$raw;
    }

    /**
     * @param mixed $value
     * @return int|null
     */
    private function toNullableInt($value): ?int
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        return (int)$raw;
    }

    /**
     * @param mixed $value
     * @return int
     */
    private function toFlag($value): int
    {
        return ((int)$value) === 1 ? 1 : 0;
    }
}
