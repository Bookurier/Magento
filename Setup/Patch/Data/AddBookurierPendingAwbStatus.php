<?php
/**
 * Register Bookurier pending AWB order status.
 */
namespace Bookurier\Shipping\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Status as StatusResource;
use Magento\Sales\Model\Order\StatusFactory;

class AddBookurierPendingAwbStatus implements DataPatchInterface
{
    private const STATUS = 'bookurier_pending_awb';
    private const LABEL = 'Pending Bookurier AWB';

    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var StatusFactory
     */
    private $statusFactory;

    /**
     * @var StatusResource
     */
    private $statusResource;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        StatusFactory $statusFactory,
        StatusResource $statusResource
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->statusFactory = $statusFactory;
        $this->statusResource = $statusResource;
    }

    public function apply()
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        $status = $this->statusFactory->create();
        $this->statusResource->load($status, self::STATUS);
        if (!$status->getStatus()) {
            $status->setData(['status' => self::STATUS, 'label' => self::LABEL]);
            $this->statusResource->save($status);
        }

        $status->assignState(Order::STATE_PROCESSING, false, true);

        $connection->endSetup();
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
