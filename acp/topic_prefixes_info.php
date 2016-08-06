<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2016 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\acp;

class topic_prefixes_info
{
	public function module()
	{
		return [
			'filename'	=> '\phpbb\topicprefixes\acp\topic_prefixes_module',
			'title'		=> 'ACP_TOPIC_PREFIXES',
			'modes'		=> [
				'manage'	=> [
					'title' => 'ACP_MANAGE_PREFIXES',
					'auth'	=> 'ext_phpbb/topicprefixes && acl_a_board',
					'cat'	=> ['ACP_TOPIC_PREFIXES']
				],
			],
		];
	}
}
