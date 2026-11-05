<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\tags;

use phpbb\cache\driver\driver_interface as cache;
use phpbb\db\driver\driver_interface;

/**
 * Repair reply subjects in tagged topics whose titles no longer start with the prefix.
 */
class reply_repairer extends title_repairer
{
	/** @var string Tag definition table */
	protected $tags;

	/** @var string Topic/tag map table */
	protected $topic_map;

	/**
	 * Constructor.
	 *
	 * @param driver_interface $db           Database connection
	 * @param cache            $cache        Cache driver
	 * @param string           $topics       Topics table
	 * @param string           $posts        Posts table
	 * @param string           $forums       Forums table
	 * @param string           $config_table Configuration table
	 * @param string           $tags         Tag definition table
	 * @param string           $topic_map    Topic/tag map table
	 */
	public function __construct(
		driver_interface $db,
		cache $cache,
		string $topics,
		string $posts,
		string $forums,
		string $config_table,
		string $tags,
		string $topic_map
	)
	{
		parent::__construct($db, $cache, $topics, $posts, $forums, $config_table);
		$this->tags = $tags;
		$this->topic_map = $topic_map;
	}

	/**
	 * Remove one literal prefix at the start or after one standard Re: marker.
	 *
	 * Custom wording and the stored suffix remain intact. Callers must reject
	 * empty results and bare reply markers before applying the change.
	 *
	 * @param string $subject Stored reply subject
	 * @param string $prefix  Display-form literal prefix
	 * @return string Cleaned stored subject, or the original subject when unmatched
	 * @throws \InvalidArgumentException When the prefix is invalid
	 */
	public static function clean_reply(string $subject, string $prefix): string
	{
		$after = parent::clean($subject, $prefix);
		if ($after !== $subject || strpos($subject, 'Re: ') !== 0)
		{
			return $after;
		}

		return 'Re: ' . parent::clean(substr($subject, 4), $prefix);
	}

	/**
	 * Preview matching replies, including matches skipped by topic eligibility checks.
	 *
	 * @param int    $forum_id Selected forum identifier, excluding descendants
	 * @param string $prefix   Display-form literal prefix
	 * @return \Generator Reply repair snapshots and skip reasons
	 * @throws \InvalidArgumentException When the prefix is invalid
	 */
	public function preview(int $forum_id, string $prefix): \Generator
	{
		self::validate_prefix($prefix);
		$cursor = 0;
		do
		{
			$sql = 'SELECT p.post_id, p.topic_id AS post_topic_id, p.forum_id AS post_forum_id,
					p.post_subject, t.topic_id, t.forum_id, t.topic_title, t.topic_first_post_id,
					t.topic_last_post_id, t.topic_moved_id, t.topic_last_post_subject
				FROM ' . $this->posts . ' p
				INNER JOIN ' . $this->topics . ' t ON t.topic_id = p.topic_id
				INNER JOIN ' . $this->forums . ' f ON f.forum_id = t.forum_id
				WHERE t.forum_id = ' . $forum_id . '
					AND p.post_id <> t.topic_first_post_id
					AND p.post_id > ' . $cursor . '
				ORDER BY p.post_id';
			$result = $this->db->sql_query_limit($sql, self::BATCH_SIZE);
			$rows = [];
			while ($row = $this->db->sql_fetchrow($result))
			{
				$rows[] = $row;
			}
			$this->db->sql_freeresult($result);
			if (!$rows)
			{
				break;
			}

			$topic_ids = array_values(array_unique(array_map('intval', array_column($rows, 'topic_id'))));
			$tag_ids = $this->load_topic_tags($topic_ids);
			$posts = $this->load_posts($rows);
			$forum_caches = $this->load_forum_caches($rows);
			foreach ($rows as $row)
			{
				$cursor = (int) $row['post_id'];
				$plan = $this->reply_plan_from_row(
					$row,
					$prefix,
					$tag_ids[(int) $row['topic_id']] ?? [],
					$posts,
					$forum_caches
				);
				if ($plan !== null)
				{
					yield $plan;
				}
			}
		}
		while (count($rows) === self::BATCH_SIZE);
	}

