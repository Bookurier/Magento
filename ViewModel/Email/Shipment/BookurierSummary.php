<?php
/**
 * Build Bookurier shipment email summary rows from persisted shipment/track data.
 */
declare(strict_types=1);

namespace Bookurier\Shipping\ViewModel\Email\Shipment;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class BookurierSummary implements ArgumentInterface
{
    /**
     * @var ResourceConnection
     */
    private $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * @param int $orderId
     * @return array<int,array{shipment_id:int,shipment_label:string,awb_codes:array<int,string>}>
     */
    public function getRowsByOrderId(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $shipmentTable = $connection->getTableName('sales_shipment');
        $trackTable = $connection->getTableName('sales_shipment_track');

        $shipments = $connection->fetchAll(
            $connection->select()
                ->from($shipmentTable, ['shipment_id' => 'entity_id', 'increment_id'])
                ->where('order_id = ?', $orderId)
                ->order('entity_id ASC')
        );

        if (!$shipments) {
            return [];
        }

        $shipmentIds = array_map(static function (array $row): int {
            return (int)$row['shipment_id'];
        }, $shipments);

        $tracks = $connection->fetchAll(
            $connection->select()
                ->from($trackTable, ['parent_id', 'track_number'])
                ->where('parent_id IN (?)', $shipmentIds)
                ->where('carrier_code = ?', 'bookurier')
                ->order('entity_id ASC')
        );

        $tracksByShipment = [];
        foreach ($tracks as $track) {
            $sid = (int)$track['parent_id'];
            $code = trim((string)$track['track_number']);
            if ($sid <= 0 || $code === '') {
                continue;
            }
            if (!isset($tracksByShipment[$sid])) {
                $tracksByShipment[$sid] = [];
            }
            $tracksByShipment[$sid][] = $code;
        }

        $rows = [];
        foreach ($shipments as $shipment) {
            $shipmentId = (int)$shipment['shipment_id'];
            $increment = trim((string)$shipment['increment_id']);
            $rows[] = [
                'shipment_id' => $shipmentId,
                'shipment_label' => $increment !== '' ? $increment : ('#' . $shipmentId),
                'awb_codes' => $tracksByShipment[$shipmentId] ?? [],
            ];
        }

        return $rows;
    }
}
