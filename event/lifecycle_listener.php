<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2016 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\event;

use phpbb\topicprefixes\tags\assignment_manager;
use phpbb\topicprefixes\tags\manager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keep tag relationships consistent across phpBB topic lifecycle operations.
 */
class lifecycle_listener implements EventSubscriberInterface
{
	/** @var assignment_manager */
	protected $assignments;

	/** @var manager */
	protected $manager;

	/** @var string */
	protected $topic_map_table;

	/** @var array Tag IDs captured immediately before topic deletion */
	protected $deleted_topic_tags = [];

	/** @var array Forked topic IDs already processed */
	protected $processed_forks = [];

	/**
	 * Constructor.
	 *
	 * @param assignment_manager $assignments    Topic/tag assignment manager
	 * @param manager            $manager        Tag manager
	 * @param string             $topic_map_table Topic/tag map table
	 */
	public function __construct(assignment_manager $assignments, manager $manager, $topic_map_table)
	{
		$this->assignments = $assignments;
		$this->manager = $manager;
		$this->topic_map_table = $topic_map_table;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'core.delete_topics_before_query' => 'delete_topic_relationships',
			'core.delete_forum_content_before_query' => 'delete_forum_topic_relationships',
			'core.acp_manage_forums_move_content_after' => 'delete_moved_forum_availability',
			'core.mcp_topic_split_topic_after' => 'copy_split_tags',
			'core.mcp_main_fork_sql_after' => 'copy_fork_tags',
			'core.mcp_forum_merge_topics_after' => 'merge_topics_tags',
			'core.mcp_topics_merge_posts_after' => 'merge_posts_tags',
			'core.acp_users_move_posts_after' => 'copy_relocated_post_tags',
		];
	}

	/**
	 * Include topic/tag rows in phpBB's normal topic deletion transaction.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function delete_topic_relationships($event): void
	{
		$topic_ids = array_map('intval', $event['topic_ids']);
		foreach ($this->assignments->get_topic_tag_ids_for_topics($topic_ids) as $topic_id => $tag_ids)
		{
			$this->deleted_topic_tags[(int) $topic_id] = $tag_ids;
		}

		$tables = $event['table_ary'];
		if (!in_array($this->topic_map_table, $tables, true))
		{
			$tables[] = $this->topic_map_table;
		}
		$event['table_ary'] = $tables;
	}

	/**
	 * Remove assignments before phpBB directly deletes every topic in a forum.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function delete_forum_topic_relationships($event): void
	{
		$forum_id = (int) $event['forum_id'];
		$this->assignments->delete_forum_topic_assignments($forum_id);
		$this->manager->delete_forum_availability([$forum_id]);
	}

	/**
	 * Remove availability after all content leaves a postable forum.
	 *
	 * phpBB uses this operation when deleting a forum while moving its content,
	 * and when converting a postable forum to a non-postable forum.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function delete_moved_forum_availability($event): void
	{
		$this->manager->delete_forum_availability([(int) $event['from_id']]);
	}

	/**
	 * Copy source-topic tags to a newly split topic.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function copy_split_tags($event): void
	{
		$source_topic_id = (int) $event['topic_id'];
		if (isset($this->deleted_topic_tags[$source_topic_id]))
		{
			$this->assignments->set_topic_tags((int) $event['to_topic_id'], $this->deleted_topic_tags[$source_topic_id]);
			unset($this->deleted_topic_tags[$source_topic_id]);
			return;
		}

		$this->assignments->copy_topic_tags($source_topic_id, (int) $event['to_topic_id']);
	}

	/**
	 * Copy source-topic tags to a newly forked topic once per fork.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function copy_fork_tags($event): void
	{
		$new_topic_id = (int) $event['new_topic_id'];
		if (isset($this->processed_forks[$new_topic_id]))
		{
			return;
		}

		$this->processed_forks[$new_topic_id] = true;
		$this->assignments->copy_topic_tags((int) $event['row']['topic_id'], $new_topic_id);
	}

	/**
	 * Preserve all source tags when complete topics are merged.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function merge_topics_tags($event): void
	{
		$target_topic_id = (int) $event['to_topic_id'];
		$source_topic_ids = array_diff(array_map('intval', array_keys($event['all_topic_data'])), [$target_topic_id]);
		$this->merge_deleted_tags($source_topic_ids, $target_topic_id);
	}

	/**
	 * Preserve source tags when merging posts removes the source topic.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function merge_posts_tags($event): void
	{
		$source_topic_id = (int) $event['topic_id'];
		if (!$this->assignments->topic_exists($source_topic_id))
		{
			$this->merge_deleted_tags([$source_topic_id], (int) $event['to_topic_id']);
		}
	}

	/**
	 * Copy tags when ACP user administration creates topics from relocated posts.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function copy_relocated_post_tags($event): void
	{
		foreach ($event['new_topic_id_map'] as $source_topic_id => $new_topic_id)
		{
			$this->assignments->copy_topic_tags((int) $source_topic_id, (int) $new_topic_id);
		}
	}

	/**
	 * Add captured source tags to a merge destination.
	 *
	 * @param array $source_topic_ids Source topic identifiers
	 * @param int   $target_topic_id  Destination topic identifier
	 * @return void
	 */
	protected function merge_deleted_tags(array $source_topic_ids, int $target_topic_id): void
	{
		$tag_ids = [];
		foreach ($source_topic_ids as $source_topic_id)
		{
			$source_topic_id = (int) $source_topic_id;
			$tag_ids = array_merge($tag_ids, $this->deleted_topic_tags[$source_topic_id] ?? $this->assignments->get_topic_tag_ids($source_topic_id));
			unset($this->deleted_topic_tags[$source_topic_id]);
		}

		if ($tag_ids)
		{
			$this->assignments->add_topic_tags($target_topic_id, $tag_ids);
		}
	}
}
