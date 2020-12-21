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
 * Class simple_test
 */
class simple_test extends \phpbb_database_test_case
{
	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/**
	 * @inheritdoc
	 */
	protected static function setup_extensions()
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
	 * Test that the table exists in the database
	 */
	public function test_table_exists()
	{
		$this->db = $this->new_dbal();
		$factory = new \phpbb\db\tools\factory();
		$db_tools = $factory->get($this->db);

		self::assertTrue($db_tools->sql_column_exists('phpbb_topics', 'topic_prefix_id'));
		self::assertTrue($db_tools->sql_table_exists('phpbb_topic_prefixes'));
	}
}
