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

use phpbb\topicprefixes\tags\title_repairer;

require_once __DIR__ . '/tags_base.php';

/**
 * Test literal title cleanup, ownership checks and transactional recovery.
 */
class title_repairer_test extends tags_base
{
	/**
	 * Create the title repair service using the fixture database.
	 *
	 * @return title_repairer Title repair service using the fixture database
	 */
	protected function repairer(): title_repairer
	{
		return new title_repairer($this->db, $this->cache, 'phpbb_topics', 'phpbb_posts', 'phpbb_forums', 'phpbb_config');
	}

	/**
	 * Create a title repair service forced through nontransactional preflight.
	 *
	 * @return title_repairer Title repair service using the fixture database
	 */
	protected function nontransactional_repairer(): title_repairer
	{
		return new nontransactional_title_repairer(
			$this->db,
			$this->cache,
			'phpbb_topics',
			'phpbb_posts',
			'phpbb_forums',
			'phpbb_config'
		);
	}

	public function test_literal_matching_preserves_stored_suffix(): void
	{
		foreach ([
			['[OLD] Topic', '[OLD]', 'Topic'],
			['[OLD]Topic', '[OLD]', 'Topic'],
			['[OLD][PHP] Topic', '[OLD]', '[PHP] Topic'],
			['[OLD][OLD] Topic', '[OLD]', '[OLD] Topic'],
			['DEV:\tTopic', 'DEV:', '\tTopic'],
			["DEV:\t\u{00a0}Topic", 'DEV:', 'Topic'],
			[' DEV: Topic', ' DEV: ', 'Topic'],
			['Title [OLD]', '[OLD]', 'Title [OLD]'],
			['[old] Topic', '[OLD]', '[old] Topic'],
			['[&amp;] &#128512; &quot;Title&quot;', '[&]', '&#128512; &quot;Title&quot;'],
			['[&#128512;] 日本語 &amp; text', '[😀]', '日本語 &amp; text'],
			['[😀]&#32;Title', '[😀]', 'Title'],
		] as [$before, $prefix, $after])
		{
			self::assertSame($after, title_repairer::clean($before, $prefix));
		}
	}

