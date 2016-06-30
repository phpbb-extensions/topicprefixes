(function($) { // Avoid conflicts with other libraries

	"use strict";

	$(function() {

		var $prefixMenu = $("select[name=topic_prefix]"),
			$topicTitle = $("input[name=subject]");

		$prefixMenu.on("change", function() {

			var title = $topicTitle.val(),
				prefix = $(this).val() ? $(this).find(":selected").text() : "",
				current = $(this).attr('data-prefix');
			title = title.replace(current, "").trim();
			$prefixMenu.attr('data-prefix', prefix);
			$topicTitle.val(prefix + " " + title).focus();

		});

	});

})(jQuery); // Avoid conflicts with other libraries
