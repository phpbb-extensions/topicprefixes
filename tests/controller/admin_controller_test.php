<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2016 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\controller;

require_once __DIR__ . '/../../../../../includes/functions_acp.php';

class admin_controller_test extends \phpbb_test_case
{
	public static $valid_hash = true;
	public static $confirmed = false;

	protected $manager;
	protected $renderer;
	protected $language;
	protected $log;
	protected $request;
	protected $template;
	protected $user;
	protected $controller;

	protected function setUp(): void
	{
		parent::setUp();
		global $phpbb_root_path, $phpEx, $user;
		$this->manager = $this->getMockBuilder('\phpbb\topicprefixes\tags\manager')->disableOriginalConstructor()->getMock();
		$this->renderer = $this->getMockBuilder('\phpbb\topicprefixes\tags\renderer')->disableOriginalConstructor()->getMock();
		$this->language = new \phpbb\language\language(new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx));
		$this->log = $this->getMockBuilder('\phpbb\log\log')->disableOriginalConstructor()->getMock();
		$this->request = $this->getMockBuilder('\phpbb\request\request')->disableOriginalConstructor()->getMock();
		$this->template = $this->getMockBuilder('\phpbb\template\template')->disableOriginalConstructor()->getMock();
		$this->user = $this->getMockBuilder('\phpbb\user')->disableOriginalConstructor()->getMock();
		$this->user->data = array('user_id' => 2, 'user_form_salt' => 'salt');
		$this->user->ip = '127.0.0.1';
		$user = $this->user;
		$this->controller = new admin_controller(
			$this->manager, $this->renderer, $this->language, $this->log, $this->request, $this->template, $this->user
		);
		$this->controller->set_u_action('adm.php?mode=manage');
	}

	public function test_display_settings_lists_tags_and_edit_form()
	{
		$this->manager->method('get_forum_names_by_tag')->willReturn(array(1 => array('Forum Two')));
		$this->manager->method('get_tags')->willReturn(array(1 => array(
			'prefix_id' => 1, 'prefix_tag' => 'Bug', 'prefix_color' => 'D4351C', 'prefix_enabled' => 1,
		)));
		$this->renderer->method('contrast_color')->with('D4351C')->willReturn('#FFFFFF');
		$this->template->expects(self::once())->method('assign_block_vars')->with('tags', self::callback(function ($row) {
			return $row['TAG_NAME'] === 'Bug' && $row['FORUM_NAMES'] === array('Forum Two') && $row['TAG_TEXT_COLOR'] === '#FFFFFF';
		}));
		$this->template->expects(self::once())->method('assign_vars')->with(self::callback(function ($vars) {
			return $vars['TAG_ID'] === 0 && $vars['S_FORUM_OPTIONS'] === '#forum options#';
		}));
		$this->controller->display_settings();
	}

	public function test_toggle_updates_enabled_state()
	{
		$this->request->method('variable')->willReturn('valid');
		$this->request->method('is_ajax')->willReturn(false);
		$this->manager->expects(self::once())->method('get_tag')->with(1)->willReturn(array('prefix_enabled' => 1));
		$this->manager->expects(self::once())->method('set_enabled')->with(1, false)->willReturn(true);
		$this->controller->toggle_tag(1);
	}

	public function test_move_updates_order()
	{
		$this->request->method('variable')->willReturn('valid');
		$this->request->method('is_ajax')->willReturn(false);
		$this->manager->expects(self::once())->method('move_tag')->with(2, 'down')->willReturn(true);
		$this->controller->move_tag(2, 'down');
	}

	public function test_delete_requires_confirmation()
	{
		self::$confirmed = false;
		$this->manager->expects(self::once())->method('get_tag')->with(1)->willReturn(array('prefix_tag' => 'Bug'));
		$this->manager->expects(self::never())->method('delete_tag');
		$this->controller->delete_tag(1);
	}
}

function make_forum_select()
{
	return '#forum options#';
}

function check_link_hash()
{
	return admin_controller_test::$valid_hash;
}

function confirm_box($check)
{
	return $check ? admin_controller_test::$confirmed : false;
}
