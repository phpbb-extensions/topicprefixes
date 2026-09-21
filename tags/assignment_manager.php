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

use phpbb\db\driver\driver_interface;

/**
 * Topic/tag relationship manager.
 */
class assignment_manager
{
	/** @var driver_interface */
	protected $db;

	/** @var string Topic/tag map table */
	protected $topic_map_table;

	/** @var string Tag definition table */
	protected $tags_table;

	/** @var string Topics table */
	protected $topics_table;

	/** @var array Request-local tag IDs grouped by topic */
	protected $topic_tag_ids = [];

	/** @var array Topics whose relationship IDs have been loaded */
	protected $topic_tag_ids_loaded = [];

	/** @var array Request-local tag definitions grouped by topic */
	protected $topic_tags = [];

	/** @var array Topics whose tag definitions have been loaded */
	protected $topic_tags_loaded = [];

	/**
	 * Constructor.
	 *
	 * @param driver_interface $db              Database connection
	 * @param string           $topic_map_table Topic/tag map table
	 * @param string           $tags_table      Tag definition table
	 * @param string           $topics_table    Topics table
	 */
	public function __construct(driver_interface $db, $topic_map_table, $tags_table, $topics_table)
	{
		$this->db = $db;
		$this->topic_map_table = $topic_map_table;
		$this->tags_table = $tags_table;
		$this->topics_table = $topics_table;
	}

	/**
	 * Replace all tag assignments for one topic.
	 *
	 * Callers must validate tag availability before invoking this method.
	 *
	 * @param int   $topic_id Topic identifier
	 * @param array $tag_ids  Tag identifiers
	 * @return bool Whether topic and tag identifiers were valid
	 */
	public function set_topic_tags(int $topic_id, array $tag_ids): bool
	{
		$topic_id = (int) $topic_id;
		$tag_ids = $this->normalize_ids($tag_ids);
		if (!$topic_id || !$this->topic_exists($topic_id) || !$this->tags_exist($tag_ids))
		{
			return false;
		}

		return $this->replace_topic_tags($topic_id, $tag_ids);
	}

	/**
	 * Replace assignments already validated by the posting workflow.
	 *
	 * This avoids repeating topic/tag existence queries after phpBB created the
	 * topic and the tag manager validated every submitted identifier.
	 *
	 * @param int   $topic_id Topic identifier
	 * @param array $tag_ids  Validated tag identifiers
	 * @return bool Whether a valid topic identifier was supplied
	 */
	public function set_validated_topic_tags(int $topic_id, array $tag_ids): bool
	{
		$topic_id = (int) $topic_id;
		if (!$topic_id)
		{
			return false;
		}

		return $this->replace_topic_tags($topic_id, $this->normalize_ids($tag_ids));
	}

