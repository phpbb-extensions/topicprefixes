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

class edit_prefix_test extends admin_controller_base
{
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
		$this->request->expects(static::once())
			->method('variable')
			->with(static::anything())
			->willReturnMap(array(
				array('hash', '', false, \phpbb\request\request_interface::REQUEST, generate_link_hash('edit' . $prefix_id))
			));

		if (!$valid_form)
		{
			$prefix_id = 0;
			$this->manager->expects(static::never())
				->method('update_prefix');
			$this->setExpectedTriggerError(E_USER_WARNING);
		}
		else
		{
			$this->manager->expects(static::once())
				->method('update_prefix')
				->with(static::equalTo(0))
				->will(static::throwException(new \OutOfBoundsException));
			$this->setExpectedTriggerError(E_USER_WARNING);
		}

		$this->controller->edit_prefix($prefix_id);
	}
}
