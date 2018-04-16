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

class manager_get_prefix_test extends manager_base
{
	/**
	 * Data for test_get_prefix
	 *
	 * @return array
	 */
	public function data_get_prefix()
	{
		return array(
			array(0, false),
			array(10, false),
			array('', false),
			array(null, false),
			array(1, '[TAG 1]'),
			array(2, '[TAG 2]'),
			array(3, '[TAG 3]'),
			array(4, '[TAG 4]'),
			array(5, '[TAG 5]'),
		);
	}

	/**
	 * Test the get_prefix() method
	 *
	 * @dataProvider data_get_prefix
	 * @param $prefix_id
	 * @param $expected
	 */
	public function test_get_prefix($prefix_id, $expected)
	{
		$prefix = $this->manager->get_prefix($prefix_id);

		$this->assertEquals($expected, $prefix['prefix_tag'], 'Assert get_prefix() gets the expected prefix data');
	}
}
