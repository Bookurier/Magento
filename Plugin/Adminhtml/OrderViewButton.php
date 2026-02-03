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

        if ((int)$order->getShipmentsCollection()->getSize() === 0) {
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

        return $result;
    }
}
