<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2016 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

/**
 * DO NOT CHANGE
 */
if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = array();
}

$lang = array_merge($lang, array(
	'ACP_TOPIC_PREFIXES'	=> 'Topic prefixes',
	'ACP_MANAGE_PREFIXES'	=> 'Manage prefixes',

	// ACP Log messages
	'ACP_LOG_PREFIX_ADDED'		=> '<strong>Created new topic prefix</strong><br />» %1$s in forum: %2$s',
	'ACP_LOG_PREFIX_DELETED'	=> '<strong>Deleted topic prefix</strong><br />» %1$s in forum: %2$s',
));
