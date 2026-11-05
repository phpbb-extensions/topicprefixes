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

use phpbb\auth\auth;
use phpbb\content_visibility;
use phpbb\db\driver\driver_interface;

/**
 * Viewforum topic tag query filter.
 */
class filter
{
	/** @var driver_interface Database connection */
	protected $db;

	/** @var content_visibility Content visibility service */
	protected $visibility;

	/** @var auth Authorization service */
	protected $auth;

	/** @var string Topic/tag map table */
	protected $topic_map_table;

	/** @var string Topics table */
	protected $topics_table;

	/** @var string Users table */
	protected $users_table;

	/** @var array|null Forums current user may read */
	protected $readable_forum_ids;

	/** @var array|null Forums current user may read and list topics in */
	protected $readable_listable_forum_ids;

	/**
	 * Constructor.
	 *
	 * @param driver_interface  $db              Database connection
	 * @param content_visibility $visibility      Content visibility service
	 * @param auth               $auth            Permission service
	 * @param string             $topic_map_table Topic/tag map table
	 * @param string             $topics_table    Topics table
	 * @param string             $users_table     Users table
	 */
	public function __construct(driver_interface $db, content_visibility $visibility, auth $auth, string $topic_map_table, string $topics_table, string $users_table)
	{
		$this->db = $db;
		$this->visibility = $visibility;
		$this->auth = $auth;
		$this->topic_map_table = $topic_map_table;
		$this->topics_table = $topics_table;
		$this->users_table = $users_table;
	}

	/**
	 * Get requested tag IDs assigned to topics visible in one forum.
	 *
	 * Global announcements must come from readable forums. Move shadows must
	 * point to visible destinations the user can read and list.
	 *
	 * @param int   $forum_id     Forum identifier
	 * @param array $candidate_ids Tag identifiers to examine
	 * @return array Visible tag identifiers
	 */
	public function get_visible_tag_ids_for_forum(int $forum_id, array $candidate_ids): array
	{
		$candidate_ids = array_values(array_unique(array_filter(array_map('intval', $candidate_ids))));
		if (!$candidate_ids)
		{
			return [];
		}

		$readable_forums = $this->get_readable_forum_ids();
		$shadow_forums = $this->get_readable_listable_forum_ids();
		$local_visibility = $this->visibility->get_visibility_sql('topic', $forum_id, 't.');

		$global_condition = '1=0';
		if ($readable_forums)
		{
			$global_condition = $this->db->sql_in_set('t.forum_id', $readable_forums) . '
				AND ' . $this->visibility->get_forums_visibility_sql('topic', $readable_forums, 't.');
		}

		$shadow_condition = '1=0';
		if ($shadow_forums)
		{
			$shadow_condition = $this->db->sql_in_set('d.forum_id', $shadow_forums) . '
				AND ' . $this->visibility->get_forums_visibility_sql('topic', $shadow_forums, 'd.');
		}

		$effective_topic_id = $this->db->sql_case(
			't.topic_moved_id <> 0',
			't.topic_moved_id',
			't.topic_id'
		);
		$sql = 'SELECT DISTINCT pt.prefix_id
			FROM ' . $this->topics_table . ' t
			LEFT JOIN ' . $this->topics_table . ' d
				ON d.topic_id = t.topic_moved_id
			INNER JOIN ' . $this->topic_map_table . ' pt
				ON pt.topic_id = ' . $effective_topic_id . '
			WHERE ' . $this->db->sql_in_set('pt.prefix_id', $candidate_ids) . '
				AND (
					(t.forum_id = ' . $forum_id . '
						AND t.topic_moved_id = 0
						AND ' . $local_visibility . ')
					OR (t.topic_type = ' . POST_GLOBAL . '
						AND ' . $global_condition . ')
					OR (t.forum_id = ' . $forum_id . '
						AND t.topic_moved_id <> 0
						AND ' . $local_visibility . '
						AND ' . $shadow_condition . ')
				)
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
	 * Resolve displayed topics to tag-bearing topics without exposing private move destinations.
	 *
	 * Callers must supply topic IDs already approved for display by phpBB. Normal
	 * topics therefore map directly; move destinations receive additional forum
	 * permission and topic visibility checks.
	 *
	 * @param array $topic_ids Displayed topic or shadow identifiers
	 * @return array Effective topic identifiers keyed by displayed topic identifier
	 */
	public function get_visible_topic_map(array $topic_ids): array
	{
		$topic_ids = array_values(array_unique(array_filter(array_map('intval', $topic_ids))));
		if (!$topic_ids)
		{
			return [];
		}

		$destination_forums = $this->get_readable_listable_forum_ids();
		$destination_condition = '1=0';
		if ($destination_forums)
		{
			$destination_condition = $this->db->sql_in_set('d.forum_id', $destination_forums) . '
				AND ' . $this->visibility->get_forums_visibility_sql('topic', $destination_forums, 'd.');
		}

		$sql = 'SELECT t.topic_id, t.topic_moved_id
			FROM ' . $this->topics_table . ' t
			LEFT JOIN ' . $this->topics_table . ' d
				ON d.topic_id = t.topic_moved_id
			WHERE ' . $this->db->sql_in_set('t.topic_id', $topic_ids) . '
				AND (
					t.topic_moved_id = 0
					OR (t.topic_moved_id <> 0 AND ' . $destination_condition . ')
				)
			ORDER BY t.topic_id ASC';
		$result = $this->db->sql_query($sql);
		$topic_map = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$topic_id = (int) $row['topic_id'];
			$topic_map[$topic_id] = !empty($row['topic_moved_id'])
				? (int) $row['topic_moved_id']
				: $topic_id;
		}
		$this->db->sql_freeresult($result);

		return $topic_map;
	}

