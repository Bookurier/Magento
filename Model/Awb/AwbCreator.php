<?php
/**
 * Create Bookurier AWB for orders.
 */
namespace Bookurier\Shipping\Model\Awb;

use Bookurier\Shipping\Model\Api\Client;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Framework\Exception\LocalizedException;

class AwbCreator
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var PayloadBuilder
     */
    private $payloadBuilder;

    /**
     * @var AwbAttacher
     */
    private $awbAttacher;

    public function __construct(
        Client $client,
        PayloadBuilder $payloadBuilder,
        AwbAttacher $awbAttacher
    ) {
        $this->client = $client;
        $this->payloadBuilder = $payloadBuilder;
        $this->awbAttacher = $awbAttacher;
    }

    /**
     * @param OrderInterface $order
     * @param array $overrides
     * @return string
     * @throws LocalizedException
     */
    public function createForOrder(OrderInterface $order, array $overrides = []): string
    {
        $overrides['rbs_val'] = $this->getCodAmount($order);

        $payload = $this->payloadBuilder->build($order, $overrides);
        $result = $this->client->addCommands([$payload], (int)$order->getStoreId());

        if (($result['status'] ?? '') !== 'success') {
            throw new LocalizedException(__($result['message'] ?? 'Failed to create AWB.'));
        }

        $awbCode = $result['data'][0] ?? null;
        if (!$awbCode) {
            throw new LocalizedException(__('No AWB code returned by Bookurier.'));
        }

        $this->awbAttacher->attach($order, (string)$awbCode);
        return (string)$awbCode;
    }

    /**
     * @param OrderInterface $order
     * @return float
     */
    private function getCodAmount(OrderInterface $order): float
    {
        $payment = $order->getPayment();
        if ($payment && $payment->getMethod() === 'cashondelivery') {
            return (float)$order->getGrandTotal();
        }
        return 0.0;
    }
}
