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

use phpbb\content_visibility;
use phpbb\db\driver\driver_interface;

/**
 * Viewforum topic tag query filter.
 */
class filter
{
	/** @var driver_interface */
	protected $db;
	/** @var content_visibility */
	protected $visibility;

	/** @var string Topic/tag map table */
	protected $topic_map_table;

	/** @var string Topics table */
	protected $topics_table;

	/**
	 * Constructor.
	 *
	 * @param driver_interface  $db              Database connection
	 * @param content_visibility $visibility      Content visibility service
	 * @param string            $topic_map_table Topic/tag map table
	 * @param string            $topics_table    Topics table
	 */
	public function __construct(driver_interface $db, content_visibility $visibility, $topic_map_table, $topics_table)
	{
		$this->db = $db;
		$this->visibility = $visibility;
		$this->topic_map_table = $topic_map_table;
		$this->topics_table = $topics_table;
	}

	/**
	 * Build SQL condition requiring every selected tag.
	 *
	 * @param string $topic_alias Topics table alias
	 * @param array  $tag_ids     Selected tag identifiers
	 * @return string SQL condition
	 */
	public function condition(string $topic_alias, array $tag_ids): string
	{
		$tag_ids = array_values(array_unique(array_filter(array_map('intval', $tag_ids))));
		if (!$tag_ids)
		{
			return '1=1';
		}

		$topic_id = $this->db->sql_case(
			$topic_alias . '.topic_moved_id <> 0',
			$topic_alias . '.topic_moved_id',
			$topic_alias . '.topic_id'
		);

		$subquery = 'SELECT tpf.topic_id
			FROM ' . $this->topic_map_table . ' tpf
			WHERE ' . $this->db->sql_in_set('tpf.prefix_id', $tag_ids);
		if (count($tag_ids) > 1)
		{
			$subquery .= '
			GROUP BY tpf.topic_id
			HAVING COUNT(tpf.prefix_id) = ' . count($tag_ids);
		}

		return $topic_id . ' IN (
			' . $subquery . '
		)';
	}

	/**
	 * Count visible forum topics matching selected tags.
	 *
	 * @param int   $forum_id Forum identifier
	 * @param array $tag_ids  Selected tag identifiers
	 * @param int   $sort_days Age filter in days
	 * @return int Matching topic count
	 */
	public function count_topics(int $forum_id, array $tag_ids, int $sort_days = 0): int
	{
		$where = 't.forum_id = ' . (int) $forum_id . '
			AND ' . $this->condition('t', $tag_ids) . '
			AND ' . $this->visibility->get_visibility_sql('topic', (int) $forum_id, 't.');
		if ($sort_days)
		{
			$min_time = time() - ((int) $sort_days * 86400);
			$where .= ' AND (t.topic_last_post_time >= ' . $min_time . '
				OR t.topic_type = ' . POST_ANNOUNCE . '
				OR t.topic_type = ' . POST_GLOBAL . ')';
		}

		$sql = 'SELECT COUNT(t.topic_id) AS num_topics
			FROM ' . $this->topics_table . ' t
			WHERE ' . $where;
		$result = $this->db->sql_query($sql);
		$count = (int) $this->db->sql_fetchfield('num_topics');
		$this->db->sql_freeresult($result);

		return $count;
	}
}
