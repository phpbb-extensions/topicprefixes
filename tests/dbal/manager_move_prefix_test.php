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

class manager_move_prefix_test extends manager_base
{
	/**
	 * Data for test_move_prefix
	 *
	 * @return array
	 */
	public function data_move_prefix()
	{
		return array(
			array(
				1,
				'up', // Move item 1 up (not expected to move)
				array(
					array('prefix_id' => 1, 'prefix_left_id' => 1),
					array('prefix_id' => 2, 'prefix_left_id' => 3),
					array('prefix_id' => 3, 'prefix_left_id' => 5),
					array('prefix_id' => 4, 'prefix_left_id' => 7),
					array('prefix_id' => 5, 'prefix_left_id' => 9),
				),
			),
			array(
				1,
				'down', // Move item 1 down
				array(
					array('prefix_id' => 2, 'prefix_left_id' => 1),
					array('prefix_id' => 1, 'prefix_left_id' => 3),
					array('prefix_id' => 3, 'prefix_left_id' => 5),
					array('prefix_id' => 4, 'prefix_left_id' => 7),
					array('prefix_id' => 5, 'prefix_left_id' => 9),
				),
			),
			array(
				3,
				'up', // Move item 3 up
				array(
					array('prefix_id' => 1, 'prefix_left_id' => 1),
					array('prefix_id' => 3, 'prefix_left_id' => 3),
					array('prefix_id' => 2, 'prefix_left_id' => 5),
					array('prefix_id' => 4, 'prefix_left_id' => 7),
					array('prefix_id' => 5, 'prefix_left_id' => 9),
				),
			),
			array(
				3,
				'down', // Move item 3 down
				array(
					array('prefix_id' => 1, 'prefix_left_id' => 1),
					array('prefix_id' => 2, 'prefix_left_id' => 3),
					array('prefix_id' => 4, 'prefix_left_id' => 5),
					array('prefix_id' => 3, 'prefix_left_id' => 7),
					array('prefix_id' => 5, 'prefix_left_id' => 9),
				),
			),
			array(
				5,
				'up', // Move item 5 up
				array(
					array('prefix_id' => 1, 'prefix_left_id' => 1),
					array('prefix_id' => 2, 'prefix_left_id' => 3),
					array('prefix_id' => 3, 'prefix_left_id' => 5),
					array('prefix_id' => 5, 'prefix_left_id' => 7),
					array('prefix_id' => 4, 'prefix_left_id' => 9),
				),
			),
			array(
				5,
				'down', // Move item 5 down (not expected to move)
				array(
					array('prefix_id' => 1, 'prefix_left_id' => 1),
					array('prefix_id' => 2, 'prefix_left_id' => 3),
					array('prefix_id' => 3, 'prefix_left_id' => 5),
					array('prefix_id' => 4, 'prefix_left_id' => 7),
					array('prefix_id' => 5, 'prefix_left_id' => 9),
				),
			),
		);
	}

	/**
	 * Test move_prefix() method
	 *
	 * @dataProvider data_move_prefix
	 * @param $id
	 * @param $direction
	 * @param $expected
	 */
	public function test_move_prefix($id, $direction, $expected)
	{
		$this->manager->move_prefix($id, $direction);

		$result = $this->db->sql_query('SELECT prefix_id, prefix_left_id
			FROM phpbb_topic_prefixes
			ORDER BY prefix_left_id ASC');

		self::assertEquals($expected, $this->db->sql_fetchrowset($result));
		$this->db->sql_freeresult($result);
	}

	/**
	 * Test move_prefix() method
	 */
	public function test_move_prefix_fails()
	{
		$this->expectException(\OutOfBoundsException::class);
		$this->expectExceptionMessage('TOPIC_PREFIXES_INVALID_ITEM');
		$this->manager->move_prefix(123, 'up');
	}
}
