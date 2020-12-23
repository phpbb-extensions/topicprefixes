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
			$this->manager->expects(self::never())
				->method('get_prefix');
			$this->manager->expects(self::never())
				->method('delete_prefix');
			$this->controller->expects(self::never())
				->method('log');
		}
		else if ($prefix_id === 0)
		{
			$this->manager->expects(self::once())
				->method('get_prefix')
				->with($prefix_id)
				->willReturn(false);
			$this->manager->expects(self::once())
				->method('delete_prefix')
				->will(self::throwException(new \OutOfBoundsException()));
			$this->controller->expects(self::never())
				->method('log');
			// Throws E_WARNING in PHP 8.0+ and E_USER_WARNING in earlier versions
			$exceptionName = PHP_VERSION_ID < 80000 ? \PHPUnit\Framework\Error\Error::class : \PHPUnit\Framework\Error\Warning::class;
			$errno = PHP_VERSION_ID < 80000 ? E_USER_WARNING : E_WARNING;
			$this->expectException($exceptionName);
			$this->expectExceptionCode($errno);
		}
		else
		{
			$this->manager->expects(self::once())
				->method('get_prefix')
				->with($prefix_id)
				->willReturn(['prefix_id' => $prefix_id, 'prefix_tag' => 'topic_prefix']);
			$this->manager->expects(self::once())
				->method('delete_prefix');
			$this->controller->expects(self::once())
				->method('log');
			// Throws E_WARNING in PHP 8.0+ and E_USER_WARNING in earlier versions
			$exceptionName = PHP_VERSION_ID < 80000 ? \PHPUnit\Framework\Error\Error::class : \PHPUnit\Framework\Error\Warning::class;
			$errno = PHP_VERSION_ID < 80000 ? E_USER_NOTICE : E_WARNING;
			$this->expectException($exceptionName);
			$this->expectExceptionCode($errno);
		}

		$this->controller->delete_prefix($prefix_id);
	}
}
