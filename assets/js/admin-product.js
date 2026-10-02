(function ($, config) {
	'use strict';

	function responseMessage(response, fallback) {
		return response && typeof response.data === 'string' && response.data ? response.data : fallback;
	}

	function showNotice($panel, type, message) {
		var $feedback = $panel.find('.sdpr-product-reservation-feedback');
		$feedback.empty();
		var $notice = $('<div>', { class: 'notice notice-' + type + ' is-dismissible inline', role: type === 'error' ? 'alert' : 'status' })
			.append($('<p>').text(message))
			.appendTo($feedback);
		$('<button>', { type: 'button', class: 'notice-dismiss' })
			.append($('<span>', { class: 'screen-reader-text', text: config.strings.dismiss }))
			.on('click', function () { $notice.remove(); })
			.appendTo($notice);
	}

	$(function () {
		$(document).on('click', '.sdpr-cancel-reservation', function () {
			var $button = $(this);
			var $panel = $button.closest('.sdpr-product-reservations');
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
						var $table = $panel.find('table');
						if (!$table.find('tbody tr').length) {
							$table.remove();
							$('<p>').text(config.strings.noActive).appendTo($panel);
						}
					});
					showNotice($panel, 'success', config.strings.cancelled);
					return;
				}
				showNotice($panel, 'error', responseMessage(response, config.strings.failed));
				$button.prop('disabled', false).text(config.strings.cancel);
			}).fail(function (xhr) {
				showNotice($panel, 'error', responseMessage(xhr.responseJSON, config.strings.requestFailed));
				$button.prop('disabled', false).text(config.strings.cancel);
			});
		});
	});
})(jQuery, window.sdprProductReservations || {});
