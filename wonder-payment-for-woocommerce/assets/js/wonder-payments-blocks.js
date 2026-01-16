(function(wp, wc) {
    if (!wp || !wc || !wc.wcBlocksRegistry || !wc.wcSettings) {
        return;
    }

    var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;
    var settings = wc.wcSettings.getPaymentMethodData
        ? wc.wcSettings.getPaymentMethodData('wonder_payments', {})
        : wc.wcSettings.getSetting('wonder_payments_data', {});
    var decodeEntities = wp.htmlEntities.decodeEntities;
    var createElement = wp.element.createElement;

    var title = decodeEntities(settings.title || 'Wonder Payments');
    var description = decodeEntities(settings.description || '');

    var Content = function() {
        return createElement('div', null, description);
    };

    registerPaymentMethod({
        name: 'wonder_payments',
        label: title,
        content: createElement(Content, null),
        edit: createElement(Content, null),
        canMakePayment: function() {
            return !!settings.is_active;
        },
        ariaLabel: title,
        supports: {
            features: settings.supports || []
        }
    });
})(window.wp, window.wc);
