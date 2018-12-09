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

class add_prefix_test extends admin_controller_base
{
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

			$this->request->expects(static::once())
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
}
