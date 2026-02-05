<?php
/**
 * Add Print Bookurier AWB action to order grid rows.
 */
namespace Bookurier\Shipping\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\UrlInterface;

class PrintAwbAction extends Column
{
    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    public function __construct(
        UrlInterface $urlBuilder,
        \Magento\Framework\View\Element\UiComponent\ContextInterface $context,
        \Magento\Framework\View\Element\UiComponentFactory $uiComponentFactory,
        array $components = [],
        array $data = []
    ) {
        $this->urlBuilder = $urlBuilder;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as & $item) {
            if (!isset($item['entity_id'])) {
                continue;
            }
            $item[$this->getData('name')]['print'] = [
                'href' => $this->urlBuilder->getUrl('bookurier/order/printAwb', ['order_id' => $item['entity_id']]),
                'label' => __('Print Bookurier AWB'),
                'hidden' => false,
                'target' => '_blank',
            ];
        }

        return $dataSource;
    }
}
