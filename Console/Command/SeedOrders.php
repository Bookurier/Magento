<?php
/**
 * Seed test orders for Bookurier bulk AWB processing.
 */
namespace Bookurier\Shipping\Console\Command;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Type\Onepage;
use Magento\Framework\App\State;
use Magento\Framework\DB\Transaction;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Directory\Model\RegionFactory;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\ShipmentFactory;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SeedOrders extends Command
{
    private const OPT_COUNT = 'count';
    private const OPT_SKU = 'sku';
    private const OPT_STORE_ID = 'store-id';
    private const OPT_SHIPPING_METHOD = 'shipping-method';
    private const OPT_PAYMENT_METHOD = 'payment-method';
    private const OPT_CREATE_SHIPMENT = 'create-shipment';
    private const OPT_COD = 'cod';
    private const OPT_COUNTRY_ID = 'country-id';
    private const OPT_REGION = 'region';
    private const OPT_REGION_ID = 'region-id';

    /**
     * @var State
     */
    private $appState;

    /**
     * @var QuoteFactory
     */
    private $quoteFactory;

    /**
     * @var CartManagementInterface
     */
    private $cartManagement;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var ShipmentFactory
     */
    private $shipmentFactory;

    /**
     * @var Transaction
     */
    private $transaction;

    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * @var RegionFactory
     */
    private $regionFactory;

    public function __construct(
        State $appState,
        QuoteFactory $quoteFactory,
        CartManagementInterface $cartManagement,
        CartRepositoryInterface $cartRepository,
        ProductRepositoryInterface $productRepository,
        StoreManagerInterface $storeManager,
        OrderRepositoryInterface $orderRepository,
        ShipmentFactory $shipmentFactory,
        Transaction $transaction,
        DateTime $dateTime,
        RegionFactory $regionFactory
    ) {
        parent::__construct();
        $this->appState = $appState;
        $this->quoteFactory = $quoteFactory;
        $this->cartManagement = $cartManagement;
        $this->cartRepository = $cartRepository;
        $this->productRepository = $productRepository;
        $this->storeManager = $storeManager;
        $this->orderRepository = $orderRepository;
        $this->shipmentFactory = $shipmentFactory;
        $this->transaction = $transaction;
        $this->dateTime = $dateTime;
        $this->regionFactory = $regionFactory;
    }

    protected function configure(): void
    {
        $this->setName('bookurier:seed-orders')
            ->setDescription('Create test orders for Bookurier bulk AWB processing.')
            ->addOption(self::OPT_SKU, null, InputOption::VALUE_REQUIRED, 'Product SKU to add to each order')
            ->addOption(self::OPT_COUNT, null, InputOption::VALUE_OPTIONAL, 'Number of orders to create', 100)
            ->addOption(self::OPT_STORE_ID, null, InputOption::VALUE_OPTIONAL, 'Store ID', 1)
            ->addOption(self::OPT_SHIPPING_METHOD, null, InputOption::VALUE_OPTIONAL, 'Shipping method', 'bookurier_bookurier')
            ->addOption(self::OPT_PAYMENT_METHOD, null, InputOption::VALUE_OPTIONAL, 'Payment method', 'checkmo')
            ->addOption(self::OPT_CREATE_SHIPMENT, null, InputOption::VALUE_OPTIONAL, 'Create shipment (yes/no)', 'yes')
            ->addOption(self::OPT_COD, null, InputOption::VALUE_OPTIONAL, 'Force COD payment method (yes/no)', 'no')
            ->addOption(self::OPT_COUNTRY_ID, null, InputOption::VALUE_OPTIONAL, 'Country ID', 'RO')
            ->addOption(self::OPT_REGION, null, InputOption::VALUE_OPTIONAL, 'Region name', 'Bucuresti')
            ->addOption(self::OPT_REGION_ID, null, InputOption::VALUE_OPTIONAL, 'Region ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode('adminhtml');
        } catch (LocalizedException $e) {
            // Area code already set.
        }

        $sku = (string)$input->getOption(self::OPT_SKU);
        if ($sku === '') {
            $output->writeln('<error>Missing required option: --sku</error>');
            return Command::FAILURE;
        }

        $count = (int)$input->getOption(self::OPT_COUNT);
        $storeId = (int)$input->getOption(self::OPT_STORE_ID);
        $shippingMethod = (string)$input->getOption(self::OPT_SHIPPING_METHOD);
        $paymentMethod = (string)$input->getOption(self::OPT_PAYMENT_METHOD);
        $createShipment = strtolower((string)$input->getOption(self::OPT_CREATE_SHIPMENT)) !== 'no';
        $forceCod = strtolower((string)$input->getOption(self::OPT_COD)) === 'yes';
        $countryId = (string)$input->getOption(self::OPT_COUNTRY_ID);
        $regionName = (string)$input->getOption(self::OPT_REGION);
        $regionId = (int)$input->getOption(self::OPT_REGION_ID);

        if ($forceCod) {
            $paymentMethod = 'cashondelivery';
        }

        $store = $this->storeManager->getStore($storeId);
        $product = $this->productRepository->get($sku, false, $storeId);

        $created = 0;
        $failed = 0;
        $now = $this->dateTime->gmtTimestamp();

        for ($i = 1; $i <= $count; $i++) {
            try {
                $quote = $this->quoteFactory->create();
                $quote->setStore($store);
                $quote->setIsActive(true);
                $quote->setIsMultiShipping(false);
                $quote->setCheckoutMethod(Onepage::METHOD_GUEST);
                $quote->setCustomerEmail('test+' . $now . '-' . $i . '@example.com');
                $quote->setCustomerFirstname('Test');
                $quote->setCustomerLastname('Buyer');

                $quote->addProduct($product, 1);

                if ($regionId <= 0) {
                    $regionId = $this->resolveRegionId($countryId, $regionName);
                }

                if ($regionId <= 0) {
                    throw new LocalizedException(
                        __('Region ID is required. Pass --region-id or configure a matching --region name.')
                    );
                }

                $addressData = [
                    'firstname' => 'Test',
                    'lastname' => 'Buyer',
                    'street' => ['Strada Exemplu 1'],
                    'city' => 'Bucuresti',
                    'postcode' => '010101',
                    'country_id' => $countryId,
                    'telephone' => '0700000000',
                    'region' => $regionName,
                    'region_id' => $regionId,
                ];

                $quote->getBillingAddress()->addData($addressData);
                $shippingAddress = $quote->getShippingAddress()->addData($addressData);
                $shippingAddress->setCollectShippingRates(true);
                $shippingAddress->collectShippingRates();
                $shippingAddress->setShippingMethod($shippingMethod);

                $quote->setPaymentMethod($paymentMethod);
                $payment = $quote->getPayment();
                $payment->setQuote($quote);
                $payment->importData(['method' => $paymentMethod]);

                $quote->collectTotals();

                $this->cartRepository->save($quote);
                $orderId = (int)$this->cartManagement->placeOrder((int)$quote->getId());
                $order = $this->orderRepository->get($orderId);

                if ($createShipment && $order->canShip()) {
                    $qtys = [];
                    foreach ($order->getAllItems() as $item) {
                        if ($item->getIsVirtual() || $item->getQtyToShip() <= 0) {
                            continue;
                        }
                        $qtys[$item->getId()] = (float)$item->getQtyToShip();
                    }

                    if ($qtys) {
                        $shipment = $this->shipmentFactory->create($order, $qtys);
                        if ($shipment) {
                            $shipment->register();
                            $shipment->getOrder()->setIsInProcess(true);
                            $this->transaction->addObject($shipment)->addObject($shipment->getOrder())->save();
                        }
                    }
                }

                $created++;
                if ($created % 10 === 0) {
                    $output->writeln(sprintf('<info>Created %d/%d orders</info>', $created, $count));
                }
            } catch (\Exception $e) {
                $failed++;
                $output->writeln('<error>Failed to create order #' . $i . ': ' . $e->getMessage() . '</error>');
            }
        }

        $output->writeln(sprintf('<info>Done. Created: %d. Failed: %d.</info>', $created, $failed));
        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param string $countryId
     * @param string $regionName
     * @return int
     */
    private function resolveRegionId(string $countryId, string $regionName): int
    {
        if ($countryId === '' || $regionName === '') {
            return 0;
        }

        $region = $this->regionFactory->create()->loadByName($regionName, $countryId);
        return (int)$region->getId();
    }
}
