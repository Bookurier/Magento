<?php
/**
 * Send fulfillment in bulk from the order grid.
 */
namespace Bookurier\Shipping\Controller\Adminhtml\Order;

use Bookurier\Shipping\Model\Fulfillment\Processor;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Ui\Component\MassAction\Filter;

class MassFulfillment extends Action
{
    public const ADMIN_RESOURCE = 'Bookurier_Shipping::fulfillment';

    /**
     * @var Filter
     */
    private $filter;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var Processor
     */
    private $processor;

    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        Processor $processor
    ) {
        parent::__construct($context);
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->processor = $processor;
    }

    public function execute()
    {
        $collection = $this->filter->getCollection($this->collectionFactory->create());
        $total = (int)$collection->getSize();
        if ($total === 0) {
            $this->messageManager->addWarningMessage(__('No orders selected.'));
            return $this->resultRedirectFactory->create()->setPath('sales/order/index');
        }

        $processed = 0;
        $failed = 0;
        $skippedByReason = [];

        foreach ($collection as $order) {
            try {
                $this->processor->process($order);
                $processed++;
            } catch (LocalizedException $e) {
                $failed++;
                $reason = trim((string)$e->getMessage());
                if ($reason === '') {
                    $reason = (string)__('Order is not eligible for fulfillment.');
                }
                $skippedByReason[$reason][] = (string)$order->getIncrementId();
            } catch (\Exception $e) {
                $failed++;
                $skippedByReason[(string)__('Failed to send fulfillment.')][] = (string)$order->getIncrementId();
            }
        }

        if ($processed > 0) {
            $this->messageManager->addSuccessMessage(
                __('Sent fulfillment for %1 order(s).', $processed)
            );
        }

        if ($failed > 0) {
            $this->messageManager->addErrorMessage(
                __('Failed for %1 order(s): %2', $failed, $this->renderFailureSummary($skippedByReason))
            );
        }

        return $this->resultRedirectFactory->create()->setPath('sales/order/index');
    }

    /**
     * @param array<string,array<int,string>> $skippedByReason
     * @return string
     */
    private function renderFailureSummary(array $skippedByReason): string
    {
        if (!$skippedByReason) {
            return (string)__('no details available');
        }

        $parts = [];
        foreach ($skippedByReason as $reason => $incrementIds) {
            $refs = array_slice(array_values(array_unique(array_filter($incrementIds))), 0, 3);
            $suffix = $refs ? ' [' . implode(', ', $refs) . ']' : '';
            $parts[] = (string)__($reason) . $suffix;
        }

        return implode('; ', $parts);
    }
}
