<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\tags;

use phpbb\cache\driver\driver_interface as cache;
use phpbb\db\driver\driver_interface;

/**
 * Safely split or merge topic tags.
 */
class repairer
{
	protected const BATCH_SIZE = 500;

	/** @var driver_interface Database connection */
	protected $db;

	/** @var cache Cache driver */
	protected $cache;

	/** @var string Tag definition table */
	protected $tags_table;

	/** @var string Forum/tag map table */
	protected $forums_map_table;

	/** @var string Topic/tag map table */
	protected $topic_map_table;

	/** @var string Topics table */
	protected $topics_table;

	/**
	 * Constructor.
	 *
	 * @param driver_interface $db               Database connection
	 * @param cache            $cache            Cache driver
	 * @param string           $tags_table       Tag definition table
	 * @param string           $forums_map_table Forum/tag map table
	 * @param string           $topic_map_table  Topic/tag map table
	 * @param string           $topics_table     Topics table
	 */
	public function __construct(
		driver_interface $db,
		cache $cache,
		string $tags_table,
		string $forums_map_table,
		string $topic_map_table,
		string $topics_table
	)
	{
		$this->db = $db;
		$this->cache = $cache;
		$this->tags_table = $tags_table;
		$this->forums_map_table = $forums_map_table;
		$this->topic_map_table = $topic_map_table;
		$this->topics_table = $topics_table;
	}

	/**
	 * Describe one source tag without changing data.
	 *
	 * @param int $source_id Source tag identifier
	 * @return array Source definition and relationship counts
	 * @throws \InvalidArgumentException When the source tag does not exist
	 */
	public function inspect(int $source_id): array
	{
		$source = $this->get_source($source_id);

		return [
			'source' => $source,
			'forum_count' => $this->count_relationships($this->forums_map_table, $source_id),
			'topic_count' => $this->count_relationships($this->topic_map_table, $source_id),
		];
	}

	/**
	 * Validate replacement names and preview exact changes.
	 *
	 * @param int   $source_id   Source tag identifier
	 * @param array $replacements Display-form replacement names
	 * @return array Validated repair preview
	 * @throws \InvalidArgumentException When the source or replacement data is invalid
	 */
	public function preview(int $source_id, array $replacements): array
	{
		$source = $this->get_source($source_id);
		$targets = $this->resolve_targets($source, $replacements);
		return $this->build_preview($source, $targets);
	}

	/**
	 * Preview merging one source tag into one existing target.
	 *
	 * @param int $source_id Source tag identifier
	 * @param int $target_id Target tag identifier
	 * @return array Validated merge preview
	 * @throws \InvalidArgumentException When either tag is missing or the source is the target
	 */
	public function preview_merge(int $source_id, int $target_id): array
	{
		$source = $this->get_source($source_id);
		if ($source_id === $target_id)
		{
			throw new \InvalidArgumentException('CLI_TOPIC_TAGS_REPAIR_SOURCE_TARGET');
		}

		$target = $this->get_tag($target_id, 'CLI_TOPIC_TAGS_REPAIR_MERGE_TARGET_NOT_FOUND');
		$target['existing'] = true;
		return $this->build_preview($source, [$target]);
	}

	/**
	 * Build a preview for resolved target definitions.
	 *
	 * @param array $source  Source tag definition
	 * @param array $targets Resolved target definitions
	 * @return array Repair preview and relationship counts
	 */
	protected function build_preview(array $source, array $targets): array
	{
		return [
			'source' => $source,
			'targets' => $targets,
			'forum_count' => count($this->get_source_forums($source['prefix_id'])),
			'topic_count' => $this->count_topics($source['prefix_id']),
		];
	}

	/**
	 * Apply one restart-safe repair.
	 *
	 * Target definitions are committed before topic batches. The source remains
	 * until every batch succeeds, so rerunning the same repair after interruption
	 * safely reuses targets, skips existing relationships, and leaves all text untouched.
	 *
	 * @param int $source_id Source tag identifier
	 * @param array $replacements Display-form replacement names
	 * @return array Applied change counts and targets
	 * @throws \Exception When validation or a database transaction fails
	 */
	public function repair(int $source_id, array $replacements): array
	{
		return $this->apply_preview($this->preview($source_id, $replacements));
	}