	/**
	 * Load current tag assignments for a set of topics.
	 *
	 * @param array $topic_ids Topic identifiers
	 * @return array Tag identifiers grouped by topic identifier
	 */
	protected function load_topic_tags(array $topic_ids): array
	{
		if (!$topic_ids)
		{
			return [];
		}

		$result = $this->db->sql_query('SELECT tt.topic_id, tt.prefix_id
			FROM ' . $this->topic_map . ' tt
			INNER JOIN ' . $this->tags . ' p ON p.prefix_id = tt.prefix_id
			WHERE ' . $this->db->sql_in_set('tt.topic_id', $topic_ids) . '
			ORDER BY tt.topic_id, tt.prefix_id');
		$tags = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$tags[(int) $row['topic_id']][] = (int) $row['prefix_id'];
		}
		$this->db->sql_freeresult($result);

		return $tags;
	}

	/**
	 * Build one reply snapshot from batch-loaded rows.
	 *
	 * @param array  $row          Joined reply and topic row
	 * @param string $prefix       Display-form literal prefix
	 * @param array  $tag_ids      Current topic tag identifiers
	 * @param array  $posts        Post rows keyed by post identifier
	 * @param array  $forum_caches Forum rows grouped by last post identifier
	 * @return array|null Reply snapshot, or null when the subject is unmatched
	 * @throws \InvalidArgumentException When the prefix is invalid
	 */
	protected function reply_plan_from_row(
		array $row,
		string $prefix,
		array $tag_ids,
		array $posts,
		array $forum_caches
	): ?array
	{
		$post_id = (int) $row['post_id'];
		$topic_id = (int) $row['topic_id'];
		$forum_id = (int) $row['forum_id'];
		$post = [
			'post_id' => $post_id,
			'topic_id' => $row['post_topic_id'],
			'forum_id' => $row['post_forum_id'],
			'post_subject' => $row['post_subject'],
		];
		$topic = [
			'topic_id' => $row['topic_id'],
			'forum_id' => $row['forum_id'],
			'topic_title' => $row['topic_title'],
			'topic_first_post_id' => $row['topic_first_post_id'],
			'topic_last_post_id' => $row['topic_last_post_id'],
			'topic_moved_id' => $row['topic_moved_id'],
			'topic_last_post_subject' => $row['topic_last_post_subject'],
		];
		$after = self::clean_reply($post['post_subject'], $prefix);
		if ($after === $post['post_subject'])
		{
			return null;
		}

		$snapshot = $topic;
		unset($snapshot['topic_last_post_subject']);
		$plan = $this->new_reply_plan($topic, $post, $prefix, $after, $snapshot, $tag_ids);
		$first = $posts[(int) $topic['topic_first_post_id']] ?? null;
		if (!empty($topic['topic_moved_id']))
		{
			$plan['reason'] = 'shadow';
		}
		else if ((int) $post['forum_id'] !== $forum_id || !$first
			|| (int) $first['topic_id'] !== $topic_id || (int) $first['forum_id'] !== $forum_id)
		{
			$plan['reason'] = 'ownership';
		}
		else if (!$tag_ids)
		{
			$plan['reason'] = 'untagged';
		}
		else if (self::clean($topic['topic_title'], $prefix) !== $topic['topic_title'])
		{
			$plan['reason'] = 'uncleaned';
		}
		else if ($this->blank_reply($after))
		{
			$plan['reason'] = 'blank';
		}
		if ($plan['reason'] !== 'eligible')
		{
			$plan['skipped'] = 1;
			return $plan;
		}

		$plan['changes'][] = [
			'table' => $this->posts,
			'key' => 'post_id',
			'row' => $post,
			'field' => 'post_subject',
			'after' => $after,
		];
		if ((int) $topic['topic_last_post_id'] === $post_id
			&& $topic['topic_last_post_subject'] === $post['post_subject'])
		{
			$plan['changes'][] = [
				'table' => $this->topics,
				'key' => 'topic_id',
				'row' => $topic,
				'field' => 'topic_last_post_subject',
				'after' => $after,
			];
		}
		foreach ($forum_caches[$post_id] ?? [] as $forum)
		{
			if ($forum['forum_last_post_subject'] === $post['post_subject'])
			{
				$plan['changes'][] = [
					'table' => $this->forums,
					'key' => 'forum_id',
					'row' => $forum,
					'field' => 'forum_last_post_subject',
					'after' => $after,
				];
			}
		}

		return $plan;
	}

