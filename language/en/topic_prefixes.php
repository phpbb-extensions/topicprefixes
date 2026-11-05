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
	'TOPIC_TAGS_FILTER' => 'Filter by tag',
	'TOPIC_TAGS_CLEAR_FILTERS' => 'Clear tag filters',
	'TOPIC_TAG_FILTER_TOOLTIP' => 'Filter topics by “%s”',
	'TOPIC_TAG_FILTER_REMOVE_TOOLTIP' => 'Remove “%s” from topic filters',
	'TOPIC_TAG_RETAINED_TOOLTIP' => '(this tag can still filter topics, but cannot be assigned to topics in this forum)',
	'TOPIC_TAG_TOGGLE_TOOLTIP' => 'Select or deselect the “%s” topic tag',
	'TOPIC_TAGS_INVALID' => 'One or more selected topic tags are disabled or unavailable in this forum.',
	'TOPIC_TAG_SEARCH' => 'Search for topic tags',
	'TOPIC_TAG_SEARCH_EXPLAIN' => 'Use topic tags to refine keyword or author searches, or search by tags alone.',
	'TOPIC_TAG_SEARCH_PLACEHOLDER' => 'Type to find tags',
	'TOPIC_TAG_SEARCH_MATCH_ALL' => 'Match all selected tags',
	'TOPIC_TAG_SEARCH_MATCH_ANY' => 'Match any selected tag',
	'TOPIC_TAG_SEARCH_REMOVE' => 'Remove',
	'TOPIC_TAG_SEARCH_SPHINX_UNSUPPORTED' => 'Topic tags cannot be combined with keywords or authors while the Sphinx search backend is active. Search by tags alone or remove the tag filters.',
]);
