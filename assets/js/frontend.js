jQuery(document).ready(function($) {
	var modalOpener = null;
	var $modal = $('#sdpr-reservation-modal');
	var requestPending = false;
	var reservationComplete = false;
	var $title = $('#sdpr-reservation-dialog-title');
	var confirmationTitle = $title.text();
	var confirmationButtonText = $modal.find('button[type="submit"]').text();

	function responseMessage(response, fallback) {
		return response && typeof response.data === 'string' && response.data ? response.data : fallback;
	}

	function closeReservationModal() {
		if (requestPending || !$modal.is(':visible')) {
			return;
		}
		$modal.hide().attr('aria-hidden', 'true');
		if (modalOpener) {
			$(modalOpener).trigger('focus');
			modalOpener = null;
		}
		if (reservationComplete) {
			window.location.reload();
		}
	}
	$(document).on('click', '.sdpr-reservations-table .cancel-reservation[data-reservation-id]', function(e) {
		e.preventDefault();
		var $link = $(this);
		if (!window.confirm($link.data('confirm'))) {
			return;
		}
		$('<form>', { method: 'post', action: window.location.href })
			.append($('<input>', { type: 'hidden', name: 'sdpr_cancel_res', value: $link.data('reservation-id') }))
			.append($('<input>', { type: 'hidden', name: '_wpnonce', value: $link.data('cancel-nonce') }))
			.appendTo('body')
			.trigger('submit');
	});

    function renderNotice(message, type) {
        var $notice = $('#sdpr-reservation-form').find('.sdpr-reservation-notice');

        if (!$notice.length) {
            return;
        }

        $notice
            .removeClass('sdpr-reservation-notice--success sdpr-reservation-notice--error')
            .addClass('sdpr-reservation-notice--' + type)
			.attr('role', type === 'error' ? 'alert' : 'status')
            .text(message)
            .show();
    }

    function clearNotice() {
        $('#sdpr-reservation-form').find('.sdpr-reservation-notice').hide().text('').removeClass('sdpr-reservation-notice--success sdpr-reservation-notice--error');
    }

    $('#sdpr_reserve_product').on('click', function(e) {
        e.preventDefault();
        var productId = $(this).data('productid');
		if ($(this).data('product-type') === 'variable') {
			productId = parseInt($(this).closest('form.variations_form').find('input.variation_id').val(), 10) || 0;
			if (!productId) {
				window.alert(sdprFrontend.i18n.selectVariation);
				return;
			}
		}
        modalOpener = this;

		if (sdprFrontend.is_logged_in == 0 && sdprFrontend.allow_guest == 0) {
			alert(sdprFrontend.i18n.loginRequired);
            return;
        }

        clearNotice();
		reservationComplete = false;
		$title.text(confirmationTitle);
		$modal.find('button[type="submit"]').prop('disabled', false).text(confirmationButtonText);
		$modal.find('.sdpr-reservation-prompt').show();
		$modal.find('[role="dialog"]').attr('aria-describedby', 'sdpr-reservation-dialog-description');
        $('#sdpr-reservation-form').find('input[name="product_id"]').val(productId);
        
		$modal.show().attr('aria-hidden', 'false');
		$modal.find('.modal-close').trigger('focus');
    });

	$(document).on('click', '#sdpr-reservation-modal .modal-close', function() {
		closeReservationModal();
	});

    $(document).on('click', '.modal-overlay', function(e) {
        if (e.target === this) {
			closeReservationModal();
        }
    });

    $(document).on('keydown', function(e) {
		if (e.key === 'Escape' && $modal.is(':visible')) {
			e.preventDefault();
			closeReservationModal();
		} else if (e.key === 'Tab' && $modal.is(':visible')) {
			var $focusable = $modal.find('button:not([disabled]), [href], input:not([disabled]), [tabindex]:not([tabindex="-1"])').filter(':visible');
			if ($focusable.length) {
				var first = $focusable.get(0);
				var last = $focusable.get($focusable.length - 1);
				if (e.shiftKey && document.activeElement === first) {
					e.preventDefault();
					$(last).trigger('focus');
				} else if (!e.shiftKey && document.activeElement === last) {
					e.preventDefault();
					$(first).trigger('focus');
				}
			}
        }
    });

    $('#sdpr-reservation-form').on('submit', function(e) {
        e.preventDefault();
		if (reservationComplete) {
			closeReservationModal();
			return;
		}
		if (requestPending) {
			return;
		}

        var $form = $(this);
        var formData = new FormData(this);
        clearNotice();
        
        var ajaxData = {};
		formData.forEach(function(value, key) {
			ajaxData[key] = value;
		});
		ajaxData.action = ajaxData.action || 'sdpr_reserve';
		ajaxData.product_id = formData.get('product_id');
		ajaxData.quantity = formData.get('quantity') || 1;
		ajaxData.security = sdprFrontend.nonce;

		var $submitBtn = $form.find('button[type="submit"]');
        var originalText = $submitBtn.text();
		requestPending = true;
		$modal.attr('aria-busy', 'true').find('.modal-close').prop('disabled', true);
		$submitBtn.prop('disabled', true).text(sdprFrontend.i18n.processing);

        $.post(sdprFrontend.ajax_url, ajaxData)
        .done(function(response) {
            if (response.success) {
				reservationComplete = true;
				$modal.find('.sdpr-reservation-prompt').hide();
				$title.text($title.attr('data-result-title'));
				$modal.find('[role="dialog"]').attr('aria-describedby', 'sdpr-reservation-result');
                var successMessage = responseMessage(response, sdprFrontend.i18n.success);
                renderNotice(successMessage, 'success');
				$submitBtn.prop('disabled', false).text(sdprFrontend.i18n.done).trigger('focus');
            } else {
                renderNotice(responseMessage(response, sdprFrontend.i18n.reservationFailed), 'error');
            }
        })
        .fail(function(xhr) {
            renderNotice(responseMessage(xhr.responseJSON, sdprFrontend.i18n.failed), 'error');
        })
        .always(function() {
			requestPending = false;
			$modal.attr('aria-busy', 'false').find('.modal-close').prop('disabled', false);
			if (!reservationComplete) {
				$submitBtn.prop('disabled', false).text(originalText);
			}
        });
    });
});
