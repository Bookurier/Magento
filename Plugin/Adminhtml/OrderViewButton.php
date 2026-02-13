<?php
/**
 * Add Bookurier AWB button to order view.
 */
namespace Bookurier\Shipping\Plugin\Adminhtml;

use Magento\Backend\Block\Widget\Button\SplitButton;
use Magento\Sales\Block\Adminhtml\Order\View;
use Magento\Framework\AuthorizationInterface;

class OrderViewButton
{
    /**
     * @var AuthorizationInterface
     */
    private $authorization;

    public function __construct(AuthorizationInterface $authorization)
    {
        $this->authorization = $authorization;
    }

    /**
     * Add button after layout is set.
     *
     * @param View $subject
     * @param View $result
     * @return View
     */
    public function afterSetLayout(View $subject, View $result): View
    {
        $order = $subject->getOrder();
        if (!$order || !$order->getId()) {
            return $result;
        }

        $shipments = $order->getShipmentsCollection();
        $totalShipments = (int)$shipments->getSize();
        if ($totalShipments === 0) {
            return $result;
        }

        $canCreate = $this->authorization->isAllowed('Bookurier_Shipping::awb_create');
        $canPrint = $this->authorization->isAllowed('Bookurier_Shipping::awb_print');
        if (!$canCreate && !$canPrint) {
            return $result;
        }

        $stats = $this->getShipmentAwbStats($order);
        $bookurierAwbShipments = $stats['bookurier'];
        $shipmentsWithoutAwb = $stats['without_awb'];

        // Hide Bookurier actions when all shipments already have non-Bookurier AWBs.
        if ($bookurierAwbShipments === 0 && $shipmentsWithoutAwb === 0) {
            return $result;
        }

        $options = [];

        if ($canCreate && $shipmentsWithoutAwb > 0) {
            $url = $subject->getUrl('bookurier/order/awbform', ['order_id' => $order->getId()]);
            $options[] = [
                'id' => 'create',
                'label' => (string)__("Create AWB\n(%1 remaining)", $shipmentsWithoutAwb),
                'onclick' => "setLocation('{$url}')",
            ];
        }

        if ($canPrint && $bookurierAwbShipments > 0) {
            $printUrl = $subject->getUrl('bookurier/order/printAwb', ['order_id' => $order->getId()]);
            $options[] = [
                'id' => 'print',
                'label' => (string)__('Print AWB'),
                'onclick' => "window.open('{$printUrl}', '_blank')",
            ];
        }

        if (!empty($options)) {
            $subject->addButton(
                'bookurier_awb_actions',
                [
                    'label' => __('Bookurier AWB'),
                    'class_name' => SplitButton::class,
                    'class' => 'bookurier-awb-main',
                    'button_class' => 'bookurier-awb-main',
                    'options' => $options,
                ]
            );
        } elseif ($canCreate && $order->getStatus() === 'bookurier_pending_awb') {
            $subject->addButton(
                'bookurier_awb_actions',
                [
                    'label' => __('Bookurier AWB'),
                    'class_name' => SplitButton::class,
                    'class' => 'bookurier-awb-main',
                    'button_class' => 'bookurier-awb-main',
                    'disabled' => true,
                    'options' => [[
                        'id' => 'queued',
                        'label' => (string)__('AWB queued'),
                        'disabled' => true,
                    ]],
                ]
            );
        }

        if ($bookurierAwbShipments > 0 && $this->authorization->isAllowed('Bookurier_Shipping::awb_delete')) {
            $url = $subject->getUrl('bookurier/order/deleteAwb', ['order_id' => $order->getId()]);
            $confirm = $subject->escapeJs(__('Are you sure you want to delete the Bookurier AWB?'));
            $subject->addButton(
                'bookurier_delete_awb',
                [
                    'label' => __('Delete Bookurier AWB'),
                    'class' => 'delete',
                    'onclick' => "confirmSetLocation('{$confirm}', '{$url}')",
                ]
            );
        }

        return $result;
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return array{bookurier:int,without_awb:int}
     */
    private function getShipmentAwbStats($order): array
    {
        $bookurier = 0;
        $withoutAwb = 0;
        foreach ($order->getShipmentsCollection() as $shipment) {
            $hasAnyAwb = false;
            $hasBookurierAwb = false;
            foreach ($shipment->getTracksCollection() as $track) {
                $hasAnyAwb = true;
                if ((string)$track->getCarrierCode() === 'bookurier') {
                    $hasBookurierAwb = true;
                }
            }
            if ($hasBookurierAwb) {
                $bookurier++;
            }
            if (!$hasAnyAwb) {
                $withoutAwb++;
            }
        }

        return [
            'bookurier' => $bookurier,
            'without_awb' => $withoutAwb,
        ];
    }
}
