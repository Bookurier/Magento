<?php
/**
 * Add Bookurier AWB button to order view.
 */
namespace Bookurier\Shipping\Plugin\Adminhtml;

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
        if (!$this->authorization->isAllowed('Bookurier_Shipping::awb_create')) {
            return $result;
        }

        $order = $subject->getOrder();
        if (!$order || !$order->getId()) {
            return $result;
        }

        $shippingMethod = (string)$order->getShippingMethod();
        if (strpos($shippingMethod, 'bookurier_') !== 0) {
            return $result;
        }

        $shipments = $order->getShipmentsCollection();
        if ((int)$shipments->getSize() === 0) {
            return $result;
        }

        $hasBookurierAwb = $this->orderHasBookurierAwb($order);
        if ($hasBookurierAwb) {
            $printUrl = $subject->getUrl('bookurier/order/printAwb', ['order_id' => $order->getId()]);
            $subject->addButton(
                'bookurier_print_awb',
                [
                    'label' => __('Print Bookurier AWB'),
                    'class' => 'primary',
                    'onclick' => "window.open('{$printUrl}', '_blank')",
                ]
            );

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
        } else {
            if ($order->getStatus() === 'bookurier_pending_awb') {
                $subject->addButton(
                    'bookurier_create_awb',
                    [
                        'label' => __('AWB queued'),
                        'class' => 'disabled',
                        'disabled' => true,
                    ]
                );
                return $result;
            }

            $url = $subject->getUrl('bookurier/order/awbform', ['order_id' => $order->getId()]);
            $subject->addButton(
                'bookurier_create_awb',
                [
                    'label' => __('Create Bookurier AWB'),
                    'class' => 'primary',
                    'onclick' => "setLocation('{$url}')",
                ]
            );
        }

        return $result;
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return bool
     */
    private function orderHasBookurierAwb($order): bool
    {
        foreach ($order->getShipmentsCollection() as $shipment) {
            foreach ($shipment->getTracksCollection() as $track) {
                if ($track->getCarrierCode() === 'bookurier') {
                    return true;
                }
            }
        }
        return false;
    }
}
