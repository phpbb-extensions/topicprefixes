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

class filter_test extends tags_base
{
	/**
	 * Create filter with configurable forum permissions and visibility.
	 *
	 * @param array $readable_forums Forums with f_read
	 * @param array $listable_forums Forums with f_list_topics
	 * @param bool  $see_hidden       Whether hidden topics are visible
	 * @return \phpbb\topicprefixes\tags\filter
	 */
	protected function create_filter(array $readable_forums = [2, 3], array $listable_forums = [2, 3], bool $see_hidden = true)
	{
		$visibility = $this->getMockBuilder('\phpbb\content_visibility')->disableOriginalConstructor()->getMock();
		$visibility->method('get_visibility_sql')->willReturnCallback(static function ($mode, $forum_id, $alias) use ($see_hidden) {
			return $see_hidden ? '1=1' : $alias . 'topic_visibility = ' . ITEM_APPROVED;
		});
		$visibility->method('get_forums_visibility_sql')->willReturnCallback(static function ($mode, $forum_ids, $alias) use ($see_hidden) {
			return $see_hidden ? '1=1' : $alias . 'topic_visibility = ' . ITEM_APPROVED;
		});
		$visibility->method('get_global_visibility_sql')->willReturnCallback(static function ($mode, $forum_ids, $alias) use ($see_hidden) {
			$conditions = [];
			if ($forum_ids)
			{
				$conditions[] = $alias . 'forum_id NOT IN (' . implode(', ', array_map('intval', $forum_ids)) . ')';
			}
			if (!$see_hidden)
			{
				$conditions[] = $alias . 'topic_visibility = ' . ITEM_APPROVED;
			}
			return $conditions ? implode(' AND ', $conditions) : '1=1';
		});
		$auth = $this->getMockBuilder('\phpbb\auth\auth')->disableOriginalConstructor()->getMock();
		$auth->method('acl_getf')->willReturnCallback(static function ($permission) use ($readable_forums, $listable_forums) {
			$forums = $permission === 'f_read' ? $readable_forums : $listable_forums;
			return array_fill_keys($forums, [$permission => true]);
		});

		return new \phpbb\topicprefixes\tags\filter($this->db, $visibility, $auth, 'phpbb_topic_prefixes_topics', 'phpbb_topics', 'phpbb_users');
	}

	/**
	 * Test filtering by one tag.
	 */
	public function test_single_tag_filter()
	{
		self::assertSame(array(10, 11), $this->query_ids(array(1)));
	}

	/**
	 * Test multiple selected tags use AND semantics.
	 */
	public function test_multiple_tags_use_and_semantics_without_duplicates()
	{
		self::assertSame(array(10), $this->query_ids(array(1, 2, 1, 0)));
	}

	public function test_topic_id_expression_condition_uses_and_semantics(): void
	{
		self::assertSame(
			[10],
			$this->query_ids_with_condition($this->create_filter()->topic_id_condition('p.topic_id', [1, 2]), 'p')
		);
	}

	public function test_tag_conditions_support_all_and_any(): void
	{
		$filter = $this->create_filter();
		self::assertSame(
			[10],
			$this->query_ids_with_condition($filter->topic_id_tags_condition('t.topic_id', [1, 2], 'all'))
		);
		self::assertSame(
			[10, 11, 12],
			$this->query_ids_with_condition($filter->topic_id_tags_condition('t.topic_id', [1, 2, 2, 0], 'any'))
		);
	}

	public function test_tag_only_search_returns_paginated_topic_ids(): void
	{
		$filter = $this->create_filter();

		list($ids, $total, $start) = $filter->search_topic_ids([1, 2], 'all', [], 't', 'd', 0, 0, 25);
		self::assertSame([10], $ids);
		self::assertSame(1, $total);
		self::assertSame(0, $start);

		list($ids, $total) = $filter->search_topic_ids([1, 2], 'any', [], 't', 'd', 0, 0, 2);
		self::assertSame([10, 11], $ids);
		self::assertSame(3, $total);

		list($ids, $total, $start) = $filter->search_topic_ids([1, 2], 'any', [], 'i', 'a', 0, 99, 2);
		self::assertSame([12], $ids);
		self::assertSame(3, $total);
		self::assertSame(2, $start);
	}

