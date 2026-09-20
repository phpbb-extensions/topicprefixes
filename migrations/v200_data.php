<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2016 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\migrations;

/**
 * Convert legacy title prefixes to relational topic tags.
 */
class v200_data extends \phpbb\db\migration\migration
{
	const DEFAULT_COLOR = '4A76A8';
	const BATCH_SIZE = 500;
	const UPDATE_CASE_BATCH_SIZE = 10;

	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return isset($this->config['topicprefixes_tags_migrated']);
	}

	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return ['\phpbb\topicprefixes\migrations\v200_schema'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return [
			['custom', [[$this, 'migrate_legacy_data']]],
			['config.add', ['topicprefixes_tags_migrated', 1]],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function revert_data()
	{
		return [
			['config.remove', ['topicprefixes_tags_migrated']],
		];
	}

	/**
	 * Migrate legacy definitions, forum availability, and topic assignments.
	 *
	 * Topic rows are processed in bounded transactions. An interrupted run can
	 * safely restart because existing relationships and already-clean titles are
	 * detected before writes.
	 *
	 * @return void
	 */
	public function migrate_legacy_data(): void
	{
		$tables = [
			'tags' => $this->table_prefix . 'topic_prefixes',
			'forums' => $this->table_prefix . 'topic_prefixes_forums',
			'topic_tags' => $this->table_prefix . 'topic_prefixes_topics',
			'topics' => $this->table_prefix . 'topics',
			'posts' => $this->table_prefix . 'posts',
		];

		$this->migrate_tag_definitions($tables['tags']);
		$this->migrate_forum_availability($tables['tags'], $tables['forums']);

		$last_topic_id = 0;
		do
		{
			$topics = $this->get_legacy_topics($tables, $last_topic_id);
			if (!$topics)
			{
				break;
			}

			$last_topic_id = (int) end($topics)['topic_id'];
			$this->migrate_topic_batch($tables, $topics);
		}
		while (count($topics) === self::BATCH_SIZE);
	}

	/**
	 * Add default presentation fields to legacy tag definitions.
	 *
	 * @param string $tags_table Tag definition table
	 * @return void
	 */
	protected function migrate_tag_definitions(string $tags_table): void
	{
		$sql = 'UPDATE ' . $tags_table . "
			SET prefix_color = '" . self::DEFAULT_COLOR . "',
				prefix_order = prefix_left_id";
		$this->db->sql_query($sql);
	}

	/**
	 * Copy legacy single-forum values into forum/tag relationships.
	 *
	 * @param string $tags_table   Tag definition table
	 * @param string $forums_table Forum/tag map table
	 * @return void
	 */
	protected function migrate_forum_availability(string $tags_table, string $forums_table): void
	{
		$sql = 'SELECT p.forum_id, p.prefix_id
			FROM ' . $tags_table . ' p
			LEFT JOIN ' . $forums_table . ' pf
				ON pf.forum_id = p.forum_id
				AND pf.prefix_id = p.prefix_id
			WHERE p.forum_id <> 0
				AND pf.prefix_id IS NULL';
		$result = $this->db->sql_query($sql);
		$rows = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = [
				'forum_id' => (int) $row['forum_id'],
				'prefix_id' => (int) $row['prefix_id'],
			];
		}
		$this->db->sql_freeresult($result);

		if ($rows)
		{
			$this->db->sql_multi_insert($forums_table, $rows);
		}
	}

	/**
	 * Load one bounded set of legacy topics and first-post subjects.
	 *
	 * @param array $tables        Migration table names
	 * @param int   $last_topic_id Last processed topic identifier
	 * @return array Legacy topic rows
	 */
	protected function get_legacy_topics(array $tables, int $last_topic_id): array
	{
		$sql = 'SELECT t.topic_id, t.topic_title, t.topic_first_post_id, t.topic_moved_id,
				t.topic_prefix_id, p.prefix_tag, fp.post_subject
			FROM ' . $tables['topics'] . ' t
			INNER JOIN ' . $tables['tags'] . ' p
				ON p.prefix_id = t.topic_prefix_id
			LEFT JOIN ' . $tables['posts'] . ' fp
				ON fp.post_id = t.topic_first_post_id
			WHERE t.topic_prefix_id <> 0
				AND t.topic_id > ' . $last_topic_id . '
			ORDER BY t.topic_id ASC';
		$result = $this->db->sql_query_limit($sql, self::BATCH_SIZE);
		$topics = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$topics[] = $row;
		}
		$this->db->sql_freeresult($result);

		return $topics;
	}

	/**
	 * Migrate one topic batch in a transaction.
	 *
	 * @param array $tables Migration table names
	 * @param array $topics Legacy topic rows
	 * @return void
	 */
	protected function migrate_topic_batch(array $tables, array $topics): void
	{
		$topic_ids = array_map('intval', array_column($topics, 'topic_id'));
		$existing = $this->get_existing_assignments($tables['topic_tags'], $topic_ids);
		$assignments = [];
		$topic_titles = [];
		$post_subjects = [];

		foreach ($topics as $topic)
		{
			$topic_id = (int) $topic['topic_id'];
			$tag_id = (int) $topic['topic_prefix_id'];
			if (empty($topic['topic_moved_id']) && empty($existing[$topic_id][$tag_id]))
			{
				$assignments[] = ['topic_id' => $topic_id, 'prefix_id' => $tag_id];
			}

			$legacy_text = $topic['prefix_tag'] . ' ';
			if ($legacy_text === ' ')
			{
				continue;
			}

			if (strpos($topic['topic_title'], $legacy_text) === 0)
			{
				$topic_titles[$topic_id] = substr($topic['topic_title'], strlen($legacy_text));
			}

			$post_id = (int) $topic['topic_first_post_id'];
			if ($post_id && $topic['post_subject'] !== null && strpos($topic['post_subject'], $legacy_text) === 0)
			{
				$post_subjects[$post_id] = substr($topic['post_subject'], strlen($legacy_text));
			}
		}

		$this->db->sql_transaction('begin');
		if ($assignments)
		{
			$this->db->sql_multi_insert($tables['topic_tags'], $assignments);
		}
		$this->bulk_update_text($tables['topics'], 'topic_id', 'topic_title', $topic_titles);
		$this->bulk_update_text($tables['posts'], 'post_id', 'post_subject', $post_subjects);
		$this->db->sql_transaction('commit');
	}

	/**
	 * Load existing topic/tag assignments for one migration batch.
	 *
	 * @param string $topic_tags_table Topic/tag map table
	 * @param array  $topic_ids        Topic identifiers
	 * @return array Existing relationship lookup
	 */
	protected function get_existing_assignments(string $topic_tags_table, array $topic_ids): array
	{
		$sql = 'SELECT topic_id, prefix_id
			FROM ' . $topic_tags_table . '
			WHERE ' . $this->db->sql_in_set('topic_id', $topic_ids);
		$result = $this->db->sql_query($sql);
		$existing = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$existing[(int) $row['topic_id']][(int) $row['prefix_id']] = true;
		}
		$this->db->sql_freeresult($result);

		return $existing;
	}

	/**
	 * Update distinct text values with portable, bounded conditional queries.
	 *
	 * @param string $table        Table name
	 * @param string $id_column    Integer primary key column
	 * @param string $value_column Text column
	 * @param array  $changes      New values keyed by identifier
	 * @return void
	 */
	protected function bulk_update_text(string $table, string $id_column, string $value_column, array $changes): void
	{
		if (!$changes)
		{
			return;
		}

		foreach (array_chunk($changes, self::UPDATE_CASE_BATCH_SIZE, true) as $batch)
		{
			$value_sql = $value_column;
			foreach (array_reverse($batch, true) as $id => $value)
			{
				$value_sql = $this->db->sql_case(
					$id_column . ' = ' . (int) $id,
					"'" . $this->db->sql_escape($value) . "'",
					$value_sql
				);
			}

			$sql = 'UPDATE ' . $table . '
				SET ' . $value_column . ' = ' . $value_sql . '
				WHERE ' . $this->db->sql_in_set($id_column, array_keys($batch));
			$this->db->sql_query($sql);
		}
	}
}
