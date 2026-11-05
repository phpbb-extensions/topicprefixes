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
	'ACP_LOG_TOPIC_PREFIX_REPLY_SUMMARY' => '<strong>Removed old prefix from reply subjects</strong><br>» Old prefix: %1$s; forum #%2$d; updated: %3$d; unchanged: %4$d',
	'ACP_LOG_TOPIC_PREFIX_RECOVERY_SUMMARY' => '<strong>Recovered missing topic tags</strong><br>» Approved groups: %1$d; topics: %2$d; assignments: %3$d; conflicts: %4$d',
	'ACP_LOG_TOPIC_PREFIX_TITLE_SUMMARY' => '<strong>Removed old prefix from topic titles</strong><br>» Old prefix: %1$s; forum #%2$d; updated: %3$d; unchanged: %4$d',
	'ACP_TOPIC_PREFIXES' => 'Topic tags',
	'ACP_MANAGE_PREFIXES' => 'Manage tags',
	'ACP_LOG_TAG_ADDED' => '<strong>Created topic tag</strong><br>» %s',
	'ACP_LOG_TAG_UPDATED' => '<strong>Updated topic tag</strong><br>» %s',
	'ACP_LOG_TAG_DELETED' => '<strong>Deleted topic tag</strong><br>» %s',
	'ACP_LOG_TAG_SPLIT' => '<strong>Split topic tag</strong><br>» %1$s into %2$s',
	'ACP_LOG_TAG_MERGED' => '<strong>Merged topic tag</strong><br>» %1$s into %2$s',
	'TOPIC_PREFIXES_UPGRADE_BOARD_ENABLED' => 'Topic Prefixes 2.0 must migrate your legacy prefix data. Before enabling it, back up your database and disable your board under General, Board settings. Then try enabling the extension again.',

	// Keeping to maintain translation of legacy log entries
	'ACP_LOG_PREFIX_ADDED' => '<strong>Created new topic prefix</strong><br>» %1$s in forum: %2$s',
	'ACP_LOG_PREFIX_DELETED' => '<strong>Deleted topic prefix</strong><br>» %1$s in forum: %2$s',
]);