	/**
	 * @dataProvider search_sort_data
	 */
	public function test_tag_only_search_honors_sort_inputs(string $sort_key, string $sort_dir, array $expected): void
	{
		$this->db->sql_query("UPDATE phpbb_topics SET topic_title = 'Zulu', topic_last_post_time = 100 WHERE topic_id = 10");
		$this->db->sql_query("UPDATE phpbb_topics SET topic_title = 'Alpha', topic_last_post_time = 300 WHERE topic_id = 11");
		$this->db->sql_query("UPDATE phpbb_topics SET topic_title = 'Middle', topic_last_post_time = 200, forum_id = 3 WHERE topic_id = 12");

		list($ids) = $this->create_filter()->search_topic_ids([1, 2], 'any', [], $sort_key, $sort_dir, 0, 0, 25);

		self::assertSame($expected, $ids);
	}

	public function search_sort_data(): array
	{
		return [
			'author ascending with unknown users' => ['a', 'a', [10, 11, 12]],
			'forum descending' => ['f', 'd', [12, 10, 11]],
			'title ascending' => ['i', 'a', [11, 12, 10]],
			'subject uses title descending' => ['s', 'd', [10, 12, 11]],
			'default uses last post descending' => ['t', 'd', [11, 12, 10]],
		];
	}

	public function test_tag_only_search_applies_visibility_forum_and_age_filters(): void
	{
		$this->db->sql_query('UPDATE phpbb_topics SET topic_visibility = ' . ITEM_UNAPPROVED . ' WHERE topic_id = 11');
		$this->db->sql_query('UPDATE phpbb_topics SET forum_id = 3 WHERE topic_id = 12');
		$this->db->sql_query('UPDATE phpbb_topics SET topic_last_post_time = 1 WHERE topic_id = 10');

		list($ids, $total, $start) = $this->create_filter([2, 3], [2, 3], false)
			->search_topic_ids([1, 2], 'any', [3], 't', 'd', 7, -5, 0);

		self::assertSame([], $ids);
		self::assertSame(0, $total);
		self::assertSame(0, $start);
	}

	/**
	 * Test filtered count matches result rows.
	 */
	public function test_filtered_count_matches_rows()
	{
		self::assertSame(2, $this->create_filter()->count_topics(2, array(1)));
		self::assertSame(1, $this->create_filter()->count_topics(2, array(1, 2)));
	}

	/**
	 * Test empty filters and topic-age count condition.
	 */
	public function test_empty_filter_and_sort_days(): void
	{
		$filter = $this->create_filter();

		self::assertSame([10, 11, 12, 13], $this->query_ids([0, 0]));
		self::assertSame(
			[10, 11, 12, 13],
			$this->query_ids_with_condition($filter->topic_id_tags_condition('t.topic_id', [0], 'any'))
		);
		self::assertSame(2, $filter->count_topics(2, [1], 7));
		self::assertSame([], $filter->get_visible_tag_ids_for_forum(2, [0, 'invalid']));
	}

	/**
	 * Test shadow topics filter using destination-topic assignments.
	 */
	public function test_shadow_topic_filter_uses_destination_tags(): void
	{
		$this->db->sql_query('UPDATE phpbb_topics SET topic_moved_id = 10 WHERE topic_id = 13');

		self::assertSame([10, 11, 13], $this->query_ids([1]));
		self::assertSame(3, $this->create_filter()->count_topics(2, [1]));
	}

