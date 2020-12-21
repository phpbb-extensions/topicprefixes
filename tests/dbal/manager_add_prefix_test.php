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

class manager_add_prefix_test extends manager_base
{
	/**
	 * Data for test_add_prefix
	 *
	 * @return array
	 */
	public function data_add_prefix()
	{
		return array(
			array('[TEST 1]', 2, 6),
			array('[TEST 2]', 3, 6),
			array('0', 2, 6),
			array('', 2, false),
		);
	}

	/**
	 * Test the add_prefix() method
	 *
	 * @dataProvider data_add_prefix
	 * @param $tag
	 * @param $forum_id
	 * @param $expected
	 */
	public function test_add_prefix($tag, $forum_id, $expected)
	{
		$result = $this->manager->add_prefix($tag, $forum_id);
		self::assertEquals($expected, $result['prefix_id']);
	}
}
