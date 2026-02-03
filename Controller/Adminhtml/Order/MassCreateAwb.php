<?php
/**
 * Handle AWB creation in bulk from order grid.
 */
namespace Bookurier\Shipping\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Bookurier\Shipping\Model\Awb\PayloadBuilder;
use Bookurier\Shipping\Model\Api\Client;
use Bookurier\Shipping\Model\Awb\AwbAttacher;
use Magento\Framework\Exception\LocalizedException;

class MassCreateAwb extends Action
{
    public const ADMIN_RESOURCE = 'Bookurier_Shipping::awb_create';

    /**
     * @var Filter
     */
    private $filter;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var PayloadBuilder
     */
    private $payloadBuilder;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var AwbAttacher
     */
    private $awbAttacher;

    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        PayloadBuilder $payloadBuilder,
        Client $client,
        AwbAttacher $awbAttacher
    ) {
        parent::__construct($context);
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->payloadBuilder = $payloadBuilder;
        $this->client = $client;
        $this->awbAttacher = $awbAttacher;
    }

    public function execute()
    {
        $collection = $this->filter->getCollection($this->collectionFactory->create());
        $groups = [];

        foreach ($collection as $order) {
            $overrides = [
                'rbs_val' => $this->getCodAmount($order),
            ];
            $payload = $this->payloadBuilder->build($order, $overrides);
            $groups[(int)$order->getStoreId()][] = [
                'order' => $order,
                'payload' => $payload,
            ];
        }

        $created = 0;
        $failed = 0;

        foreach ($groups as $storeId => $items) {
            $payloads = array_map(static function ($item) {
                return $item['payload'];
            }, $items);

            $result = $this->client->addCommands($payloads, $storeId);
            if (($result['status'] ?? '') !== 'success') {
                $failed += count($items);
                $this->messageManager->addErrorMessage(__($result['message'] ?? 'Failed to create AWBs.'));
                continue;
            }

            $awbCodes = $result['data'] ?? [];
            foreach ($items as $index => $item) {
                $awbCode = $awbCodes[$index] ?? null;
                if (!$awbCode) {
                    $failed++;
                    continue;
                }

                try {
                    $this->awbAttacher->attach($item['order'], (string)$awbCode);
                    $created++;
                } catch (LocalizedException $e) {
                    $failed++;
                }
            }
        }

        if ($created) {
            $this->messageManager->addSuccessMessage(__('Created %1 AWB(s).', $created));
        }
        if ($failed) {
            $this->messageManager->addErrorMessage(__('Failed to create %1 AWB(s).', $failed));
        }

        return $this->resultRedirectFactory->create()->setPath('sales/order/index');
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return float
     */
    private function getCodAmount($order): float
    {
        $payment = $order->getPayment();
        if ($payment && $payment->getMethod() === 'cashondelivery') {
            return (float)$order->getGrandTotal();
        }
        return 0.0;
    }
}
