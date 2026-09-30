(function ($) {
	'use strict';

	$(function () {
		var params = new URLSearchParams(window.location.search);
		var activeTab = params.get('active_tab') || window.localStorage.getItem('sdpr_active_tab') || 'general';

		function activateTab(target) {
			var $content = $('#sdpr-' + target);
			var $tabs = $('.sdpr-nav-tab');
			if (!$content.length) {
				target = 'general';
				$content = $('#sdpr-general');
			}
			$tabs
				.removeClass('sdpr-nav-tab-active')
				.attr({'aria-selected': 'false', 'tabindex': '-1'});
			$tabs
				.filter('[data-target="' + target + '"]')
				.addClass('sdpr-nav-tab-active')
				.attr({'aria-selected': 'true', 'tabindex': '0'});
			$('.sdpr-tab-content').removeClass('sdpr-tab-active').hide().attr('hidden', true);
			$content.addClass('sdpr-tab-active').show().removeAttr('hidden');
			$('#sdpr-active-tab-field').val(target);
			window.localStorage.setItem('sdpr_active_tab', target);
		}

		$('<input>', {
			type: 'hidden',
			name: 'active_tab',
			id: 'sdpr-active-tab-field'
		}).appendTo('.sdpr-settings-form');

		activateTab(activeTab);
		$('.sdpr-form-actions').addClass('sdpr-ready');

		$('.sdpr-nav-tab').on('click', function () {
			activateTab(String($(this).data('target')));
		});

		$('.sdpr-nav-tab').on('keydown', function (event) {
			var $tabs = $('.sdpr-nav-tab');
			var current = $tabs.index(this);
			var next = current;

			if (event.key === 'ArrowRight') {
				next = (current + 1) % $tabs.length;
			} else if (event.key === 'ArrowLeft') {
				next = (current - 1 + $tabs.length) % $tabs.length;
			} else if (event.key === 'Home') {
				next = 0;
			} else if (event.key === 'End') {
				next = $tabs.length - 1;
			} else {
				return;
			}

			event.preventDefault();
			activateTab(String($tabs.eq(next).data('target')));
			$tabs.eq(next).trigger('focus');
		});

		$('.sdpr-popup-tab').on('click', function () {
			var tab = String($(this).data('popup-tab'));
			$('.sdpr-popup-tab').removeClass('sdpr-popup-tab-active');
			$(this).addClass('sdpr-popup-tab-active');
			$('.sdpr-popup-tab-content').hide();
			$('.sdpr-popup-tab-content-' + tab).show();
		});

		var $toggle = $('input[name="sdpr_options[enable_popup_customization_logged_in]"]');
		var $fields = $('.sdpr-popup-customization-fields-logged-in');
		$toggle.on('change', function () {
			$fields.stop(true, true)[$(this).is(':checked') ? 'slideDown' : 'slideUp']();
		});
	});
})(jQuery);
