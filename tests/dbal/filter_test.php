<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2016 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\tests\dbal;

require_once __DIR__ . '/tags_base.php';

class filter_test extends tags_base
{
	protected function create_filter()
	{
		$visibility = $this->getMockBuilder('\phpbb\content_visibility')->disableOriginalConstructor()->getMock();
		$visibility->method('get_visibility_sql')->willReturn('1=1');
		return new \phpbb\topicprefixes\tags\filter($this->db, $visibility, 'phpbb_topic_prefixes_topics', 'phpbb_topics');
	}

	public function test_single_tag_filter()
	{
		self::assertSame(array(10, 11), $this->query_ids(array(1)));
	}

	public function test_multiple_tags_use_and_semantics_without_duplicates()
	{
		self::assertSame(array(10), $this->query_ids(array(1, 2)));
	}

	public function test_filtered_count_matches_rows()
	{
		self::assertSame(2, $this->create_filter()->count_topics(2, array(1)));
		self::assertSame(1, $this->create_filter()->count_topics(2, array(1, 2)));
	}

	protected function query_ids(array $tag_ids)
	{
		$sql = 'SELECT t.topic_id FROM phpbb_topics t WHERE t.forum_id = 2 AND ' . $this->create_filter()->condition('t', $tag_ids) . ' ORDER BY t.topic_id';
		$result = $this->db->sql_query($sql);
		$ids = array_map('intval', array_column($this->db->sql_fetchrowset($result), 'topic_id'));
		$this->db->sql_freeresult($result);
		return $ids;
	}
}
