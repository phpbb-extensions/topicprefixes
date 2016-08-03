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

class delete_prefix_test extends admin_controller_base
{
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
}
