<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2016 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\tests\prefixes;

class manager_test extends \phpbb_test_case
{
	/**
	 * @var \phpbb\topicprefixes\prefixes\manager
	 */
	protected $manager;

	/**
	 * @var \phpbb\topicprefixes\prefixes\nestedset_prefixes|\PHPUnit_Framework_MockObject_MockObject
	 */
	protected $nestedset;

	/**
	 * @inheritdoc
	 */
	public function setUp()
	{
		parent::setUp();

		$this->nestedset = $this->getMockBuilder('\phpbb\topicprefixes\prefixes\nestedset_prefixes')
			->disableOriginalConstructor()
			->getMock();

		$this->manager = new \phpbb\topicprefixes\prefixes\manager($this->nestedset);
	}

	/**
	 * Generate data sets for testing
	 *
	 * @param int $set The data set to generate
	 * @return array An array of simulated prefix data
	 */
	public function import_data($set)
	{
		$fields = [
			'prefix_id',
			'prefix_tag',
			'prefix_enabled',
			'forum_id',
			'prefix_parent_id',
			'prefix_left_id',
			'prefix_right_id'
		];

		$data_sets = array(
			1 => [1, 'PARENT 1', 1, '', 0, 1, 2],

			2 => [2, 'PARENT 2', 1, '', 0, 3, 10],
			3 => [3, 'PREFIX 2.1', 1, '["2"]', 2, 4, 5],
			4 => [4, 'PREFIX 2.2', 1, '["2","3"]', 2, 6, 7],
			5 => [5, 'PREFIX 2.3', 1, '["3"]', 2, 8, 9],

			6 => [6, 'PARENT 3', 1, '', 0, 11, 14],
			7 => [7, 'PREFIX 3.1', 1, '["2"]', 6, 12, 13],

			8 => [8, 'PARENT 4', 1, '', 0, 15, 18],
			9 => [9, 'PREFIX 4.1', 0, '["2"]', 8, 16, 17],

			10 => [10, 'PARENT 5', 1, '', 0, 19, 22],
			11 => [11, 'PREFIX 5.1', 1, '["3"]', 10, 20, 21],

			12 => [12, 'PARENT 6', 1, '', 0, 23, 24],
			13 => [13, 'PARENT 7', 1, '', 0, 25, 26],
		);

		return array_combine($fields, $data_sets[$set]);
	}

	/**
	 * Data set for get_prefixes_test
	 *
	 * @return array
	 */
	public function get_prefixes_test_data()
	{
		return array(
			array( // no parent id or prefix data
				0,
				array(),
			),
			array( // no prefix data
				2,
				array(),
			),
			array( // prefixes by parent id
				2,
				array(
					2 => $this->import_data(2),
					3 => $this->import_data(3),
					4 => $this->import_data(4),
					5 => $this->import_data(5),
				),
			),
			array( // all prefixes
				0,
				array(
					1 => $this->import_data(1),
					2 => $this->import_data(2),
					3 => $this->import_data(3),
					4 => $this->import_data(4),
					5 => $this->import_data(5),
					6 => $this->import_data(6),
					7 => $this->import_data(7),
					8 => $this->import_data(8),
					9 => $this->import_data(9),
					10 => $this->import_data(10),
					11 => $this->import_data(11),
					12 => $this->import_data(12),
					13 => $this->import_data(13),
				),
			),
		);
	}

	/**
	 * Test the get_prefixes() method
	 *
	 * @param int $parent_id
	 * @param array $datas_set
	 *
	 * @dataProvider get_prefixes_test_data
	 */
	public function test_get_prefixes($parent_id, $datas_set)
	{
		$this->nestedset->expects($this->any())
			->method('get_all_tree_data')
			->will($this->returnValue($datas_set));
		$this->nestedset->expects($this->any())
			->method('get_subtree_data')
			->will($this->returnValue($datas_set));

		$this->assertSame($datas_set, $this->manager->get_prefixes($parent_id));
	}

	/**
	 * Data set for get_active_prefixes_test
	 *
	 * @return array
	 */
	public function get_active_prefixes_test_data()
	{
		return array(
			array( // no forum id or prefix data
				   0,
				   array(),
				   array(),
			),
			array( // no prefix data
				   2,
				   array(),
				   array(),
			),
			array( // prefix parent only (no children)
				   2,
				   array(
					   2 => $this->import_data(2),
				   ),
				   array(),
			),
			array( // prefixes with parents and children
				   2,
				   array(
					   1 => $this->import_data(1), // parent (with no children) - ignore
					   2 => $this->import_data(2), // parent (with children)
					   3 => $this->import_data(3), // enabled in current forum
					   4 => $this->import_data(4), // enabled in current forum
					   5 => $this->import_data(5), // enabled in another forum - ignore
					   6 => $this->import_data(6), // parent (with children)
					   7 => $this->import_data(7), // enabled in current forum
					   8 => $this->import_data(8), // parent (with children) - ignore
					   9 => $this->import_data(9), // disabled in current forum - ignore
					   10 => $this->import_data(10), // parent (with children) - ignore
					   11 => $this->import_data(11), // enabled in another forum - ignore
					   12 => $this->import_data(12), // parent (with no children) - ignore
					   13 => $this->import_data(13), // parent (with no children) - ignore
				   ),
				   array(
					   2 => $this->import_data(2),
					   3 => $this->import_data(3),
					   4 => $this->import_data(4),
					   6 => $this->import_data(6),
					   7 => $this->import_data(7),
				   ),
			),
		);
	}

	/**
	 * Test the get_active_prefixes() method
	 *
	 * @param int $forum_id
	 * @param array $data_set
	 * @param array $expected
	 *
	 * @dataProvider get_active_prefixes_test_data
	 */
	public function test_get_active_prefixes($forum_id, $data_set, $expected)
	{
		$this->nestedset->expects($this->any())
			->method('get_all_tree_data')
			->will($this->returnValue($data_set));

		$this->assertSame($expected, $this->manager->get_active_prefixes($forum_id));
	}

	/**
	 * Data for test_is_enabled
	 *
	 * @return array
	 */
	public function is_enabled_test_data()
	{
		return array(
			array($this->import_data(2), true),
			array($this->import_data(9), false),
		);
	}

	/**
	 * Test the is_enabled() method
	 *
	 * @param array $prefix_data
	 * @param bool  $expected
	 *
	 * @dataProvider is_enabled_test_data
	 */
	public function test_is_enabled($prefix_data, $expected)
	{
		$this->assertEquals($expected, $this->manager->is_enabled($prefix_data));
	}

	/**
	 * Data for test_is_parent
	 *
	 * @return array
	 */
	public function is_parent_test_data()
	{
		return array(
			array($this->import_data(2), true),
			array($this->import_data(3), false),
		);
	}

	/**
	 * Test the is_parent() method
	 *
	 * @param array $prefix_data
	 * @param bool  $expected
	 *
	 * @dataProvider is_parent_test_data
	 */
	public function test_is_parent($prefix_data, $expected)
	{
		$this->assertEquals($expected, $this->manager->is_parent($prefix_data));
	}
}
