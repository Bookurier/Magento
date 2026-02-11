<?php
/**
 * Print AWB format source model.
 */
namespace Bookurier\Shipping\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class PrintAwbFormat implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'pdf', 'label' => __('PDF')],
            ['value' => 'html', 'label' => __('HTML')],
        ];
    }
}
