define([
    'jquery',
    'jquery/bootstrap/collapse',
    'mage/smart-keyboard-handler',
    'collapsable',
    'domReady!'
], function ($, _collapse, keyboardHandler) {
    'use strict';

    /* Keep Magento admin fieldset collapsible behavior. */
    $('.collapse').collapsable();

    $.each($('.entry-edit'), function (i, entry) {
        var collapsible = $('.collapse:first', entry).filter(function () {
            return $(this).data('collapsed') !== true;
        });

        if ($.isFunction(collapsible.collapse)) {
            collapsible.collapse('show');
        }
    });

    keyboardHandler.apply();
});
