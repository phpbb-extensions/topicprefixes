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
	public static $confirm = true;

	public static $valid_form = false;

	/** @var \phpbb\topicprefixes\controller\admin_controller|\PHPUnit_Framework_MockObject_MockObject */
	protected $controller;

	/** @var \phpbb\topicprefixes\prefixes\manager|\PHPUnit_Framework_MockObject_MockObject */
	protected $manager;

	/** @var \phpbb\log\log|\PHPUnit_Framework_MockObject_MockObject */
	protected $log;

	/** @var \phpbb\request\request|\PHPUnit_Framework_MockObject_MockObject */
	protected $request;

	/** @var \phpbb\template\template|\PHPUnit_Framework_MockObject_MockObject */
	protected $template;

	/** @var \phpbb\user|\PHPUnit_Framework_MockObject_MockObject */
	protected $user;

	/**
	 * @inheritdoc
	 */
	public function setUp()
	{
		global $phpbb_root_path, $phpEx;

		parent::setUp();

		$this->manager = $this->getMockBuilder('\phpbb\topicprefixes\prefixes\manager')
			->disableOriginalConstructor()
			->getMock();

		$this->log = $this->getMockBuilder('\phpbb\log\log')
			->disableOriginalConstructor()
			->getMock();

		$this->request = $this->getMock('\phpbb\request\request');
		$this->template = $this->getMock('\phpbb\template\template');
		$this->user = $this->getMock('\phpbb\user', array(), array(
			new \phpbb\language\language(new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx)),
			'\phpbb\datetime'
		));

		$this->controller = $this->getMockBuilder('\phpbb\topicprefixes\controller\admin_controller')
			->setMethods(array('get_forum_info', 'log'))
			->setConstructorArgs(array(
				$this->manager,
				$this->log,
				$this->request,
				$this->template,
				$this->user,
				$phpbb_root_path,
				$phpEx,
			))
			->getMock();
	}

	/**
	 * Data for test_display_settings
	 *
	 * @return array
	 */
	public function data_display_settings()
	{
		return array(
			array(0, 1),
			array(1, 2),
			array(2, 3),
		);
	}

	/**
	 * Test display_settings()
	 *
	 * @dataProvider data_display_settings
	 * @param $prefix_count
	 * @param $forum_id
	 */
	public function test_display_settings($prefix_count, $forum_id)
	{
		$this->manager->expects(static::any())
			->method('get_prefixes')
			->will(static::returnValue(array_pad(array(), $prefix_count, 0)));

		$this->template->expects(static::exactly($prefix_count))
			->method('assign_block_vars');

		$this->template->expects(static::any())
			->method('assign_vars')
			->with(array(
				'S_FORUM_OPTIONS'	=> '#select menu#',
				'FORUM_ID'			=> $forum_id,
				'U_ACTION'			=> 'foo',
			));

		$this->controller
			->set_u_action('foo')
			->set_forum_id($forum_id)
			->display_settings();
	}

	/**
	 * Data for test_add_prefix
	 *
	 * @return array
	 */
	public function data_add_prefix()
	{
		return array(
			array(true, true),
			array(true, false),
			array(false, false),
		);
	}

	/**
	 * Test add_prefix()
	 *
	 * @dataProvider data_add_prefix
	 * @param $submit
	 * @param $valid_form
	 */
	public function test_add_prefix($submit, $valid_form)
	{
		if ($submit)
		{
			self::$valid_form = $valid_form;

			$this->request->expects(static::any())
				->method('is_set_post')
				->will(static::returnValue($submit));

			if (!$valid_form)
			{
				$this->setExpectedTriggerError(E_USER_WARNING);
			}
			else
			{
				$this->manager->expects(static::once())
					->method('add_prefix');
				$this->controller->expects(static::once())
					->method('log');
			}
		}
		else
		{
			$this->manager->expects(static::never())
				->method('add_prefix');
			$this->controller->expects(static::never())
				->method('log');
		}

		$this->controller->add_prefix();
	}

	/**
	 * Data for test_edit_prefix
	 *
	 * @return array
	 */
	public function data_edit_prefix()
	{
		return array(
			array(1, true), // valid prefix, valid form/hash
			array(1, false), // valid prefix, invalid form/hash
			array(0, true), // invalid prefix, valid form/hash
		);
	}

	/**
	 * Test edit_prefix() method
	 *
	 * @dataProvider data_edit_prefix
	 * @param $prefix_id
	 * @param $valid_form
	 */
	public function test_edit_prefix($prefix_id, $valid_form)
	{
		$this->request->expects(static::any())
			->method('variable')
			->with(static::anything())
			->will(static::returnValueMap(array(
				array('hash', '', false, \phpbb\request\request_interface::REQUEST, generate_link_hash('edit' . $prefix_id))
			)));

		if (!$valid_form)
		{
			$prefix_id = 0;
			$this->setExpectedTriggerError(E_USER_WARNING);
			$this->manager->expects(static::never())
				->method('update_prefix');
		}
		else
		{
			$this->setExpectedTriggerError(E_USER_WARNING);
			$this->manager->expects(static::once())
				->method('update_prefix')
				->with(static::equalTo(0))
				->will(static::throwException(new \OutOfBoundsException));
		}

		$this->controller->edit_prefix($prefix_id);
	}

	/**
	 * Data for test_delete_prefix
	 *
	 * @return array
	 */
	public function data_delete_prefix()
	{
		return array(
			array(1, false), // valid prefix, not confirmed
			array(1, true), // valid prefix, confirmed
			array(0, true), // invalid prefix, confirmed
		);
	}

	/**
	 * Test delete_prefix() method
	 *
	 * @dataProvider data_delete_prefix
	 * @param $prefix_id
	 * @param $confirm
	 */
	public function test_delete_prefix($prefix_id, $confirm)
	{
		self::$confirm = $confirm;

		if (!$confirm)
		{
			$this->manager->expects(static::never())
				->method('delete_prefix');
			$this->controller->expects(static::never())
				->method('log');
		}
		else if ($prefix_id === 0)
		{
			$this->setExpectedTriggerError(E_USER_WARNING);
			$this->manager->expects(static::once())
				->method('delete_prefix')
				->will(static::throwException(new \OutOfBoundsException()));
			$this->controller->expects(static::never())
				->method('log');
		}
		else
		{
			$this->setExpectedTriggerError(E_USER_NOTICE);
			$this->manager->expects(static::once())
				->method('delete_prefix');
			$this->controller->expects(static::once())
				->method('log');
		}

		$this->controller->delete_prefix($prefix_id);
	}

	/**
	 * Data for test_move_prefix
	 *
	 * @return array
	 */
	public function data_move_prefix()
	{
		return array(
			// prefix id, direction, valid form/hash, is ajax
			array(1, 'up', true),
			array(1, 'down', true),
			array(2, 'up', true),
			array(2, 'down', true),
			array(1, 'up', false),
			array(0, 'up', true),
		);
	}

	/**
	 * Test move_prefix() method
	 *
	 * @dataProvider data_move_prefix
	 * @param $prefix_id
	 * @param $direction
	 * @param $valid_form
	 */
	public function test_move_prefix($prefix_id, $direction, $valid_form)
	{
		$this->request->expects(static::any())
			->method('variable')
			->with(static::anything())
			->will(static::returnValueMap(array(
				array('hash', '', false, \phpbb\request\request_interface::REQUEST, generate_link_hash($direction . $prefix_id))
			)));

		if (!$valid_form)
		{
			$prefix_id = 0;
			$this->setExpectedTriggerError(E_USER_WARNING);
			$this->manager->expects(static::never())
				->method('move_prefix');
		}
		else
		{
			if ($prefix_id === 0)
			{
				$this->setExpectedTriggerError(E_USER_WARNING);
				$this->manager->expects(static::once())
					->method('move_prefix')
					->with(static::equalTo(0), static::stringContains($direction))
					->will(static::throwException(new \OutOfBoundsException));
			}
			else
			{
				$this->manager->expects(static::once())
					->method('move_prefix')
					->with(static::equalTo($prefix_id), static::stringContains($direction));
			}
		}

		$this->controller->move_prefix($prefix_id, $direction);
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
	return \phpbb\topicprefixes\controller\admin_controller_test::$confirm;
}

/**
 * Mock check_form_key()
 * Note: use the same namespace as the admin_controller
 *
 * @return bool
 */
function check_form_key()
{
	return \phpbb\topicprefixes\controller\admin_controller_test::$valid_form;
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
