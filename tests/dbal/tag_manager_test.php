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
		$tags = $this->create_tag_manager()->get_available_tags(2);
		self::assertSame(array(1, 2), array_keys($tags));
		self::assertSame(array(1, 4), array_keys($this->create_tag_manager()->get_available_tags(3)));
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
		self::assertFalse($manager->normalize_color('red'));
	}
}
