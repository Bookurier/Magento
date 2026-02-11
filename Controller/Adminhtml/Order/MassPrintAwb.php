<?php
/**
 * Print Bookurier AWB in bulk from order grid.
 */
namespace Bookurier\Shipping\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Bookurier\Shipping\Model\Awb\AwbLocator;
use Bookurier\Shipping\Model\Api\Client;
use Bookurier\Shipping\Model\Config;

class MassPrintAwb extends Action
{
    public const ADMIN_RESOURCE = 'Bookurier_Shipping::awb_print';

    /**
     * @var Filter
     */
    private $filter;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

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
        Filter $filter,
        CollectionFactory $collectionFactory,
        AwbLocator $awbLocator,
        Client $client,
        Config $config
    ) {
        parent::__construct($context);
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->awbLocator = $awbLocator;
        $this->client = $client;
        $this->config = $config;
    }

    public function execute()
    {
        $checkOnly = (bool)$this->getRequest()->getParam('check_only');
        $collection = $this->filter->getCollection($this->collectionFactory->create());

        $awbCodes = [];
        $printedOrderIds = [];
        $skippedOrders = [];

        foreach ($collection as $order) {
            $orderAwbs = $this->awbLocator->getBookurierAwbs($order);
            if (empty($orderAwbs)) {
                $skippedOrders[] = [
                    'order_id' => (int)$order->getId(),
                    'increment_id' => (string)$order->getIncrementId(),
                    'url' => $this->getUrl('sales/order/view', ['order_id' => $order->getId()]),
                ];
                continue;
            }

            $awbCodes = array_merge($awbCodes, $orderAwbs);
            $printedOrderIds[] = (string)$order->getIncrementId();
        }

        if ($checkOnly) {
            $skippedIds = array_map(static function ($order) {
                return $order['increment_id'] ?? $order['order_id'] ?? '';
            }, $skippedOrders);
            $skippedIds = array_filter($skippedIds);

            $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            if (!empty($skippedIds)) {
                return $result->setData([
                    'ok' => false,
                    'missing' => array_values($skippedIds),
                    'message' => (string)__(
                        'Cannot print Bookurier AWB. Remove orders without Bookurier AWB and try again. Missing: %1',
                        implode(', ', $skippedIds)
                    )
                ]);
            }

            return $result->setData(['ok' => true]);
        }

        if (!empty($skippedOrders)) {
            $skippedIds = array_map(static function ($order) {
                return $order['increment_id'] ?? $order['order_id'] ?? '';
            }, $skippedOrders);
            $this->messageManager->addErrorMessage(
                __('Cannot print Bookurier AWB. Remove orders without Bookurier AWB and try again. Missing: %1', implode(', ', array_filter($skippedIds)))
            );
            return $this->resultRedirectFactory->create()->setPath('sales/order/index');
        }

        $awbCodes = array_values(array_unique($awbCodes));
        if (empty($awbCodes)) {
            $this->messageManager->addErrorMessage(__('No Bookurier AWB found for selected orders.'));
            return $this->resultRedirectFactory->create()->setPath('sales/order/index');
        }

        $pageParam = $this->getRequest()->getParam('page');
        $page = ($pageParam === null || $pageParam === '') ? null : (int)$pageParam;
        $mode = $this->config->getPrintAwbMode();
        $format = $this->config->getPrintAwbFormat();

        try {
            $document = $this->client->printAwbs($awbCodes, $format, $mode, $page);
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Failed to print Bookurier AWB.'));
            return $this->resultRedirectFactory->create()->setPath('sales/order/index');
        }

        if (strpos(ltrim($document), '{') === 0) {
            $decoded = json_decode($document, true);
            if (is_array($decoded) && ($decoded['status'] ?? '') === 'error') {
                $this->messageManager->addErrorMessage(__($decoded['message'] ?? 'Failed to print Bookurier AWB.'));
                return $this->resultRedirectFactory->create()->setPath('sales/order/index');
            }
        }

        $extension = $format === 'html' ? 'html' : 'pdf';
        $contentType = $format === 'html' ? 'text/html; charset=UTF-8' : 'application/pdf';
        $fileName = 'bookurier_awb_bulk_' . date('Ymd_His') . '.' . $extension;
        $response = $this->getResponse();
        $response->setHeader('Content-Type', $contentType, true);
        $response->setHeader('Content-Disposition', 'inline; filename="' . $fileName . '"', true);
        $response->setBody($document);
        return $response;
    }
}
