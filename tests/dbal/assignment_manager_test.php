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

class assignment_manager_test extends tags_base
{
	/**
	 * Test replacing, removing, and deduplicating topic tags.
	 */
	public function test_assign_multiple_edit_remove_and_deduplicate()
	{
		$manager = $this->create_assignment_manager();
		self::assertTrue($manager->set_topic_tags(13, array(1, 2, 2)));
		self::assertSame(array(1, 2), $manager->get_topic_tag_ids(13));

		self::assertTrue($manager->set_topic_tags(13, array(2)));
		self::assertSame(array(2), $manager->get_topic_tag_ids(13));

		self::assertTrue($manager->set_topic_tags(13, array()));
		self::assertSame(array(), $manager->get_topic_tag_ids(13));
	}

	/**
	 * Test batch tag retrieval.
	 */
	public function test_batch_retrieval()
	{
		$manager = $this->create_assignment_manager();
		$tags = $manager->get_tags_for_topics(array(10, 11, 13));
		self::assertSame(array(1, 2), array_keys($tags[10]));
		self::assertSame(array(1), array_keys($tags[11]));
		self::assertArrayNotHasKey(13, $tags);
		$after_definitions = $this->db->sql_num_queries();
		self::assertSame([10 => [1, 2], 11 => [1]], $manager->get_topic_tag_ids_for_topics([10, 11, 13]));
		self::assertSame($after_definitions, $this->db->sql_num_queries());
	}

	public function test_tag_names_are_decoded_for_topic_display_queries()
	{
		$this->db->sql_query("UPDATE phpbb_topic_prefixes
			SET prefix_tag = '&#129522;'
			WHERE prefix_id = 1");
		$manager = $this->create_assignment_manager();

		self::assertSame('🧲', $manager->get_tags_for_topics([10])[10][1]['prefix_tag']);
	}

	/**
	 * Test moving a topic preserves its tags.
	 */
	public function test_topic_move_preserves_assignments(): void
	{
		$this->db->sql_query('UPDATE phpbb_topics SET forum_id = 3 WHERE topic_id = 12');
		$manager = $this->create_assignment_manager();

		self::assertSame(array(2), $manager->get_topic_tag_ids(12));
	}

	/**
	 * Test invalid and empty identifiers avoid relationship queries.
	 */
	public function test_empty_identifiers_are_rejected(): void
	{
		$manager = $this->create_assignment_manager();

		self::assertFalse($manager->set_topic_tags(0, [1]));
		self::assertFalse($manager->set_topic_tags(999, [1]));
		self::assertTrue($manager->set_topic_tags(13, [1]));
		self::assertFalse($manager->set_topic_tags(13, [999]));
		self::assertSame([1], $manager->get_topic_tag_ids(13));
		self::assertSame([], $manager->get_tags_for_topics([0, 0]));
		self::assertSame([], $manager->get_topic_tag_ids_for_topics([0, 0]));
		self::assertSame([], $manager->get_topic_tag_ids(999));
	}

	/**
	 * Test copying and merging assignments between topics.
	 */
	public function test_copy_and_add_topic_tags(): void
	{
		$manager = $this->create_assignment_manager();

		self::assertTrue($manager->copy_topic_tags(10, 13));
		self::assertSame([1, 2], $manager->get_topic_tag_ids(13));
		self::assertTrue($manager->add_topic_tags(11, [2]));
		self::assertSame([1, 2], $manager->get_topic_tag_ids(11));
		self::assertFalse($manager->copy_topic_tags(999, 13));
	}

	/**
	 * Test trusted lifecycle assignment helpers.
	 */
	public function test_validated_assignment_helpers(): void
	{
		$manager = $this->create_assignment_manager();

		self::assertTrue($manager->set_validated_topic_tags(13, [1]));
		self::assertTrue($manager->add_validated_topic_tags(13, [2]));
		self::assertSame([1, 2], $manager->get_topic_tag_ids(13));
		self::assertFalse($manager->set_validated_topic_tags(0, [1]));
	}

	/**
	 * Test deletion helpers remove only relationships in scope.
	 */
	public function test_delete_assignment_helpers(): void
	{
		$manager = $this->create_assignment_manager();
		$manager->delete_topic_assignments([10]);
		self::assertSame([], $manager->get_topic_tag_ids(10));
		self::assertSame([1], $manager->get_topic_tag_ids(11));

		$manager->delete_forum_topic_assignments(2);
		self::assertSame([], $manager->get_topic_tag_ids(11));
		self::assertSame([], $manager->get_topic_tag_ids(12));
	}

	/**
	 * Reapplying unchanged assignments performs no relationship writes.
	 */
	public function test_unchanged_assignment_avoids_database_churn(): void
	{
		$manager = $this->create_assignment_manager();
		self::assertSame([1, 2], $manager->get_topic_tag_ids(10));
		$before = $this->db->sql_num_queries();

		self::assertTrue($manager->set_topic_tags(10, [2, 1, 2]));

		// Only topic and tag validation queries; no DELETE or INSERT.
		self::assertSame(2, $this->db->sql_num_queries() - $before);
	}

}
