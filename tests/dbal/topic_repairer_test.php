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

use phpbb\topicprefixes\tags\topic_repairer;

require_once __DIR__ . '/tags_base.php';

/**
 * Test recovery of missing topic tags from surviving old prefix text.
 */
class topic_repairer_test extends tags_base
{
	/**
	 * Create the topic recovery service using the fixture database.
	 *
	 * @return topic_repairer Topic recovery service using the fixture database
	 */
	protected function repairer(): topic_repairer
	{
		return new topic_repairer(
			$this->db,
			$this->cache,
			'phpbb_topics',
			'phpbb_posts',
			'phpbb_forums',
			'phpbb_config',
			'phpbb_topic_prefixes',
			'phpbb_topic_prefixes_topics',
			'phpbb_topic_prefixes_forums'
		);
	}

	/**
	 * Create a recovery service forced through nontransactional preflight.
	 *
	 * @return topic_repairer Recovery service using the fixture database
	 */
	protected function nontransactional_repairer(): topic_repairer
	{
		return new nontransactional_topic_repairer(
			$this->db,
			$this->cache,
			'phpbb_topics',
			'phpbb_posts',
			'phpbb_forums',
			'phpbb_config',
			'phpbb_topic_prefixes',
			'phpbb_topic_prefixes_topics',
			'phpbb_topic_prefixes_forums'
		);
	}

