define([
    'jquery',
    'underscore',
    'mageUtils',
    'Magento_Ui/js/modal/alert',
    'mage/translate'
], function ($, _, utils, alert, $t) {
    'use strict';

    return function (Massactions) {
        return Massactions.extend({
            /**
             * Submit Bookurier print in a new tab, then refresh current grid tab.
             */
            defaultCallback: function (action, data) {
                if (action && action.type === 'bookurier_print_awb') {
                    var itemsType = data.excludeMode ? 'excluded' : 'selected',
                        selections = {},
                        self = this;

                    selections[itemsType] = data[itemsType];

                    if (!selections[itemsType].length) {
                        selections[itemsType] = false;
                    }

                    _.extend(selections, data.params || {});

                    $.ajax({
                        url: action.url,
                        type: 'POST',
                        dataType: 'json',
                        data: _.extend({}, selections, {
                            form_key: window.FORM_KEY,
                            check_only: 1
                        })
                    }).done(function (response) {
                        if (!response || response.ok !== true) {
                            alert({
                                modalClass: 'bookurier-warning',
                                content: (response && response.message) ?
                                    response.message :
                                    $t('Cannot print Bookurier AWB. Remove orders without Bookurier AWB and try again.')
                            });
                            return;
                        }

                        utils.submit({
                            url: action.url,
                            data: selections
                        }, {
                            target: '_blank'
                        });

                        setTimeout(function () {
                            window.location.reload();
                        }, 300);
                    }).fail(function () {
                        alert({
                            modalClass: 'bookurier-warning',
                            content: $t('Unable to validate Bookurier AWB selection. Please try again.')
                        });
                    });

                    return;
                }

                return this._super(action, data);
            }
        });
    };
});
