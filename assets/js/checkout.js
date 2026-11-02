/**
 * NexPay — checkout helpers (classic WooCommerce checkout).
 *
 * 1. Tags every NexPay gateway row with .nexpay-method and keeps a
 *    .nexpay-selected class on the currently chosen row, so the violet
 *    "selected" styling in checkout.css also works in browsers without
 *    :has() support.
 * 2. Nothing else — field validation happens server-side in each gateway.
 */
(function ($) {
	'use strict';

	var GATEWAY_IDS = [
		'nexpay_jazzcash',
		'nexpay_easypaisa',
		'nexpay_bkash',
		'nexpay_nagad',
		'nexpay_upi',
		'nexpay_mpesa'
	];

	function refresh() {
		$.each(GATEWAY_IDS, function (_, id) {
			var $row = $('.payment_method_' + id);
			if (!$row.length) {
				return;
			}
			$row.addClass('nexpay-method');
			$row.toggleClass('nexpay-selected', $row.find('input[name="payment_method"]').is(':checked'));
		});
	}

	$(function () {
		refresh();
		// WooCommerce re-renders the payment section via AJAX on checkout updates.
		$(document.body).on('updated_checkout payment_method_selected', refresh);
		$(document.body).on('change', 'input[name="payment_method"]', refresh);
	});
})(jQuery);
