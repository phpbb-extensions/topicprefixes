<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\tests\event;

class lifecycle_listener_test extends \phpbb_test_case
{
	public function test_subscribed_events(): void
	{
		self::assertSame([
			'core.delete_topics_before_query',
			'core.delete_forum_content_before_query',
			'core.acp_manage_forums_move_content_after',
			'core.mcp_topic_split_topic_after',
			'core.mcp_main_fork_sql_after',
			'core.mcp_forum_merge_topics_after',
			'core.mcp_topics_merge_posts_after',
			'core.acp_users_move_posts_after',
		], array_keys(\phpbb\topicprefixes\event\lifecycle_listener::getSubscribedEvents()));
	}

	public function test_topic_and_forum_deletion_cleanup(): void
	{
		$assignments = $this->assignment_mock();
		$manager = $this->manager_mock();
		$assignments->expects(self::once())->method('get_topic_tag_ids_for_topics')->with([10])->willReturn([]);
		$assignments->expects(self::once())->method('delete_forum_topic_assignments')->with(2);
		$manager->expects(self::exactly(2))->method('delete_forum_availability')->withConsecutive([[2]], [[3]]);
		$listener = $this->listener($assignments, $manager);

		$delete = new \phpbb\event\data(['topic_ids' => [10], 'table_ary' => ['phpbb_topics']]);
		$listener->delete_topic_relationships($delete);
		self::assertSame(['phpbb_topics', 'phpbb_topic_prefixes_topics'], $delete['table_ary']);

		$listener->delete_forum_topic_relationships(new \phpbb\event\data(['forum_id' => 2]));
		$listener->delete_moved_forum_availability(new \phpbb\event\data(['from_id' => 3]));
	}

	public function test_split_fork_and_relocated_topics_copy_tags(): void
	{
		$assignments = $this->assignment_mock();
		$assignments->expects(self::exactly(4))->method('copy_topic_tags')->withConsecutive(
			[10, 20],
			[10, 30],
			[10, 40],
			[11, 41]
		)->willReturn(true);
		$listener = $this->listener($assignments, $this->manager_mock());

		$listener->copy_split_tags(new \phpbb\event\data(['topic_id' => 10, 'to_topic_id' => 20]));
		$fork = new \phpbb\event\data(['new_topic_id' => 30, 'row' => ['topic_id' => 10]]);
		$listener->copy_fork_tags($fork);
		$listener->copy_fork_tags($fork);
		$listener->copy_relocated_post_tags(new \phpbb\event\data([
			'new_topic_id_map' => [10 => 40, 11 => 41],
		]));
	}

	public function test_complete_topic_merge_unions_deleted_source_tags(): void
	{
		$assignments = $this->assignment_mock();
		$assignments->expects(self::once())->method('get_topic_tag_ids_for_topics')->with([10])->willReturn([10 => [2]]);
		$assignments->expects(self::once())->method('add_topic_tags')->with(20, [2])->willReturn(true);
		$listener = $this->listener($assignments, $this->manager_mock());

		$listener->delete_topic_relationships(new \phpbb\event\data([
			'topic_ids' => [10],
			'table_ary' => ['phpbb_topics'],
		]));
		$listener->merge_topics_tags(new \phpbb\event\data([
			'to_topic_id' => 20,
			'all_topic_data' => [10 => [], 20 => []],
		]));
	}

	public function test_split_all_uses_tags_captured_before_source_deletion(): void
	{
		$assignments = $this->assignment_mock();
		$assignments->expects(self::once())->method('get_topic_tag_ids_for_topics')->with([10])->willReturn([10 => [2]]);
		$assignments->expects(self::once())->method('set_topic_tags')->with(20, [2])->willReturn(true);
		$assignments->expects(self::never())->method('copy_topic_tags');
		$listener = $this->listener($assignments, $this->manager_mock());

		$listener->delete_topic_relationships(new \phpbb\event\data([
			'topic_ids' => [10],
			'table_ary' => ['phpbb_topics'],
		]));
		$listener->copy_split_tags(new \phpbb\event\data(['topic_id' => 10, 'to_topic_id' => 20]));
	}

	public function test_post_merge_only_unions_tags_when_source_was_removed(): void
	{
		$assignments = $this->assignment_mock();
		$assignments->expects(self::exactly(2))->method('topic_exists')->with(10)->willReturnOnConsecutiveCalls(true, false);
		$assignments->expects(self::once())->method('get_topic_tag_ids')->with(10)->willReturn([1]);
		$assignments->expects(self::once())->method('add_topic_tags')->with(20, [1])->willReturn(true);
		$listener = $this->listener($assignments, $this->manager_mock());
		$event = new \phpbb\event\data(['topic_id' => 10, 'to_topic_id' => 20]);

		$listener->merge_posts_tags($event);
		$listener->merge_posts_tags($event);
	}

	protected function listener($assignments, $manager): \phpbb\topicprefixes\event\lifecycle_listener
	{
		return new \phpbb\topicprefixes\event\lifecycle_listener(
			$assignments,
			$manager,
			'phpbb_topic_prefixes_topics'
		);
	}

	protected function assignment_mock()
	{
		return $this->getMockBuilder('\phpbb\topicprefixes\tags\assignment_manager')
			->disableOriginalConstructor()
			->getMock();
	}

	protected function manager_mock()
	{
		return $this->getMockBuilder('\phpbb\topicprefixes\tags\manager')
			->disableOriginalConstructor()
			->getMock();
	}
}
