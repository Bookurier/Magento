<?php
/**
 * Bookurier delivery service options.
 */
namespace Bookurier\Shipping\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Service implements OptionSourceInterface
{
    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => '1', 'label' => __('Bucuresti 24h')],
            ['value' => '3', 'label' => __('Metropolitan')],
            ['value' => '5', 'label' => __('Ilfov Extins')],
            ['value' => '7', 'label' => __('Bucuresti Today')],
            ['value' => '8', 'label' => __('National Economic')],
            ['value' => '9', 'label' => __('National 24')],
            ['value' => '11', 'label' => __('National Premium')],
        ];
    }
}
