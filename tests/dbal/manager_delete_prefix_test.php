<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2016 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\tests\dbal;

class manager_delete_prefix_test extends manager_base
{
	/**
	 * Test delete_prefix() method
	 */
	public function test_delete_prefix()
	{
		self::assertEquals(array(1), $this->manager->delete_prefix(1));
	}

	/**
	 * Data for test_delete_prefix_fails
	 *
	 * @return array
	 */
	public function data_delete_prefix_fails()
	{
		return array(
			array(0),
			array(10),
		);
	}

	/**
	 * Test delete_prefix() method
	 *
	 * @dataProvider data_delete_prefix_fails
	 * @param $id
	 */
	public function test_delete_prefix_fails($id)
	{
		$this->expectException(\OutOfBoundsException::class);
		$this->expectExceptionMessage('TOPIC_PREFIXES_INVALID_ITEM');
		$this->manager->delete_prefix($id);
	}
}
