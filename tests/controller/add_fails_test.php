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

class add_fails_test extends admin_controller_base
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

	public function test_add_fails($input)
		{
			self::$valid_form = $valid_form;

			$this->request->expects(static::once())
				->method('is_set_post')
				->willReturn(true);
			$this->manager->expects(static::once())
				->method('add_prefix')
				->willReturn(['prefix_tag' => $input]);
			$this->log->expects($input ? static::once() : static::never())
				->method('add');
			$this->db->expects($input ? static::once() : static::never())
				->method('sql_fetchrow');

			if ($input == '')
			{
				$exceptionName = version_compare(PHP_VERSION, '8.0', '<') ? \PHPUnit\Framework\Error\Error::class : \PHPUnit\Framework\Error\Warning::class;
				$errno = version_compare(PHP_VERSION, '8.0', '<') ? E_USER_WARNING : E_WARNING;
				$this->expectExceptionMessage('TOPIC_PREFIXES_INPUT_EMPTY');
				$this->expectException($exceptionName);
				$this->expectExceptionCode($errno);
			}

			$this->controller->add_prefix();
		}
