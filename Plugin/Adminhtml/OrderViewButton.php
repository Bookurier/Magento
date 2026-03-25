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

        $canCreate = $this->authorization->isAllowed('Bookurier_Shipping::awb_create');
        $canPrint = $this->authorization->isAllowed('Bookurier_Shipping::awb_print');
        $canDelete = $this->authorization->isAllowed('Bookurier_Shipping::awb_delete');
        $canFulfillment = $this->authorization->isAllowed('Bookurier_Shipping::fulfillment');
        if (!$canCreate && !$canPrint && !$canDelete && !$canFulfillment) {
            return $result;
        }

        $stats = $this->getShipmentAwbStats($order);
        $bookurierAwbShipments = $stats['bookurier'];
        $shipmentsWithoutAwb = $stats['without_awb'];
        $hasAnyAwb = $stats['any_awb'] > 0;
        $hasEligibleFulfillment = $this->canSendFulfillment($order);

        $options = [];

        if ($canCreate && !$hasAnyAwb && $shipmentsWithoutAwb > 0) {
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

        if ($canFulfillment && $hasEligibleFulfillment) {
            $fulfillmentUrl = $subject->getUrl('bookurier/order/fulfillment', ['order_id' => $order->getId()]);
            $options[] = [
                'id' => 'fulfillment',
                'label' => (string)__('Fulfillment'),
                'onclick' => "setLocation('{$fulfillmentUrl}')",
            ];
        }

        if ($canDelete && $bookurierAwbShipments > 0) {
            $deleteUrl = $subject->getUrl('bookurier/order/deleteAwb', ['order_id' => $order->getId()]);
            $confirm = $subject->escapeJs(__('Are you sure you want to delete the Bookurier AWB?'));
            $options[] = [
                'id' => 'delete',
                'label' => (string)__('Delete Bookurier AWB'),
                'class' => 'delete',
                'onclick' => "confirmSetLocation('{$confirm}', '{$deleteUrl}')",
            ];
        }

        if (!empty($options)) {
            $subject->addButton(
                'bookurier_awb_actions',
                [
                    'label' => __('Bookurier'),
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
                    'label' => __('Bookurier'),
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

        return $result;
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return bool
     */
    private function canSendFulfillment($order): bool
    {
        $shippingAddress = $order->getShippingAddress();
        if (!$shippingAddress || !$shippingAddress->getId()) {
            return false;
        }

        foreach ($order->getAllVisibleItems() as $item) {
            if (!$item->isDummy() && (float)$item->getQtyOrdered() > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return array{bookurier:int,without_awb:int,any_awb:int,fulfillment_ready:int}
     */
    private function getShipmentAwbStats($order): array
    {
        $bookurier = 0;
        $withoutAwb = 0;
        $anyAwb = 0;
        $fulfillmentReady = 0;
        foreach ($order->getShipmentsCollection() as $shipment) {
            $hasAnyAwb = false;
            $hasBookurierAwb = false;
            foreach ($shipment->getTracksCollection() as $track) {
                if (!(string)$track->getTrackNumber()) {
                    continue;
                }
                $hasAnyAwb = true;
                if ((string)$track->getCarrierCode() === 'bookurier') {
                    $hasBookurierAwb = true;
                }
            }
            if ($hasBookurierAwb) {
                $bookurier++;
            }
            if ($hasAnyAwb) {
                $anyAwb++;
                $fulfillmentReady++;
            }
            if (!$hasAnyAwb) {
                $withoutAwb++;
            }
        }

        return [
            'bookurier' => $bookurier,
            'without_awb' => $withoutAwb,
            'any_awb' => $anyAwb,
            'fulfillment_ready' => $fulfillmentReady,
        ];
    }
}
