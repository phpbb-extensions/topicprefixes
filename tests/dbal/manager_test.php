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

use Symfony\Component\DependencyInjection\Exception\OutOfBoundsException;

/**
 * Class manager_test
 */
class manager_test extends \phpbb_database_test_case
{
	/**
	 * @var \phpbb\db\driver\driver_interface
	 */
	protected $db;

	/**
	 * @var \phpbb\topicprefixes\prefixes\manager
	 */
	protected $manager;

	/**
	 * @inheritdoc
	 */
	static protected function setup_extensions()
	{
		return ['phpbb/topicprefixes'];
	}

	/**
	 * @inheritdoc
	 */
	public function getDataSet()
	{
		return $this->createXMLDataSet(__DIR__ . '/fixtures/topic_prefixes.xml');
	}

	/**
	 * @inheritdoc
	 */
	public function setUp()
	{
		parent::setUp();

		$this->db = $this->new_dbal();

		$config = new \phpbb\config\config(array('topicprefixes.table_lock.topic_prefixes_table' => 0));
		$lock = new \phpbb\lock\db('topicprefixes.table_lock.topic_prefixes_table', $config, $this->db);

		$this->manager = new \phpbb\topicprefixes\prefixes\manager(
			new \phpbb\topicprefixes\prefixes\nestedset_prefixes(
				$this->db,
				$lock,
				'phpbb_topic_prefixes'
			)
		);
	}

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

		$this->assertEquals($expected, array_column($prefixes, 'prefix_tag'));
	}

	/**
	 * Data for test_get_active_prefixes
	 *
	 * @return array
	 */
	public function data_get_active_prefixes()
	{
		return array(
			array(null, array('[TAG 1]', '[TAG 2]', '[TAG 3]')),
			array('', array('[TAG 1]', '[TAG 2]', '[TAG 3]')),
			array(0, array('[TAG 1]', '[TAG 2]', '[TAG 3]')),
			array(2, array('[TAG 1]', '[TAG 2]')),
			array(3, array('[TAG 3]')),
			array(9, array()),
		);
	}

	/**
	 * Test the get_active_prefixes() method
	 *
	 * @dataProvider data_get_active_prefixes
	 * @param $forum_id
	 * @param $expected
	 */
	public function test_get_active_prefixes($forum_id, $expected)
	{
		$prefixes = $this->manager->get_active_prefixes($forum_id);

		$this->assertEquals($expected, array_column($prefixes, 'prefix_tag'));
	}

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
		$this->assertEquals($expected, $result['prefix_id']);
	}

	/**
	 * Test delete_prefix() method
	 */
	public function test_delete_prefix()
	{
		$this->assertEquals(array(1), $this->manager->delete_prefix(1));
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
	 * @expectedException OutOfBoundsException
	 */
	public function test_delete_prefix_fails($id)
	{
		$this->manager->delete_prefix($id);
	}

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
		$this->assertEquals($expected, $this->manager->update_prefix($id, $data));
	}

	/**
	 * Test the update_prefix() method
	 *
	 * @expectedException OutOfBoundsException
	 */
	public function test_update_prefix_fails()
	{
		$this->manager->update_prefix(0, array());
	}

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

		$this->assertEquals($expected, $this->db->sql_fetchrowset($result));
		$this->db->sql_freeresult($result);
	}

	/**
	 * Test move_prefix() method
	 *
	 * @expectedException OutOfBoundsException
	 */
	public function test_move_prefix_fails()
	{
		$this->manager->move_prefix(123, 'up');
	}

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
		$this->assertEquals($expected, $this->manager->prepend_prefix($prefix, $subject));
	}
}
