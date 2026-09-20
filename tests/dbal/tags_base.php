<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\tests\dbal;

abstract class tags_base extends \phpbb_database_test_case
{
	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	protected static function setup_extensions()
	{
		return array('phpbb/topicprefixes');
	}

	public function getDataSet()
	{
		return $this->createXMLDataSet(__DIR__ . '/fixtures/topic_tags.xml');
	}

	protected function setUp(): void
	{
		parent::setUp();
		$this->db = $this->new_dbal();
	}

	protected function create_tag_manager()
	{
		return new \phpbb\topicprefixes\tags\manager(
			$this->db,
			'phpbb_topic_prefixes',
			'phpbb_topic_prefixes_forums',
			'phpbb_topic_prefixes_topics',
			'phpbb_forums'
		);
	}

	protected function create_assignment_manager()
	{
		return new \phpbb\topicprefixes\tags\assignment_manager(
			$this->db,
			'phpbb_topic_prefixes_topics',
			'phpbb_topic_prefixes',
			'phpbb_topics'
		);
	}
}