	public function test_private_global_announcement_tag_is_not_retained(): void
	{
		$this->db->sql_query('UPDATE phpbb_topics
			SET forum_id = 3, topic_type = ' . POST_GLOBAL . '
			WHERE topic_id = 10');
		$this->db->sql_query('INSERT INTO phpbb_topic_prefixes_topics ' . $this->db->sql_build_array('INSERT', [
			'topic_id' => 10,
			'prefix_id' => 4,
		]));

		self::assertSame([], $this->create_filter([2], [2], false)->get_visible_tag_ids_for_forum(2, [4]));
		self::assertSame([], $this->create_filter([], [], false)->get_visible_tag_ids_for_forum(2, [4]));
		self::assertSame([4], $this->create_filter([2, 3], [2, 3], false)->get_visible_tag_ids_for_forum(2, [4]));
	}

	public function test_hidden_local_topic_tag_requires_visibility(): void
	{
		$this->db->sql_query('UPDATE phpbb_topics
			SET topic_visibility = ' . ITEM_UNAPPROVED . '
			WHERE topic_id = 13');
		$this->db->sql_query('INSERT INTO phpbb_topic_prefixes_topics ' . $this->db->sql_build_array('INSERT', [
			'topic_id' => 13,
			'prefix_id' => 4,
		]));

		self::assertSame([], $this->create_filter([2], [2], false)->get_visible_tag_ids_for_forum(2, [4]));
		self::assertSame([4], $this->create_filter([2], [2], true)->get_visible_tag_ids_for_forum(2, [4]));
	}

	public function test_shadow_tag_requires_visible_readable_destination(): void
	{
		$this->db->sql_query('UPDATE phpbb_topics SET forum_id = 3 WHERE topic_id = 12');
		$this->db->sql_query('UPDATE phpbb_topics SET topic_moved_id = 12 WHERE topic_id = 13');
		$this->db->sql_query('INSERT INTO phpbb_topic_prefixes_topics ' . $this->db->sql_build_array('INSERT', [
			'topic_id' => 12,
			'prefix_id' => 4,
		]));

		self::assertSame([], $this->create_filter([2], [2], false)->get_visible_tag_ids_for_forum(2, [4]));
		self::assertSame([], $this->create_filter([2, 3], [2], false)->get_visible_tag_ids_for_forum(2, [4]));
		self::assertSame([], $this->create_filter([2, 3], [], false)->get_visible_tag_ids_for_forum(2, [4]));
		self::assertSame([4], $this->create_filter([2, 3], [2, 3], false)->get_visible_tag_ids_for_forum(2, [4]));

		$this->db->sql_query('UPDATE phpbb_topics
			SET topic_visibility = ' . ITEM_UNAPPROVED . '
			WHERE topic_id = 12');
		self::assertSame([], $this->create_filter([2, 3], [2, 3], false)->get_visible_tag_ids_for_forum(2, [4]));

		$this->db->sql_query('UPDATE phpbb_topics SET topic_visibility = ' . ITEM_APPROVED . ' WHERE topic_id = 12');
		$this->db->sql_query('UPDATE phpbb_topics SET topic_visibility = ' . ITEM_UNAPPROVED . ' WHERE topic_id = 13');
		self::assertSame([], $this->create_filter([2, 3], [2, 3], false)->get_visible_tag_ids_for_forum(2, [4]));
	}

	public function test_displayed_shadow_map_requires_visible_readable_destination(): void
	{
		$this->db->sql_query('UPDATE phpbb_topics SET forum_id = 3 WHERE topic_id = 12');
		$this->db->sql_query('UPDATE phpbb_topics SET topic_moved_id = 12 WHERE topic_id = 13');

		self::assertSame([10 => 10], $this->create_filter([2], [2], false)->get_visible_topic_map([10, 13]));
		self::assertSame([10 => 10], $this->create_filter([2, 3], [2], false)->get_visible_topic_map([10, 13]));
		self::assertSame([10 => 10], $this->create_filter([2, 3], [], false)->get_visible_topic_map([10, 13]));
		self::assertSame([10 => 10, 13 => 12], $this->create_filter([2, 3], [2, 3], false)->get_visible_topic_map([10, 13]));

		$this->db->sql_query('UPDATE phpbb_topics SET topic_visibility = ' . ITEM_UNAPPROVED . ' WHERE topic_id = 12');
		self::assertSame([10 => 10], $this->create_filter([2, 3], [2, 3], false)->get_visible_topic_map([10, 13]));

		$this->db->sql_query('UPDATE phpbb_topics SET topic_moved_id = 999 WHERE topic_id = 13');
		self::assertSame([], $this->create_filter([2, 3], [2, 3], true)->get_visible_topic_map([13]));
		self::assertSame([], $this->create_filter()->get_visible_topic_map([0, 'invalid']));
	}

	/**
	 * Query topic identifiers matching tags.
	 *
	 * @param array $tag_ids Tag identifiers
	 * @return array Topic identifiers
	 */
	protected function query_ids(array $tag_ids)
	{
		return $this->query_ids_with_condition($this->create_filter()->condition('t', $tag_ids));
	}

	/**
	 * Execute a generated topic condition and return matching identifiers.
	 *
	 * @param string $condition SQL condition under test
	 * @param string $alias     Topic table alias used by condition
	 * @return array Topic identifiers
	 */
	protected function query_ids_with_condition(string $condition, string $alias = 't'): array
	{
		$sql = 'SELECT ' . $alias . '.topic_id FROM phpbb_topics ' . $alias . '
			WHERE ' . $alias . '.forum_id = 2 AND ' . $condition . '
			ORDER BY ' . $alias . '.topic_id';
		$result = $this->db->sql_query($sql);
		$ids = array_map('intval', array_column($this->db->sql_fetchrowset($result), 'topic_id'));
		$this->db->sql_freeresult($result);
		return $ids;
	}
}
