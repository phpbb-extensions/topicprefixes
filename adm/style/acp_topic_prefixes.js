(function($) {

	"use strict";

	$("#select_forum").on("change", function() {
		$(this).closest("form").submit();
	});

})(jQuery);