	public function test_empty_prefix_rejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		title_repairer::clean('Title', " \t");
	}

	public function test_forum_picker_label_does_not_change_plain_label(): void
	{
		$this->db->sql_query('UPDATE phpbb_forums SET left_id = 1, right_id = 4 WHERE forum_id = 2');
		$this->db->sql_query('UPDATE phpbb_forums SET left_id = 2, right_id = 3 WHERE forum_id = 3');
		$forums = $this->repairer()->get_forums();
		self::assertSame('  Forum Three (#3)', $forums[3]['label']);
		self::assertSame('Forum Three (#3)', $forums[3]['plain_label']);
	}

	/**
	 * Set up a prefixed topic, its posts, subject caches and maintenance mode.
	 *
	 * @return void
	 */
	protected function prepare_topic(): void
	{
		$sql = "DELETE
			FROM phpbb_config
			WHERE config_name = 'board_disable'";
		$this->db->sql_query($sql);
		$sql = 'DELETE
			FROM phpbb_posts
			WHERE post_id IN (100, 101)';
		$this->db->sql_query($sql);
		$sql = "INSERT INTO phpbb_config (config_name, config_value, is_dynamic)
			VALUES ('board_disable', '1', 0)";
		$this->db->sql_query($sql);
		$sql = "UPDATE phpbb_topics
			SET topic_title = '[OLD][PHP] Topic', topic_first_post_id = 100,
				topic_last_post_id = 101, topic_last_post_subject = '[OLD] Reply'
			WHERE topic_id = 10";
		$this->db->sql_query($sql);
		$this->insert_explicit_rows('phpbb_posts', [
			['post_id' => 100, 'topic_id' => 10, 'forum_id' => 2, 'post_subject' => '[OLD] First', 'post_text' => ''],
			['post_id' => 101, 'topic_id' => 10, 'forum_id' => 2, 'post_subject' => '[OLD] Reply', 'post_text' => ''],
		]);
		$sql = "UPDATE phpbb_forums
			SET forum_last_post_id = 101, forum_last_post_subject = '[OLD] Reply'";
		$this->db->sql_query($sql);
	}

	public function test_cleanup_updates_owned_subjects_and_caches_only(): void
	{
		$this->prepare_topic();
		$repairer = $this->repairer();
		$plans = iterator_to_array($repairer->preview(2, '[OLD]'));
		self::assertCount(1, $plans);
		self::assertSame('[OLD][PHP] Topic', $this->field('phpbb_topics', 'topic_title', 'topic_id = 10'));
		self::assertSame([
			'topics' => 1,
			'subjects' => 1,
			'caches' => 0,
			'skipped' => 0,
			'conflicts' => 0,
		], $repairer->apply($plans[0]));
		self::assertSame('[PHP] Topic', $this->field('phpbb_topics', 'topic_title', 'topic_id = 10'));
		self::assertSame('First', $this->field('phpbb_posts', 'post_subject', 'post_id = 100'));
		self::assertSame('[OLD] Reply', $this->field('phpbb_posts', 'post_subject', 'post_id = 101'));
		self::assertSame('[OLD] Reply', $this->field('phpbb_topics', 'topic_last_post_subject', 'topic_id = 10'));
		self::assertSame('[OLD] Reply', $this->field('phpbb_forums', 'forum_last_post_subject', 'forum_id = 3'));
		self::assertSame(2, (int) $this->field('phpbb_topic_prefixes_topics', 'COUNT(*)', 'topic_id = 10'));
		self::assertSame([], iterator_to_array($repairer->preview(2, '[OLD]')));
	}

	public function test_changed_snapshot_is_skipped_without_subject_changes(): void
	{
		$this->prepare_topic();
		$repairer = $this->nontransactional_repairer();
		$plan = iterator_to_array($repairer->preview(2, '[OLD]'))[0];
		$sql = "UPDATE phpbb_posts
			SET post_subject = 'Edited'
			WHERE post_id = 100";
		$this->db->sql_query($sql);
		self::assertSame([
			'topics' => 0,
			'subjects' => 0,
			'caches' => 0,
			'skipped' => 0,
			'conflicts' => 1,
		], $repairer->apply($plan));
		self::assertSame('[OLD][PHP] Topic', $this->field('phpbb_topics', 'topic_title', 'topic_id = 10'));
		self::assertSame('Edited', $this->field('phpbb_posts', 'post_subject', 'post_id = 100'));
	}

	public function test_mixed_batch_applies_valid_snapshot_without_touching_stale_snapshot(): void
	{
		$this->prepare_topic();
		$this->insert_explicit_rows('phpbb_topics', [
			['topic_id' => 14, 'forum_id' => 2, 'topic_title' => '[OLD] Valid'],
		]);
		$repairer = $this->nontransactional_repairer();
		$plans = iterator_to_array($repairer->preview(2, '[OLD]'));
		self::assertCount(2, $plans);
		$this->db->sql_query("UPDATE phpbb_posts SET post_subject = 'Edited' WHERE post_id = 100");

		self::assertSame([
			'topics' => 1,
			'subjects' => 0,
			'caches' => 0,
			'skipped' => 0,
			'conflicts' => 1,
		], $repairer->apply_batch($plans));
		self::assertSame('[OLD][PHP] Topic', $this->field('phpbb_topics', 'topic_title', 'topic_id = 10'));
		self::assertSame('Edited', $this->field('phpbb_posts', 'post_subject', 'post_id = 100'));
		self::assertSame('Valid', $this->field('phpbb_topics', 'topic_title', 'topic_id = 14'));
	}

	public function test_nontransactional_preflight_batches_one_hundred_rows(): void
	{
		$this->prepare_topic();
		$rows = [];
		for ($id = 2000; $id < 2100; $id++)
		{
			$rows[] = ['topic_id' => $id, 'forum_id' => 2, 'topic_title' => '[BULK] Topic ' . $id];
		}
		$this->insert_explicit_rows('phpbb_topics', $rows);
		$repairer = $this->nontransactional_repairer();
		$plans = iterator_to_array($repairer->preview(2, '[BULK]'));
		self::assertCount(100, $plans);
		$before_queries = $this->db->sql_num_queries();

		$result = $repairer->apply_batch($plans);

		self::assertSame(100, $result['topics']);
		self::assertLessThanOrEqual(13, $this->db->sql_num_queries() - $before_queries);
	}

	public function test_single_post_topic_updates_owned_last_subject_caches(): void
	{
		$this->prepare_topic();
		$this->db->sql_query("UPDATE phpbb_topics
			SET topic_last_post_id = 100, topic_last_post_subject = '[OLD] First'
			WHERE topic_id = 10");
		$this->db->sql_query("UPDATE phpbb_forums
			SET forum_last_post_id = 100, forum_last_post_subject = '[OLD] First'");
		$repairer = $this->repairer();
		$plan = iterator_to_array($repairer->preview(2, '[OLD]'))[0];
		$result = $repairer->apply($plan);
		self::assertSame(3, $result['caches']);
		self::assertSame('First', $this->field('phpbb_posts', 'post_subject', 'post_id = 100'));
		self::assertSame('First', $this->field('phpbb_topics', 'topic_last_post_subject', 'topic_id = 10'));
		self::assertSame('First', $this->field('phpbb_forums', 'forum_last_post_subject', 'forum_id = 2'));
	}

	public function test_cursor_batches_and_interrupted_run_can_resume(): void
	{
		$this->prepare_topic();
		$rows = [];
		for ($id = 1000; $id < 1101; $id++)
		{
			$rows[] = ['topic_id' => $id, 'forum_id' => 2, 'topic_title' => '[OLD] Topic ' . $id];
		}
		$this->insert_explicit_rows('phpbb_topics', $rows);
		$repairer = $this->repairer();
		$before_queries = $this->db->sql_num_queries();
		$plans = iterator_to_array($repairer->preview(2, '[OLD]'));
		self::assertLessThanOrEqual(6, $this->db->sql_num_queries() - $before_queries);
		self::assertCount(102, $plans);
		$repairer->apply($plans[0]);
		$remaining = iterator_to_array($repairer->preview(2, '[OLD]'));
		self::assertCount(101, $remaining);
		$repairer->apply($remaining[0]);
		self::assertSame('Topic 1000', $this->field('phpbb_topics', 'topic_title', 'topic_id = 1000'));
	}

	public function test_live_board_check_rejects_writes(): void
	{
		$this->prepare_topic();
		$repairer = $this->repairer();
		$plan = iterator_to_array($repairer->preview(2, '[OLD]'))[0];
		$sql = "UPDATE phpbb_config
			SET config_value = '0'
			WHERE config_name = 'board_disable'";
		$this->db->sql_query($sql);
		$this->expectException(\RuntimeException::class);
		$repairer->apply($plan);
	}

	public function test_force_bypasses_live_board_check(): void
	{
		$this->prepare_topic();
		$repairer = $this->repairer();
		$plan = iterator_to_array($repairer->preview(2, '[OLD]'))[0];
		$this->db->sql_query("UPDATE phpbb_config SET config_value = '0' WHERE config_name = 'board_disable'");

		$result = $repairer->apply_batch([$plan], true, true);

		self::assertSame(1, $result['topics']);
		self::assertSame('[PHP] Topic', $this->field('phpbb_topics', 'topic_title', 'topic_id = 10'));
	}

	public function test_shadow_and_wrong_post_owner_never_edit_destination_posts(): void
	{
		$this->prepare_topic();
		$sql = "UPDATE phpbb_topics
			SET topic_title = '[OLD] Shadow', topic_moved_id = 10, topic_first_post_id = 100,
				topic_last_post_id = 101, topic_last_post_subject = '[OLD] Reply'
			WHERE topic_id = 11";
		$this->db->sql_query($sql);
		$repairer = $this->repairer();
		$plans = iterator_to_array($repairer->preview(2, '[OLD]'));
		self::assertSame([
			'topics' => 1,
			'subjects' => 0,
			'caches' => 0,
			'skipped' => 0,
			'conflicts' => 0,
		], $repairer->apply($plans[1]));
		self::assertSame('[OLD] First', $this->field('phpbb_posts', 'post_subject', 'post_id = 100'));
		$sql = 'UPDATE phpbb_topics
			SET topic_moved_id = 0
			WHERE topic_id = 11';
		$this->db->sql_query($sql);
		$sql = "UPDATE phpbb_topics
			SET topic_title = '[OLD] Wrong owner'
			WHERE topic_id = 11";
		$this->db->sql_query($sql);
		$plans = iterator_to_array($repairer->preview(2, '[OLD]'));
		self::assertSame(0, $repairer->apply($plans[1])['subjects']);
	}

	public function test_blank_results_and_other_forums_preserved(): void
	{
		$this->prepare_topic();
		$sql = "UPDATE phpbb_topics
			SET topic_title = '[OLD] '
			WHERE topic_id = 11";
		$this->db->sql_query($sql);
		$sql = "UPDATE phpbb_topics
			SET topic_title = '[OLD] Other forum', forum_id = 3
			WHERE topic_id = 12";
		$this->db->sql_query($sql);
		$sql = "UPDATE phpbb_posts
			SET post_subject = '[OLD] '
			WHERE post_id = 100";
		$this->db->sql_query($sql);
		$repairer = $this->repairer();
		$plans = iterator_to_array($repairer->preview(2, '[OLD]'));
		self::assertCount(2, $plans);
		self::assertSame([], $plans[1]['changes']);
		self::assertSame(1, $repairer->apply($plans[0])['skipped']);
		self::assertSame('[OLD] ', $this->field('phpbb_posts', 'post_subject', 'post_id = 100'));
		self::assertSame('[OLD] Other forum', $this->field('phpbb_topics', 'topic_title', 'topic_id = 12'));
	}

	/**
	 * Fetch one stored fixture field or aggregate value.
	 *
	 * @param string $table Fixture table
	 * @param string $field Selected column or expression
	 * @param string $where Row selection condition
	 * @return mixed Value returned by the database driver
	 */
	protected function field(string $table, string $field, string $where)
	{
		$sql = 'SELECT ' . $field . ' AS value
			FROM ' . $table . '
			WHERE ' . $where;
		$result = $this->db->sql_query($sql);
		$value = $this->db->sql_fetchfield('value', false, $result);
		$this->db->sql_freeresult($result);
		return $value;
	}
}

/**
 * Force database tests through nontransactional snapshot preflight.
 */
class nontransactional_title_repairer extends title_repairer
{
	/**
	 * Force preflight independently of the fixture table engine.
	 *
	 * @param array $plans Confirmed repair snapshots
	 * @return bool Always true
	 */
	protected function requires_snapshot_preflight(array $plans): bool
	{
		return true;
	}
}
