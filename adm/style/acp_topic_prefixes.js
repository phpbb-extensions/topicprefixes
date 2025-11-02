(function() {
	'use strict';

	document.getElementById('select_forum').addEventListener('change', function() {
		this.closest('form').submit();
	});

	phpbb.addAjaxCallback('tp_toggle', function(res) {
		if (typeof res.success === 'undefined' || !res.success) {
			return;
		}

		const icon = this.querySelector('i');
		icon.classList.toggle('tp-toggle-on');
		icon.classList.toggle('tp-toggle-off');
	});
})();
