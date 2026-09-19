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

require_once __DIR__ . '/tags_base.php';

class legacy_migration_test extends tags_base
{
	/** @var \phpbb\db\tools\tools_interface */
	protected $tools;

	protected function setUp(): void
	{
		parent::setUp();
		$factory = new \phpbb\db\tools\factory();
		$this->tools = $factory->get($this->db);
		$this->tools->sql_column_add('phpbb_topics', 'topic_prefix_id', array('UINT', 0));
		$this->tools->sql_column_add('phpbb_topic_prefixes', 'prefix_parent_id', array('UINT', 0));
		$this->tools->sql_column_add('phpbb_topic_prefixes', 'prefix_left_id', array('UINT', 0));
		$this->tools->sql_column_add('phpbb_topic_prefixes', 'prefix_right_id', array('UINT', 0));
		$this->tools->sql_column_add('phpbb_topic_prefixes', 'prefix_parents', array('MTEXT_UNI', ''));
		$this->tools->sql_column_add('phpbb_topic_prefixes', 'forum_id', array('UINT', 0));

		$this->db->sql_query("UPDATE phpbb_topic_prefixes SET prefix_left_id = prefix_id * 2 - 1, prefix_right_id = prefix_id * 2, forum_id = 2");
		$this->db->sql_query('DELETE FROM phpbb_topic_prefixes_forums WHERE prefix_id = 4');
		$this->db->sql_query("UPDATE phpbb_topics SET topic_title = 'Bug Both tags', topic_prefix_id = 1, topic_first_post_id = 100 WHERE topic_id = 10");
		$this->db->sql_query("UPDATE phpbb_topics SET topic_title = '[Other] untouched', topic_prefix_id = 1, topic_first_post_id = 101 WHERE topic_id = 11");
		$this->db->sql_query("UPDATE phpbb_topics SET topic_title = 'PHP 8.4 PHP only', topic_prefix_id = 2, topic_first_post_id = 102 WHERE topic_id = 12");
		$this->db->sql_query("UPDATE phpbb_topics SET topic_title = '[Random] No tags', topic_prefix_id = 0, topic_first_post_id = 103 WHERE topic_id = 13");
		foreach (array(
			array(100, 10, 'Bug Both tags'),
			array(101, 11, 'Unrelated first post'),
			array(102, 12, 'PHP 8.4 PHP only'),
			array(103, 13, '[Random] No tags'),
			array(104, 10, 'Bug reply subject'),
		) as $post)
		{
			$sql = 'INSERT INTO phpbb_posts ' . $this->db->sql_build_array('INSERT', array(
				'post_id' => $post[0],
				'topic_id' => $post[1],
				'forum_id' => 2,
				'post_subject' => $post[2],
				'post_text' => '',
			));
			$this->db->sql_query($sql);
		}
	}

	protected function tearDown(): void
	{
		$this->tools->sql_column_remove('phpbb_topics', 'topic_prefix_id');
		foreach (array('prefix_parent_id', 'prefix_left_id', 'prefix_right_id', 'prefix_parents', 'forum_id') as $column)
		{
			$this->tools->sql_column_remove('phpbb_topic_prefixes', $column);
		}
		parent::tearDown();
	}

	public function test_legacy_definitions_titles_subjects_and_idempotency()
	{
		$migration = $this->create_migration();
		$migration->migrate_legacy_data();
		$migration->migrate_legacy_data();

		self::assertSame('4A76A8', $this->field('SELECT prefix_color FROM phpbb_topic_prefixes WHERE prefix_id = 1', 'prefix_color'));
		self::assertSame(1, (int) $this->field('SELECT prefix_order FROM phpbb_topic_prefixes WHERE prefix_id = 1', 'prefix_order'));
		self::assertSame('Both tags', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 10', 'topic_title'));
		self::assertSame('Both tags', $this->field('SELECT post_subject FROM phpbb_posts WHERE post_id = 100', 'post_subject'));
		self::assertSame('PHP only', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 12', 'topic_title'));
		self::assertSame('PHP only', $this->field('SELECT post_subject FROM phpbb_posts WHERE post_id = 102', 'post_subject'));
		self::assertSame('[Other] untouched', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 11', 'topic_title'));
		self::assertSame('Unrelated first post', $this->field('SELECT post_subject FROM phpbb_posts WHERE post_id = 101', 'post_subject'));
		self::assertSame('[Random] No tags', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 13', 'topic_title'));
		self::assertSame('Bug reply subject', $this->field('SELECT post_subject FROM phpbb_posts WHERE post_id = 104', 'post_subject'));
		self::assertSame(1, (int) $this->field('SELECT COUNT(*) AS total FROM phpbb_topic_prefixes_forums WHERE forum_id = 2 AND prefix_id = 4', 'total'));

		$result = $this->db->sql_query('SELECT COUNT(*) AS total FROM phpbb_topic_prefixes_topics WHERE topic_id = 10 AND prefix_id = 1');
		self::assertSame(1, (int) $this->db->sql_fetchfield('total'));
		$this->db->sql_freeresult($result);
	}

	protected function create_migration()
	{
		global $phpbb_root_path, $phpEx;
		return new \phpbb\topicprefixes\migrations\v200_data(
			new \phpbb\config\config(array()),
			$this->db,
			$this->tools,
			$phpbb_root_path,
			$phpEx,
			'phpbb_'
		);
	}

	protected function field($sql, $field)
	{
		$result = $this->db->sql_query($sql);
		$value = $this->db->sql_fetchfield($field);
		$this->db->sql_freeresult($result);
		return $value;
	}
}
