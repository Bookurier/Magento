<?php
/**
 * Handle AWB creation in bulk from order grid.
 */
namespace Bookurier\Shipping\Controller\Adminhtml\Order;

use Bookurier\Shipping\Model\Awb\AwbCreator;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Bookurier\Shipping\Model\Queue\Enqueuer;
use Magento\Framework\Exception\LocalizedException;

class MassCreateAwb extends Action
{
    public const ADMIN_RESOURCE = 'Bookurier_Shipping::awb_create';
    private const SYNC_LIMIT = 10;
    private const MAX_BULK = 100;

    /**
     * @var Filter
     */
    private $filter;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var AwbCreator
     */
    private $awbCreator;

    /**
     * @var Enqueuer
     */
    private $enqueuer;

    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        AwbCreator $awbCreator,
        Enqueuer $enqueuer
    ) {
        parent::__construct($context);
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->awbCreator = $awbCreator;
        $this->enqueuer = $enqueuer;
    }

    public function execute()
    {
        $collection = $this->filter->getCollection($this->collectionFactory->create());
        $total = (int)$collection->getSize();

        if ($total === 0) {
            $this->messageManager->addWarningMessage(__('No orders selected.'));
            return $this->resultRedirectFactory->create()->setPath('sales/order/index');
        }

        if ($total > self::MAX_BULK) {
            $this->messageManager->addErrorMessage(__('You can select up to %1 orders at a time.', self::MAX_BULK));
            return $this->resultRedirectFactory->create()->setPath('sales/order/index');
        }

        if ($total > self::SYNC_LIMIT) {
            $result = $this->enqueuer->enqueueOrders($collection->getItems());
            if ($result['enqueued'] > 0) {
                $this->messageManager->addSuccessMessage(
                    __('Queued %1 shipment(s) for AWB creation. Make sure Magento cron is configured.', $result['enqueued'])
                );
            }
            if ($result['skipped'] > 0) {
                $this->messageManager->addWarningMessage(
                    __(
                        'Skipped %1 shipment(s): %2',
                        $result['skipped'],
                        $this->renderQueueSkipSummary($result['skipped_items'] ?? [])
                    )
                );
            }
            return $this->resultRedirectFactory->create()->setPath('sales/order/index');
        }

        $created = 0;
        $failed = 0;
        $skipped = 0;
        $skippedByReason = [];

        foreach ($collection as $order) {
            $shippingMethod = (string)$order->getShippingMethod();
            if (strpos($shippingMethod, 'bookurier_') !== 0) {
                $skipped++;
                $message = 'Not Bookurier shipping.';
                $skippedByReason[$message][] = (string)$order->getIncrementId();
                continue;
            }

            if ((int)$order->getShipmentsCollection()->getSize() === 0) {
                $skipped++;
                $message = 'No shipment.';
                $skippedByReason[$message][] = (string)$order->getIncrementId();
                continue;
            }

            try {
                $this->awbCreator->createForOrder($order, [], null);
                $created++;
            } catch (LocalizedException $e) {
                $skipped++;
                $message = trim((string)$e->getMessage());
                if ($message === '') {
                    $message = 'Not eligible for AWB creation.';
                }
                $skippedByReason[$message][] = (string)$order->getIncrementId();
            } catch (\Exception $e) {
                $failed++;
            }
        }

        if ($created) {
            $this->messageManager->addSuccessMessage(__('Processed %1 order(s) for Bookurier AWB creation.', $created));
        }
        if ($skipped) {
            $this->messageManager->addWarningMessage(
                __('Skipped %1 order(s): %2', $skipped, $this->renderSyncSkipSummary($skippedByReason))
            );
        }
        if ($failed) {
            $this->messageManager->addErrorMessage(__('Failed to process %1 order(s) for Bookurier AWB creation.', $failed));
        }

        return $this->resultRedirectFactory->create()->setPath('sales/order/index');
    }

    /**
     * @param array $items
     * @return string
     */
    private function renderQueueSkipSummary(array $items): string
    {
        if (!$items) {
            return (string)__('no details available');
        }

        $labels = [
            'already_has_awb' => 'already has AWB',
            'already_queued' => 'already queued',
            'not_allowed_country' => 'destination country not allowed',
            'missing_shipment' => 'no shipment',
            'not_bookurier' => 'not Bookurier shipping',
            'invalid_order' => 'invalid order',
            'invalid_shipment' => 'invalid shipment',
        ];

        $counts = [];
        foreach ($items as $item) {
            $reason = (string)($item['reason'] ?? 'other');
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }

        $parts = [];
        foreach ($counts as $reason => $count) {
            $refs = [];
            foreach ($items as $item) {
                if (($item['reason'] ?? '') !== $reason) {
                    continue;
                }
                $orderRef = (string)($item['order_increment_id'] ?? $item['order_id'] ?? '');
                $shipmentRef = (string)($item['shipment_increment_id'] ?? $item['shipment_id'] ?? '');
                if ($orderRef !== '' && $shipmentRef !== '') {
                    $refs[] = $orderRef . '/' . $shipmentRef;
                } elseif ($orderRef !== '') {
                    $refs[] = $orderRef;
                } elseif ($shipmentRef !== '') {
                    $refs[] = $shipmentRef;
                }
            }
            $refs = array_slice(array_values(array_unique(array_filter($refs))), 0, 3);
            $suffix = empty($refs) ? '' : ' [' . implode(', ', $refs) . ']';
            $parts[] = (string)__($labels[$reason] ?? $reason) . ': ' . $count . $suffix;
        }

        return implode('; ', $parts);
    }

    /**
     * @param array<string,array<int,string>> $skippedByReason
     * @return string
     */
    private function renderSyncSkipSummary(array $skippedByReason): string
    {
        if (!$skippedByReason) {
            return (string)__('no details available');
        }

        $parts = [];
        foreach ($skippedByReason as $reason => $incrementIds) {
            $refs = array_slice(array_filter(array_unique($incrementIds)), 0, 3);
            $suffix = '';
            if (!empty($refs)) {
                $suffix = ' [' . implode(', ', $refs) . ']';
            }
            $parts[] = (string)__($reason) . $suffix;
        }

        return implode('; ', $parts);
    }
}
