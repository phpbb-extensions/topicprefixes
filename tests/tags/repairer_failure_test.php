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

class repairer_failure_test extends \phpbb_test_case
{
	/** @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\cache\driver\driver_interface */
	protected $cache;

	protected function setUp(): void
	{
		parent::setUp();
		$this->db = $this->createMock('\phpbb\db\driver\driver_interface');
		$this->cache = $this->createMock('\phpbb\cache\driver\driver_interface');
	}

	public function test_target_failure_rolls_back_and_invalidates_cache(): void
	{
		$repairer = $this->create_repairer();
		$repairer->fail_forum_merge = true;
		$this->db->expects(self::exactly(2))->method('sql_transaction')
			->withConsecutive(['begin'], ['rollback']);
		$this->cache->expects(self::once())->method('destroy')
			->with(\phpbb\topicprefixes\tags\manager::CACHE_KEY);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Forum merge failed.');

		$repairer->create_targets(
			['prefix_id' => 1, 'prefix_enabled' => 0],
			[['prefix_id' => 2, 'prefix_enabled' => 1, 'existing' => true]]
		);
	}

	public function test_topic_batch_failure_rolls_back(): void
	{
		$repairer = $this->create_repairer();
		$this->db->expects(self::exactly(2))->method('sql_transaction')
			->withConsecutive(['begin'], ['rollback']);
		$this->db->expects(self::once())->method('sql_multi_insert')
			->willThrowException(new \RuntimeException('Topic assignment failed.'));
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Topic assignment failed.');

		$repairer->repair_topics(
			[2],
			[['topic_id' => 10]]
		);
	}

	public function test_source_deletion_failure_rolls_back_and_invalidates_cache(): void
	{
		$repairer = $this->create_repairer();
		$repairer->preview_result = [
			'source' => ['prefix_id' => 1, 'prefix_tag' => 'Combined'],
			'targets' => [['prefix_id' => 2, 'prefix_tag' => 'A', 'existing' => true]],
		];
		$repairer->created_targets = [['prefix_id' => 2, 'prefix_tag' => 'A', 'existing' => true]];
		$this->db->expects(self::exactly(2))->method('sql_transaction')
			->withConsecutive(['begin'], ['rollback']);
		$this->db->expects(self::once())->method('sql_query')
			->willThrowException(new \RuntimeException('Source deletion failed.'));
		$this->cache->expects(self::once())->method('destroy')
			->with(\phpbb\topicprefixes\tags\manager::CACHE_KEY);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Source deletion failed.');

		$repairer->repair(1, ['A']);
	}

	protected function create_repairer(): repairer_failure_harness
	{
		return new repairer_failure_harness(
			$this->db,
			$this->cache,
			'tags',
			'forums_map',
			'topic_map',
			'topics'
		);
	}
}

class repairer_failure_harness extends \phpbb\topicprefixes\tags\repairer
{
	/** @var bool */
	public $fail_forum_merge = false;

	/** @var array */
	public $preview_result = [];

	/** @var array */
	public $created_targets = [];

	public function create_targets(array $source, array $targets): array
	{
		return parent::create_or_update_targets($source, $targets);
	}

	public function repair_topics(array $target_ids, array $topics): int
	{
		return parent::repair_topic_batch($target_ids, $topics);
	}

	public function preview(int $source_id, array $replacements): array
	{
		return $this->preview_result;
	}

	protected function create_or_update_targets(array $source, array $targets): array
	{
		return $this->created_targets;
	}

	protected function get_source_forums(int $source_id): array
	{
		return [];
	}

	protected function merge_forums(int $target_id, array $source_forums): void
	{
		if ($this->fail_forum_merge)
		{
			throw new \RuntimeException('Forum merge failed.');
		}

		parent::merge_forums($target_id, $source_forums);
	}

	protected function get_topic_batch(int $source_id, int $last_topic_id): array
	{
		return [];
	}

	protected function get_existing_assignments(array $topic_ids, array $target_ids): array
	{
		return [];
	}

}
