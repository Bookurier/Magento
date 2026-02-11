<?php
/**
 * Print AWB mode source model.
 */
namespace Bookurier\Shipping\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class PrintAwbMode implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 's', 'label' => __('single')],
            ['value' => 'm', 'label' => __('multiple')],
        ];
    }
}
