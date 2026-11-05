/* global phpbb */
(($) => {
	'use strict';

	const $app = $('#topic-tag-app');
	if (!$app.length) {
		return;
	}

	$app.addClass('is-js');

	const $list = $('#topic-tag-list'),
		$form = $('#acp_topic_tag'),
		$name = $('#tag_name'),
		$color = $('#tag_color'),
		$colorText = $('#tag_color_text'),
		$enabled = $('#tag_enabled'),
		$preview = $('#topic-tag-preview'),
		$formError = $('#topic-tag-form-error'),
		$notices = $('#topic-tag-notices'),
		$noticeTemplate = $('#topic-tag-notice-template'),
		$colorError = $('#tag_color_error'),
		$dirtyLabel = $('#topic-tag-dirty'),
		$tagSearch = $('#topic-tag-search'),
		$statusFilter = $('#topic-tag-status-filter'),
		$forumSearch = $('#topic-tag-forum-search'),
		$forumPicker = $('#topic-tag-forums'),
		$selectedCount = $('#topic-tag-forum-selected'),
		$resultCount = $('#topic-tag-result-count'),
		$noResults = $('#topic-tag-no-results'),
		$orderHint = $('#topic-tag-order-hint');
	let $selectedRow = $(),
		saving = false,
		dirty = false,
		draggedRow = null,
		dragImage = null,
		dropCommitted = false,
		previousOrder = [],
		orderSaving = false;

	function format(template, value) {
		return String(template).replace('%d', value).replace('%s', value);
	}

	function removeNotice($notice, immediate) {
		window.clearTimeout($notice.data('notice-timer'));
		if (immediate) {
			$notice.remove();
			return;
		}
		$notice.removeClass('is-visible');
		window.setTimeout(() => {
			$notice.remove();
		}, 180);
	}

	function showNotice(message, isError) {
		const $notice = $noticeTemplate.clone().removeAttr('id hidden').toggleClass('is-error', !!isError)
			.attr({ role: isError ? 'alert' : 'status', 'aria-live': isError ? 'assertive' : 'polite' });
		$notice.find('#topic-tag-notice-message').removeAttr('id').text(message);
		$notices.prepend($notice);
		$notice[0].getBoundingClientRect();
		$notice.addClass('is-visible');
		$notices.children('.topic-tag-notice').slice(4).each((index, notice) => {
			removeNotice($(notice), true);
		});
		if (!isError) {
			$notice.data('notice-timer', window.setTimeout(() => {
				removeNotice($notice, false);
			}, 4000));
		}
	}

	$notices.on('click', '.topic-tag-notice-close', function() {
		removeNotice($(this).closest('.topic-tag-notice'), false);
	});

	function responseData(xhr) {
		if (xhr.responseJSON) {
			return xhr.responseJSON;
		}
		try {
			return JSON.parse(xhr.responseText);
		} catch (error) {
			return {};
		}
	}

	function requestError(xhr) {
		const response = responseData(xhr),
			message = response.message || $app.data('request-failed');
		showNotice(message, true);
		return response;
	}

	function contrastColor(hex) {
		const clean = hex.replace('#', ''), channels = [];
		if (!/^[0-9a-f]{6}$/i.test(clean)) {
			return '#000000';
		}
		for (let i = 0; i < 3; i++) {
			const channel = parseInt(clean.substr(i * 2, 2), 16) / 255;
			channels.push(channel <= 0.03928 ? channel / 12.92 : Math.pow((channel + 0.055) / 1.055, 2.4));
		}
		const luminance = 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2],
			whiteContrast = 1.05 / (luminance + 0.05),
			blackContrast = (luminance + 0.05) / 0.05;
		return whiteContrast >= blackContrast ? '#FFFFFF' : '#000000';
	}

	function normalizeColor(value) {
		let normalized = String(value || '').trim().toUpperCase();
		if (normalized && normalized.charAt(0) !== '#') {
			normalized = '#' + normalized;
		}
		return normalized;
	}

	function validateColor(showError) {
		const valid = /^#[0-9A-F]{6}$/.test(normalizeColor($colorText.val()));
		$colorText.attr('aria-invalid', valid ? 'false' : 'true');
		$colorError.prop('hidden', valid || !showError);
		return valid;
	}

	function updatePreview() {
		const value = normalizeColor($colorText.val()), valid = /^#[0-9A-F]{6}$/.test(value);
		$preview.text($name.val().trim() || $app.data('preview'));
		if (valid) {
			$preview.css({ backgroundColor: value, color: contrastColor(value) });
		}
	}

	function setDirty(value) {
		dirty = !!value;
		$dirtyLabel.prop('hidden', !dirty);
	}

	function confirmDiscard(callback) {
		if (saving) {
			return false;
		}
		if (dirty && !window.confirm($app.data('discard'))) {
			return false;
		}
		callback();
		return true;
	}

	function formTokenData() {
		return {
			creation_time: $form.find('input[name="creation_time"]').val(),
			form_token: $form.find('input[name="form_token"]').val()
		};
	}

	function forumIdsForRow($row) {
		const value = String($row.attr('data-forum-ids') || '');
		return value ? value.split(',') : [];
	}

	function resetForumSearch() {
		$forumSearch.val('');
		$forumPicker.children().prop('hidden', false);
	}

	function updateForumCount() {
		const count = $forumPicker.find('input[type="checkbox"]:checked').length;
		$selectedCount.text(format($app.data('selected-count'), count));
	}

	function loadEditor($row) {
		const ids = forumIdsForRow($row), tagId = $row.data('tag-id');
		$selectedRow.removeClass('is-selected');
		$selectedRow = $row.addClass('is-selected');
		$form.find('input[name="tag_id"]').val(tagId);
		$name.val($row.attr('data-tag-name'));
		$color.val($row.attr('data-tag-color'));
		$colorText.val($row.attr('data-tag-color').toUpperCase());
		$enabled.prop('checked', $row.attr('data-tag-enabled') === '1');
		$forumPicker.find('input[type="checkbox"]').each((index, checkbox) => {
			checkbox.checked = ids.indexOf(checkbox.value) !== -1;
		});
		$('#topic-tag-editor-title').text($app.data('edit-title'));
		resetForumSearch();
		updateForumCount();
		updatePreview();
		validateColor(false);
		$formError.prop('hidden', true).empty();
		setDirty(false);
	}

	function loadNewEditor() {
		$selectedRow.removeClass('is-selected');
		$selectedRow = $();
		$form.find('input[name="tag_id"]').val('0');
		$name.val('');
		$color.val($app.data('default-color'));
		$colorText.val($app.data('default-color'));
		$enabled.prop('checked', true);
		$forumPicker.find('input[type="checkbox"]').prop('checked', false);
		$('#topic-tag-editor-title').text($app.data('create-title'));
		resetForumSearch();
		updateForumCount();
		updatePreview();
		validateColor(false);
		$formError.prop('hidden', true).empty();
		setDirty(false);
	}

	function updateEnabledState($row, enabled) {
		const $toggle = $row.find('.topic-tag-toggle');
		$row.attr('data-tag-enabled', enabled ? '1' : '0').toggleClass('is-disabled', !enabled);
		$toggle.attr('aria-checked', enabled ? 'true' : 'false');
		$toggle.find('.topic-tag-state-current').text($toggle.data(enabled ? 'enabled-label' : 'disabled-label'));
		if ($selectedRow.is($row)) {
			$enabled.prop('checked', enabled);
		}
	}

	function forumNamesFromIds(ids) {
		const names = [];
		$forumPicker.find('input[type="checkbox"]').each((index, checkbox) => {
			if (ids.indexOf(parseInt(checkbox.value, 10)) !== -1) {
				names.push($(checkbox).siblings('span').text());
			}
		});
		return names;
	}

	function populateRow($row, tag) {
		const names = tag.forum_names || forumNamesFromIds(tag.forum_ids),
			previewText = names.join(', '),
			$previewNames = $row.find('.topic-tag-forum-preview');
		$row.attr({
			'data-tag-id': tag.id,
			'data-tag-name': tag.name,
			'data-tag-color': tag.color,
			'data-tag-text-color': tag.text_color,
			'data-forum-ids': tag.forum_ids.join(','),
			'data-search': (tag.name + ' ' + names.join(' ')).toLowerCase()
		});
		$row.data('tag-id', tag.id);
		$row.find('.topic-tag-summary .topic-tag').text(tag.name).css({ backgroundColor: tag.color, color: tag.text_color });
		$row.find('.topic-tag-forum-summary strong').text(format($app.data('forum-count'), names.length));
		$previewNames.text(previewText);
		if (previewText) {
			$previewNames.attr('title', previewText);
		} else {
			$previewNames.removeAttr('title');
		}
		$row.find('[data-tag-edit]').attr('href', tag.urls.edit);
		$row.find('.topic-tag-toggle').attr('href', tag.urls.toggle);
		$row.find('.topic-tag-move-up').attr('data-action-url', tag.urls.move_up);
		$row.find('.topic-tag-move-down').attr('data-action-url', tag.urls.move_down);
		$row.find('[data-ajax="tp_delete"]').attr('href', tag.urls.delete);
		updateEnabledState($row, tag.enabled);
	}

	function createRow(tag) {
		const $row = $('#topic-tag-row-template').clone();
		$row.removeAttr('id hidden').removeClass('topic-tag-row-template').attr('draggable', 'false');
		populateRow($row, tag);
		$list.find('.topic-tags-empty').remove();
		$list.append($row);
		phpbb.ajaxify({ selector: $row.find('[data-ajax="tp_delete"]'), callback: 'tp_delete' });
		return $row;
	}

	function itemRows() {
		return $list.children('.topic-tag-item');
	}

	function setOrderActionState($action, disabled) {
		$action.attr('aria-disabled', disabled ? 'true' : 'false');
		if (disabled) {
			$action.removeAttr('href').attr('tabindex', '-1');
		} else {
			$action.attr('href', $action.attr('data-action-url')).removeAttr('tabindex');
		}
	}

	function updateOrderActions() {
		const $rows = itemRows(), disableAll = orderSaving || $tagSearch.val().trim() || $statusFilter.val() !== 'all';
		$rows.each((index, row) => {
			setOrderActionState($(row).find('.topic-tag-move-up'), disableAll || index === 0);
			setOrderActionState($(row).find('.topic-tag-move-down'), disableAll || index === $rows.length - 1);
		});
	}

	function updateListFilter() {
		const query = $tagSearch.val().trim().toLowerCase(), status = $statusFilter.val(),
			filterActive = query !== '' || status !== 'all', $rows = itemRows();
		let visible = 0;
		$rows.each((index, row) => {
			const $row = $(row), enabled = $row.attr('data-tag-enabled') === '1',
				matchesText = String($row.attr('data-search') || '').toLowerCase().indexOf(query) !== -1,
				matchesStatus = status === 'all' || (status === 'enabled' && enabled) || (status === 'disabled' && !enabled),
				show = matchesText && matchesStatus;
			$row.prop('hidden', !show).attr('draggable', 'false');
			$row.find('.topic-tag-drag').prop('disabled', filterActive);
			if (show) {
				visible++;
			}
		});
		$resultCount.text(format($app.data('result-count'), visible));
		$noResults.prop('hidden', !($rows.length && visible === 0));
		$orderHint.prop('hidden', !filterActive);
		updateOrderActions();
		if (!$rows.length && !$list.find('.topic-tags-empty').length) {
			$('<div class="topic-tags-empty">').text($app.data('empty')).appendTo($list);
		}
	}

	function currentOrder() {
		return itemRows().map((index, row) => {
			return parseInt($(row).attr('data-tag-id'), 10);
		}).get();
	}

	function restoreOrder(order) {
		order.forEach((id) => {
			const $row = itemRows().filter((index, row) => {
				return parseInt($(row).attr('data-tag-id'), 10) === id;
			}).first();
			$list.append($row);
		});
	}

	function persistOrder(oldOrder) {
		const data = formTokenData(), $rows = itemRows(), $actions = $rows.find('.topic-tag-actions');
		orderSaving = true;
		updateOrderActions();
		data.action = 'reorder';
		data.tag_ids = currentOrder();
		$actions.addClass('is-saving');
		$rows.find('.topic-tag-drag').prop('disabled', true);
		$.ajax({ url: $app.data('action'), type: 'POST', data: data, cache: false })
			.done((response) => {
				if (response.success) {
					showNotice(response.message, false);
				}
			})
			.fail((xhr) => {
				restoreOrder(oldOrder);
				requestError(xhr);
			})
			.always(() => {
				orderSaving = false;
				$actions.removeClass('is-saving');
				updateListFilter();
			});
	}

	function toggleRow($row, desired, source) {
		const data = formTokenData(), $toggle = $row.find('.topic-tag-toggle');
		if ($toggle.hasClass('is-saving')) {
			return;
		}
		data.action = 'toggle';
		data.tag_id = $row.attr('data-tag-id');
		data.enabled = desired ? 1 : 0;
		$toggle.addClass('is-saving');
		$.ajax({ url: $app.data('action'), type: 'POST', data: data, cache: false })
			.done((response) => {
				if (response.success) {
					updateEnabledState($row, response.enabled);
					updateListFilter();
					showNotice(response.message, false);
				}
			})
			.fail((xhr) => {
				if (source === 'editor') {
					$enabled.prop('checked', !desired);
				}
				requestError(xhr);
			})
			.always(() => {
				$toggle.removeClass('is-saving');
			});
	}

	phpbb.addAjaxCallback('tp_delete', function(response) {
		const $row = $(this).closest('.topic-tag-item');
		if (!response.success) {
			return;
		}
		if ($selectedRow.is($row)) {
			loadNewEditor();
		}
		$row.remove();
		updateListFilter();
		showNotice(response.message, false);
	});

	$list[0].addEventListener('click', (event) => {
		if (saving) {
			event.preventDefault();
			event.stopImmediatePropagation();
			return;
		}
		const $delete = $(event.target).closest('[data-ajax="tp_delete"]');
		if (!$delete.length) {
			return;
		}
		const $row = $delete.closest('.topic-tag-item');
		if (dirty && $selectedRow.is($row)) {
			if (!window.confirm($app.data('discard'))) {
				event.preventDefault();
				event.stopImmediatePropagation();
			}
		}
	}, true);

	$list.on('click', '[data-tag-edit]', function(event) {
		const $row = $(this).closest('.topic-tag-item');
		event.preventDefault();
		if ($selectedRow.is($row)) {
			return;
		}
		confirmDiscard(() => {
			loadEditor($row);
			if (window.matchMedia && window.matchMedia('(max-width: 980px)').matches) {
				document.querySelector('.topic-tag-inspector').scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		});
	});

	$('.topic-tag-new').on('click', () => {
		confirmDiscard(() => {
			loadNewEditor();
			$name.trigger('focus');
		});
	});

	$('#topic-tag-cancel').on('click', (event) => {
		event.preventDefault();
		confirmDiscard(() => {
			loadNewEditor();
		});
	});

	$list.on('click', '.topic-tag-toggle', function(event) {
		const $row = $(this).closest('.topic-tag-item');
		event.preventDefault();
		toggleRow($row, $row.attr('data-tag-enabled') !== '1', 'row');
	});

	$list.on('keydown', '.topic-tag-toggle', function(event) {
		if (event.key === ' ' || event.keyCode === 32) {
			event.preventDefault();
			$(this).trigger('click');
		}
	});

	$enabled.on('change', function() {
		if ($selectedRow.length) {
			toggleRow($selectedRow, this.checked, 'editor');
		} else {
			setDirty(true);
		}
	});

	$name.on('input', () => {
		setDirty(true);
		updatePreview();
	});

	$color.on('input change', function() {
		$colorText.val(this.value.toUpperCase());
		setDirty(true);
		updatePreview();
		validateColor(false);
	});

	$colorText.on('input', () => {
		setDirty(true);
		updatePreview();
		validateColor(false);
	}).on('blur', function() {
		const value = normalizeColor(this.value);
		if (/^#[0-9A-F]{6}$/.test(value)) {
			this.value = value;
			$color.val(value);
		}
		validateColor(true);
		updatePreview();
	});

	$forumPicker.on('change', 'input[type="checkbox"]', () => {
		setDirty(true);
		updateForumCount();
	});

	$forumSearch.on('input', function() {
		const query = this.value.trim().toLowerCase();
		$forumPicker.children().each((index, forum) => {
			$(forum).prop('hidden', String($(forum).attr('data-forum-name') || '').toLowerCase().indexOf(query) === -1);
		});
	});

	$('#topic-tag-select-visible').on('click', () => {
		$forumPicker.find('.topic-tag-forum-option:not([hidden]) input').prop('checked', true);
		setDirty(true);
		updateForumCount();
	});

	$('#topic-tag-clear-forums').on('click', () => {
		$forumPicker.find('input[type="checkbox"]').prop('checked', false);
		setDirty(true);
		updateForumCount();
	});

	$tagSearch.add($statusFilter).on('input change', updateListFilter);

	$list.on('click', '.topic-tag-move-up, .topic-tag-move-down', function(event) {
		const $link = $(this), $row = $link.closest('.topic-tag-item'), oldOrder = currentOrder();
		if ($link.attr('aria-disabled') === 'true' || orderSaving || $tagSearch.val().trim() || $statusFilter.val() !== 'all') {
			event.preventDefault();
			return;
		}
		event.preventDefault();
		const $sibling = $link.hasClass('topic-tag-move-up') ? $row.prev('.topic-tag-item') : $row.next('.topic-tag-item');
		if (!$sibling.length) {
			return;
		}
		if ($link.hasClass('topic-tag-move-up')) {
			$row.insertBefore($sibling);
		} else {
			$row.insertAfter($sibling);
		}
		persistOrder(oldOrder);
	});

	$list.on('mousedown', '.topic-tag-drag', function() {
		if (!orderSaving && !$tagSearch.val().trim() && $statusFilter.val() === 'all') {
			$(this).closest('.topic-tag-item').attr('draggable', 'true');
		}
	});

	$list.on('dragstart', '.topic-tag-item', function(event) {
		if ($(this).attr('draggable') !== 'true') {
			event.preventDefault();
			return;
		}
		draggedRow = this;
		dropCommitted = false;
		previousOrder = currentOrder();
		$(this).addClass('is-dragging');
		dragImage = $('<div class="topic-tag-drag-image">').text($(this).attr('data-tag-name')).appendTo(document.body)[0];
		event.originalEvent.dataTransfer.effectAllowed = 'move';
		event.originalEvent.dataTransfer.setData('text/plain', $(this).attr('data-tag-id'));
		if (event.originalEvent.dataTransfer.setDragImage) {
			event.originalEvent.dataTransfer.setDragImage(dragImage, 20, 20);
		}
	});

	$list.on('dragover', '.topic-tag-item', function(event) {
		if (!draggedRow || this === draggedRow) {
			return;
		}
		event.preventDefault();
		event.originalEvent.dataTransfer.dropEffect = 'move';
		const rect = this.getBoundingClientRect();
		if (event.originalEvent.clientY < rect.top + rect.height / 2) {
			$(draggedRow).insertBefore(this);
		} else {
			$(draggedRow).insertAfter(this);
		}
	});

	$list.on('dragover', (event) => {
		if (draggedRow) {
			event.preventDefault();
			event.originalEvent.dataTransfer.dropEffect = 'move';
		}
	});

	$list.on('drop', (event) => {
		if (!draggedRow) {
			return;
		}
		event.preventDefault();
		event.originalEvent.dataTransfer.dropEffect = 'move';
		dropCommitted = true;
	});

	$list.on('dragend', '.topic-tag-item', function() {
		const changed = previousOrder.join(',') !== currentOrder().join(',');
		$(this).removeClass('is-dragging').attr('draggable', 'false');
		if (dragImage) {
			document.body.removeChild(dragImage);
			dragImage = null;
		}
		draggedRow = null;
		if (!dropCommitted) {
			restoreOrder(previousOrder);
		} else if (changed) {
			persistOrder(previousOrder);
		}
		dropCommitted = false;
	});

	$form.on('submit', function(event) {
		const $submit = $form.find('input[type="submit"]');
		event.preventDefault();
		if (saving || orderSaving || $list.find('.is-saving').length || draggedRow) {
			return;
		}
		$formError.prop('hidden', true).empty();
		if (!this.checkValidity()) {
			this.reportValidity();
			return;
		}
		if (!validateColor(true)) {
			$colorText.trigger('focus');
			return;
		}
		$colorText.val(normalizeColor($colorText.val()));
		$color.val($colorText.val());
		const creating = parseInt($form.find('input[name="tag_id"]').val(), 10) === 0,
			data = $form.serialize() + '&submit=1',
			$origin = $selectedRow,
			$controls = $app.find('input, button, select, textarea').filter(':enabled');
		saving = true;
		$controls.prop('disabled', true);
		$submit.prop('disabled', true);
		$.ajax({ url: $form.attr('action'), type: 'POST', data: data, cache: false })
			.always(() => {
				saving = false;
				$controls.prop('disabled', false);
				$submit.prop('disabled', false);
			})
			.done((response) => {
				if (!response.success) {
					return;
				}
				if (creating) {
					createRow(response.tag);
					loadNewEditor();
				} else {
					populateRow($origin, response.tag);
					loadEditor($origin);
				}
				updateListFilter();
				showNotice(response.message, false);
				if (creating) {
					$name.trigger('focus');
				}
			})
			.fail((xhr) => {
				const response = responseData(xhr),
					field = response.field ? document.getElementById(response.field) : null;
				$formError.text(response.message || $app.data('request-failed')).prop('hidden', false);
				if (field) {
					field.focus();
				}
			});
	});

	$(window).on('beforeunload', () => {
		if (dirty) {
			return $app.data('discard');
		}
	});

	itemRows().attr('draggable', 'false');
	updateListFilter();
	updateForumCount();
	updatePreview();
	validateColor(false);
	const initialId = parseInt($form.find('input[name="tag_id"]').val(), 10);
	if (initialId) {
		const $initial = itemRows().filter((index, row) => {
			return parseInt($(row).attr('data-tag-id'), 10) === initialId;
		}).first();
		if ($initial.length) {
			$selectedRow = $initial.addClass('is-selected');
		}
	}
})(jQuery);
