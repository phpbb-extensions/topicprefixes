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

use phpbb\topicprefixes\tags\reply_repairer;

require_once __DIR__ . '/tags_base.php';

/**
 * Test reply cleanup eligibility, subject cache ownership and transactional recovery.
 */
class reply_repairer_test extends tags_base
{
	public function test_empty_batch_changes_nothing(): void
	{
		self::assertSame([
			'topics' => 0,
			'subjects' => 0,
			'caches' => 0,
			'skipped' => 0,
			'conflicts' => 0,
		], $this->create_repairer()->apply_batch([]));
	}

	/**
	 * Create the reply repair service using the fixture database.
	 *
	 * @return reply_repairer Reply repair service using the fixture database
	 */
	protected function create_repairer(): reply_repairer
	{
		return new reply_repairer(
			$this->db,
			$this->cache,
			'phpbb_topics',
			'phpbb_posts',
			'phpbb_forums',
			'phpbb_config',
			'phpbb_topic_prefixes',
			'phpbb_topic_prefixes_topics'
		);
	}

	/**
	 * Set up a tagged topic with a clean title, prefixed replies and subject caches.
	 *
	 * @return void
	 */
	protected function prepare_topic(): void
	{
		$sql = "DELETE
			FROM phpbb_config
			WHERE config_name = 'board_disable'";
		$this->db->sql_query($sql);
		$sql = "INSERT INTO phpbb_config (config_name, config_value, is_dynamic)
			VALUES ('board_disable', '1', 0)";
		$this->db->sql_query($sql);
		$sql = 'DELETE
			FROM phpbb_posts
			WHERE post_id BETWEEN 100 AND 199';
		$this->db->sql_query($sql);
		$sql = "UPDATE phpbb_topics
			SET topic_title = 'Clean topic', topic_first_post_id = 100,
				topic_last_post_id = 102, topic_last_post_subject = 'Re: [OLD] Last custom'
			WHERE topic_id = 10";
		$this->db->sql_query($sql);
		$this->insert_explicit_rows('phpbb_posts', [
			['post_id' => 100, 'topic_id' => 10, 'forum_id' => 2, 'post_subject' => '[OLD] First', 'post_text' => ''],
			['post_id' => 101, 'topic_id' => 10, 'forum_id' => 2, 'post_subject' => '[OLD] Custom subject', 'post_text' => ''],
			['post_id' => 102, 'topic_id' => 10, 'forum_id' => 2, 'post_subject' => 'Re: [OLD] Last custom', 'post_text' => ''],
		]);
		$sql = "UPDATE phpbb_forums
			SET forum_last_post_id = 102, forum_last_post_subject = 'Re: [OLD] Last custom'";
		$this->db->sql_query($sql);
	}

	public function test_literal_reply_matching(): void
	{
		foreach ([
			['[OLD] Custom', '[OLD]', 'Custom'],
			['Re: [OLD] Custom', '[OLD]', 'Re: Custom'],
			['Re: [OLD]Custom', '[OLD]', 'Re: Custom'],
			["Re: [OLD]\t\u{00a0}Custom", '[OLD]', 'Re: Custom'],
			['Re: [OLD][PHP] Topic', '[OLD]', 'Re: [PHP] Topic'],
			['Re: [OLD][OLD] Topic', '[OLD]', 'Re: [OLD] Topic'],
			['Re: Topic [OLD]', '[OLD]', 'Re: Topic [OLD]'],
			['re: [OLD] Topic', '[OLD]', 're: [OLD] Topic'],
			['Re: Re: [OLD] Topic', '[OLD]', 'Re: Re: [OLD] Topic'],
			['Re: [old] Topic', '[OLD]', 'Re: [old] Topic'],
			['AW: [OLD] Topic', 'AW: [OLD]', 'Topic'],
			['Re: [&#128512;&amp;] 日本語 &quot;Custom&quot;', '[😀&]', 'Re: 日本語 &quot;Custom&quot;'],
			['Re: DEV: Topic', 'DEV: ', 'Re: Topic'],
		] as [$before, $prefix, $after])
		{
			self::assertSame($after, reply_repairer::clean_reply($before, $prefix));
		}
	}

