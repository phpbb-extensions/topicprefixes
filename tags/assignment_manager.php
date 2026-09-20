<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2016 phpBB Limited <https://www.phpbb.com>
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
	 * @return bool Whether topic identifier was valid
	 */
	public function set_topic_tags(int $topic_id, array $tag_ids): bool
	{
		$topic_id = (int) $topic_id;
		$tag_ids = array_values(array_unique(array_filter(array_map('intval', $tag_ids))));
		if (!$topic_id)
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
	 * Get tags assigned to topics currently in one forum.
	 *
	 * Includes tags that are disabled or unavailable for new assignments in the
	 * forum, so relationships preserved by topic moves remain filterable.
	 *
	 * @param int $forum_id Forum identifier
	 * @return array Tags keyed by identifier
	 */
	public function get_tags_for_forum(int $forum_id): array
	{
		$sql = 'SELECT DISTINCT p.*
			FROM ' . $this->topic_map_table . ' pt
			INNER JOIN ' . $this->tags_table . ' p
				ON p.prefix_id = pt.prefix_id
			INNER JOIN ' . $this->topics_table . ' t
				ON t.topic_id = pt.topic_id
			WHERE t.forum_id = ' . (int) $forum_id . '
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
}
