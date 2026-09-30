(function ($, config) {
	'use strict';

	function responseMessage(response, fallback) {
		return response && typeof response.data === 'string' && response.data ? response.data : fallback;
	}

	$(function () {
		$(document).on('click', '.sdpr-cancel-reservation', function () {
			var $button = $(this);
			var customer = $button.data('customer');
			if (!window.confirm(config.strings.confirmCancel.replace('%s', customer))) {
				return;
			}

			$button.prop('disabled', true).text(config.strings.cancelling);
			$.post(config.ajaxUrl, {
				action: 'sdpr_cancel_admin_reservation',
				reservation_id: $button.data('reservation-id'),
				nonce: config.nonce
			}).done(function (response) {
				if (response.success) {
					$button.closest('tr').fadeOut(function () {
						$(this).remove();
					});
					window.alert(config.strings.cancelled);
					return;
				}
				window.alert(responseMessage(response, config.strings.failed));
				$button.prop('disabled', false).text(config.strings.cancel);
			}).fail(function (xhr) {
				window.alert(responseMessage(xhr.responseJSON, config.strings.requestFailed));
				$button.prop('disabled', false).text(config.strings.cancel);
			});
		});
	});
})(jQuery, window.sdprProductReservations || {});
