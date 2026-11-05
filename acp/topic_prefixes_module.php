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

/**
 * Dispatch Topic Prefixes ACP requests to the administrator controller.
 */
class topic_prefixes_module
{
	/** @var string Module action URL */
	public $u_action;

	/** @var string Template name */
	public $tpl_name;

	/** @var string Page title language key */
	public $page_title;

	/**
	 * Dispatch the current ACP request.
	 *
	 * @return void
	 * @throws \Exception When controller dispatch fails
	 */
	public function main(): void
	{
		global $phpbb_container;

		$lang = $phpbb_container->get('language');
		$lang->add_lang('acp/forums');
		$lang->add_lang('acp_topic_prefixes', 'phpbb/topicprefixes');
		$this->tpl_name   = 'acp_topic_prefixes';
		$this->page_title = 'TOPIC_TAGS';

		$admin_controller = $phpbb_container->get('phpbb.topicprefixes.admin_controller');
		$admin_controller->set_u_action($this->u_action)->main();
	}
}
