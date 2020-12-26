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

class manager_update_prefix_test extends manager_base
{
	/**
	 * Data for test_update_prefix
	 *
	 * @return array
	 */
	public function data_update_prefix()
	{
		return array(
			array(1, array('prefix_enabled' => 0), 1),
			array(2, array('prefix_enabled' => 1), 1),
			array(9, array('prefix_enabled' => 0), 0),
		);
	}

	/**
	 * Test the update_prefix() method
	 *
	 * @dataProvider data_update_prefix
	 * @param $id
	 * @param $data
	 * @param $expected
	 */
	public function test_update_prefix($id, $data, $expected)
	{
		self::assertEquals($expected, $this->manager->update_prefix($id, $data));
	}

	/**
	 * Test the update_prefix() method
	 */
	public function test_update_prefix_fails()
	{
		$this->expectException(\OutOfBoundsException::class);
		$this->expectExceptionMessage('TOPIC_PREFIXES_INVALID_ITEM');
		$this->manager->update_prefix(0, array());
	}
}
