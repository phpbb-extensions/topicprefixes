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
 * Class topic_prefixes_module
 */
class topic_prefixes_module
{
	/** @var string */
	public $u_action;
	public $tpl_name;
	public $page_title;

	/**
	 * Main ACP module
	 *
	 * @throws \Exception
	 */
	public function main()
	{
		global $phpbb_container;

		$lang = $phpbb_container->get('language');
		$lang->add_lang('acp/forums');
		$lang->add_lang('acp_topic_prefixes', 'phpbb/topicprefixes');
		$this->tpl_name   = 'acp_topic_prefixes';
		$this->page_title = 'TOPIC_PREFIXES';

		$admin_controller = $phpbb_container->get('phpbb.topicprefixes.admin_controller');
		$admin_controller->set_u_action($this->u_action)->main();
	}
}