	/**
	 * Apply only changed topic/tag relationships.
	 *
	 * @param int   $topic_id Topic identifier
	 * @param array $tag_ids  Normalized tag identifiers
	 * @return bool True
	 */
	protected function replace_topic_tags(int $topic_id, array $tag_ids): bool
	{
		$current = $this->get_topic_tag_ids($topic_id);
		$remove = array_values(array_diff($current, $tag_ids));
		$add = array_values(array_diff($tag_ids, $current));
		if (!$remove && !$add)
		{
			return true;
		}

		$this->db->sql_transaction('begin');
		if ($remove)
		{
			$this->db->sql_query('DELETE FROM ' . $this->topic_map_table . '
				WHERE topic_id = ' . $topic_id . '
					AND ' . $this->db->sql_in_set('prefix_id', $remove));
		}
		$rows = [];
		foreach ($add as $tag_id)
		{
			$rows[] = ['topic_id' => $topic_id, 'prefix_id' => $tag_id];
		}
		if ($rows)
		{
			$this->db->sql_multi_insert($this->topic_map_table, $rows);
		}
		$this->db->sql_transaction('commit');
		$this->topic_tag_ids[$topic_id] = $tag_ids;
		$this->topic_tag_ids_loaded[$topic_id] = true;
		unset($this->topic_tags[$topic_id], $this->topic_tags_loaded[$topic_id]);

		return true;
	}

	/**
	 * Add tags without removing existing assignments.
	 *
	 * @param int   $topic_id Topic identifier
	 * @param array $tag_ids  Tag identifiers
	 * @return bool Whether topic and tag identifiers were valid
	 */
	public function add_topic_tags(int $topic_id, array $tag_ids): bool
	{
		return $this->set_topic_tags($topic_id, array_merge($this->get_topic_tag_ids($topic_id), $tag_ids));
	}

	/**
	 * Add relationships already validated by a phpBB lifecycle operation.
	 *
	 * @param int   $topic_id Topic identifier
	 * @param array $tag_ids  Validated tag identifiers
	 * @return bool Whether a valid topic identifier was supplied
	 */
	public function add_validated_topic_tags(int $topic_id, array $tag_ids): bool
	{
		return $this->set_validated_topic_tags(
			$topic_id,
			array_merge($this->get_topic_tag_ids($topic_id), $tag_ids)
		);
	}

	/**
	 * Copy every assignment from one topic to another.
	 *
	 * @param int $source_topic_id Source topic identifier
	 * @param int $target_topic_id Target topic identifier
	 * @return bool Whether both topics were valid
	 */
	public function copy_topic_tags(int $source_topic_id, int $target_topic_id): bool
	{
		if (!$this->topic_exists($source_topic_id))
		{
			return false;
		}

		return $this->set_topic_tags($target_topic_id, $this->get_topic_tag_ids($source_topic_id));
	}

	/**
	 * Delete assignments belonging to topics.
	 *
	 * @param array $topic_ids Topic identifiers
	 * @return void
	 */
	public function delete_topic_assignments(array $topic_ids): void
	{
		$topic_ids = $this->normalize_ids($topic_ids);
		if ($topic_ids)
		{
			$this->db->sql_query('DELETE FROM ' . $this->topic_map_table . '
				WHERE ' . $this->db->sql_in_set('topic_id', $topic_ids));
			foreach ($topic_ids as $topic_id)
			{
				$this->topic_tag_ids[$topic_id] = [];
				$this->topic_tag_ids_loaded[$topic_id] = true;
				$this->topic_tags[$topic_id] = [];
				$this->topic_tags_loaded[$topic_id] = true;
			}
		}
	}

	/**
	 * Delete assignments for every topic currently in one forum.
	 *
	 * @param int $forum_id Forum identifier
	 * @return void
	 */
	public function delete_forum_topic_assignments(int $forum_id): void
	{
		$sql = 'DELETE FROM ' . $this->topic_map_table . '
			WHERE topic_id IN (
				SELECT topic_id
				FROM ' . $this->topics_table . '
				WHERE forum_id = ' . (int) $forum_id . '
			)';
		$this->db->sql_query($sql);
		$this->clear_request_cache();
	}

	/**
	 * Get assigned tag identifiers for one topic.
	 *
	 * @param int $topic_id Topic identifier
	 * @return array Tag identifiers
	 */
	public function get_topic_tag_ids(int $topic_id): array
	{
		$tag_ids = $this->get_topic_tag_ids_for_topics([$topic_id]);
		return $tag_ids[$topic_id] ?? [];
	}

	/**
	 * Batch-load relationship identifiers without loading tag definitions.
	 *
	 * @param array $topic_ids Topic identifiers
	 * @return array Tag identifiers grouped by topic identifier
	 */
	public function get_topic_tag_ids_for_topics(array $topic_ids): array
	{
		$topic_ids = $this->normalize_ids($topic_ids);
		if (!$topic_ids)
		{
			return [];
		}

		$missing = array_values(array_filter($topic_ids, function ($topic_id) {
			return !isset($this->topic_tag_ids_loaded[$topic_id]);
		}));
		if ($missing)
		{
			foreach ($missing as $topic_id)
			{
				$this->topic_tag_ids[$topic_id] = [];
				$this->topic_tag_ids_loaded[$topic_id] = true;
			}
			$sql = 'SELECT topic_id, prefix_id
				FROM ' . $this->topic_map_table . '
				WHERE ' . $this->db->sql_in_set('topic_id', $missing) . '
				ORDER BY topic_id ASC, prefix_id ASC';
			$result = $this->db->sql_query($sql);
			while ($row = $this->db->sql_fetchrow($result))
			{
				$this->topic_tag_ids[(int) $row['topic_id']][] = (int) $row['prefix_id'];
			}
			$this->db->sql_freeresult($result);
		}

		$return = [];
		foreach ($topic_ids as $topic_id)
		{
			if ($this->topic_tag_ids[$topic_id])
			{
				$return[$topic_id] = $this->topic_tag_ids[$topic_id];
			}
		}

		return $return;
	}

	/**
	 * Batch-load tag definitions for topics.
	 *
	 * @param array $topic_ids Topic identifiers
	 * @return array Tags grouped by topic then tag identifier
	 */
	public function get_tags_for_topics(array $topic_ids): array
	{
		$topic_ids = $this->normalize_ids($topic_ids);
		if (!$topic_ids)
		{
			return [];
		}

		$missing = array_values(array_filter($topic_ids, function ($topic_id) {
			return !isset($this->topic_tags_loaded[$topic_id]);
		}));
		if ($missing)
		{
			foreach ($missing as $topic_id)
			{
				$this->topic_tags[$topic_id] = [];
				$this->topic_tags_loaded[$topic_id] = true;
				$this->topic_tag_ids[$topic_id] = [];
				$this->topic_tag_ids_loaded[$topic_id] = true;
			}
			$sql = 'SELECT pt.topic_id, p.*
				FROM ' . $this->topic_map_table . ' pt
				INNER JOIN ' . $this->tags_table . ' p
					ON p.prefix_id = pt.prefix_id
				WHERE ' . $this->db->sql_in_set('pt.topic_id', $missing) . '
				ORDER BY p.prefix_order ASC, p.prefix_id ASC';
			$result = $this->db->sql_query($sql);
			while ($row = $this->db->sql_fetchrow($result))
			{
				$topic_id = (int) $row['topic_id'];
				$tag_id = (int) $row['prefix_id'];
				$row['prefix_tag'] = manager::decode_name($row['prefix_tag']);
				$this->topic_tags[$topic_id][$tag_id] = $row;
				$this->topic_tag_ids[$topic_id][] = $tag_id;
			}
			$this->db->sql_freeresult($result);
		}

		$return = [];
		foreach ($topic_ids as $topic_id)
		{
			if ($this->topic_tags[$topic_id])
			{
				$return[$topic_id] = $this->topic_tags[$topic_id];
			}
		}

		return $return;
	}

	/**
	 * Batch-load tags keyed by displayed topic, resolving move shadows in SQL.
	 *
	 * @param array $topic_ids Topic or shadow-topic identifiers
	 * @return array Tags grouped by displayed topic identifier
	 */
	public function get_tags_for_displayed_topics(array $topic_ids): array
	{
		$topic_ids = $this->normalize_ids($topic_ids);
		if (!$topic_ids)
		{
			return [];
		}

		$effective_topic_id = $this->db->sql_case(
			't.topic_moved_id <> 0',
			't.topic_moved_id',
			't.topic_id'
		);
		$sql = 'SELECT t.topic_id AS display_topic_id, p.*
			FROM ' . $this->topics_table . ' t
			INNER JOIN ' . $this->topic_map_table . ' pt
				ON pt.topic_id = ' . $effective_topic_id . '
			INNER JOIN ' . $this->tags_table . ' p
				ON p.prefix_id = pt.prefix_id
			WHERE ' . $this->db->sql_in_set('t.topic_id', $topic_ids) . '
			ORDER BY p.prefix_order ASC, p.prefix_id ASC';
		$result = $this->db->sql_query($sql);
		$tags = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$topic_id = (int) $row['display_topic_id'];
			$tag_id = (int) $row['prefix_id'];
			unset($row['display_topic_id']);
			$row['prefix_tag'] = manager::decode_name($row['prefix_tag']);
			$tags[$topic_id][$tag_id] = $row;
		}
		$this->db->sql_freeresult($result);

		return $tags;
	}

	/**
	 * Get tag IDs assigned to topics displayed in one forum.
	 *
	 * Includes tags that are disabled or unavailable for new assignments in the
	 * forum, tags reached through move shadows, and tags on global announcements.
	 *
	 * @param int   $forum_id     Forum identifier
	 * @param array $candidate_ids Optional tag identifiers to examine
	 * @return array Tag identifiers
	 */
	public function get_tag_ids_for_forum(int $forum_id, array $candidate_ids = []): array
	{
		$candidate_ids = $this->normalize_ids($candidate_ids);
		$effective_topic_id = $this->db->sql_case(
			't.topic_moved_id <> 0',
			't.topic_moved_id',
			't.topic_id'
		);
		$sql = 'SELECT DISTINCT pt.prefix_id
			FROM ' . $this->topic_map_table . ' pt
			INNER JOIN ' . $this->topics_table . ' t
				ON pt.topic_id = ' . $effective_topic_id . '
			WHERE (t.forum_id = ' . (int) $forum_id . '
				OR t.topic_type = ' . POST_GLOBAL . ')' .
			($candidate_ids ? '
				AND ' . $this->db->sql_in_set('pt.prefix_id', $candidate_ids) : '') . '
			ORDER BY pt.prefix_id ASC';
		$result = $this->db->sql_query($sql);
		$tag_ids = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$tag_ids[] = (int) $row['prefix_id'];
		}
		$this->db->sql_freeresult($result);

		return $tag_ids;
	}

	/**
	 * Check whether a topic exists.
	 *
	 * @param int $topic_id Topic identifier
	 * @return bool
	 */
	public function topic_exists(int $topic_id): bool
	{
		$sql = 'SELECT topic_id
			FROM ' . $this->topics_table . '
			WHERE topic_id = ' . (int) $topic_id;
		$result = $this->db->sql_query($sql);
		$exists = $this->db->sql_fetchfield('topic_id') !== false;
		$this->db->sql_freeresult($result);

		return $exists;
	}

	/**
	 * Check whether every tag identifier exists.
	 *
	 * @param array $tag_ids Tag identifiers
	 * @return bool
	 */
	protected function tags_exist(array $tag_ids): bool
	{
		if (!$tag_ids)
		{
			return true;
		}

		$sql = 'SELECT COUNT(prefix_id) AS total
			FROM ' . $this->tags_table . '
			WHERE ' . $this->db->sql_in_set('prefix_id', $tag_ids);
		$result = $this->db->sql_query($sql);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		return $total === count($tag_ids);
	}

	/**
	 * Normalize an identifier list.
	 *
	 * @param array $ids Identifiers
	 * @return array Positive unique identifiers
	 */
	protected function normalize_ids(array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
		sort($ids, SORT_NUMERIC);

		return $ids;
	}

	/**
	 * Drop request-local relationship data after a bulk mutation.
	 *
	 * @return void
	 */
	protected function clear_request_cache(): void
	{
		$this->topic_tag_ids = [];
		$this->topic_tag_ids_loaded = [];
		$this->topic_tags = [];
		$this->topic_tags_loaded = [];
	}
}
