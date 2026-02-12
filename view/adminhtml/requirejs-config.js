var config = {
    map: {
        '*': {
            'js/theme': 'Bookurier_Shipping/js/theme-patch'
        }
    },
    config: {
        mixins: {
            'Magento_Ui/js/grid/massactions': {
                'Bookurier_Shipping/js/massaction-print-awb-mixin': true
            }
        }
    }
};
