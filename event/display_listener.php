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
use phpbb\topicprefixes\tags\renderer;
use phpbb\template\template;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Display topic tags on viewtopic and search result pages.
 */
class display_listener implements EventSubscriberInterface
{
	/** @var assignment_manager Topic/tag assignment manager */
	protected $assignments;

	/** @var renderer Topic tag renderer */
	protected $renderer;

	/** @var template Template object */
	protected $template;

	/** @var array Tags grouped by search result topic */
	protected $search_tags = [];

	/**
	 * Constructor.
	 *
	 * @param assignment_manager $assignments Topic/tag assignment manager
	 * @param renderer           $renderer    Topic tag renderer
	 * @param template           $template    Template object
	 */
	public function __construct(assignment_manager $assignments, renderer $renderer, template $template)
	{
		$this->assignments = $assignments;
		$this->renderer = $renderer;
		$this->template = $template;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'core.viewtopic_assign_template_vars_before' => 'add_viewtopic_tags',
			'core.search_modify_rowset' => 'load_search_tags',
			'core.search_modify_tpl_ary' => 'add_search_tags',
		];
	}

	/**
	 * Assign tags for current viewtopic heading.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function add_viewtopic_tags($event): void
	{
		$topic_id = (int) $event['topic_id'];
		$tags = $this->assignments->get_tags_for_topics([$topic_id]);
		$topic_tags = $tags[$topic_id] ?? [];
		$this->template->assign_var('TOPIC_TAGS', $this->renderer->render($topic_tags, (int) $event['forum_id']));
	}

	/**
	 * Batch-load tags for search result topics.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function load_search_tags($event): void
	{
		$topic_ids = [];
		foreach ($event['rowset'] as $row)
		{
			$topic_ids[] = (int) $row['topic_id'];
		}
		$this->search_tags = $this->assignments->get_tags_for_topics($topic_ids);
	}

	/**
	 * Add tag badge data to one search result row.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function add_search_tags($event): void
	{
		$topic_id = (int) $event['row']['topic_id'];
		$tags = $this->search_tags[$topic_id] ?? [];
		$tpl = $event['tpl_ary'];
		$tpl['TOPIC_TAGS'] = $this->renderer->render($tags, (int) $event['row']['forum_id']);
		$event['tpl_ary'] = $tpl;
	}
}
