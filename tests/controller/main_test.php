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

/**
 * We need to manually include the base file because auto loading won't work since the base
 * uses the same namespace as the controller being tested (to override global scope functions).
 */
require_once __DIR__ . '/admin_controller_base.php';

class main_test extends admin_controller_base
{
	protected function setUp(): void
	{
		global $phpbb_root_path, $phpEx;

		parent::setUp();

		$this->controller = $this->getMockBuilder('\phpbb\topicprefixes\controller\admin_controller')
			->onlyMethods(array('add_prefix', 'edit_prefix', 'delete_prefix', 'move_prefix', 'display_settings'))
			->setConstructorArgs(array(
				$this->manager,
				$this->language,
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
	 * Data for test_main
	 *
	 * @return array
	 */
	public function data_main()
	{
		return array(
			array('add', 'add_prefix'),
			array('edit', 'edit_prefix'),
			array('delete', 'delete_prefix'),
			array('move_up', 'move_prefix'),
			array('move_down', 'move_prefix'),
		);
	}

	/**
	 * Test main()
	 *
	 * @dataProvider data_main
	 * @param $action
	 * @param $expected
	 */
	public function test_main($action, $expected)
	{
		$this->request->expects(static::any())
			->method('variable')
			->willReturnMap(array(
				array('action', '', false, \phpbb\request\request_interface::REQUEST, $action),
			));

		$this->controller->expects(static::once())
			->method($expected);

		$this->controller->main();
	}
}
