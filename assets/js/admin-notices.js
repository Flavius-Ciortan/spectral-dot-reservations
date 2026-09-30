(function ($, config) {
	'use strict';
	$(document).on('click', '.sdpr-dependency-notice .notice-dismiss', function () {
		var $notice = $(this).closest('.sdpr-dependency-notice');
		$.post(config.ajaxUrl, {
			action: 'sdpr_dismiss_notice',
			nonce: $notice.attr('data-sdpr-nonce'),
			notice_id: $notice.attr('data-sdpr-notice')
		});
	});
})(jQuery, window.sdprNotices || {});
