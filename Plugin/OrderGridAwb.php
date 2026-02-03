<?php
/**
 * Add Bookurier AWB column data to the sales order grid.
 */
namespace Bookurier\Shipping\Plugin;

use Magento\Sales\Model\ResourceModel\Order\Grid\Collection;

class OrderGridAwb
{
    /**
     * Join shipment track data for Bookurier and expose it as a grid column.
     *
     * @param Collection $collection
     * @param bool $printQuery
     * @param bool $logQuery
     * @return array
     */
    public function beforeLoad(Collection $collection, $printQuery = false, $logQuery = false): array
    {
        if ($collection->getFlag('bookurier_awb_joined')) {
            return [$printQuery, $logQuery];
        }

        $shipmentTable = $collection->getTable('sales_shipment');
        $trackTable = $collection->getTable('sales_shipment_track');

        $collection->getSelect()->joinLeft(
            ['bs' => $shipmentTable],
            'bs.order_id = main_table.entity_id',
            []
        )->joinLeft(
            ['bst' => $trackTable],
            "bst.parent_id = bs.entity_id AND bst.carrier_code = 'bookurier'",
            []
        );

        $collection->getSelect()->columns([
            'bookurier_awb' => new \Zend_Db_Expr("GROUP_CONCAT(DISTINCT bst.track_number SEPARATOR ', ')")
        ]);
        $collection->getSelect()->group('main_table.entity_id');
        $collection->addFilterToMap('bookurier_awb', 'bst.track_number');

        $collection->setFlag('bookurier_awb_joined', true);
        return [$printQuery, $logQuery];
    }
}
