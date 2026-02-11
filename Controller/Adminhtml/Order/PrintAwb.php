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
use Bookurier\Shipping\Model\Config;
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

    /**
     * @var Config
     */
    private $config;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        AwbLocator $awbLocator,
        Client $client,
        Config $config
    ) {
        parent::__construct($context);
        $this->orderRepository = $orderRepository;
        $this->awbLocator = $awbLocator;
        $this->client = $client;
        $this->config = $config;
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

        $mode = $this->config->getPrintAwbMode((int)$order->getStoreId());
        $format = $this->config->getPrintAwbFormat((int)$order->getStoreId());
        $pageParam = $this->getRequest()->getParam('page');
        $page = ($pageParam === null || $pageParam === '') ? null : (int)$pageParam;

        try {
            $document = $this->client->printAwbs($awbCodes, $format, $mode, $page, (int)$order->getStoreId());
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Failed to print Bookurier AWB.'));
            return $this->resultRedirectFactory->create()->setPath('sales/order/view', ['order_id' => $orderId]);
        }

        if (strpos(ltrim($document), '{') === 0) {
            $decoded = json_decode($document, true);
            if (is_array($decoded) && ($decoded['status'] ?? '') === 'error') {
                $this->messageManager->addErrorMessage(__($decoded['message'] ?? 'Failed to print Bookurier AWB.'));
                return $this->resultRedirectFactory->create()->setPath('sales/order/view', ['order_id' => $orderId]);
            }
        }

        $extension = $format === 'html' ? 'html' : 'pdf';
        $contentType = $format === 'html' ? 'text/html; charset=UTF-8' : 'application/pdf';
        $fileName = 'bookurier_awb_' . $order->getIncrementId() . '.' . $extension;
        $response = $this->getResponse();
        $response->setHeader('Content-Type', $contentType, true);
        $response->setHeader('Content-Disposition', 'inline; filename="' . $fileName . '"', true);
        $response->setBody($document);
        return $response;
    }
}
