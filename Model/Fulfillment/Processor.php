<?php
/**
 * Send orders to the Bookurier fulfillment API.
 */
namespace Bookurier\Shipping\Model\Fulfillment;

use Bookurier\Shipping\Model\Api\Client;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;

class Processor
{
    /**
     * @var PayloadBuilder
     */
    private $payloadBuilder;

    /**
     * @var Client
     */
    private $client;

    public function __construct(
        PayloadBuilder $payloadBuilder,
        Client $client
    ) {
        $this->payloadBuilder = $payloadBuilder;
        $this->client = $client;
    }

    /**
     * @param OrderInterface $order
     * @return array{shipment_label:string,awb:string,courier:string,number:string}
     * @throws LocalizedException
     */
    public function process(OrderInterface $order): array
    {
        $payload = $this->payloadBuilder->build($order);
        $response = $this->client->addFulfillmentOrder($payload['message_xml'], (int)$order->getStoreId());
        if (strcasecmp((string)($response['status'] ?? ''), 'Ok') !== 0) {
            $description = trim((string)($response['description'] ?? ''));
            if ($description === '') {
                $description = (string)__('Bookurier fulfillment API returned an error.');
            }

            throw new LocalizedException(__($description));
        }

        return [
            'shipment_label' => $payload['shipment_label'],
            'awb' => $payload['awb'],
            'courier' => $payload['courier'],
            'number' => (string)($response['number'] ?? ''),
        ];
    }
}
