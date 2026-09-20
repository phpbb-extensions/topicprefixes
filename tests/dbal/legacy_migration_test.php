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

require_once __DIR__ . '/tags_base.php';

class legacy_migration_test extends tags_base
{
	/** @var \phpbb\db\tools\tools_interface */
	protected $tools;

	/** @var int */
	protected $moved_topic_id;

	/** @var array */
	protected $post_ids = [];

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
		$this->db->sql_query("UPDATE phpbb_topics SET topic_title = 'Bug Both tags', topic_prefix_id = 1 WHERE topic_id = 10");
		$this->db->sql_query("UPDATE phpbb_topics SET topic_title = '[Other] untouched', topic_prefix_id = 1 WHERE topic_id = 11");
		$this->db->sql_query("UPDATE phpbb_topics SET topic_title = 'PHP 8.4 PHP only', topic_prefix_id = 2 WHERE topic_id = 12");
		$this->db->sql_query("UPDATE phpbb_topics SET topic_title = '[Random] No tags', topic_prefix_id = 0 WHERE topic_id = 13");
		$this->db->sql_query('INSERT INTO phpbb_topics ' . $this->db->sql_build_array('INSERT', array(
			'forum_id' => 2,
			'topic_title' => 'Bug Moved topic',
			'topic_prefix_id' => 1,
			'topic_moved_id' => 10,
			'topic_visibility' => ITEM_APPROVED,
			'topic_type' => POST_NORMAL,
		)));
		$this->moved_topic_id = (int) $this->db->sql_nextid();

		foreach (array(
			array('both', 10, 'Bug Both tags'),
			array('unrelated', 11, 'Unrelated first post'),
			array('php', 12, 'PHP 8.4 PHP only'),
			array('random', 13, '[Random] No tags'),
			array('reply', 10, 'Bug reply subject'),
			array('moved', $this->moved_topic_id, 'Bug Moved topic'),
		) as $post)
		{
			$sql = 'INSERT INTO phpbb_posts ' . $this->db->sql_build_array('INSERT', array(
				'topic_id' => $post[1],
				'forum_id' => 2,
				'post_subject' => $post[2],
				'post_text' => '',
			));
			$this->db->sql_query($sql);
			$this->post_ids[$post[0]] = (int) $this->db->sql_nextid();
		}

		foreach (array(
			10 => $this->post_ids['both'],
			11 => $this->post_ids['unrelated'],
			12 => $this->post_ids['php'],
			13 => $this->post_ids['random'],
			$this->moved_topic_id => $this->post_ids['moved'],
		) as $topic_id => $post_id)
		{
			$this->db->sql_query('UPDATE phpbb_topics
				SET topic_first_post_id = ' . $post_id . '
				WHERE topic_id = ' . $topic_id);
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
		self::assertSame('Both tags', $this->field('SELECT post_subject FROM phpbb_posts WHERE post_id = ' . $this->post_ids['both'], 'post_subject'));
		self::assertSame('PHP only', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 12', 'topic_title'));
		self::assertSame('PHP only', $this->field('SELECT post_subject FROM phpbb_posts WHERE post_id = ' . $this->post_ids['php'], 'post_subject'));
		self::assertSame('[Other] untouched', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 11', 'topic_title'));
		self::assertSame('Unrelated first post', $this->field('SELECT post_subject FROM phpbb_posts WHERE post_id = ' . $this->post_ids['unrelated'], 'post_subject'));
		self::assertSame('[Random] No tags', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = 13', 'topic_title'));
		self::assertSame('Bug reply subject', $this->field('SELECT post_subject FROM phpbb_posts WHERE post_id = ' . $this->post_ids['reply'], 'post_subject'));
		self::assertSame('Moved topic', $this->field('SELECT topic_title FROM phpbb_topics WHERE topic_id = ' . $this->moved_topic_id, 'topic_title'));
		self::assertSame('Moved topic', $this->field('SELECT post_subject FROM phpbb_posts WHERE post_id = ' . $this->post_ids['moved'], 'post_subject'));
		self::assertSame(0, (int) $this->field('SELECT COUNT(*) AS total FROM phpbb_topic_prefixes_topics WHERE topic_id = ' . $this->moved_topic_id, 'total'));
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
