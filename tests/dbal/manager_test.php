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

		$this->manager = new \phpbb\topicprefixes\prefixes\manager($this->db, 'phpbb_topic_prefixes');
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
}
