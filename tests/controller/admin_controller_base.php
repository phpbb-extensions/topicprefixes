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
 * We need to use the same namesapce as the controller we are
 * testing so we can override global scope functions (see bottom).
 */
namespace phpbb\topicprefixes\controller;

require_once __DIR__ . '/../../../../../includes/functions_acp.php';

class admin_controller_base extends \phpbb_test_case
{
	/** @var bool A return value for confirm_box() */
	public static $confirm = true;

	/** @var bool A return value for check_form_key() */
	public static $valid_form = false;

	/** @var \phpbb\topicprefixes\controller\admin_controller */
	protected $controller;

	/** @var \phpbb\topicprefixes\prefixes\manager|\PHPUnit\Framework\MockObject\MockObject */
	protected $manager;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\log\log|\PHPUnit\Framework\MockObject\MockObject */
	protected $log;

	/** @var \phpbb\request\request|\PHPUnit\Framework\MockObject\MockObject */
	protected $request;

	/** @var \phpbb\template\template|\PHPUnit\Framework\MockObject\MockObject */
	protected $template;

	/** @var \phpbb\user|\PHPUnit\Framework\MockObject\MockObject */
	protected $user;

	/** @var \phpbb\db\driver\driver_interface|\PHPUnit\Framework\MockObject\MockObject */
	protected $db;

	/**
	 * @inheritdoc
	 */
	protected function setUp(): void
	{
		global $db, $user, $phpbb_root_path, $phpEx;

		parent::setUp();

		$this->manager = $this->getMockBuilder('\phpbb\topicprefixes\prefixes\manager')
			->disableOriginalConstructor()
			->getMock();

		$this->language = new \phpbb\language\language(new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx));

		$this->log = $this->getMockBuilder('\phpbb\log\log')
			->disableOriginalConstructor()
			->getMock();

		$this->request = $this->getMockBuilder('\phpbb\request\request')
			->disableOriginalConstructor()
			->getMock();

		$this->template = $this->getMockBuilder('\phpbb\template\template')
			->disableOriginalConstructor()
			->getMock();

		$this->user = $this->getMockBuilder('\phpbb\user')
			->setConstructorArgs(array(
				$this->language,
				'\phpbb\datetime'
			))
			->getMock();
		$this->user->data['user_form_salt'] = '';
		$this->user->data['user_id'] = '';
		$this->user->ip = '';
		$user = $this->user;
		$db = $this->db = $this->getMockBuilder('\phpbb\db\driver\driver_interface')
			->disableOriginalConstructor()
			->getMock();

		$this->controller = new \phpbb\topicprefixes\controller\admin_controller(
			$this->manager,
			$this->language,
			$this->log,
			$this->request,
			$this->template,
			$this->user,
			$phpbb_root_path,
			$phpEx
		);
	}
}

/**
 * Mock confirm_box()
 * Note: use the same namespace as the admin_controller
 *
 * @return bool
 */
function confirm_box()
{
	return \phpbb\topicprefixes\controller\admin_controller_base::$confirm;
}

/**
 * Mock add_form_key()
 * Note: use the same namespace as the admin_controller
 */
function add_form_key()
{
}

/**
 * Mock check_form_key()
 * Note: use the same namespace as the admin_controller
 *
 * @return bool
 */
function check_form_key()
{
	return \phpbb\topicprefixes\controller\admin_controller_base::$valid_form;
}

/**
 * Mock make_forum_select()
 *
 * @return string
 */
function make_forum_select()
{
	return '#select menu#';
}
