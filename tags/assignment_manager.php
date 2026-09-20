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
		$tag_ids = array_values(array_unique(array_filter(array_map('intval', $tag_ids))));
		if (!$topic_id || !$this->topic_exists($topic_id) || !$this->tags_exist($tag_ids))
		{
			return false;
		}

		$this->db->sql_transaction('begin');
		$this->db->sql_query('DELETE FROM ' . $this->topic_map_table . ' WHERE topic_id = ' . $topic_id);
		$rows = [];
		foreach ($tag_ids as $tag_id)
		{
			$rows[] = ['topic_id' => $topic_id, 'prefix_id' => $tag_id];
		}
		if ($rows)
		{
			$this->db->sql_multi_insert($this->topic_map_table, $rows);
		}
		$this->db->sql_transaction('commit');

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
		$topic_ids = array_values(array_unique(array_filter(array_map('intval', $topic_ids))));
		if ($topic_ids)
		{
			$this->db->sql_query('DELETE FROM ' . $this->topic_map_table . '
				WHERE ' . $this->db->sql_in_set('topic_id', $topic_ids));
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
	}

	/**
	 * Get assigned tag identifiers for one topic.
	 *
	 * @param int $topic_id Topic identifier
	 * @return array Tag identifiers
	 */
	public function get_topic_tag_ids(int $topic_id): array
	{
		$tags = $this->get_tags_for_topics([$topic_id]);
		return isset($tags[$topic_id]) ? array_keys($tags[$topic_id]) : [];
	}

	/**
	 * Batch-load relationship identifiers without loading tag definitions.
	 *
	 * @param array $topic_ids Topic identifiers
	 * @return array Tag identifiers grouped by topic identifier
	 */
	public function get_topic_tag_ids_for_topics(array $topic_ids): array
	{
		$topic_ids = array_values(array_unique(array_filter(array_map('intval', $topic_ids))));
		if (!$topic_ids)
		{
			return [];
		}

		$sql = 'SELECT topic_id, prefix_id
			FROM ' . $this->topic_map_table . '
			WHERE ' . $this->db->sql_in_set('topic_id', $topic_ids) . '
			ORDER BY topic_id ASC, prefix_id ASC';
		$result = $this->db->sql_query($sql);
		$tag_ids = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$tag_ids[(int) $row['topic_id']][] = (int) $row['prefix_id'];
		}
		$this->db->sql_freeresult($result);

		return $tag_ids;
	}

	/**
	 * Batch-load tag definitions for topics.
	 *
	 * @param array $topic_ids Topic identifiers
	 * @return array Tags grouped by topic then tag identifier
	 */
	public function get_tags_for_topics(array $topic_ids): array
	{
		$topic_ids = array_values(array_unique(array_filter(array_map('intval', $topic_ids))));
		if (!$topic_ids)
		{
			return [];
		}

		$sql = 'SELECT pt.topic_id, p.*
			FROM ' . $this->topic_map_table . ' pt
			INNER JOIN ' . $this->tags_table . ' p
				ON p.prefix_id = pt.prefix_id
			WHERE ' . $this->db->sql_in_set('pt.topic_id', $topic_ids) . '
			ORDER BY p.prefix_order ASC, p.prefix_id ASC';
		$result = $this->db->sql_query($sql);
		$tags = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$topic_id = (int) $row['topic_id'];
			$tag_id = (int) $row['prefix_id'];
			$tags[$topic_id][$tag_id] = $row;
		}
		$this->db->sql_freeresult($result);

		return $tags;
	}

	/**
	 * Resolve shadow topic identifiers to their destination topics.
	 *
	 * @param array $topic_ids Topic or shadow-topic identifiers
	 * @return array Effective topic identifiers keyed by requested identifier
	 */
	public function get_effective_topic_ids(array $topic_ids): array
	{
		$topic_ids = array_values(array_unique(array_filter(array_map('intval', $topic_ids))));
		if (!$topic_ids)
		{
			return [];
		}

		$sql = 'SELECT topic_id, topic_moved_id
			FROM ' . $this->topics_table . '
			WHERE ' . $this->db->sql_in_set('topic_id', $topic_ids);
		$result = $this->db->sql_query($sql);
		$resolved = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$topic_id = (int) $row['topic_id'];
			$resolved[$topic_id] = !empty($row['topic_moved_id']) ? (int) $row['topic_moved_id'] : $topic_id;
		}
		$this->db->sql_freeresult($result);

		return $resolved;
	}

	/**
	 * Get tags assigned to topics displayed in one forum.
	 *
	 * Includes tags that are disabled or unavailable for new assignments in the
	 * forum, tags reached through move shadows, and tags on global announcements.
	 *
	 * @param int $forum_id Forum identifier
	 * @return array Tags keyed by identifier
	 */
	public function get_tags_for_forum(int $forum_id): array
	{
		$effective_topic_id = $this->db->sql_case(
			't.topic_moved_id <> 0',
			't.topic_moved_id',
			't.topic_id'
		);
		$sql = 'SELECT DISTINCT p.*
			FROM ' . $this->topic_map_table . ' pt
			INNER JOIN ' . $this->topics_table . ' t
				ON pt.topic_id = ' . $effective_topic_id . '
			INNER JOIN ' . $this->tags_table . ' p
				ON p.prefix_id = pt.prefix_id
			WHERE (t.forum_id = ' . (int) $forum_id . '
				OR t.topic_type = ' . POST_GLOBAL . ')
			ORDER BY p.prefix_order ASC, p.prefix_id ASC';
		$result = $this->db->sql_query($sql);
		$tags = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$tags[(int) $row['prefix_id']] = $row;
		}
		$this->db->sql_freeresult($result);

		return $tags;
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
}