	/**
	 * Merge one source tag into one existing target.
	 *
	 * @param int $source_id Source tag identifier
	 * @param int $target_id Target tag identifier
	 * @return array Applied change counts and targets
	 * @throws \InvalidArgumentException When either tag is missing or the source is the target
	 * @throws \Exception When a database transaction fails
	 */
	public function merge(int $source_id, int $target_id): array
	{
		return $this->apply_preview($this->preview_merge($source_id, $target_id));
	}

	/**
	 * Apply one validated preview.
	 *
	 * @param array $preview Validated repair preview
	 * @return array Applied change counts and targets
	 * @throws \Exception When a database transaction fails
	 */
	protected function apply_preview(array $preview): array
	{
		$source = $preview['source'];
		$source_id = (int) $source['prefix_id'];
		$targets = $this->create_or_update_targets($source, $preview['targets']);
		$target_ids = array_column($targets, 'prefix_id');
		$topic_count = 0;

		$last_topic_id = 0;
		do
		{
			$topics = $this->get_topic_batch($source_id, $last_topic_id);
			if (!$topics)
			{
				break;
			}

			$last_topic_id = (int) end($topics)['topic_id'];
			$topic_count += $this->repair_topic_batch($target_ids, $topics);
		}
		while (count($topics) === self::BATCH_SIZE);

		$this->db->sql_transaction('begin');
		try
		{
			$this->db->sql_query('DELETE FROM ' . $this->topic_map_table . ' WHERE prefix_id = ' . $source_id);
			$this->db->sql_query('DELETE FROM ' . $this->forums_map_table . ' WHERE prefix_id = ' . $source_id);
			$this->db->sql_query('DELETE FROM ' . $this->tags_table . ' WHERE prefix_id = ' . $source_id);
			$this->db->sql_transaction('commit');
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			$this->invalidate_cache();
			throw $e;
		}
		$this->invalidate_cache();

		return [
			'source' => $source,
			'targets' => $targets,
			'topic_count' => $topic_count,
		];
	}

	/**
	 * Load and normalize one source definition.
	 *
	 * @param int $source_id Source tag identifier
	 * @return array Normalized source definition
	 * @throws \InvalidArgumentException When source does not exist
	 */
	protected function get_source(int $source_id): array
	{
		return $this->get_tag($source_id, 'CLI_TOPIC_TAGS_REPAIR_TAG_NOT_FOUND');
	}

	/**
	 * Load and normalize one tag definition.
	 *
	 * @param int    $tag_id      Tag identifier
	 * @param string $missing_key Language key used when the tag is missing
	 * @return array Normalized tag definition
	 * @throws \InvalidArgumentException When the tag does not exist
	 */
	protected function get_tag(int $tag_id, string $missing_key): array
	{
		$sql = 'SELECT prefix_id, prefix_tag, prefix_color, prefix_enabled, prefix_order
			FROM ' . $this->tags_table . '
			WHERE prefix_id = ' . (int) $tag_id;
		$result = $this->db->sql_query($sql);
		$tag = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$tag)
		{
			throw new \InvalidArgumentException($missing_key);
		}

		$tag['prefix_id'] = (int) $tag['prefix_id'];
		$tag['prefix_enabled'] = (int) $tag['prefix_enabled'];
		$tag['prefix_order'] = (int) $tag['prefix_order'];
		$tag['prefix_tag'] = manager::decode_name($tag['prefix_tag']);