	public function test_reply_cleanup_preserves_title_first_post_and_tags(): void
	{
		$this->prepare_topic();
		$repairer = $this->create_repairer();
		$plans = iterator_to_array($repairer->preview(2, '[OLD]'));
		self::assertCount(2, $plans);
		self::assertSame('eligible', $plans[0]['reason']);
		self::assertSame('[OLD] Custom subject', $this->field('phpbb_posts', 'post_subject', 'post_id = 101'));
		self::assertSame([
			'topics' => 0,
			'subjects' => 1,
			'caches' => 0,
			'skipped' => 0,
			'conflicts' => 0,
		], $repairer->apply($plans[0]));
		self::assertSame([
			'topics' => 0,
			'subjects' => 1,
			'caches' => 3,
			'skipped' => 0,
			'conflicts' => 0,
		], $repairer->apply($plans[1]));
		self::assertSame('Clean topic', $this->field('phpbb_topics', 'topic_title', 'topic_id = 10'));
		self::assertSame('[OLD] First', $this->field('phpbb_posts', 'post_subject', 'post_id = 100'));
		self::assertSame('Custom subject', $this->field('phpbb_posts', 'post_subject', 'post_id = 101'));
		self::assertSame('Re: Last custom', $this->field('phpbb_topics', 'topic_last_post_subject', 'topic_id = 10'));
		self::assertSame('Re: Last custom', $this->field('phpbb_forums', 'forum_last_post_subject', 'forum_id = 3'));
		self::assertSame(2, (int) $this->field('phpbb_topic_prefixes_topics', 'COUNT(*)', 'topic_id = 10'));
	}

	public function test_eligibility_rejects_untagged_uncleaned_shadow_and_invalid_owner(): void
	{
		$this->prepare_topic();
		$repairer = $this->create_repairer();
		$sql = 'DELETE
			FROM phpbb_topic_prefixes_topics
			WHERE topic_id = 10';
		$this->db->sql_query($sql);
		$sql = 'INSERT INTO phpbb_topic_prefixes_topics (topic_id, prefix_id)
			VALUES (10, 999)';
		$this->db->sql_query($sql);
		self::assertSame('untagged', iterator_to_array($repairer->preview(2, '[OLD]'))[0]['reason']);
		$sql = 'INSERT INTO phpbb_topic_prefixes_topics (topic_id, prefix_id)
			VALUES (10, 3)';
		$this->db->sql_query($sql);
		self::assertSame('eligible', iterator_to_array($repairer->preview(2, '[OLD]'))[0]['reason']);
		$sql = "UPDATE phpbb_topics
			SET topic_title = '[OLD] Clean topic'
			WHERE topic_id = 10";
		$this->db->sql_query($sql);
		self::assertSame('uncleaned', iterator_to_array($repairer->preview(2, '[OLD]'))[0]['reason']);
		$sql = "UPDATE phpbb_topics
			SET topic_title = 'Clean topic', topic_moved_id = 11
			WHERE topic_id = 10";
		$this->db->sql_query($sql);
		self::assertSame('shadow', iterator_to_array($repairer->preview(2, '[OLD]'))[0]['reason']);
		$sql = 'UPDATE phpbb_topics
			SET topic_moved_id = 0, topic_first_post_id = 999
			WHERE topic_id = 10';
		$this->db->sql_query($sql);
		self::assertSame('ownership', iterator_to_array($repairer->preview(2, '[OLD]'))[0]['reason']);
		$sql = 'UPDATE phpbb_topics
			SET topic_first_post_id = 100
			WHERE topic_id = 10';
		$this->db->sql_query($sql);
		$sql = 'UPDATE phpbb_posts
			SET topic_id = 11
			WHERE post_id = 100';
		$this->db->sql_query($sql);
		self::assertSame('ownership', iterator_to_array($repairer->preview(2, '[OLD]'))[0]['reason']);
	}

	public function test_empty_and_bare_marker_results_are_skipped(): void
	{
		$this->prepare_topic();
		$sql = "UPDATE phpbb_posts
			SET post_subject = '[OLD] '
			WHERE post_id = 101";
		$this->db->sql_query($sql);
		$sql = "UPDATE phpbb_posts
			SET post_subject = 'Re: [OLD] '
			WHERE post_id = 102";
		$this->db->sql_query($sql);
		$plans = iterator_to_array($this->create_repairer()->preview(2, '[OLD]'));
		self::assertCount(2, $plans);
		foreach ($plans as $plan)
		{
			self::assertSame('blank', $plan['reason']);
			self::assertSame([], $plan['changes']);
		}
	}

	public function test_divergent_or_cleaned_caches_are_preserved(): void
	{
		$this->prepare_topic();
		$sql = "UPDATE phpbb_topics
			SET topic_last_post_subject = 'Re: Last custom'
			WHERE topic_id = 10";
		$this->db->sql_query($sql);
		$sql = "UPDATE phpbb_forums
			SET forum_last_post_subject = '[OLD] Different'
			WHERE forum_id = 2";
		$this->db->sql_query($sql);
		$sql = 'UPDATE phpbb_forums
			SET forum_last_post_id = 100
			WHERE forum_id = 3';
		$this->db->sql_query($sql);
		$repairer = $this->create_repairer();
		$plans = iterator_to_array($repairer->preview(2, '[OLD]'));
		self::assertSame(0, $repairer->apply($plans[1])['caches']);
		self::assertSame('[OLD] Different', $this->field('phpbb_forums', 'forum_last_post_subject', 'forum_id = 2'));
		self::assertSame('Re: [OLD] Last custom', $this->field('phpbb_forums', 'forum_last_post_subject', 'forum_id = 3'));
	}

