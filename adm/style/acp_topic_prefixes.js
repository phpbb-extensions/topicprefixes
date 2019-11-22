(function($) {

	"use strict";

	$("#select_forum").on("change", function() {
		$(this).closest("form").trigger('submit');
	});

})(jQuery);
