<?php
/**
 * Print Bookurier AWB for a single order.
 */
namespace Bookurier\Shipping\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Sales\Api\OrderRepositoryInterface;
use Bookurier\Shipping\Model\Awb\AwbLocator;
use Bookurier\Shipping\Model\Api\Client;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class PrintAwb extends Action
{
    public const ADMIN_RESOURCE = 'Bookurier_Shipping::awb_print';

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var AwbLocator
     */
    private $awbLocator;

    /**
     * @var Client
     */
    private $client;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        AwbLocator $awbLocator,
        Client $client
    ) {
        parent::__construct($context);
        $this->orderRepository = $orderRepository;
        $this->awbLocator = $awbLocator;
        $this->client = $client;
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

        $awbCodes = $this->awbLocator->getBookurierAwbs($order);
        if (empty($awbCodes)) {
            $this->messageManager->addErrorMessage(__('No Bookurier AWB found for this order.'));
            return $this->resultRedirectFactory->create()->setPath('sales/order/view', ['order_id' => $orderId]);
        }

        $mode = count($awbCodes) === 1 ? 's' : 'm';

        try {
            $pdf = $this->client->printAwbs($awbCodes, 'pdf', $mode, null, (int)$order->getStoreId());
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Failed to print Bookurier AWB.'));
            return $this->resultRedirectFactory->create()->setPath('sales/order/view', ['order_id' => $orderId]);
        }

        if (strpos(ltrim($pdf), '{') === 0) {
            $decoded = json_decode($pdf, true);
            if (is_array($decoded) && ($decoded['status'] ?? '') === 'error') {
                $this->messageManager->addErrorMessage(__($decoded['message'] ?? 'Failed to print Bookurier AWB.'));
                return $this->resultRedirectFactory->create()->setPath('sales/order/view', ['order_id' => $orderId]);
            }
        }

        $fileName = 'bookurier_awb_' . $order->getIncrementId() . '.pdf';
        $response = $this->getResponse();
        $response->setHeader('Content-Type', 'application/pdf', true);
        $response->setHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"', true);
        $response->setBody($pdf);
        return $response;
    }
}