	public function test_cache_repair_does_not_conflict_with_later_reply_in_same_run(): void
	{
		$this->prepare_topic();
		$sql = "UPDATE phpbb_topics
			SET topic_last_post_id = 101, topic_last_post_subject = '[OLD] Custom subject'
			WHERE topic_id = 10";
		$this->db->sql_query($sql);
		$repairer = $this->create_repairer();
		$plans = iterator_to_array($repairer->preview(2, '[OLD]'));
		self::assertSame(1, $repairer->apply($plans[0])['caches']);
		self::assertSame(0, $repairer->apply($plans[1])['conflicts']);
	}

	public function test_changed_subject_or_topic_eligibility_skips_conflicts(): void
	{
		$this->prepare_topic();
		$repairer = $this->create_repairer();
		$plans = iterator_to_array($repairer->preview(2, '[OLD]'));
		$sql = "UPDATE phpbb_posts
			SET post_subject = '[OLD] Edited'
			WHERE post_id = 101";
		$this->db->sql_query($sql);
		self::assertSame(1, $repairer->apply($plans[0])['conflicts']);
		$sql = 'DELETE
			FROM phpbb_topic_prefixes_topics
			WHERE topic_id = 10';
		$this->db->sql_query($sql);
		self::assertSame(1, $repairer->apply($plans[1])['conflicts']);
		self::assertSame('Re: [OLD] Last custom', $this->field('phpbb_posts', 'post_subject', 'post_id = 102'));
	}

	public function test_forum_scope_and_post_forum_ownership(): void
	{
		$this->prepare_topic();
		$repairer = $this->create_repairer();
		self::assertSame([], iterator_to_array($repairer->preview(3, '[OLD]')));
		$sql = 'UPDATE phpbb_posts
			SET forum_id = 3
			WHERE post_id = 101';
		$this->db->sql_query($sql);
		self::assertSame('ownership', iterator_to_array($repairer->preview(2, '[OLD]'))[0]['reason']);
	}

	public function test_board_check_rejects_writes(): void
	{
		$this->prepare_topic();
		$repairer = $this->create_repairer();
		$plans = iterator_to_array($repairer->preview(2, '[OLD]'));
		$sql = "UPDATE phpbb_config
			SET config_value = '0'
			WHERE config_name = 'board_disable'";
		$this->db->sql_query($sql);
		try
		{
			$repairer->apply($plans[1]);
			self::fail('Expected maintenance failure');
		}
		catch (\RuntimeException $e)
		{
			self::assertSame('CLI_TOPIC_TAGS_REPAIR_BOARD_ENABLED', $e->getMessage());
		}
		$sql = "UPDATE phpbb_config
			SET config_value = '1'
			WHERE config_name = 'board_disable'";
		$this->db->sql_query($sql);
	}

	public function test_force_bypasses_board_check(): void
	{
		$this->prepare_topic();
		$repairer = $this->create_repairer();
		$plan = iterator_to_array($repairer->preview(2, '[OLD]'))[0];
		$this->db->sql_query("UPDATE phpbb_config SET config_value = '0' WHERE config_name = 'board_disable'");

		$result = $repairer->apply_batch([$plan], true, true);

		self::assertSame(1, $result['subjects']);
		self::assertSame('Custom subject', $this->field('phpbb_posts', 'post_subject', 'post_id = 101'));
	}

	public function test_bounded_preview_and_resume(): void
	{
		$this->prepare_topic();
		$sql = 'DELETE
			FROM phpbb_posts
			WHERE post_id BETWEEN 1000 AND 1100';
		$this->db->sql_query($sql);
		$rows = [];
		for ($id = 1000; $id <= 1100; $id++)
		{
			$rows[] = [
				'post_id' => $id,
				'topic_id' => 10,
				'forum_id' => 2,
				'post_subject' => '[OLD] Reply ' . $id,
				'post_text' => '',
			];
		}
		$this->insert_explicit_rows('phpbb_posts', $rows);
		$repairer = $this->create_repairer();
		$before_queries = $this->db->sql_num_queries();
		$plans = iterator_to_array($repairer->preview(2, '[OLD]'));
		self::assertLessThanOrEqual(10, $this->db->sql_num_queries() - $before_queries);
		self::assertCount(103, $plans);
		$repairer->apply($plans[0]);
		self::assertCount(102, iterator_to_array($repairer->preview(2, '[OLD]')));
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
