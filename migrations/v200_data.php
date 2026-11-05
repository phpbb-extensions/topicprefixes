<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\migrations;

use phpbb\topicprefixes\tags\manager as tag_manager;

/**
 * Convert legacy title prefixes to relational topic tags.
 */
class v200_data extends \phpbb\db\migration\migration
{
	protected const DEFAULT_COLOR = '4A76A8';
	protected const BATCH_SIZE = 500;

	// sql_case() produces nested CASE expressions; SQL Server supports at most 10 levels.
	protected const UPDATE_CASE_BATCH_SIZE = 10;

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
	 * Topic rows are processed in bounded transactions. Committed topics clear
	 * their legacy IDs, so retries never strip legitimate title text twice.
	 *
	 * @param array|int|null $state Last committed topic cursor, or initial migrator state
	 * @return array|bool Continuation state, or true when complete
	 * @throws \Throwable When a database transaction fails
	 */
	public function migrate_legacy_data($state = null)
	{
		$tables = [
			'topic_prefixes' => $this->table_prefix . 'topic_prefixes',
			'topic_prefixes_forums' => $this->table_prefix . 'topic_prefixes_forums',
			'topic_prefixes_topics' => $this->table_prefix . 'topic_prefixes_topics',
			'forums' => $this->table_prefix . 'forums',
			'topics' => $this->table_prefix . 'topics',
			'posts' => $this->table_prefix . 'posts',
		];

		$valid_forum_ids = $this->get_valid_forum_ids($tables['forums']);
		$this->migrate_tag_definitions($tables['topic_prefixes']);
		$this->migrate_forum_availability($tables['topic_prefixes'], $tables['topic_prefixes_forums'], $valid_forum_ids);
		[$tag_map, $replaced_source_ids] = $this->prepare_legacy_tags($tables, $valid_forum_ids);

		$last_topic_id = (int) ($state['last_topic_id'] ?? 0);
		$topics = $tag_map ? $this->get_legacy_topics($tables, $last_topic_id, array_keys($tag_map)) : [];
		if ($topics)
		{
			$this->migrate_topic_batch($tables, $topics, $tag_map);
			if (count($topics) === self::BATCH_SIZE)
			{
				return ['last_topic_id' => (int) end($topics)['topic_id']];
			}
		}
		$this->remove_replaced_sources($tables, $replaced_source_ids);
		return true;
	}

	/**
	 * Load existing forum identifiers for relationship validation.
	 *
	 * @param string $forums_table Forums table
	 * @return array Forum identifier lookup
	 */
	protected function get_valid_forum_ids(string $forums_table): array
	{
		$result = $this->db->sql_query('SELECT forum_id FROM ' . $forums_table);
		$forum_ids = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$forum_ids[(int) $row['forum_id']] = true;
		}
		$this->db->sql_freeresult($result);

