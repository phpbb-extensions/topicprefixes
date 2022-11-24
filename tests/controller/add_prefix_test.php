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
				->willReturn($submit);

			if (!$valid_form)
			{
				// Throws E_WARNING in PHP 8.0+ and E_USER_WARNING in earlier versions
				$exceptionName = version_compare(PHP_VERSION, '8.0', '<') ? \PHPUnit\Framework\Error\Error::class : \PHPUnit\Framework\Error\Warning::class;
				$errno = version_compare(PHP_VERSION, '8.0', '<') ? E_USER_WARNING : E_WARNING;
				$this->expectException($exceptionName);
				$this->expectExceptionCode($errno);
			}
			else
			{
				$this->manager->expects(static::once())
					->method('add_prefix')
					->willReturn(['prefix_tag' => 'topic_prefix']);
				$this->log->expects(static::once())
					->method('add')
					->with('admin', static::anything(), static::anything(), 'ACP_LOG_PREFIX_ADDED', static::anything(), ['topic_prefix', 'Test Forum']);
				$this->db->expects(static::once())
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
