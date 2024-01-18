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
			array(1, true, false), // valid prefix, valid form/hash, not ajax
			array(1, true, true), // valid prefix, valid form/hash, valid ajax
			array(1, false, false), // valid prefix, invalid form/hash, not ajax
			array(0, true, false), // invalid prefix, valid form/hash, not ajax
		);
	}

	/**
	 * Test edit_prefix() method
	 *
	 * @dataProvider data_edit_prefix
	 * @param $prefix_id
	 * @param $valid_form
	 */
	public function test_edit_prefix($prefix_id, $valid_form, $is_ajax)
	{
		$this->request->expects(self::once())
			->method('variable')
			->with(self::anything())
			->willReturnMap(array(
				array('hash', '', false, \phpbb\request\request_interface::REQUEST, generate_link_hash(($valid_form ? 'edit' : '') . $prefix_id))
			));

		if (!$valid_form)
		{
			$this->manager->expects(self::never())
				->method('get_prefix');
			$this->manager->expects(self::never())
				->method('update_prefix');
			$this->setExpectedTriggerError(E_USER_WARNING, $this->language->lang('FORM_INVALID'));
		}
		else if ($prefix_id === 0)
		{
			$this->manager->expects(self::once())
				->method('get_prefix')
				->with($prefix_id)
				->willReturn(false);
			$this->manager->expects(self::once())
				->method('update_prefix')
				->will(self::throwException(new \OutOfBoundsException));
			$this->setExpectedTriggerError(E_USER_WARNING);
		}
		else
		{
			$this->manager->expects(self::once())
				->method('get_prefix')
				->with($prefix_id)
				->willReturn(['prefix_id' => $prefix_id, 'prefix_enabled' => true]);
			$this->manager->expects(self::once())
				->method('update_prefix');

			$this->request->expects(self::atMost(1))
				->method('is_ajax')
				->willReturn($is_ajax);

			if ($is_ajax)
			{
				// Handle trigger_error() output called from json_response
				$this->setExpectedTriggerError(E_WARNING);
			}
		}

		$this->controller->edit_prefix($prefix_id);
	}
}
