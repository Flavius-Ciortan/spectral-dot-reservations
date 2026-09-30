(function ($, config) {
	'use strict';

	function showNotice(type, message) {
		$('.sdpr-inline-notice').remove();
		var $notice = $('<div>', { class: 'notice notice-' + type + ' sdpr-inline-notice is-dismissible', role: 'status', 'aria-live': 'polite' })
			.append($('<p>').text(message))
			.insertAfter('.sdpr-reservations-admin h1');
		$('<button>', { type: 'button', class: 'notice-dismiss' })
			.append($('<span>', { class: 'screen-reader-text', text: config.strings.dismiss }))
			.on('click', function () { $notice.remove(); })
			.appendTo($notice);
	}

	function responseMessage(response, fallback) {
		return response && typeof response.data === 'string' && response.data ? response.data : fallback;
	}

	function actionButton(classes, label, data) {
		return $('<button>', { type: 'button', class: 'button button-small ' + classes, text: label }).attr({
			'data-reservation-id': data.id,
			'data-customer': data.customer,
			'data-product': data.product
		});
	}

	function rowData($button) {
		return {
			id: $button.data('reservation-id'),
			customer: $button.data('customer'),
			product: $button.data('product') || config.strings.thisProduct
		};
	}

	function postAction($button, action, nonce, pendingLabel, failureLabel, data, onSuccess) {
		$button.prop('disabled', true).text(pendingLabel);
		$.post(config.ajaxUrl, $.extend({
			action: action,
			reservation_id: data.id,
			nonce: nonce
		}, data.extra || {})).done(function (response) {
			if (response.success) {
				onSuccess($button, data);
				return;
			}
			showNotice('error', responseMessage(response, failureLabel));
			$button.prop('disabled', false).text(data.originalLabel);
		}).fail(function (xhr) {
			showNotice('error', responseMessage(xhr.responseJSON, config.strings.requestFailed));
			$button.prop('disabled', false).text(data.originalLabel);
		});
	}

	$(function () {
		$('#filter-reservations').on('click', function () {
			var url = new URL(window.location.href);
			var search = $('#reservation-search').val();
			url.searchParams.set('status', $('#status-filter').val());
			url.searchParams.set('search_type', $('#search-type').val());
			if (search) {
				url.searchParams.set('search', search);
			} else {
				url.searchParams.delete('search');
			}
			window.location.href = url.toString();
		});

		$('#clear-filters').on('click', function () {
			var url = new URL(window.location.href);
			['status', 'search', 'search_type', 'paged'].forEach(function (key) {
				url.searchParams.delete(key);
			});
			window.location.href = url.toString();
		});

		$('#reservation-search').on('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				$('#filter-reservations').trigger('click');
			}
		});

		$(document).on('click', '.sdpr-delete-reservation', function () {
			var $button = $(this);
			var data = rowData($button);
			data.originalLabel = config.strings.delete;
			if (!window.confirm(config.strings.confirmDelete.replace('%1$s', data.customer).replace('%2$s', data.product))) {
				return;
			}
			postAction($button, 'sdpr_delete_admin_reservation', config.nonces.delete, config.strings.deleting, config.strings.deleteFailed, data, function ($current) {
				$current.closest('tr').fadeOut(function () { $(this).remove(); });
				showNotice('success', config.strings.deleted);
			});
		});

		$(document).on('click', '.sdpr-approve-reservation', function () {
			var $button = $(this);
			var data = rowData($button);
			data.originalLabel = config.strings.approve;
			if (!window.confirm(config.strings.confirmApprove.replace('%1$s', data.customer).replace('%2$s', data.product))) {
				return;
			}
			postAction($button, 'sdpr_approve_reservation', config.nonces.approve, config.strings.approving, config.strings.approveFailed, data, function ($current, currentData) {
				var $row = $current.closest('tr');
				$row.find('td:last-child').empty().append(actionButton('sdpr-cancel-reservation', config.strings.cancel, currentData));
				$row.find('td:nth-child(3) span').removeClass('status-pending-approval').addClass('status-active').text(config.strings.active);
				showNotice('success', config.strings.approved);
			});
		});

		$(document).on('click', '.sdpr-deny-reservation', function () {
			var $button = $(this);
			var data = rowData($button);
			var reason = window.prompt(config.strings.denyReason);
			if (reason === null) {
				return;
			}
			data.extra = { reason: reason };
			data.originalLabel = config.strings.deny;
			postAction($button, 'sdpr_deny_reservation', config.nonces.deny, config.strings.denying, config.strings.denyFailed, data, function ($current, currentData) {
				var $row = $current.closest('tr');
				$row.find('td:last-child').empty().append(actionButton('button-link-delete sdpr-delete-reservation', config.strings.delete, currentData));
				$row.find('td:nth-child(3) span').removeClass('status-pending-approval').addClass('status-denied').text(config.strings.deniedStatus);
				$row.find('td:nth-child(6)').text('\u2014').removeClass('time-left-critical time-left-warning');
				showNotice('success', config.strings.denied);
			});
		});

		$(document).on('click', '.sdpr-cancel-reservation', function () {
			var $button = $(this);
			var data = rowData($button);
			data.originalLabel = config.strings.cancel;
			if (!data.id) {
				showNotice('error', config.strings.missingId);
				return;
			}
			if (!window.confirm(config.strings.confirmCancel.replace('%1$s', data.customer).replace('%2$s', data.product))) {
				return;
			}
			postAction($button, 'sdpr_cancel_admin_reservation', config.nonces.cancel, config.strings.cancelling, config.strings.cancelFailed, data, function ($current, currentData) {
				var $row = $current.closest('tr');
				$row.find('td:nth-child(3) span').removeClass().addClass('status-cancelled').text(config.strings.cancelledStatus);
				$row.find('td:nth-child(6)').text('\u2014').removeClass('time-left-critical time-left-warning');
				$row.find('td:last-child').empty().append(actionButton('button-link-delete sdpr-delete-reservation', config.strings.delete, currentData));
				showNotice('success', config.strings.cancelled);
			});
		});
	});
})(jQuery, window.sdprReservationsAdmin || {});
