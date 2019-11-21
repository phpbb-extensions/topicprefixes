(function($) { // Avoid conflicts with other libraries

	"use strict";

	$(function() {

		var getPrefix = function(el) {
			return el.val() ? el.find(":selected").text() : "";
		};

		var $prefixMenu = $("select[name=topic_prefix]"),
			$topicTitle = $("input[name=subject]"),
			prefix = getPrefix($prefixMenu);

		$prefixMenu.on("change", function() {
			var title = $topicTitle.val();
			title = title.replace(prefix, "").trim();
			prefix = getPrefix($(this));
			title = title.replace(prefix, "").trim();
			$topicTitle.val(prefix ? prefix + " " + title : title).trigger('focus');
		});

	});

})(jQuery); // Avoid conflicts with other libraries
