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
	'TOPIC_TAG' => 'Tag',
	'TOPIC_TAG_TEXT' => 'Tag text',
	'TOPIC_TAG_COLOR' => 'Badge color',
	'TOPIC_TAG_COLOR_EXPLAIN' => 'Choose the badge background color. Badge text color is selected automatically for contrast.',
	'TOPIC_TAG_ENABLED' => 'Enabled',
	'TOPIC_TAG_FORUMS' => 'Available forums',
	'TOPIC_TAG_FORUMS_EXPLAIN' => 'Select one or more forums. Use Ctrl/Cmd to select multiple forums.',
	'TOPIC_TAG_NO_FORUMS' => 'No forums',
	'TOPIC_TAGS_EMPTY' => 'No topic tags have been created.',
	'CREATE_TOPIC_TAG' => 'Create topic tag',
	'EDIT_TOPIC_TAG' => 'Edit topic tag',
	'DELETE_TOPIC_TAG_CONFIRM' => 'Delete this topic tag? Existing topic assignments will also be removed.',
	'TOPIC_TAG_DELETED' => 'Topic tag deleted.',
	'TOPIC_TAG_SAVED' => 'Topic tag saved.',
	'TOPIC_TAG_TOGGLE_STATE' => 'Enable or disable this topic tag',
	'TOPIC_TAG_NAME_REQUIRED' => 'Tag text is required.',
	'TOPIC_TAG_COLOR_INVALID' => 'Badge color must be a six-digit hexadecimal color.',
	'TOPIC_TAG_NOT_FOUND' => 'Requested topic tag does not exist.',
]);