		return $forum_ids;
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
				prefix_order = prefix_left_id
			WHERE prefix_order = 0";
		$this->db->sql_query($sql);
	}

	/**
	 * Prepare all legacy prefixes as tags, consolidating equivalent standalone
	 * definitions and splitting complete multi-bracket sequences when detected.
	 * Other prefix formats retain their names unchanged.
	 *
	 * @param array $tables          Migration table names
	 * @param array $valid_forum_ids Existing forum identifier lookup
	 * @return array Source-to-target map and replaced source identifiers
	 */
	protected function prepare_legacy_tags(array $tables, array $valid_forum_ids): array
	{
		$sql = 'SELECT prefix_id, prefix_tag, prefix_enabled, prefix_order, forum_id,
				prefix_left_id, prefix_right_id
			FROM ' . $tables['topic_prefixes'] . '
			ORDER BY prefix_order ASC, prefix_id ASC';
		$result = $this->db->sql_query($sql);
		$sources = [];
		$known_tags = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			// Split targets created by an interrupted attempt have no legacy
			// nested-set position. Keep them available for reuse, but never treat
			// their identifiers as legacy topic references on retry.
			if (!empty($row['prefix_left_id']) || !empty($row['prefix_right_id']))
			{
				$sources[] = $row;
			}
			$key = $this->tag_identity($row['prefix_tag'], (int) $row['prefix_enabled']);
			if (!isset($known_tags[$key]))
			{
				$known_tags[$key] = [
					'prefix_id' => (int) $row['prefix_id'],
				];
			}
		}
		$this->db->sql_freeresult($result);

		$sql = 'SELECT forum_id, prefix_id FROM ' . $tables['topic_prefixes_forums'];
		$result = $this->db->sql_query($sql);
		$forum_keys = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$forum_keys[(int) $row['forum_id'] . ':' . (int) $row['prefix_id']] = true;
		}
		$this->db->sql_freeresult($result);

		$tag_map = [];
		$replaced_source_ids = [];
		$forum_rows = [];
		foreach ($sources as $source)
		{
			$source_id = (int) $source['prefix_id'];
			$names = $this->split_legacy_tag($source['prefix_tag']);
			if (!$names)
			{
				$key = $this->tag_identity($source['prefix_tag'], (int) $source['prefix_enabled']);
				$target_ids = [$known_tags[$key]['prefix_id']];
				if ($target_ids[0] !== $source_id)
				{
					$replaced_source_ids[] = $source_id;
				}
			}
			else
			{
				$replaced_source_ids[] = $source_id;
				$target_ids = [];
				foreach (array_unique($names) as $name)
				{
					$key = $this->tag_identity($name, (int) $source['prefix_enabled']);
					if (isset($known_tags[$key]))
					{
						$target_id = $known_tags[$key]['prefix_id'];
					}
					else
					{
						$sql = 'INSERT INTO ' . $tables['topic_prefixes'] . '
							(prefix_tag, prefix_color, prefix_enabled, prefix_order, prefix_parent_id,
								prefix_left_id, prefix_right_id, prefix_parents, forum_id)
							VALUES (' . $this->sql_text_literal($name) . ", '" . self::DEFAULT_COLOR . "', " .
							(int) !empty($source['prefix_enabled']) . ', ' . (int) $source['prefix_order'] . ", 0, 0, 0, '', 0)";
						$this->db->sql_query($sql);
						$target_id = (int) $this->db->sql_last_inserted_id();
						$known_tags[$key] = ['prefix_id' => $target_id];
					}

					$target_ids[] = $target_id;
				}
			}

			$tag_map[$source_id] = array_values(array_unique($target_ids));
			foreach ($tag_map[$source_id] as $target_id)
			{
				$forum_key = (int) $source['forum_id'] . ':' . $target_id;
				if (isset($valid_forum_ids[(int) $source['forum_id']]) && !isset($forum_keys[$forum_key]))
				{
					$forum_rows[] = [
						'forum_id' => (int) $source['forum_id'],
						'prefix_id' => $target_id,
					];
					$forum_keys[$forum_key] = true;
				}
			}
		}

		if ($forum_rows)
		{
			$this->db->sql_multi_insert($tables['topic_prefixes_forums'], $forum_rows);
		}

		return [$tag_map, array_values(array_unique($replaced_source_ids))];
	}

	/**
	 * Build a case-sensitive identity for tags with equivalent behavior.
	 *
	 * @param string $stored_name Database-safe tag name
	 * @param int    $enabled     Enabled state
	 * @return string Tag identity
	 */
	protected function tag_identity(string $stored_name, int $enabled): string
	{
		return base64_encode(tag_manager::decode_name($stored_name)) . ':' . (int) !empty($enabled);
	}

	/**
	 * Split a complete, uninterrupted sequence containing at least two
	 * bracketed labels. Brackets remain part of each resulting tag name.
	 *
	 * Mixed, empty, or invalid values remain unchanged.
	 *
	 * @param string $stored_name Database-safe legacy tag name
	 * @return array Split tag names, or an empty array when unchanged
	 */
	protected function split_legacy_tag(string $stored_name): array
	{
		$name = tag_manager::decode_name($stored_name);
		if (!preg_match('/\A(?:\[[^\[\]]+\])+\z/u', $name))
		{
			return [];
		}

		preg_match_all('/\[([^\[\]]+)\]/u', $name, $matches, PREG_SET_ORDER);
		if (count($matches) < 2)
		{
			return [];
		}

		$names = [];
		foreach ($matches as $match)
		{
			$normalized = tag_manager::normalize_name($match[0]);
			if ($normalized === '' || trim($match[1]) === '')
			{
				return [];
			}
			$names[] = $normalized;
		}

		return $names;
	}

	/**
	 * Remove obsolete split and duplicate source definitions after all topics are mapped.
	 *
	 * @param array $tables     Migration table names
	 * @param array $source_ids Replaced legacy tag identifiers
	 * @return void
	 */
	protected function remove_replaced_sources(array $tables, array $source_ids): void
	{
		if (!$source_ids)
		{
			return;
		}

		$this->db->sql_transaction('begin');
		foreach (['topic_prefixes_forums', 'topic_prefixes_topics', 'topic_prefixes'] as $table)
		{
			$this->db->sql_query('DELETE FROM ' . $tables[$table] . '
				WHERE ' . $this->db->sql_in_set('prefix_id', $source_ids));
		}
		$this->db->sql_transaction('commit');
	}

	/**
	 * Copy legacy single-forum values into forum/tag relationships.
	 *
	 * @param string $tags_table      Tag definition table
	 * @param string $forums_table    Forum/tag map table
	 * @param array  $valid_forum_ids Existing forum identifier lookup
	 * @return void
	 */
	protected function migrate_forum_availability(string $tags_table, string $forums_table, array $valid_forum_ids): void
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
			if (!isset($valid_forum_ids[(int) $row['forum_id']]))
			{
				continue;
			}
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
	 * @param array $tables            Migration table names
	 * @param int   $last_topic_id     Last processed topic identifier
	 * @param array $legacy_prefix_ids Prefix identifiers present before conversion
	 * @return array Legacy topic rows
	 */
	protected function get_legacy_topics(array $tables, int $last_topic_id, array $legacy_prefix_ids): array
	{
		$sql = 'SELECT t.topic_id, t.topic_title, t.topic_first_post_id, t.topic_last_post_id,
				t.topic_last_post_subject, t.topic_moved_id, t.topic_prefix_id, p.prefix_tag,
				fp.post_subject
			FROM ' . $tables['topics'] . ' t
			INNER JOIN ' . $tables['topic_prefixes'] . ' p
				ON p.prefix_id = t.topic_prefix_id
			LEFT JOIN ' . $tables['posts'] . ' fp
				ON fp.post_id = t.topic_first_post_id AND fp.topic_id = t.topic_id
			WHERE t.topic_prefix_id <> 0
				AND ' . $this->db->sql_in_set('t.topic_prefix_id', $legacy_prefix_ids) . '
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
	 * @param array $tables  Migration table names
	 * @param array $topics  Legacy topic rows
	 * @param array $tag_map Legacy-to-relational tag map
	 * @return void
	 * @throws \Throwable When the database transaction fails
	 */
	protected function migrate_topic_batch(array $tables, array $topics, array $tag_map): void
	{
		$this->db->sql_transaction('begin');
		try
		{
			$topic_ids = array_map('intval', array_column($topics, 'topic_id'));
			$existing = $this->get_existing_assignments($tables['topic_prefixes_topics'], $topic_ids);
			$assignments = [];
			$topic_titles = [];
			$topic_last_post_subjects = [];
			$post_subjects = [];

			foreach ($topics as $topic)
			{
				$topic_id = (int) $topic['topic_id'];
				$source_id = (int) $topic['topic_prefix_id'];
				if (empty($topic['topic_moved_id']))
				{
					foreach ($tag_map[$source_id] as $tag_id)
					{
						if (empty($existing[$topic_id][$tag_id]))
						{
							$assignments[] = ['topic_id' => $topic_id, 'prefix_id' => $tag_id];
							$existing[$topic_id][$tag_id] = true;
						}
					}
				}

				$legacy_text = $topic['prefix_tag'] . ' ';
				if ($legacy_text === ' ')
				{
					$legacy_text = '';
				}

				$clean_title = $this->strip_legacy_text($topic['topic_title'], $legacy_text);
				if ($clean_title !== $topic['topic_title'])
				{
					$topic_titles[$topic_id] = $clean_title;
				}
				if ($topic['topic_last_post_subject'] !== null)
				{
					$clean_last_subject = $this->strip_legacy_text($topic['topic_last_post_subject'], $legacy_text);
					if ($clean_last_subject !== $topic['topic_last_post_subject'])
					{
						$topic_last_post_subjects[$topic_id] = $clean_last_subject;
					}
				}

				$post_id = (int) $topic['topic_first_post_id'];
				if (empty($topic['topic_moved_id']) && $post_id && $topic['post_subject'] !== null)
				{
					$clean_subject = $this->strip_legacy_text($topic['post_subject'], $legacy_text);
					if ($clean_subject !== $topic['post_subject'])
					{
						$post_subjects[$post_id] = $clean_subject;
					}
				}
			}

			if ($assignments)
			{
				$this->db->sql_multi_insert($tables['topic_prefixes_topics'], $assignments);
			}
			$this->bulk_update_text($tables['topics'], 'topic_id', 'topic_title', $topic_titles);
			$this->bulk_update_text($tables['topics'], 'topic_id', 'topic_last_post_subject', $topic_last_post_subjects);
			$this->bulk_update_text($tables['posts'], 'post_id', 'post_subject', $post_subjects);
			$this->bulk_update_text($tables['forums'], 'forum_id', 'forum_last_post_subject', $this->get_forum_last_post_subject_changes($tables['forums'], $topics));
			$this->db->sql_query('UPDATE ' . $tables['topics'] . ' SET topic_prefix_id = 0 WHERE ' . $this->db->sql_in_set('topic_id', $topic_ids));
			$this->db->sql_transaction('commit');
		}
		catch (\Throwable $e)
		{
			$this->db->sql_transaction('rollback');
			throw $e;
		}
	}

	/**
	 * Remove one exact leading prefix without creating an empty title.
	 *
	 * @param string $text   Stored title or subject
	 * @param string $prefix Exact legacy prefix, including its separator
	 * @return string Cleaned text, or the original text when unmatched
	 */
	protected function strip_legacy_text(string $text, string $prefix): string
	{
		if ($prefix === '' || strpos($text, $prefix) !== 0)
		{
			return $text;
		}
		// An exact byte match makes the prefix length a safe UTF-8 boundary.
		$after = substr($text, strlen($prefix));
		return trim($after) === '' ? $text : $after;
	}

	/**
	 * Build forum last-post subject changes without duplicating paginated topic rows.
	 *
	 * @param string $forums_table Forums table
	 * @param array  $topics       Legacy topic rows
	 * @return array New subjects keyed by forum identifier
	 */
	protected function get_forum_last_post_subject_changes(string $forums_table, array $topics): array
	{
		$prefixes_by_post = [];
		foreach ($topics as $topic)
		{
			$post_id = (int) $topic['topic_last_post_id'];
			$legacy_text = $topic['prefix_tag'] . ' ';
			if ($post_id && $legacy_text !== ' ')
			{
				$prefixes_by_post[$post_id][$legacy_text] = true;
			}
		}

		if (!$prefixes_by_post)
		{
			return [];
		}

		$sql = 'SELECT forum_id, forum_last_post_id, forum_last_post_subject
			FROM ' . $forums_table . '
			WHERE ' . $this->db->sql_in_set('forum_last_post_id', array_keys($prefixes_by_post));
		$result = $this->db->sql_query($sql);
		$changes = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			if ($row['forum_last_post_subject'] === null)
			{
				continue;
			}

			$prefixes = array_keys($prefixes_by_post[(int) $row['forum_last_post_id']]);
			usort($prefixes, static function ($left, $right) {
				return strlen($right) <=> strlen($left);
			});
			foreach ($prefixes as $legacy_text)
			{
				if (strpos($row['forum_last_post_subject'], $legacy_text) === 0)
				{
					$changes[(int) $row['forum_id']] = $this->strip_legacy_text($row['forum_last_post_subject'], $legacy_text);
					break;
				}
			}
		}
		$this->db->sql_freeresult($result);

		return $changes;
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
					$this->sql_text_literal($value),
					$value_sql
				);
			}

			$sql = 'UPDATE ' . $table . '
				SET ' . $value_column . ' = ' . $value_sql . '
				WHERE ' . $this->db->sql_in_set($id_column, array_keys($batch));
			$this->db->sql_query($sql);
		}
	}

	/**
	 * Quote text for use as an SQL literal.
	 *
	 * SQL Server requires the N prefix to preserve Unicode text when a literal
	 * is assigned to a nvarchar column. Other supported DBMS use the standard
	 * quoted form generated throughout phpBB's DBAL.
	 *
	 * @param string $value Text value
	 * @return string Quoted SQL literal
	 */
	protected function sql_text_literal(string $value): string
	{
		$unicode_prefix = strpos($this->db->get_sql_layer(), 'mssql') === 0 ? 'N' : '';

		return $unicode_prefix . "'" . $this->db->sql_escape($value) . "'";
	}
}
