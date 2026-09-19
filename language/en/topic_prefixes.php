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
	'TOPIC_TAGS_INVALID' => 'One or more selected topic tags are disabled or unavailable in this forum.',
]);