	/**
	 * Get forums current user may read.
	 *
	 * @return array Forum identifiers
	 */
	protected function get_readable_forum_ids(): array
	{
		if ($this->readable_forum_ids === null)
		{
			$this->readable_forum_ids = array_keys($this->auth->acl_getf('f_read', true));
		}

		return $this->readable_forum_ids;
	}

	/**
	 * Get forums current user may read and list topics in.
	 *
	 * @return array Forum identifiers
	 */
	protected function get_readable_listable_forum_ids(): array
	{
		if ($this->readable_listable_forum_ids === null)
		{
			$listable_forums = array_keys($this->auth->acl_getf('f_list_topics', true));
			$this->readable_listable_forum_ids = array_values(array_intersect(
				$this->get_readable_forum_ids(),
				$listable_forums
			));
		}

		return $this->readable_listable_forum_ids;
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
		$topic_id = $this->db->sql_case(
			$topic_alias . '.topic_moved_id <> 0',
			$topic_alias . '.topic_moved_id',
			$topic_alias . '.topic_id'
		);
		return $this->topic_id_condition($topic_id, $tag_ids);
	}

	/**
	 * Build SQL condition requiring every selected tag for a topic ID expression.
	 *
	 * Search backends operate on posts before loading topic rows, so callers can
	 * use p.topic_id without weakening phpBB's existing visibility condition.
	 *
	 * @param string $topic_id Topic ID SQL expression
	 * @param array  $tag_ids  Selected tag identifiers
	 * @return string SQL condition
	 */
	public function topic_id_condition(string $topic_id, array $tag_ids): string
	{
		$tag_ids = array_values(array_unique(array_filter(array_map('intval', $tag_ids))));
		if (!$tag_ids)
		{
			return '1=1';
		}

		$conditions = [];
		foreach ($tag_ids as $tag_id)
		{
			$conditions[] = 'EXISTS (
				SELECT 1
				FROM ' . $this->topic_map_table . ' tpf
				WHERE tpf.topic_id = ' . $topic_id . '
					AND tpf.prefix_id = ' . $tag_id . '
			)';
		}

		return implode('
			AND ', $conditions);
	}

	/**
	 * Build search condition for selected tag identifiers.
	 *
	 * @param string $topic_id Topic ID SQL expression
	 * @param array  $tag_ids  Selected tag identifiers
	 * @param string $match    all or any
	 * @return string SQL condition
	 */
	public function topic_id_tags_condition(string $topic_id, array $tag_ids, string $match = 'all'): string
	{
		$tag_ids = array_values(array_unique(array_filter(array_map('intval', $tag_ids))));
		if (!$tag_ids)
		{
			return '1=1';
		}

		if ($match === 'any')
		{
			return 'EXISTS (
				SELECT 1
				FROM ' . $this->topic_map_table . ' tpf
				WHERE tpf.topic_id = ' . $topic_id . '
					AND ' . $this->db->sql_in_set('tpf.prefix_id', $tag_ids) . '
			)';
		}

		return $this->topic_id_condition($topic_id, $tag_ids);
	}

	/**
	 * Search visible topic IDs using only selected tags.
	 *
	 * @param array  $tag_ids      Selected tag identifiers
	 * @param string $match        all or any
	 * @param array  $excluded_forums Forum IDs excluded by phpBB search
	 * @param string $sort_key     phpBB topic sort key
	 * @param string $sort_dir     a or d
	 * @param int    $sort_days    Maximum topic age in days
	 * @param int    $start        Requested result offset
	 * @param int    $per_page     Results per page
	 * @return array IDs, total count, and validated offset
	 */
	public function search_topic_ids(array $tag_ids, string $match, array $excluded_forums, string $sort_key, string $sort_dir, int $sort_days, int $start, int $per_page): array
	{
		$where = 't.topic_moved_id = 0
			AND ' . $this->topic_id_tags_condition('t.topic_id', $tag_ids, $match) . '
			AND ' . $this->visibility->get_global_visibility_sql('topic', $excluded_forums, 't.');
		if ($sort_days > 0)
		{
			$where .= ' AND t.topic_last_post_time >= ' . (time() - ($sort_days * 86400));
		}

		$result = $this->db->sql_query('SELECT COUNT(t.topic_id) AS total
			FROM ' . $this->topics_table . ' t
			WHERE ' . $where);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		$per_page = max(1, $per_page);
		$start = max(0, $start);
		if ($total && $start >= $total)
		{
			$start = (int) (floor(($total - 1) / $per_page) * $per_page);
		}

		$join = '';
		switch ($sort_key)
		{
			case 'a':
				$join = ' LEFT JOIN ' . $this->users_table . ' u ON u.user_id = t.topic_poster';
				$order_by = 'u.username_clean';
			break;

			case 'f':
				$order_by = 't.forum_id';
			break;

			case 'i':
			case 's':
				$order_by = 't.topic_title';
			break;

			default:
				$order_by = 't.topic_last_post_time';
			break;
		}

		$sql = 'SELECT t.topic_id
			FROM ' . $this->topics_table . ' t' . $join . '
			WHERE ' . $where . '
			ORDER BY ' . $order_by . ($sort_dir === 'a' ? ' ASC' : ' DESC') . ', t.topic_id ASC';
		$result = $this->db->sql_query_limit($sql, $per_page, $start);
		$ids = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$ids[] = (int) $row['topic_id'];
		}
		$this->db->sql_freeresult($result);

		return [$ids, $total, $start];
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
