<?php
/**
 * Build Bookurier fulfillment payloads from Magento orders.
 */
namespace Bookurier\Shipping\Model\Fulfillment;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Directory\Model\CountryFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\ShipmentInterface;

class PayloadBuilder
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var CountryFactory
     */
    private $countryFactory;

    public function __construct(
        Config $config,
        ProductRepositoryInterface $productRepository,
        CountryFactory $countryFactory
    ) {
        $this->config = $config;
        $this->productRepository = $productRepository;
        $this->countryFactory = $countryFactory;
    }

    /**
     * Build the fulfillment XML message from order-level data.
     *
     * @param OrderInterface $order
     * @return array{message_xml:string,courier:string}
     * @throws LocalizedException
     */
    public function build(OrderInterface $order): array
    {
        $shippingAddress = $order->getShippingAddress();
        if (!$shippingAddress || !$shippingAddress->getId()) {
            throw new LocalizedException(__('Order has no shipping address.'));
        }

        $products = $this->buildProducts($order, (int)$order->getStoreId());
        if (!$products) {
            throw new LocalizedException(__('Order has no fulfillable products.'));
        }

        $courier = $this->resolveCourier($order);

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;

        $msgNode = $document->appendChild($document->createElement('msg'));
        $ordersNode = $msgNode->appendChild($document->createElement('orders'));
        $orderNode = $ordersNode->appendChild($document->createElement('order'));

        $this->appendNode($document, $orderNode, 'order_num', (string)$order->getIncrementId());
        $this->appendNode($document, $orderNode, 'receiver', $this->buildReceiver($shippingAddress));
        $this->appendNode($document, $orderNode, 'country', $this->resolveCountryName($shippingAddress));
        $this->appendNode($document, $orderNode, 'city', trim((string)$shippingAddress->getCity()));
        $this->appendNode($document, $orderNode, 'district', trim((string)$shippingAddress->getRegion()));
        $this->appendNode($document, $orderNode, 'address', $this->buildStreet($shippingAddress));
        $this->appendNode($document, $orderNode, 'zip', trim((string)$shippingAddress->getPostcode()));
        $this->appendNode($document, $orderNode, 'phone', trim((string)$shippingAddress->getTelephone()));
        $this->appendNode($document, $orderNode, 'email', $this->resolveEmail($order, $shippingAddress));
        $this->appendNode($document, $orderNode, 'repayment', $this->formatDecimal((float)$order->getGrandTotal()));
        $this->appendNode($document, $orderNode, 'currency', trim((string)$order->getOrderCurrencyCode()));
        $this->appendNode($document, $orderNode, 'insurance', '0');
        $this->appendNode(
            $document,
            $orderNode,
            'packtype',
            (string)$this->config->getPacktype((int)$order->getStoreId())
        );
        $this->appendNode($document, $orderNode, 'exchange_pack', '0');
        $this->appendNode($document, $orderNode, 'courier', $courier);
        $this->appendNode($document, $orderNode, 'awb', $this->resolveAwb($order));
        $this->appendNode($document, $orderNode, 'obs', '');
        $this->appendNode($document, $orderNode, 'invoice_url', '');

        $productsNode = $orderNode->appendChild($document->createElement('products'));
        foreach ($products as $productData) {
            $productNode = $productsNode->appendChild($document->createElement('product'));
            $this->appendNode($document, $productNode, 'barcode', $productData['barcode']);
            $this->appendNode($document, $productNode, 'quantity', $productData['quantity']);
        }

        return [
            'message_xml' => $document->saveXML($msgNode),
            'courier' => $courier,
        ];
    }

    /**
     * @param OrderInterface $order
     * @param int $storeId
     * @return array<int,array{barcode:string,quantity:string}>
     * @throws LocalizedException
     */
    private function buildProducts(OrderInterface $order, int $storeId): array
    {
        $items = [];
        foreach ($order->getAllVisibleItems() as $orderItem) {
            if (!$orderItem instanceof OrderItemInterface || $orderItem->isDummy()) {
                continue;
            }

            $productId = (int)$orderItem->getProductId();
            $qty = (float)$orderItem->getQtyOrdered();
            if ($productId <= 0 || $qty <= 0) {
                continue;
            }

            $product = $this->productRepository->getById($productId, false, $storeId);
            $barcode = trim((string)$product->getData('ean'));
            if ($barcode === '') {
                throw new LocalizedException(
                    __('Product "%1" is missing EAN and cannot be sent to fulfillment.', $orderItem->getName())
                );
            }

            $items[] = [
                'barcode' => $barcode,
                'quantity' => $this->formatQuantity($qty),
            ];
        }

        return $items;
    }

    /**
     * @param OrderAddressInterface $shippingAddress
     * @return string
     * @throws LocalizedException
     */
    private function buildReceiver(OrderAddressInterface $shippingAddress): string
    {
        $parts = array_filter([
            trim((string)$shippingAddress->getName()),
            trim((string)$shippingAddress->getCompany()),
        ]);
        $receiver = implode(', ', array_unique($parts));
        if ($receiver === '') {
            throw new LocalizedException(__('Shipping address is missing receiver name.'));
        }

        return $receiver;
    }

    /**
     * @param OrderAddressInterface $shippingAddress
     * @return string
     * @throws LocalizedException
     */
    private function buildStreet(OrderAddressInterface $shippingAddress): string
    {
        $street = $shippingAddress->getStreet();
        $streetLines = is_array($street) ? $street : [$street];
        $streetValue = trim(implode(', ', array_filter(array_map('trim', $streetLines))));
        if ($streetValue === '') {
            throw new LocalizedException(__('Shipping address is missing street.'));
        }

        return $streetValue;
    }

    /**
     * @param OrderInterface $order
     * @param OrderAddressInterface $shippingAddress
     * @return string
     * @throws LocalizedException
     */
    private function resolveEmail(OrderInterface $order, OrderAddressInterface $shippingAddress): string
    {
        $email = trim((string)$shippingAddress->getEmail());
        if ($email === '') {
            $email = trim((string)$order->getCustomerEmail());
        }
        if ($email === '') {
            throw new LocalizedException(__('Shipping address is missing email.'));
        }

        return $email;
    }

    /**
     * @param OrderAddressInterface $shippingAddress
     * @return string
     * @throws LocalizedException
     */
    private function resolveCountryName(OrderAddressInterface $shippingAddress): string
    {
        $countryId = trim((string)$shippingAddress->getCountryId());
        if ($countryId === '') {
            throw new LocalizedException(__('Shipping address is missing country.'));
        }

        $country = $this->countryFactory->create()->loadByCode($countryId);
        $countryName = trim((string)$country->getName());
        if ($countryName === '') {
            throw new LocalizedException(__('Could not resolve country name for %1.', $countryId));
        }

        return $countryName;
    }

    /**
     * @param OrderInterface $order
     * @return string
     * @throws LocalizedException
     */
    private function resolveCourier(OrderInterface $order): string
    {
        $storeId = (int)$order->getStoreId();
        $override = $this->config->getCourierOverride($storeId);
        if ($override !== '') {
            return $override;
        }

        $description = trim((string)$order->getShippingDescription());
        if ($description !== '') {
            return $description;
        }

        throw new LocalizedException(__('Order is missing shipping description and no fulfillment courier override is configured.'));
    }

    /**
     * Return all Bookurier AWB track numbers on the order as a comma-separated string.
     *
     * @param OrderInterface $order
     * @return string
     */
    private function resolveAwb(OrderInterface $order): string
    {
        $awbCodes = [];

        foreach ($order->getShipmentsCollection() as $shipment) {
            if (!$shipment instanceof ShipmentInterface) {
                continue;
            }

            foreach ($shipment->getTracksCollection() as $track) {
                if ((string)$track->getCarrierCode() !== 'bookurier') {
                    continue;
                }

                $awbCode = trim((string)$track->getTrackNumber());
                if ($awbCode === '') {
                    continue;
                }

                $awbCodes[] = $awbCode;
            }
        }

        return implode(',', array_unique($awbCodes));
    }

    /**
     * @param \DOMDocument $document
     * @param \DOMElement $parentNode
     * @param string $name
     * @param string $value
     * @return void
     */
    private function appendNode(\DOMDocument $document, \DOMElement $parentNode, string $name, string $value): void
    {
        $parentNode->appendChild($document->createElement($name))->appendChild($document->createTextNode($value));
    }

    /**
     * @param float $value
     * @return string
     */
    private function formatDecimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * @param float $qty
     * @return string
     */
    private function formatQuantity(float $qty): string
    {
        if ((float)(int)$qty === $qty) {
            return (string)(int)$qty;
        }

        return rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');
    }
}
