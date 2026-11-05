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
 * Recover missing topic tags from surviving old prefix text.
 */
class topic_repairer extends title_repairer
{
	/** @var string Tag definition table */
	protected $tags;

	/** @var string Topic/tag map table */
	protected $topic_map;

	/** @var string Forum/tag map table */
	protected $forum_map;

	/** @var array Forum tag rows cached for one command run */
	protected $forum_tags = [];

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
	 * @param string           $forum_map    Forum/tag map table
	 */
	public function __construct(
		driver_interface $db,
		cache $cache,
		string $topics,
		string $posts,
		string $forums,
		string $config_table,
		string $tags,
		string $topic_map,
		string $forum_map
	)
	{
		parent::__construct($db, $cache, $topics, $posts, $forums, $config_table);
		$this->tags = $tags;
		$this->topic_map = $topic_map;
		$this->forum_map = $forum_map;
	}

	/**
	 * Preview untagged topics whose leading text uniquely identifies available tags.
	 *
	 * @return \Generator Eligible and ambiguous recovery snapshots
	 */
	public function preview_recovery(): \Generator
	{
		$cursor = 0;
		do
		{
			$sql = 'SELECT t.topic_id, t.forum_id, t.topic_title, t.topic_moved_id,
					t.topic_first_post_id, t.topic_last_post_id, t.topic_last_post_subject
				FROM ' . $this->topics . ' t
				INNER JOIN ' . $this->forums . ' f ON f.forum_id = t.forum_id
				WHERE t.topic_id > ' . $cursor . '
					AND t.topic_moved_id = 0
					AND NOT EXISTS (
						SELECT 1 FROM ' . $this->topic_map . ' tt
						WHERE tt.topic_id = t.topic_id
					)
				ORDER BY t.topic_id';
			$result = $this->db->sql_query_limit($sql, self::BATCH_SIZE);
			$topics = [];
			while ($row = $this->db->sql_fetchrow($result))
			{
				$topics[] = $row;
			}
			$this->db->sql_freeresult($result);
			if (!$topics)
			{
				break;
			}

			$candidates = [];
			foreach ($topics as $topic)
			{
				$cursor = (int) $topic['topic_id'];
				$forum_tags = $this->get_forum_tags((int) $topic['forum_id']);
				$match = $this->match_prefix($topic['topic_title'], $forum_tags);
				if ($match !== null)
				{
					$candidates[] = [
						'topic' => $topic,
						'match' => $match,
					];
				}
			}

			$candidate_topics = array_column($candidates, 'topic');
			$posts = $this->load_posts($candidate_topics);
			$forum_caches = $this->load_forum_caches($candidate_topics);
			foreach ($candidates as $candidate)
			{
				$topic = $candidate['topic'];
				$match = $candidate['match'];
				if ($match['ambiguous'])
				{
					yield $this->ambiguous_plan($topic, $match);
					continue;
				}

				$plan = $this->plan_from_row($topic, $match['prefix'], $posts, $forum_caches);
				if ($plan !== null)
				{
					$plan['tag_ids'] = $match['tag_ids'];
					$plan['tag_names'] = $match['tag_names'];
					$plan['reason'] = 'eligible';
					yield $plan;
				}
			}
		}
		while (count($topics) === self::BATCH_SIZE);
	}

	/**
	 * Build a manual-only recovery snapshot for an ambiguous match.
	 *
	 * @param array $topic Topic row
	 * @param array $match Matched prefix details
	 * @return array Ambiguous recovery snapshot
	 */
	protected function ambiguous_plan(array $topic, array $match): array
	{
		return [
			'topic_id' => (int) $topic['topic_id'],
			'forum_id' => (int) $topic['forum_id'],
			'before' => $topic['topic_title'],
			'after' => $topic['topic_title'],
			'prefix' => $match['prefix'],
			'tag_ids' => $match['tag_ids'],
			'tag_names' => $match['tag_names'],
			'changes' => [],
			'skipped' => 0,
			'reason' => 'ambiguous',
		];
	}

	/**
	 * Load every tag available in one forum, including disabled historical tags.
	 *
	 * @param int $forum_id Forum identifier
	 * @return array Tag rows available in the forum
	 */
	protected function get_forum_tags(int $forum_id): array
	{
		if (isset($this->forum_tags[$forum_id]))
		{
			return $this->forum_tags[$forum_id];
		}

		$sql = 'SELECT p.prefix_id, p.prefix_tag
			FROM ' . $this->tags . ' p
			INNER JOIN ' . $this->forum_map . ' pf ON pf.prefix_id = p.prefix_id
			WHERE pf.forum_id = ' . $forum_id . '
			ORDER BY p.prefix_id';
		$result = $this->db->sql_query($sql);
		$tags = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			if ($row['prefix_tag'] !== '')
			{
				$tags[] = [
					'prefix_id' => (int) $row['prefix_id'],
					'prefix_tag' => $row['prefix_tag'],
				];
			}
		}
		$this->db->sql_freeresult($result);

