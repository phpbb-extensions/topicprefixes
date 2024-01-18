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

class move_prefix_test extends admin_controller_base
{
	/**
	 * Data for test_move_prefix
	 *
	 * @return array
	 */
	public function data_move_prefix()
	{
		return array(
			// prefix id, direction, valid form/hash, is ajax
			array(1, 'up', true, false),
			array(1, 'down', true, false),
			array(2, 'up', true, false),
			array(2, 'down', true, false),
			array(3, 'up', true, true),
			array(3, 'down', true, true),
			array(1, 'up', false, false),
			array(0, 'up', true, false),
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
	public function test_move_prefix($prefix_id, $direction, $valid_form, $is_ajax)
	{
		$this->request->expects(static::once())
			->method('variable')
			->with(static::anything())
			->willReturnMap(array(
				array('hash', '', false, \phpbb\request\request_interface::REQUEST, generate_link_hash($direction . $prefix_id))
			));

		if (!$valid_form)
		{
			$prefix_id = 0;
			$this->setExpectedTriggerError(E_USER_WARNING, 'The submitted form was invalid. Try submitting again.');
			$this->manager->expects(static::never())
				->method('move_prefix');
		}
		else if ($prefix_id === 0)
		{
			$this->setExpectedTriggerError(E_USER_WARNING);
			$this->manager->expects(static::once())
				->method('move_prefix')
				->with(static::equalTo(0), static::stringContains($direction))
				->will(static::throwException(new \OutOfBoundsException));
		}
		else
		{
			$this->request->expects(static::once())
				->method('is_ajax')
				->willReturn($is_ajax);

			if ($is_ajax)
			{
				// Handle trigger_error() output called from json_response
				$this->setExpectedTriggerError(E_WARNING);
			}

			$this->manager->expects(static::once())
				->method('move_prefix')
				->with(static::equalTo($prefix_id), static::stringContains($direction));
		}

		$this->controller->move_prefix($prefix_id, $direction);
	}
}
