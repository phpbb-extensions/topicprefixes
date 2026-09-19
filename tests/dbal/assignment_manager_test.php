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

class assignment_manager_test extends tags_base
{
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

	public function test_batch_retrieval()
	{
		$tags = $this->create_assignment_manager()->get_tags_for_topics(array(10, 11, 13));
		self::assertSame(array(1, 2), array_keys($tags[10]));
		self::assertSame(array(1), array_keys($tags[11]));
		self::assertArrayNotHasKey(13, $tags);
	}
}
