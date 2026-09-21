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

class tag_manager_test extends tags_base
{
	public function test_schema_is_normalized()
	{
		$tools = (new \phpbb\db\tools\factory())->get($this->db);
		self::assertTrue($tools->sql_table_exists('phpbb_topic_prefixes_forums'));
		self::assertTrue($tools->sql_table_exists('phpbb_topic_prefixes_topics'));
		self::assertFalse($tools->sql_column_exists('phpbb_topics', 'topic_prefix_id'));
		self::assertFalse($tools->sql_column_exists('phpbb_topic_prefixes', 'forum_id'));
	}

	public function test_crud_and_forum_availability()
	{
		$manager = $this->create_tag_manager();
		$tag = $manager->add_tag('Security', '#AA00CC', true, array(2, 3, 999));
		self::assertSame('Security', $tag['prefix_tag']);
		self::assertSame('AA00CC', $tag['prefix_color']);
		self::assertSame(array(2, 3), $tag['forum_ids']);

		$tag = $manager->update_tag($tag['prefix_id'], 'Security fix', '00AA00', false, array(3));
		self::assertSame('Security fix', $tag['prefix_tag']);
		self::assertSame(0, (int) $tag['prefix_enabled']);
		self::assertSame(array(3), $tag['forum_ids']);

		self::assertTrue($manager->delete_tag($tag['prefix_id']));
		self::assertFalse($manager->get_tag($tag['prefix_id']));
	}

