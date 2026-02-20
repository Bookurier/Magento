<?php
/**
 * Shipment tracking URL provider for emails.
 */
declare(strict_types=1);

namespace Bookurier\Shipping\Block\DataProviders\Email\Shipment;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Model\Order\Shipment\Track;
use Magento\Shipping\Helper\Data as ShippingHelper;

class TrackingUrl implements ArgumentInterface
{
    /**
     * @var ShippingHelper
     */
    private $shippingHelper;

    public function __construct(ShippingHelper $shippingHelper)
    {
        $this->shippingHelper = $shippingHelper;
    }

    /**
     * @param Track $track
     * @return string
     */
    public function getUrl(Track $track): string
    {
        if ((string)$track->getCarrierCode() === 'bookurier') {
            return 'https://t.bookurier.ro/?awb=' . rawurlencode((string)$track->getNumber());
        }

        return $this->shippingHelper->getTrackingPopupUrlBySalesModel($track);
    }
}