	/**
	 * Enable maintenance mode in the fixture database.
	 *
	 * @return void
	 */
	protected function enable_maintenance(): void
	{
		$this->db->sql_query("DELETE FROM phpbb_config WHERE config_name = 'board_disable'");
		$this->db->sql_query("INSERT INTO phpbb_config (config_name, config_value, is_dynamic)
			VALUES ('board_disable', '1', 0)");
	}

	public function test_longest_adjacent_and_disabled_tags_are_discovered(): void
	{
		$this->db->sql_query("UPDATE phpbb_topics SET topic_title = 'PHP 8.4 Topic' WHERE topic_id = 13");
		$this->insert_explicit_rows('phpbb_topics', [
			['topic_id' => 14, 'forum_id' => 2, 'topic_title' => 'BugPHP 8.4 Adjacent'],
			['topic_id' => 15, 'forum_id' => 2, 'topic_title' => 'Hidden History'],
			['topic_id' => 16, 'forum_id' => 3, 'topic_title' => 'PHP 8.4 Unavailable'],
		]);
		$plans = iterator_to_array($this->repairer()->preview_recovery());
		self::assertSame([13, 14, 15], array_column($plans, 'topic_id'));
		self::assertSame([2], $plans[0]['tag_ids']);
		self::assertSame([1, 2], $plans[1]['tag_ids']);
		self::assertSame([3], $plans[2]['tag_ids']);
	}

	public function test_existing_assignments_case_and_space_are_not_candidates(): void
	{
		$this->db->sql_query("UPDATE phpbb_topics SET topic_title = 'Bug Tagged' WHERE topic_id = 11");
		$this->insert_explicit_rows('phpbb_topics', [
			['topic_id' => 14, 'forum_id' => 2, 'topic_title' => 'bug Wrong case'],
			['topic_id' => 15, 'forum_id' => 2, 'topic_title' => 'BugNo separator'],
		]);
		self::assertSame([], iterator_to_array($this->repairer()->preview_recovery()));
	}

	public function test_equal_longest_matches_are_ambiguous(): void
	{
		$this->insert_explicit_rows('phpbb_topic_prefixes', [
			['prefix_id' => 5, 'prefix_tag' => 'Bug', 'prefix_color' => '000000', 'prefix_enabled' => 1, 'prefix_order' => 5],
		]);
		$this->db->sql_query('INSERT INTO phpbb_topic_prefixes_forums (forum_id, prefix_id) VALUES (2, 5)');
		$this->db->sql_query("UPDATE phpbb_topics SET topic_title = 'Bug Ambiguous' WHERE topic_id = 13");
		$plans = iterator_to_array($this->repairer()->preview_recovery());
		self::assertCount(1, $plans);
		self::assertSame('ambiguous', $plans[0]['reason']);
		self::assertSame([1, 5], $plans[0]['tag_ids']);
	}

	public function test_apply_adds_tags_and_cleans_migration_owned_text_only(): void
	{
		$this->enable_maintenance();
		$this->db->sql_query("UPDATE phpbb_topics
			SET topic_title = 'Bug Topic', topic_first_post_id = 9100,
				topic_last_post_id = 9101, topic_last_post_subject = 'Bug Reply'
			WHERE topic_id = 13");
		$this->insert_explicit_rows('phpbb_posts', [
			['post_id' => 9100, 'topic_id' => 13, 'forum_id' => 2, 'post_subject' => 'Bug First', 'post_text' => ''],
			['post_id' => 9101, 'topic_id' => 13, 'forum_id' => 2, 'post_subject' => 'Bug Reply', 'post_text' => ''],
		]);
		$this->db->sql_query("UPDATE phpbb_forums
			SET forum_last_post_id = 9101, forum_last_post_subject = 'Bug Reply'
			WHERE forum_id = 2");
		$repairer = $this->repairer();
		$plan = iterator_to_array($repairer->preview_recovery())[0];
		self::assertSame([
			'assignments' => 1,
			'topics' => 1,
			'subjects' => 1,
			'caches' => 0,
			'skipped' => 0,
			'conflicts' => 0,
		], $repairer->apply($plan));
		self::assertSame('Topic', $this->field('phpbb_topics', 'topic_title', 'topic_id = 13'));
		self::assertSame('First', $this->field('phpbb_posts', 'post_subject', 'post_id = 9100'));
		self::assertSame('Bug Reply', $this->field('phpbb_posts', 'post_subject', 'post_id = 9101'));
		self::assertSame(1, (int) $this->field(
			'phpbb_topic_prefixes_topics',
			'COUNT(*)',
			'topic_id = 13 AND prefix_id = 1'
		));
		self::assertSame([], iterator_to_array($repairer->preview_recovery()));
	}

	public function test_stale_text_does_not_add_tags_or_clean_title(): void
	{
		$this->enable_maintenance();
		$this->db->sql_query("UPDATE phpbb_topics
			SET topic_title = 'Bug Topic', topic_first_post_id = 9200
			WHERE topic_id = 13");
		$this->insert_explicit_rows('phpbb_posts', [
			['post_id' => 9200, 'topic_id' => 13, 'forum_id' => 2, 'post_subject' => 'Bug First', 'post_text' => ''],
		]);
		$repairer = $this->nontransactional_repairer();
		$plan = iterator_to_array($repairer->preview_recovery())[0];
		$this->db->sql_query("UPDATE phpbb_posts SET post_subject = 'Edited' WHERE post_id = 9200");

		self::assertSame([
			'assignments' => 0,
			'topics' => 0,
			'subjects' => 0,
			'caches' => 0,
			'skipped' => 0,
			'conflicts' => 1,
		], $repairer->apply($plan));
		self::assertSame('Bug Topic', $this->field('phpbb_topics', 'topic_title', 'topic_id = 13'));
		self::assertSame('Edited', $this->field('phpbb_posts', 'post_subject', 'post_id = 9200'));
		self::assertSame(0, (int) $this->field(
			'phpbb_topic_prefixes_topics',
			'COUNT(*)',
			'topic_id = 13'
		));
	}

	public function test_existing_assignment_added_after_preview_is_a_conflict(): void
	{
		$this->enable_maintenance();
		$this->db->sql_query("UPDATE phpbb_topics SET topic_title = 'Bug Topic' WHERE topic_id = 13");
		$repairer = $this->repairer();
		$plan = iterator_to_array($repairer->preview_recovery())[0];
		$this->db->sql_query('INSERT INTO phpbb_topic_prefixes_topics (topic_id, prefix_id) VALUES (13, 1)');

		$result = $repairer->apply($plan);

		self::assertSame(1, $result['conflicts']);
		self::assertSame('Bug Topic', $this->field('phpbb_topics', 'topic_title', 'topic_id = 13'));
	}

	public function test_forum_availability_removed_after_preview_is_a_conflict(): void
	{
		$this->enable_maintenance();
		$this->db->sql_query("UPDATE phpbb_topics SET topic_title = 'Bug Topic' WHERE topic_id = 13");
		$repairer = $this->repairer();
		$plan = iterator_to_array($repairer->preview_recovery())[0];
		$this->db->sql_query('DELETE FROM phpbb_topic_prefixes_forums WHERE forum_id = 2 AND prefix_id = 1');

		$result = $repairer->apply($plan);

		self::assertSame(1, $result['conflicts']);
		self::assertSame(0, (int) $this->field('phpbb_topic_prefixes_topics', 'COUNT(*)', 'topic_id = 13'));
	}

	/**
	 * Fetch one scalar field from the fixture database.
	 *
	 * @param string $table Database table
	 * @param string $field Selected field
	 * @param string $where SQL condition
	 * @return mixed Selected value
	 */
	protected function field(string $table, string $field, string $where)
	{
		$result = $this->db->sql_query('SELECT ' . $field . ' AS value FROM ' . $table . ' WHERE ' . $where);
		$value = $this->db->sql_fetchfield('value', false, $result);
		$this->db->sql_freeresult($result);

		return $value;
	}
}

/**
 * Force recovery tests through nontransactional snapshot preflight.
 */
class nontransactional_topic_repairer extends topic_repairer
{
	/**
	 * Force preflight independently of the fixture table engine.
	 *
	 * @param array $plans Confirmed recovery snapshots
	 * @return bool Always true
	 */
	protected function requires_snapshot_preflight(array $plans): bool
	{
		return true;
	}
}
