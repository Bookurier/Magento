<?php
/**
 * Resource model for Bookurier AWB queue.
 */
namespace Bookurier\Shipping\Model\ResourceModel\Queue;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class QueueItem extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('bookurier_awb_queue', 'queue_id');
    }
}
