<?php
/**
 * Collection for Bookurier AWB queue items.
 */
namespace Bookurier\Shipping\Model\ResourceModel\Queue\QueueItem;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(
            \Bookurier\Shipping\Model\Queue\QueueItem::class,
            \Bookurier\Shipping\Model\ResourceModel\Queue\QueueItem::class
        );
    }
}
