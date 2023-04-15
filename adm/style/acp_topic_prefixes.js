(function($) {

	'use strict';

	$('#select_forum').on('change', function() {
		$(this).closest('form').trigger('submit');
	});

	phpbb.addAjaxCallback('tp_toggle', function(res) {
		if (typeof res.success === 'undefined' || !res.success) {
			return;
		}

		const icon = this.querySelector('i');
		icon.classList.toggle('fa-toggle-on');
		icon.classList.toggle('fa-toggle-off');
	});

})(jQuery);