		return $tag;
	}

	/**
	 * Resolve replacement names against current definitions.
	 *
	 * @param array $source       Source tag definition
	 * @param array $replacements Display-form replacement names
	 * @return array Resolved target definitions
	 * @throws \InvalidArgumentException For invalid or unsafe names
	 */
	protected function resolve_targets(array $source, array $replacements): array
	{
		if (!$replacements)
		{
			throw new \InvalidArgumentException('CLI_TOPIC_TAGS_REPAIR_NEW_TAG_REQUIRED');
		}

		$result = $this->db->sql_query('SELECT prefix_id, prefix_tag, prefix_enabled
			FROM ' . $this->tags_table . '
			ORDER BY prefix_id ASC');
		$known = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$key = base64_encode(manager::decode_name($row['prefix_tag']));
			if (!isset($known[$key]))
			{
				$known[$key] = [
					'prefix_id' => (int) $row['prefix_id'],
					'prefix_enabled' => (int) $row['prefix_enabled'],
				];
			}
		}
		$this->db->sql_freeresult($result);

		$targets = [];
		$seen = [];
		foreach ($replacements as $replacement)
		{
			$stored_name = manager::normalize_name((string) $replacement);
			if ($stored_name === '')
			{
				throw new \InvalidArgumentException('CLI_TOPIC_TAGS_REPAIR_INVALID_TAG');
			}

			$name = manager::decode_name($stored_name);
			$key = base64_encode($name);
			if ($name === $source['prefix_tag'])
			{
				throw new \InvalidArgumentException('CLI_TOPIC_TAGS_REPAIR_SOURCE_TARGET');
			}
			if (isset($seen[$key]))
			{
				throw new \InvalidArgumentException('CLI_TOPIC_TAGS_REPAIR_DUPLICATE_TAG');
			}
			$seen[$key] = true;

			$targets[] = [
				'prefix_id' => $known[$key]['prefix_id'] ?? null,
				'prefix_enabled' => $known[$key]['prefix_enabled'] ?? null,
				'prefix_tag' => $name,
				'stored_name' => $stored_name,
				'existing' => isset($known[$key]),
			];
		}

		return $targets;
	}

	/**
	 * Create missing targets and merge source metadata into existing targets.
	 *
	 * @param array $source  Source tag definition
	 * @param array $targets Resolved target definitions
	 * @return array Created or updated target definitions
	 * @throws \Exception When a database transaction fails
	 */
	protected function create_or_update_targets(array $source, array $targets): array
	{
		$source_forums = $this->get_source_forums($source['prefix_id']);
		$this->db->sql_transaction('begin');
		try
		{
			foreach ($targets as &$target)
			{
				if ($target['existing'])
				{
					if ($source['prefix_enabled'] && !$target['prefix_enabled'])
					{
						$this->db->sql_query('UPDATE ' . $this->tags_table . '
							SET prefix_enabled = 1
							WHERE prefix_id = ' . (int) $target['prefix_id']);
						$target['prefix_enabled'] = 1;
					}
				}
				else
				{
					$sql = 'INSERT INTO ' . $this->tags_table . '
						(prefix_tag, prefix_color, prefix_enabled, prefix_order)
						VALUES (' . $this->sql_text_literal($target['stored_name']) . ', ' .
						$this->sql_text_literal($source['prefix_color']) . ', ' .
						(int) $source['prefix_enabled'] . ', ' . (int) $source['prefix_order'] . ')';
					$this->db->sql_query($sql);
					$target['prefix_id'] = (int) $this->db->sql_last_inserted_id();
					$target['prefix_enabled'] = $source['prefix_enabled'];
				}

				$this->merge_forums((int) $target['prefix_id'], $source_forums);
			}
			unset($target);
			$this->db->sql_transaction('commit');
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			$this->invalidate_cache();
			throw $e;
		}
		$this->invalidate_cache();

		return $targets;
	}

	/**
	 * Add missing source forums to one target.
	 *
	 * @param int   $target_id     Target tag identifier
	 * @param array $source_forums Source forum identifiers
	 * @return void
	 */
	protected function merge_forums(int $target_id, array $source_forums): void
	{
		if (!$source_forums)
		{
			return;
		}

		$sql = 'SELECT forum_id FROM ' . $this->forums_map_table . '
			WHERE prefix_id = ' . $target_id . '
				AND ' . $this->db->sql_in_set('forum_id', $source_forums);
		$result = $this->db->sql_query($sql);
		$existing = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$existing[(int) $row['forum_id']] = true;
		}
		$this->db->sql_freeresult($result);

		$rows = [];
		foreach ($source_forums as $forum_id)
		{
			if (!isset($existing[$forum_id]))
			{
				$rows[] = ['forum_id' => $forum_id, 'prefix_id' => $target_id];
			}
		}
		if ($rows)
		{
			$this->db->sql_multi_insert($this->forums_map_table, $rows);
		}
	}

	/**
	 * Count canonical topics assigned to the source tag.
	 *
	 * @param int $source_id Source tag identifier
	 * @return int Number of topics eligible for transfer
	 */
	protected function count_topics(int $source_id): int
	{
		$result = $this->db->sql_query('SELECT COUNT(*) AS total
			FROM ' . $this->topic_map_table . ' pt
			INNER JOIN ' . $this->topics_table . ' t ON t.topic_id = pt.topic_id
			WHERE pt.prefix_id = ' . $source_id . ' AND t.topic_moved_id = 0');
		$count = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		return $count;
	}

	/**
	 * Repair one bounded topic batch.
	 *
	 * @param array $target_ids Destination tag identifiers
	 * @param array $topics     Canonical topic rows
	 * @return int Number of topics processed
	 * @throws \Exception When the database transaction fails
	 */
	protected function repair_topic_batch(array $target_ids, array $topics): int
	{
		$topic_ids = array_map('intval', array_column($topics, 'topic_id'));
		$existing = $this->get_existing_assignments($topic_ids, $target_ids);
		$rows = [];
		foreach ($topic_ids as $topic_id)
		{
			foreach ($target_ids as $target_id)
			{
				if (!isset($existing[$topic_id][$target_id]))
				{
					$rows[] = ['topic_id' => $topic_id, 'prefix_id' => $target_id];
				}
			}
		}
		$this->db->sql_transaction('begin');
		try
		{
			foreach (array_chunk($rows, self::BATCH_SIZE) as $row_batch)
			{
				$this->db->sql_multi_insert($this->topic_map_table, $row_batch);
			}
			$this->db->sql_transaction('commit');
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			throw $e;
		}

		return count($topics);
	}

	/**
	 * Load one bounded topic set assigned to source.
	 *
	 * @param int $source_id    Source tag identifier
	 * @param int $last_topic_id Previous batch cursor
	 * @return array Canonical topic rows
	 */
	protected function get_topic_batch(int $source_id, int $last_topic_id): array
	{
		$sql = 'SELECT t.topic_id
			FROM ' . $this->topic_map_table . ' pt
			INNER JOIN ' . $this->topics_table . ' t ON t.topic_id = pt.topic_id
			WHERE pt.prefix_id = ' . $source_id . '
				AND t.topic_moved_id = 0 AND t.topic_id > ' . $last_topic_id . '
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
	 * Find target relationships already present in one topic batch.
	 *
	 * @param array $topic_ids Topic identifiers
	 * @param array $target_ids Target tag identifiers
	 * @return array Existing assignments grouped by topic and tag
	 */
	protected function get_existing_assignments(array $topic_ids, array $target_ids): array
	{
		$sql = 'SELECT topic_id, prefix_id FROM ' . $this->topic_map_table . '
			WHERE ' . $this->db->sql_in_set('topic_id', $topic_ids) . '
				AND ' . $this->db->sql_in_set('prefix_id', $target_ids);
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
	 * Get source forum availability identifiers.
	 *
	 * @param int $source_id Source tag identifier
	 * @return array Forum identifiers
	 */
	protected function get_source_forums(int $source_id): array
	{
		$result = $this->db->sql_query('SELECT forum_id FROM ' . $this->forums_map_table . '
			WHERE prefix_id = ' . $source_id . '
			ORDER BY forum_id ASC');
		$forum_ids = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$forum_ids[] = (int) $row['forum_id'];
		}
		$this->db->sql_freeresult($result);

		return $forum_ids;
	}

	/**
	 * Count source relationships without loading topic content.
	 *
	 * @param string $table     Relationship table
	 * @param int    $source_id Source tag identifier
	 * @return int Relationship count
	 */
	protected function count_relationships(string $table, int $source_id): int
	{
		$result = $this->db->sql_query('SELECT COUNT(*) AS total FROM ' . $table . '
			WHERE prefix_id = ' . $source_id);
		$count = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		return $count;
	}

	/**
	 * Quote text for portable Unicode SQL literals.
	 *
	 * @param string $value Unquoted value
	 * @return string Quoted SQL literal
	 */
	protected function sql_text_literal(string $value): string
	{
		$unicode_prefix = strpos($this->db->get_sql_layer(), 'mssql') === 0 ? 'N' : '';
		return $unicode_prefix . "'" . $this->db->sql_escape($value) . "'";
	}

	/**
	 * Invalidate persistent tag metadata.
	 *
	 * @return void
	 */
	protected function invalidate_cache(): void
	{
		if ($this->cache)
		{
			$this->cache->destroy(manager::CACHE_KEY);
		}
	}
}
