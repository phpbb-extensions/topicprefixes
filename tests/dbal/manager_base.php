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

class manager_base extends \phpbb_database_test_case
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
	 * @inheritdoc
	 */
	protected function setUp(): void
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
}
