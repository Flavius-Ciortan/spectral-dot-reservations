(function ($, config) {
	'use strict';
	var $denialForm = null;
	var $denialOpener = null;

	function closeDenialForm(restoreFocus) {
		if (!$denialForm || $denialForm.data('pending')) {
			return;
		}
		$denialForm.remove();
		$denialForm = null;
		if (restoreFocus && $denialOpener && $.contains(document, $denialOpener[0])) {
			$denialOpener.trigger('focus');
		}
		$denialOpener = null;
	}

	function showDenialForm($button, data) {
		if ($denialForm && $denialForm.data('pending')) {
			return;
		}
		closeDenialForm(false);
		$denialOpener = $button;
		$denialForm = $('<form>', { class: 'sdpr-denial-form', 'aria-labelledby': 'sdpr-denial-title' });
		$('<h2>', { id: 'sdpr-denial-title', text: config.strings.denyTitle }).appendTo($denialForm);
		$('<p>').text(config.strings.confirmDeny.replace('%1$s', data.customer).replace('%2$s', data.product)).appendTo($denialForm);
		$('<label>', { for: 'sdpr-denial-reason', text: config.strings.denyReason }).appendTo($denialForm);
		var $reason = $('<input>', { type: 'text', id: 'sdpr-denial-reason', name: 'reason', autocomplete: 'off' }).appendTo($denialForm);
		var $controls = $('<div>', { class: 'sdpr-denial-actions' }).appendTo($denialForm);
		$('<button>', { type: 'submit', class: 'button button-primary', text: config.strings.confirmDenial }).appendTo($controls);
		$('<button>', { type: 'button', class: 'button', text: config.strings.cancelDenial }).on('click', function () {
			closeDenialForm(true);
		}).appendTo($controls);
		$denialForm.on('keydown', function (event) {
			if (event.key === 'Escape') {
				event.preventDefault();
				closeDenialForm(true);
			}
		}).on('submit', function (event) {
			event.preventDefault();
			if ($denialForm.data('pending')) {
				return;
			}
			data.extra = { reason: $reason.val() };
			postAction($button, 'sdpr_deny_reservation', config.nonces.deny, config.strings.denying, config.strings.denyFailed, data);
		}).prependTo('.sdpr-reservations-admin .sdpr-admin-content');
		$reason.trigger('focus');
	}

	function makeDismissible($notice) {
		if ($notice.find('.notice-dismiss').length) {
			return;
		}
		$('<button>', { type: 'button', class: 'notice-dismiss' })
			.append($('<span>', { class: 'screen-reader-text', text: config.strings.dismiss }))
			.on('click', function () {
				$notice.remove();
				$('#filter-reservations').trigger('focus');
			})
			.appendTo($notice);
	}

	function showNotice(type, message) {
		$('.sdpr-inline-notice').remove();
		var $notice = $('<div>', { class: 'notice notice-' + type + ' sdpr-inline-notice is-dismissible', role: 'status', 'aria-live': 'polite', tabindex: '-1' })
			.append($('<p>').text(message))
			.prependTo('.sdpr-reservations-admin .sdpr-admin-content');
		makeDismissible($notice);
		$notice.trigger('focus');
	}

	function responseMessage(response, fallback) {
		if (response && response.data && typeof response.data.message === 'string') {
			return response.data.message || fallback;
		}
		return response && typeof response.data === 'string' && response.data ? response.data : fallback;
	}

	function rowData($button) {
		return {
			id: $button.data('reservation-id'),
			customer: $button.data('customer'),
			product: $button.data('product') || config.strings.thisProduct
		};
	}

	function postAction($button, action, nonce, pendingLabel, failureLabel, data) {
		var params = new URL(window.location.href).searchParams;
		var $actions = $('.sdpr-reservations-admin tbody button');
		var $denialControls = $('.sdpr-denial-form :input').prop('disabled', true);
		if ($denialForm) {
			$denialForm.data('pending', true).attr('aria-busy', 'true');
		}
		$actions.prop('disabled', true);
		$button.prop('disabled', true).text(pendingLabel);
		$.post(config.ajaxUrl, $.extend({
			action: action,
			reservation_id: data.id,
			nonce: nonce,
			list_status: params.get('status') || 'all',
			list_search: params.get('search') || '',
			list_search_type: params.get('search_type') || 'email',
			list_paged: $('#sdpr-list-page').val() || 1
		}, data.extra || {})).done(function (response) {
			if (response.success && response.data && typeof response.data.content === 'string') {
				$('.sdpr-reservations-admin .sdpr-admin-content').html(response.data.content);
				$denialForm = null;
				$denialOpener = null;
				$('.sdpr-reservations-admin .notice.is-dismissible').each(function () { makeDismissible($(this)); });
				showNotice('success', responseMessage(response, failureLabel));
				return;
			}
			showNotice('error', responseMessage(response, failureLabel));
			$button.prop('disabled', false).text(data.originalLabel);
		}).fail(function (xhr) {
			showNotice('error', responseMessage(xhr.responseJSON, config.strings.requestFailed));
			$button.prop('disabled', false).text(data.originalLabel);
		}).always(function () {
			$actions.prop('disabled', false);
			$denialControls.prop('disabled', false);
			if ($denialForm) {
				$denialForm.data('pending', false).attr('aria-busy', 'false');
			}
		});
	}

	$(function () {
		$(document).on('click', '#filter-reservations', function () {
			var url = new URL(window.location.href);
			url.searchParams.delete('paged');
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

		$(document).on('click', '#clear-filters', function () {
			var url = new URL(window.location.href);
			['status', 'search', 'search_type', 'paged'].forEach(function (key) {
				url.searchParams.delete(key);
			});
			window.location.href = url.toString();
		});

		$(document).on('keydown', '#reservation-search', function (event) {
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
			postAction($button, 'sdpr_delete_admin_reservation', config.nonces.delete, config.strings.deleting, config.strings.deleteFailed, data);
		});

		$(document).on('click', '.sdpr-approve-reservation', function () {
			var $button = $(this);
			var data = rowData($button);
			data.originalLabel = config.strings.approve;
			if (!window.confirm(config.strings.confirmApprove.replace('%1$s', data.customer).replace('%2$s', data.product))) {
				return;
			}
			postAction($button, 'sdpr_approve_reservation', config.nonces.approve, config.strings.approving, config.strings.approveFailed, data);
		});

		$(document).on('click', '.sdpr-deny-reservation', function () {
			var $button = $(this);
			var data = rowData($button);
			data.originalLabel = config.strings.deny;
			showDenialForm($button, data);
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
			postAction($button, 'sdpr_cancel_admin_reservation', config.nonces.cancel, config.strings.cancelling, config.strings.cancelFailed, data);
		});
	});
})(jQuery, window.sdprReservationsAdmin || {});
