(function($) { // Avoid conflicts with other libraries

	"use strict";

	$(function() {

		var $prefixMenu = $("select[name=topic_prefix]"),
			$topicTitle = $("input[name=subject]");

		$prefixMenu.on("change", function() {

			var title = $topicTitle.val().trim(),
				prefix = $(this).val();
				//prefix = $(this).val() ? $(this).find(":selected").text() : "";
			title = title.replace(/^(\[.*?\]\s+)/, "");
			$topicTitle.val(prefix + " " + title).trim();

		});

	});

})(jQuery); // Avoid conflicts with other libraries