	public function test_four_byte_tag_names_use_phpbb_unicode_storage()
	{
		$manager = $this->create_tag_manager();
		$tag = $manager->add_tag('😇', '4A76A8', true, array(2));

		self::assertSame('😇', $tag['prefix_tag']);
		$result = $this->db->sql_query('SELECT prefix_tag
			FROM phpbb_topic_prefixes
			WHERE prefix_id = ' . (int) $tag['prefix_id']);
		self::assertSame('&#128519;', $this->db->sql_fetchfield('prefix_tag'));
		$this->db->sql_freeresult($result);

		$tag = $manager->update_tag($tag['prefix_id'], 'Fixed 🚀', '4A76A8', true, array(2));
		self::assertSame('Fixed 🚀', $tag['prefix_tag']);
		$result = $this->db->sql_query('SELECT prefix_tag
			FROM phpbb_topic_prefixes
			WHERE prefix_id = ' . (int) $tag['prefix_id']);
		self::assertSame('Fixed &#128640;', $this->db->sql_fetchfield('prefix_tag'));
		$this->db->sql_freeresult($result);
		self::assertSame('Fixed 🚀', $this->create_tag_manager()->get_tag($tag['prefix_id'])['prefix_tag']);
	}

	public function test_delete_cascades_availability_and_topic_assignments()
	{
		$manager = $this->create_tag_manager();
		self::assertTrue($manager->delete_tag(1));
		$result = $this->db->sql_query('SELECT COUNT(*) AS total FROM phpbb_topic_prefixes_forums WHERE prefix_id = 1');
		self::assertSame(0, (int) $this->db->sql_fetchfield('total'));
		$this->db->sql_freeresult($result);
		$result = $this->db->sql_query('SELECT COUNT(*) AS total FROM phpbb_topic_prefixes_topics WHERE prefix_id = 1');
		self::assertSame(0, (int) $this->db->sql_fetchfield('total'));
		$this->db->sql_freeresult($result);
	}

	public function test_available_tags_exclude_disabled_and_wrong_forum()
	{
		$manager = $this->create_tag_manager();
		$tags = $manager->get_available_tags(2);
		self::assertSame(array(1, 2), array_keys($tags));
		self::assertSame(array(1, 4), array_keys($this->create_tag_manager()->get_available_tags(3)));
		self::assertSame([4], $this->create_tag_manager()->get_unavailable_tag_ids(2));
		self::assertSame([1, 4], array_keys($manager->get_tags_by_ids([4, 1, 999, 1])));
	}

	/**
	 * Tag metadata is loaded once and shared through phpBB's cache driver.
	 */
	public function test_tag_catalog_is_cached(): void
	{
		$before = $this->db->sql_num_queries();
		self::assertSame([1, 2], array_keys($this->create_tag_manager()->get_available_tags(2)));
		$after_first_read = $this->db->sql_num_queries();
		self::assertSame(1, $after_first_read - $before);

		self::assertSame([1], array_keys($this->create_tag_manager()->get_assignable_tags(2, [1, 3, 999])));
		self::assertSame($after_first_read, $this->db->sql_num_queries());
	}

	/**
	 * Mutations invalidate request-independent catalog data.
	 */
	public function test_tag_catalog_is_invalidated_after_mutation(): void
	{
		self::assertArrayHasKey(1, $this->create_tag_manager()->get_available_tags(2));
		self::assertTrue($this->create_tag_manager()->set_enabled(1, false));
		self::assertArrayNotHasKey(1, $this->create_tag_manager()->get_available_tags(2));
	}

	public function test_deleted_forum_availability_is_removed()
	{
		$manager = $this->create_tag_manager();
		$manager->delete_forum_availability([2, 999]);

		self::assertSame([], $manager->get_available_tags(2, false));
		self::assertSame([1, 4], array_keys($manager->get_available_tags(3, false)));
	}

	public function test_assignable_tags_reject_disabled_and_unavailable()
	{
		$tags = $this->create_tag_manager()->get_assignable_tags(2, array(1, 3, 4, 999));
		self::assertSame(array(1), array_keys($tags));
	}

	public function test_move_changes_global_order()
	{
		$manager = $this->create_tag_manager();
		self::assertTrue($manager->move_tag(2, 'up'));
		self::assertSame(array(2, 1, 3, 4), array_keys($manager->get_tags()));
	}

	public function test_color_validation()
	{
		$manager = $this->create_tag_manager();
		self::assertSame('AABBCC', $manager->normalize_color('#aabbcc'));
		self::assertSame('', $manager->normalize_color('red'));
		self::assertSame(str_repeat('a', 50), \phpbb\topicprefixes\tags\manager::normalize_name(str_repeat('a', 50)));
		self::assertSame('', \phpbb\topicprefixes\tags\manager::normalize_name(str_repeat('a', 51)));
		self::assertSame('&#128519;', \phpbb\topicprefixes\tags\manager::normalize_name('😇'));
		self::assertSame('', \phpbb\topicprefixes\tags\manager::normalize_name(str_repeat('😇', 29)));
	}

	/**
	 * Test invalid writes and missing tag operations.
	 */
	public function test_invalid_and_missing_tag_operations(): void
	{
		$manager = $this->create_tag_manager();

		self::assertFalse($manager->add_tag('', 'FFFFFF', true, [2]));
		self::assertFalse($manager->add_tag(str_repeat('😇', 29), 'FFFFFF', true, [2]));
		self::assertFalse($manager->add_tag('Invalid color', 'red', true, [2]));
		self::assertFalse($manager->update_tag(999, 'Missing', 'FFFFFF', true, [2]));
		self::assertFalse($manager->set_enabled(999, true));
		self::assertFalse($manager->delete_tag(999));
		self::assertFalse($manager->move_tag(999, 'up'));
		self::assertTrue($manager->move_tag(1, 'up'));
		self::assertSame([], $manager->get_assignable_tags(2, []));
	}

	/**
	 * Test enabled state and ACP forum name lookup.
	 */
	public function test_enabled_state_and_forum_names(): void
	{
		$manager = $this->create_tag_manager();
		self::assertTrue($manager->set_enabled(1, false));
		self::assertArrayNotHasKey(1, $manager->get_available_tags(2));
		self::assertSame([
			1 => ['Forum Two', 'Forum Three'],
			2 => ['Forum Two'],
			3 => ['Forum Two'],
			4 => ['Forum Three'],
		], $manager->get_forum_names_by_tag());
	}
}
