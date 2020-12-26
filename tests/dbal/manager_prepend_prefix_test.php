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

class manager_prepend_prefix_test extends manager_base
{
	/**
	 * Data for test_prepend_prefix
	 *
	 * @return array
	 */
	public function data_prepend_prefix()
	{
		return array(
			array('[FOO]', 'Test subject', '[FOO] Test subject'),
			array('[FOO][BAR]', 'Test subject', '[FOO][BAR] Test subject'),
			array('', 'Test subject', 'Test subject'),
			array(0, 'Test subject', 'Test subject'),
			array(null, 'Test subject', 'Test subject'),
			array('', '', ''),
			array('[FOO]', '', '[FOO] '),
		);
	}

	/**
	 * Test the prepend_prefix() method
	 *
	 * @dataProvider data_prepend_prefix
	 * @param $prefix
	 * @param $subject
	 * @param $expected
	 */
	public function test_prepend_prefix($prefix, $subject, $expected)
	{
		self::assertEquals($expected, $this->manager->prepend_prefix($prefix, $subject));
	}
}
