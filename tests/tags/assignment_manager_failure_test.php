<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\tests\tags;

class assignment_manager_failure_test extends \phpbb_test_case
{
	/**
	 * Insert failure must not delete existing assignments first.
	 */
	public function test_insert_failure_does_not_delete_existing_assignments(): void
	{
		$db = $this->createMock('\phpbb\db\driver\driver_interface');
		$db->expects(self::once())->method('sql_transaction')->with('begin');
		$db->expects(self::once())->method('sql_multi_insert')
			->with('topic_map', [['topic_id' => 10, 'prefix_id' => 2]])
			->willThrowException(new \RuntimeException('Insert failed.'));
		$db->expects(self::never())->method('sql_query');
		$manager = new assignment_manager_failure_harness(
			$db,
			'topic_map',
			'tags',
			'topics'
		);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Insert failed.');

		$manager->set_validated_topic_tags(10, [2]);
	}
}

class assignment_manager_failure_harness extends \phpbb\topicprefixes\tags\assignment_manager
{
	public function get_topic_tag_ids(int $topic_id): array
	{
		return [1];
	}
}
