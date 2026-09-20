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
	 * Create filter with unrestricted content visibility.
	 *
	 * @return \phpbb\topicprefixes\tags\filter
	 */
	protected function create_filter()
	{
		$visibility = $this->getMockBuilder('\phpbb\content_visibility')->disableOriginalConstructor()->getMock();
		$visibility->method('get_visibility_sql')->willReturn('1=1');
		return new \phpbb\topicprefixes\tags\filter($this->db, $visibility, 'phpbb_topic_prefixes_topics', 'phpbb_topics');
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
		self::assertSame(array(10), $this->query_ids(array(1, 2)));
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

		self::assertSame('1=1', $filter->condition('t', [0, 0]));
		self::assertSame(2, $filter->count_topics(2, [1], 7));
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

	/**
	 * Query topic identifiers matching tags.
	 *
	 * @param array $tag_ids Tag identifiers
	 * @return array Topic identifiers
	 */
	protected function query_ids(array $tag_ids)
	{
		$sql = 'SELECT t.topic_id FROM phpbb_topics t WHERE t.forum_id = 2 AND ' . $this->create_filter()->condition('t', $tag_ids) . ' ORDER BY t.topic_id';
		$result = $this->db->sql_query($sql);
		$ids = array_map('intval', array_column($this->db->sql_fetchrowset($result), 'topic_id'));
		$this->db->sql_freeresult($result);
		return $ids;
	}
}
