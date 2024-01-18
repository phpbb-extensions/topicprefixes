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
		return [
			['', true, true],
			['topic_prefix1', true, true],
			['topic_prefix2', true, false],
			['topic_prefix3', false, false],
		];
	}

	/**
	 * Test add_prefix()
	 *
	 * @dataProvider data_add_prefix
	 * @param $prefix
	 * @param $submit
	 * @param $valid_form
	 */
	public function test_add_prefix($prefix, $submit, $valid_form)
	{
		if ($submit)
		{
			self::$valid_form = $valid_form;

			$this->request->expects(static::once())
				->method('is_set_post')
				->willReturn($submit);

			if (!$valid_form)
			{
				$this->setExpectedTriggerError(E_USER_WARNING, 'The submitted form was invalid. Try submitting again.');
			}
			else
			{
				$valid_prefix = $prefix !== '';
				$this->request->expects(static::once())
					->method('variable')
					->willReturnMap([
						['prefix_tag', '', true, \phpbb\request\request_interface::REQUEST, $prefix],
					]);
				$this->manager->expects(static::once())
					->method('add_prefix')
					->willReturn($valid_prefix ? ['prefix_tag' => $prefix] : false);
				$this->log->expects($valid_prefix ? static::once() : static::never())
					->method('add')
					->with('admin', static::anything(), static::anything(), 'ACP_LOG_PREFIX_ADDED', static::anything(), [$prefix, 'Test Forum']);
				$this->db->expects($valid_prefix ? static::once() : static::never())
					->method('sql_fetchrow')
					->willReturn(['forum_name' => 'Test Forum']);
			}
		}
		else
		{
			$this->manager->expects(static::never())
				->method('add_prefix');
			$this->log->expects(static::never())
				->method('add');
		}

		$this->controller->add_prefix();
	}
}
