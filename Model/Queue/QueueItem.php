<?php
/**
 * Queue item model for Bookurier AWB processing.
 */
namespace Bookurier\Shipping\Model\Queue;

use Magento\Framework\Model\AbstractModel;

class QueueItem extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(\Bookurier\Shipping\Model\ResourceModel\Queue\QueueItem::class);
    }
}
