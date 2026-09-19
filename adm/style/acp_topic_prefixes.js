(function() {
	'use strict';

	phpbb.addAjaxCallback('tp_toggle', function(res) {
		if (typeof res.success === 'undefined' || !res.success) {
			return;
		}

		const icon = this.querySelector('i');
		icon.classList.toggle('tp-toggle-on');
		icon.classList.toggle('tp-toggle-off');
	});
})();