	/**
	 * Create the common structure for one reply repair snapshot.
	 *
	 * @param array  $topic    Topic row
	 * @param array  $post     Reply row
	 * @param string $prefix   Display-form literal prefix
	 * @param string $after    Cleaned reply subject
	 * @param array  $snapshot Topic values used to guard the repair
	 * @param array  $tag_ids  Current topic tag identifiers
	 * @return array Reply repair snapshot
	 */
	protected function new_reply_plan(
		array $topic,
		array $post,
		string $prefix,
		string $after,
		array $snapshot,
		array $tag_ids
	): array
	{
		return [
			'topic_id' => (int) $topic['topic_id'],
			'post_id' => (int) $post['post_id'],
			'forum_id' => (int) $topic['forum_id'],
			'prefix' => $prefix,
			'title' => $topic['topic_title'],
			'before' => $post['post_subject'],
			'after' => $after,
			'snapshot' => $snapshot,
			'tag_ids' => $tag_ids,
			'changes' => [],
			'skipped' => 0,
			'reason' => 'eligible',
		];
	}

	/**
	 * Check for an empty subject or a bare standard reply marker.
	 *
	 * @param string $subject Stored reply subject
	 * @return bool Whether cleanup would leave no substantive text
	 */
	protected function blank_reply(string $subject): bool
	{
		return (bool) preg_match('/\A(?:Re:)?[\s\p{Z}]*\z/u', manager::decode_name($subject));
	}

	/**
	 * Revalidate and apply a batch of reply repair snapshots.
	 *
	 * @param array $plans      Confirmed reply repair snapshots
	 * @param bool  $invalidate Whether to invalidate SQL caches after the batch
	 * @param bool  $force      Whether to allow repairs while the board is enabled
	 * @return array Applied change and conflict counts
	 * @throws \RuntimeException When maintenance mode fails or data conflicts
	 * @throws \Throwable When a transaction fails
	 */
	public function apply_batch(array $plans, bool $invalidate = true, bool $force = false): array
	{
		if (!$plans)
		{
			return $this->empty_counts();
		}

		$topic_ids = array_values(array_unique(array_map('intval', array_column($plans, 'topic_id'))));
		$result = $this->db->sql_query('SELECT topic_id, forum_id, topic_title, topic_first_post_id,
			topic_last_post_id, topic_moved_id, topic_last_post_subject
			FROM ' . $this->topics . '
			WHERE ' . $this->db->sql_in_set('topic_id', $topic_ids));
		$topics = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$topics[(int) $row['topic_id']] = $row;
		}
		$this->db->sql_freeresult($result);

		$tags = $this->load_topic_tags($topic_ids);
		$first_posts = $this->load_posts(array_values($topics));
		$valid = [];
		$conflicts = 0;
		foreach ($plans as $plan)
		{
			$topic = $topics[(int) $plan['topic_id']] ?? null;
			if ($topic)
			{
				$snapshot = $topic;
				unset($snapshot['topic_last_post_subject']);
				$first = $first_posts[(int) $topic['topic_first_post_id']] ?? null;
			}
			if (!$topic || $snapshot !== $plan['snapshot']
				|| ($tags[(int) $plan['topic_id']] ?? []) !== $plan['tag_ids']
				|| !$first || (int) $first['topic_id'] !== (int) $plan['topic_id']
				|| (int) $first['forum_id'] !== (int) $plan['forum_id'])
			{
				$conflicts++;
				continue;
			}
			$valid[] = $plan;
		}
		$counts = $valid ? parent::apply_batch($valid, $invalidate, $force) : $this->empty_counts();
		$counts['conflicts'] += $conflicts;
		if (!$valid && $invalidate)
		{
			$this->invalidate_caches();
		}

		return $counts;
	}
}