		return $this->forum_tags[$forum_id] = $tags;
	}

	/**
	 * Find the longest unique sequence of adjacent tag names followed by a space.
	 *
	 * @param string $title Stored topic title
	 * @param array  $tags  Tag rows available in the topic's forum
	 * @return array|null Matched prefix and tags, or null when no prefix matches
	 */
	protected function match_prefix(string $title, array $tags): ?array
	{
		$states = [0 => ['' => []]];
		$matches = [];
		$title_length = strlen($title);
		for ($offset = 0; $offset < $title_length; $offset++)
		{
			if (empty($states[$offset]))
			{
				continue;
			}
			foreach ($states[$offset] as $path)
			{
				foreach ($tags as $tag)
				{
					$name = $tag['prefix_tag'];
					if (substr($title, $offset, strlen($name)) !== $name)
					{
						continue;
					}
					$next = $offset + strlen($name);
					$next_path = $path;
					$next_path[] = $tag;
					$signature = implode(',', array_column($next_path, 'prefix_id'));
					if (!isset($states[$next][$signature]) && count($states[$next] ?? []) < 2)
					{
						$states[$next][$signature] = $next_path;
					}
					if ($next < $title_length
						&& $title[$next] === ' '
						&& !isset($matches[$next][$signature])
						&& count($matches[$next] ?? []) < 2)
					{
						$matches[$next][$signature] = $next_path;
					}
				}
			}
		}
		if (!$matches)
		{
			return null;
		}

		$longest = max(array_keys($matches));
		$paths = $matches[$longest];
		$tag_ids = [];
		$tag_names = [];
		foreach ($paths as $path)
		{
			foreach ($path as $tag)
			{
				$tag_id = (int) $tag['prefix_id'];
				$tag_name = $tag['prefix_tag'];
				$tag_ids[$tag_id] = $tag_id;
				$tag_names[$tag_name] = $tag_name;
			}
		}

		return [
			'prefix' => substr($title, 0, $longest),
			'tag_ids' => array_values($tag_ids),
			'tag_names' => array_values($tag_names),
			'ambiguous' => count($paths) > 1,
		];
	}

	/**
	 * Create an empty recovery change count set.
	 *
	 * @return array Empty recovery change counts
	 */
	protected function empty_counts(): array
	{
		return ['assignments' => 0] + parent::empty_counts();
	}

	/**
	 * List text and relationship tables that one recovery batch may write.
	 *
	 * @param array $plans Confirmed recovery snapshots
	 * @return array Unique table names
	 */
	protected function get_write_tables(array $plans): array
	{
		$tables = parent::get_write_tables($plans);
		foreach ($plans as $plan)
		{
			if (($plan['reason'] ?? '') === 'eligible')
			{
				$tables[] = $this->topic_map;
				break;
			}
		}

		return array_values(array_unique($tables));
	}

	/**
	 * Validate and insert tag assignments for one recovery batch.
	 *
	 * @param array $plans  Confirmed recovery snapshots
	 * @param array $counts Applied change counts, updated by reference
	 * @return void
	 * @throws \RuntimeException When assignments no longer match a snapshot
	 */
	protected function apply_batch_relations(array $plans, array &$counts): void
	{
		$topic_ids = [];
		foreach ($plans as $plan)
		{
			if (($plan['reason'] ?? '') === 'eligible')
			{
				$topic_ids[] = (int) $plan['topic_id'];
			}
		}
		if (!$topic_ids)
		{
			return;
		}

		$result = $this->db->sql_query('SELECT topic_id FROM ' . $this->topic_map . '
			WHERE ' . $this->db->sql_in_set('topic_id', $topic_ids));
		if ($this->db->sql_fetchrow($result))
		{
			$this->db->sql_freeresult($result);
			throw new \RuntimeException('CLI_TOPIC_REPAIR_CONFLICT');
		}
		$this->db->sql_freeresult($result);

		$rows = [];
		$forum_ids = [];
		$tag_ids = [];
		foreach ($plans as $plan)
		{
			if (($plan['reason'] ?? '') !== 'eligible')
			{
				continue;
			}
			foreach ($plan['tag_ids'] as $tag_id)
			{
				$rows[] = [
					'topic_id' => (int) $plan['topic_id'],
					'prefix_id' => (int) $tag_id,
				];
				$forum_ids[(int) $plan['forum_id']] = true;
				$tag_ids[(int) $tag_id] = true;
			}
		}
		$result = $this->db->sql_query('SELECT pf.forum_id, pf.prefix_id
			FROM ' . $this->forum_map . ' pf
			INNER JOIN ' . $this->tags . ' p ON p.prefix_id = pf.prefix_id
			WHERE ' . $this->db->sql_in_set('pf.forum_id', array_keys($forum_ids)) . '
				AND ' . $this->db->sql_in_set('pf.prefix_id', array_keys($tag_ids)));
		$available = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$availability_key = (int) $row['forum_id'] . ':' . (int) $row['prefix_id'];
			$available[$availability_key] = true;
		}
		$this->db->sql_freeresult($result);

		foreach ($plans as $plan)
		{
			if (($plan['reason'] ?? '') !== 'eligible')
			{
				continue;
			}
			foreach ($plan['tag_ids'] as $tag_id)
			{
				$availability_key = (int) $plan['forum_id'] . ':' . (int) $tag_id;
				if (!isset($available[$availability_key]))
				{
					throw new \RuntimeException('CLI_TOPIC_REPAIR_CONFLICT');
				}
			}
		}
		foreach (array_chunk($rows, 500) as $batch)
		{
			$this->db->sql_multi_insert($this->topic_map, $batch);
		}

		$counts['assignments'] += count($rows);
	}

	/**
	 * Invalidate relationship and text caches changed by recovery.
	 *
	 * @return void
	 */
	public function invalidate_caches(): void
	{
		$this->cache->destroy(manager::CACHE_KEY);
		$this->cache->destroy('sql', $this->topic_map);
		parent::invalidate_caches();
	}
}
