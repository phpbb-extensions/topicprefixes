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
	'ACP_TOPIC_PREFIXES' => 'Topic tags',
	'ACP_MANAGE_PREFIXES' => 'Manage tags',
	'ACP_LOG_TAG_ADDED' => '<strong>Created topic tag</strong><br>» %s',
	'ACP_LOG_TAG_UPDATED' => '<strong>Updated topic tag</strong><br>» %s',
	'ACP_LOG_TAG_DELETED' => '<strong>Deleted topic tag</strong><br>» %s',
]);
