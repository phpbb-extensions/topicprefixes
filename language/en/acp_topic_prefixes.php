<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2016 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = [];
}

$lang = array_merge($lang, [
	'TOPIC_TAGS' => 'Topic tags',
	'TOPIC_TAGS_EXPLAIN' => 'Create administrator-curated topic tags, set their display order, and choose where each tag is available.',
	'ALLOW_TOPIC_TAG_SEARCH' => 'Allow topic tag search',
	'ALLOW_TOPIC_TAG_SEARCH_EXPLAIN' => 'Allow topic tag search controls on the advanced search page.',
	'TOPIC_TAGS_REPAIR_CLI' => 'Maintenance tools',
	'TOPIC_TAGS_REPAIR_CLI_EXPLAIN' => 'Command-line tools for repairing topic tags and removing old prefixes from topic titles and reply subjects. They can be run from your board root:',
	'TOPIC_TAGS_REPAIR_TAGS' => '&bull; Split or merge topic tags:',
	'TOPIC_TAGS_REPAIR_TOPICS' => '&bull; Repair missing topic tags or old prefixes, usually after upgrading to version 2:',
	'TOPIC_TAG_CATALOG' => 'Tag catalog',
	'TOPIC_TAG_EDITOR' => 'Tag editor',
	'TOPIC_TAG_SEARCH' => 'Search tags or forums',
	'TOPIC_TAG_FILTER_STATUS' => 'Filter by status',
	'TOPIC_TAG_FILTER_ALL' => 'All statuses',
	'TOPIC_TAG_FILTER_ENABLED' => 'Enabled only',
	'TOPIC_TAG_FILTER_DISABLED' => 'Disabled only',
	'TOPIC_TAG_CLEAR_FILTER_TO_SORT' => 'Clear search and status filters to change tag order.',
	'TOPIC_TAG_RESULT_COUNT' => [
		0 => 'No tags',
		1 => '%d tag',
		2 => '%d tags',
	],
	'TOPIC_TAG_RESULT_COUNT_JS' => '%d tag(s)',
	'TOPIC_TAG' => 'Tag',
	'TOPIC_TAG_TEXT' => 'Tag text',
	'TOPIC_TAG_COLOR' => 'Badge color',
	'TOPIC_TAG_COLOR_EXPLAIN' => 'Choose the badge background color. Badge text color is selected automatically for contrast.',
	'TOPIC_TAG_COLOR_HEX' => 'Six-digit hexadecimal badge color',
	'TOPIC_TAG_PREVIEW' => 'Tag preview',
	'TOPIC_TAG_ENABLED' => 'Enabled',
	'TOPIC_TAG_ENABLED_EXPLAIN' => 'Disabled tags remain on existing topics but cannot be assigned to new topics.',
	'TOPIC_TAG_FORUMS' => 'Available forums',
	'TOPIC_TAG_FORUMS_EXPLAIN' => 'Choose forums where this tag can be assigned.',
	'TOPIC_TAG_FORUM_SEARCH' => 'Search forums',
	'TOPIC_TAG_SELECT_VISIBLE' => 'Select visible',
	'TOPIC_TAG_CLEAR_SELECTION' => 'Clear selection',
	'TOPIC_TAG_SELECTED_COUNT_JS' => '%d selected',
	'TOPIC_TAG_FORUM_COUNT' => [
		0 => 'No forums',
		1 => '%d forum',
		2 => '%d forums',
	],
	'TOPIC_TAG_FORUM_COUNT_JS' => '%d forum(s)',
	'TOPIC_TAG_NO_FORUMS' => 'No forums',
	'TOPIC_TAGS_EMPTY' => 'No topic tags have been created.',
	'TOPIC_TAGS_NO_RESULTS' => 'No tags match these filters.',
	'CREATE_TOPIC_TAG' => 'Create topic tag',
	'EDIT_TOPIC_TAG' => 'Edit topic tag',
	'TOPIC_TAG_SAVE' => 'Save',
	'DELETE_TOPIC_TAG_CONFIRM' => 'Delete “%s”? Existing topic assignments will also be removed.',
	'TOPIC_TAG_DELETED' => 'Topic tag deleted.',
	'TOPIC_TAG_SAVED' => 'Topic tag saved.',
	'TOPIC_TAG_ENABLED_NOTICE' => 'Topic tag enabled.',
	'TOPIC_TAG_DISABLED_NOTICE' => 'Topic tag disabled.',
	'TOPIC_TAG_ORDER_SAVED' => 'Tag order saved.',
	'TOPIC_TAG_ORDER_STALE' => 'Tag list changed since this page loaded. Reload the page before sorting again.',
	'TOPIC_TAG_TOGGLE_STATE' => 'Enable or disable this topic tag',
	'TOPIC_TAG_DRAG' => 'Drag to reorder',
	'TOPIC_TAG_UNSAVED' => 'Unsaved changes',
	'TOPIC_TAG_DISCARD_CONFIRM' => 'Discard unsaved tag changes?',
	'TOPIC_TAG_REQUEST_FAILED' => 'Request failed. Your unsaved changes remain available.',
	'TOPIC_TAG_NOTICE_DISMISS' => 'Dismiss notification',
	'TOPIC_TAG_NAME_REQUIRED' => 'Tag text is required.',
	'TOPIC_TAG_NAME_TOO_LONG' => 'Tag text is too long to store.',
	'TOPIC_TAG_COLOR_INVALID' => 'Badge color must be a six-digit hexadecimal color.',
	'TOPIC_TAG_NOT_FOUND' => 'Requested topic tag does not exist.',
]);
