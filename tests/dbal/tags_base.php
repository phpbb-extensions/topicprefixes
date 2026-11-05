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

	/** @var \phpbb_mock_cache */
	protected $cache;

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
		$this->cache = new \phpbb_mock_cache();
	}

	protected function create_tag_manager()
	{
		return new \phpbb\topicprefixes\tags\manager(
			$this->db,
			'phpbb_topic_prefixes',
			'phpbb_topic_prefixes_forums',
			'phpbb_topic_prefixes_topics',
			'phpbb_forums',
			$this->cache
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

	/**
	 * Insert rows containing explicit auto-increment identifiers.
	 *
	 * SQL Server requires IDENTITY_INSERT around fixture rows that name an
	 * identity column. Other test databases accept the rows directly.
	 *
	 * @param string $table Table name
	 * @param array  $rows  Rows to insert
	 * @return void
	 */
	protected function insert_explicit_rows(string $table, array $rows): void
	{
		if (!$rows)
		{
			return;
		}

		$mssql = strpos($this->db->get_sql_layer(), 'mssql') === 0;
		if ($mssql)
		{
			$this->db->sql_query('SET IDENTITY_INSERT ' . $table . ' ON');
		}

		try
		{
			if ($mssql)
			{
				foreach ($rows as $row)
				{
					$values = [];
					foreach ($row as $value)
					{
						$values[] = is_string($value)
							? "N'" . $this->db->sql_escape($value) . "'"
							: ($value === null ? 'NULL' : (string) (is_bool($value) ? (int) $value : $value));
					}
					$this->db->sql_query('INSERT INTO ' . $table . '
						(' . implode(', ', array_keys($row)) . ')
						VALUES (' . implode(', ', $values) . ')');
				}
			}
			else
			{
				$this->db->sql_multi_insert($table, $rows);
			}
		}
		finally
		{
			if ($mssql)
			{
				$this->db->sql_query('SET IDENTITY_INSERT ' . $table . ' OFF');
			}
		}
	}
}
