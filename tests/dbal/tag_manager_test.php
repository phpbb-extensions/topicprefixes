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

	public function test_unicode_tag_names_use_phpbb_unicode_storage()
	{
		$manager = $this->create_tag_manager();
		$tag = $manager->add_tag('日本語 😇', '4A76A8', true, array(2));

		self::assertSame('日本語 😇', $tag['prefix_tag']);
		$result = $this->db->sql_query('SELECT prefix_tag
			FROM phpbb_topic_prefixes
			WHERE prefix_id = ' . (int) $tag['prefix_id']);
		self::assertSame('日本語 &#128519;', $this->db->sql_fetchfield('prefix_tag'));
		$this->db->sql_freeresult($result);

		$tag = $manager->update_tag($tag['prefix_id'], '修正 🚀', '4A76A8', true, array(2));
		self::assertSame('修正 🚀', $tag['prefix_tag']);
		$result = $this->db->sql_query('SELECT prefix_tag
			FROM phpbb_topic_prefixes
			WHERE prefix_id = ' . (int) $tag['prefix_id']);
		self::assertSame('修正 &#128640;', $this->db->sql_fetchfield('prefix_tag'));
		$this->db->sql_freeresult($result);
		self::assertSame('修正 🚀', $this->create_tag_manager()->get_tag($tag['prefix_id'])['prefix_tag']);
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

	public function test_search_catalog_keeps_duplicate_names_and_colors_separate(): void
	{
		$this->insert_explicit_rows('phpbb_topic_prefixes', [
			[
				'prefix_id' => 5,
				'prefix_tag' => 'bug',
				'prefix_color' => '1D70B8',
				'prefix_enabled' => 1,
				'prefix_order' => 5,
			],
			[
				'prefix_id' => 6,
				'prefix_tag' => 'BUG',
				'prefix_color' => '00703C',
				'prefix_enabled' => 0,
				'prefix_order' => 6,
			],
		]);
		$this->db->sql_multi_insert('phpbb_topic_prefixes_forums', [
			['forum_id' => 2, 'prefix_id' => 5],
			['forum_id' => 2, 'prefix_id' => 6],
		]);

		$manager = $this->create_tag_manager();
		$catalog = $manager->get_search_catalog([2]);
		self::assertArrayHasKey(1, $catalog);
		self::assertArrayHasKey(5, $catalog);
		self::assertArrayNotHasKey(6, $catalog);
		self::assertSame(1, (int) $catalog[1]['prefix_id']);
		self::assertSame(5, (int) $catalog[5]['prefix_id']);
		self::assertSame('bug', $catalog[5]['search_name']);
		self::assertSame('D4351C', $catalog[1]['prefix_color']);
		self::assertSame('1D70B8', $catalog[5]['prefix_color']);
		self::assertArrayNotHasKey(3, $catalog);

		$selected = $manager->resolve_search_tags([5, 6]);
		self::assertSame([5, 6], array_keys($selected));
		self::assertSame(5, (int) $selected[5]['prefix_id']);
		self::assertSame(6, (int) $selected[6]['prefix_id']);
		self::assertSame('1D70B8', $selected[5]['prefix_color']);
		self::assertSame('00703C', $selected[6]['prefix_color']);
	}

	public function test_search_lookups_reject_empty_identifiers_and_forums(): void
	{
		$manager = $this->create_tag_manager();

		self::assertSame([], $manager->resolve_search_tags([0, 'invalid']));
		self::assertSame([], $manager->resolve_search_tags_for_forums([1], [0, 'invalid']));
		self::assertSame([], $manager->get_search_catalog([0, 'invalid']));
	}

	public function test_selected_search_tags_require_one_allowed_forum(): void
	{
		$manager = $this->create_tag_manager();

		self::assertSame(
			[1, 3],
			array_keys($manager->resolve_search_tags_for_forums([1, 3, 4], [2]))
		);
		self::assertSame(
			[1, 4],
			array_keys($manager->resolve_search_tags_for_forums([1, 3, 4], [3]))
		);
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

	public function test_move_resolves_tied_legacy_order(): void
	{
		$this->db->sql_query('UPDATE phpbb_topic_prefixes SET prefix_order = 1 WHERE prefix_id = 2');
		$manager = $this->create_tag_manager();
		self::assertTrue($manager->move_tag(2, 'up'));
		self::assertSame([2, 1, 3, 4], array_keys($manager->get_tags()));
	}

	public function test_reorder_replaces_complete_global_order()
	{
		$manager = $this->create_tag_manager();
		self::assertTrue($manager->reorder_tags(array(4, 2, 1, 3)));
		self::assertSame(array(4, 2, 1, 3), array_keys($manager->get_tags()));
		self::assertSame(array(4, 2, 1, 3), array_keys($this->create_tag_manager()->get_tags()));
	}

	public function test_reorder_rejects_stale_or_duplicate_identifier_sets()
	{
		$manager = $this->create_tag_manager();
		self::assertFalse($manager->reorder_tags(array(1, 2, 3)));
		self::assertFalse($manager->reorder_tags(array(1, 2, 3, 3)));
		self::assertFalse($manager->reorder_tags(array(1, 2, 3, 999)));
		self::assertSame(array(1, 2, 3, 4), array_keys($manager->get_tags()));
	}

	public function test_color_validation()
	{
		$manager = $this->create_tag_manager();
		self::assertSame('AABBCC', \phpbb\topicprefixes\tags\manager::normalize_color('#aabbcc'));
		self::assertSame('', \phpbb\topicprefixes\tags\manager::normalize_color('red'));
		self::assertSame(str_repeat('a', 50), \phpbb\topicprefixes\tags\manager::normalize_name(str_repeat('a', 50)));
		self::assertSame('', \phpbb\topicprefixes\tags\manager::normalize_name(str_repeat('a', 51)));
		self::assertSame('&#128519;', \phpbb\topicprefixes\tags\manager::normalize_name('😇'));
		self::assertSame('R&amp;D', \phpbb\topicprefixes\tags\manager::normalize_name('R&D'));
		self::assertSame('R&amp;amp;D', \phpbb\topicprefixes\tags\manager::normalize_name('R&amp;D'));
		self::assertSame('R&D', \phpbb\topicprefixes\tags\manager::decode_name('R&amp;D'));
		self::assertSame('R&amp;D', \phpbb\topicprefixes\tags\manager::decode_name('R&amp;amp;D'));
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
		$this->db->sql_query("UPDATE phpbb_forums
			SET forum_name = 'Forum Two &#128512; &amp; More'
			WHERE forum_id = 2");
		$manager = $this->create_tag_manager();
		self::assertTrue($manager->set_enabled(1, false));
		self::assertArrayNotHasKey(1, $manager->get_available_tags(2));
		self::assertSame([
			1 => ['Forum Two 😀 & More', 'Forum Three'],
			2 => ['Forum Two 😀 & More'],
			3 => ['Forum Two 😀 & More'],
			4 => ['Forum Three'],
		], $manager->get_forum_names_by_tag());
	}
}
