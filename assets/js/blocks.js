/**
 * NexPay — WooCommerce Blocks (Cart/Checkout) payment methods.
 *
 * Plain JS (no build step). For each NexPay gateway it registers a Blocks
 * payment method whose data (title, description, settlement currency and
 * checkout fields) is provided by the PHP integration in
 * includes/class-nexpay-blocks.php. The field values typed by the
 * customer are returned as paymentMethodData on payment processing;
 * nexpay.php copies them onto the order via the Store API hook, so the
 * gateway process_payment() works exactly as on classic checkout.
 */
(function () {
	'use strict';

	if (!window.wc || !window.wc.wcBlocksRegistry || !window.wp || !window.wp.element) {
		return;
	}

	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = window.wc.wcSettings.getSetting;
	var createElement = window.wp.element.createElement;
	var useState = window.wp.element.useState;
	var useEffect = window.wp.element.useEffect;

	var GATEWAY_IDS = [
		'nexpay_jazzcash',
		'nexpay_easypaisa',
		'nexpay_bkash',
		'nexpay_nagad',
		'nexpay_upi',
		'nexpay_mpesa'
	];

	function makeContentComponent(data) {
		return function NexPayBlocksContent(props) {
			var fields = data.fields || [];
			var state = useState({});
			var values = state[0];
			var setValues = state[1];

			var onPaymentProcessing = props.eventRegistration.onPaymentProcessing;
			var responseTypes = props.emitResponse.responseTypes;

			useEffect(function () {
				var unsubscribe = onPaymentProcessing(function () {
					var missing = fields.filter(function (field) {
						return !values[field.name] || '' === String(values[field.name]).trim();
					});
					if (missing.length) {
						return {
							type: responseTypes.ERROR,
							message: 'Please fill in the required payment fields for ' + data.title + '.'
						};
					}
					return {
						type: responseTypes.SUCCESS,
						meta: {
							paymentMethodData: values
						}
					};
				});
				return function () {
					unsubscribe();
				};
			}, [values, onPaymentProcessing, responseTypes]);

			var children = [];
			if (data.description) {
				children.push(
					createElement('p', { key: 'desc', className: 'nexpay-blocks-description' }, data.description)
				);
			}
			fields.forEach(function (field) {
				children.push(
					createElement(
						'p',
						{ key: field.name, className: 'nexpay-blocks-field' },
						createElement('label', { htmlFor: 'nexpay-blocks-' + field.name }, field.label + ' *'),
						createElement('input', {
							id: 'nexpay-blocks-' + field.name,
							type: field.type || 'text',
							placeholder: field.placeholder || '',
							autoComplete: 'off',
							value: values[field.name] || '',
							onChange: function (event) {
								var next = {};
								Object.keys(values).forEach(function (k) { next[k] = values[k]; });
								next[field.name] = event.target.value;
								setValues(next);
							}
						})
					)
				);
			});

			return createElement('div', { className: 'nexpay-blocks-fields' }, children);
		};
	}

	GATEWAY_IDS.forEach(function (gatewayId) {
		var data = getSetting(gatewayId + '_data', null);
		if (!data) {
			return;
		}

		registerPaymentMethod({
			name: gatewayId,
			label: createElement('span', { className: 'nexpay-blocks-label' }, data.title),
			ariaLabel: data.title,
			content: createElement(makeContentComponent(data)),
			edit: createElement(makeContentComponent(data)),
			canMakePayment: function (args) {
				if (!data.currency) {
					return true;
				}
				var cartCurrency = '';
				if (args && args.cart && args.cart.totals && args.cart.totals.currency_code) {
					cartCurrency = args.cart.totals.currency_code;
				}
				if (!cartCurrency && data.storeCurrency) {
					cartCurrency = data.storeCurrency;
				}
				return '' === cartCurrency || cartCurrency === data.currency;
			},
			supports: {
				features: (data.supports && data.supports.features) || ['products']
			}
		});
	});
})();
