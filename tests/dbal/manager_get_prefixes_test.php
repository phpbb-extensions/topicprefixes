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

class manager_get_prefixes_test extends manager_base
{
	/**
	 * Data for test_get_prefixes
	 *
	 * @return array
	 */
	public function data_get_prefixes()
	{
		return array(
			array(null, array('[TAG 1]', '[TAG 2]', '[TAG 3]', '[TAG 4]', '[TAG 5]')),
			array('', array('[TAG 1]', '[TAG 2]', '[TAG 3]', '[TAG 4]', '[TAG 5]')),
			array(0, array('[TAG 1]', '[TAG 2]', '[TAG 3]', '[TAG 4]', '[TAG 5]')),
			array(2, array('[TAG 1]', '[TAG 2]', '[TAG 4]')),
			array(3, array('[TAG 3]', '[TAG 5]')),
			array(9, array()),
		);
	}

	/**
	 * Test the get_prefixes() method
	 *
	 * @dataProvider data_get_prefixes
	 * @param $forum_id
	 * @param $expected
	 */
	public function test_get_prefixes($forum_id, $expected)
	{
		$prefixes = $this->manager->get_prefixes($forum_id);

		self::assertEquals($expected, array_column($prefixes, 'prefix_tag'));
	}
}
