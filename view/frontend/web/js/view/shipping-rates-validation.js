define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/shipping-rates-validator',
        'Magento_Checkout/js/model/shipping-rates-validation-rules',
        'DCOnline_Fastway/js/model/shipping-rates-validator',
        'DCOnline_Fastway/js/model/shipping-rates-validation-rules',
        'rjsResolver'
    ],
    function (
        Component,
        defaultShippingRatesValidator,
        defaultShippingRatesValidationRules,
        shippingRatesValidator,
        shippingRatesValidationRules,
        resolver
    ) {
        'use strict'
        defaultShippingRatesValidator.registerValidator('fastway', shippingRatesValidator)
        defaultShippingRatesValidationRules.registerRules('fastway', shippingRatesValidationRules)
        return Component.extend({})
    }
)
  