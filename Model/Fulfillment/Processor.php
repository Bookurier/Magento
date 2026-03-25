<?php
/**
 * Send orders to the Bookurier fulfillment API.
 */
namespace Bookurier\Shipping\Model\Fulfillment;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;

class Processor
{
    /**
     * @var PayloadBuilder
     */
    private $payloadBuilder;

    /**
     * @var ApiClient
     */
    private $client;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    public function __construct(
        PayloadBuilder $payloadBuilder,
        ApiClient $client,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->payloadBuilder = $payloadBuilder;
        $this->client = $client;
        $this->orderRepository = $orderRepository;
    }

    /**
     * @param OrderInterface $order
     * @return array{courier:string,number:string}
     * @throws LocalizedException
     */
    public function process(OrderInterface $order): array
    {
        $payload = $this->payloadBuilder->build($order);
        $response = $this->client->addOrder($payload['message_xml'], (int)$order->getStoreId());
        if (strcasecmp((string)($response['status'] ?? ''), 'Ok') !== 0) {
            $description = trim((string)($response['description'] ?? ''));
            if ($description === '') {
                $description = (string)__('Bookurier fulfillment API returned an error.');
            }

            throw new LocalizedException(__($description));
        }

        $this->addSuccessComment($order, (string)($response['number'] ?? ''), $payload['courier']);

        return [
            'courier' => $payload['courier'],
            'number' => (string)($response['number'] ?? ''),
        ];
    }

    /**
     * @param OrderInterface $order
     * @param string $reference
     * @param string $courier
     * @return void
     */
    private function addSuccessComment(OrderInterface $order, string $reference, string $courier): void
    {
        if (!$order instanceof Order) {
            return;
        }

        $comment = $reference !== ''
            ? (string)__('Bookurier fulfillment created. Reference: %1. Courier: %2.', $reference, $courier)
            : (string)__('Bookurier fulfillment created. Courier: %1.', $courier);

        $order->addCommentToStatusHistory($comment);
        $this->orderRepository->save($order);
    }
}
