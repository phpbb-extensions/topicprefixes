<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\event;

use phpbb\language\language;
use phpbb\template\template;
use phpbb\topicprefixes\tags\assignment_manager;
use phpbb\topicprefixes\tags\renderer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Display topic tags on topic headings and topic-list pages outside viewforum.
 */
class display_listener implements EventSubscriberInterface
{
	/** @var assignment_manager Topic/tag assignment manager */
	protected $assignments;

	/** @var renderer Topic tag renderer */
	protected $renderer;

	/** @var template Template object */
	protected $template;

	/** @var language Language object */
	protected $language;

	/** @var bool Whether extension language has been loaded */
	protected $language_loaded = false;

	/** @var array Tags grouped by search result topic */
	protected $search_tags = [];

	/** @var array Tags grouped by MCP topic */
	protected $mcp_tags = [];

	/** @var array Tags grouped by UCP topic */
	protected $ucp_tags = [];

	/**
	 * Constructor.
	 *
	 * @param assignment_manager $assignments Topic/tag assignment manager
	 * @param renderer           $renderer    Topic tag renderer
	 * @param template           $template    Template object
	 * @param language           $language    Language object
	 */
	public function __construct(assignment_manager $assignments, renderer $renderer, template $template, language $language)
	{
		$this->assignments = $assignments;
		$this->renderer = $renderer;
		$this->template = $template;
		$this->language = $language;
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
			'core.mcp_forum_topic_data_modify_sql' => 'load_mcp_tags',
			'core.mcp_view_forum_modify_topicrow' => 'add_mcp_tags',
			'core.ucp_main_front_modify_topic_data' => 'load_ucp_tags', // event coming soon to phpBB 3.3.18/19
			'core.ucp_main_front_modify_template_vars' => 'add_ucp_front_tags',
			'core.ucp_main_topiclist_modify_topic_data' => 'load_ucp_tags', // event coming soon to phpBB 3.3.18/19
			'core.ucp_main_topiclist_topic_modify_template_vars' => 'add_ucp_topiclist_tags',
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
		$this->load_language();
		$topic_id = (int) $event['topic_id'];
		$tags = $this->assignments->get_tags_for_topics([$topic_id]);
		$topic_tags = $tags[$topic_id] ?? [];
		$this->template->assign_block_vars_array(
			'topic_tags',
			$this->renderer->render($topic_tags, (int) $event['forum_id'])
		);
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
		$this->load_language();
		$topic_id = (int) $event['row']['topic_id'];
		$tags = $this->search_tags[$topic_id] ?? [];
		$tpl = $event['tpl_ary'];
		$tpl['TOPIC_TAGS'] = $this->renderer->render($tags, (int) $event['row']['forum_id']);
		$event['tpl_ary'] = $tpl;
	}

	/**
	 * Batch-load tags for topics displayed in MCP forum view.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function load_mcp_tags($event): void
	{
		$this->mcp_tags = $this->assignments->get_tags_for_displayed_topics($event['topic_list']);
	}

	/**
	 * Add tag badge data to one MCP forum topic row.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function add_mcp_tags($event): void
	{
		$this->load_language();
		$topic_id = (int) $event['row']['topic_id'];
		$topic_row = $event['topic_row'];
		$topic_row['MCP_TOPIC_TAGS'] = $this->renderer->render(
			$this->mcp_tags[$topic_id] ?? [],
			(int) $event['row']['forum_id']
		);
		$event['topic_row'] = $topic_row;
	}

	/**
	 * Batch-load tags for topics displayed in a UCP topic list.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function load_ucp_tags($event): void
	{
		$this->ucp_tags = $this->assignments->get_tags_for_topics($event['topic_list']);
	}

	/**
	 * Add tag badge data to one UCP front-page topic row.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function add_ucp_front_tags($event): void
	{
		$this->load_language();
		$topic_id = (int) $event['row']['topic_id'];
		$topic_row = $event['topicrow'];
		$topic_row['TOPIC_TAGS'] = $this->renderer->render(
			$this->ucp_tags[$topic_id] ?? [],
			(int) $event['forum_id']
		);
		$event['topicrow'] = $topic_row;
	}

	/**
	 * Add tag badge data to one UCP watched/bookmarked topic row.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function add_ucp_topiclist_tags($event): void
	{
		$this->load_language();
		$topic_id = (int) $event['topic_id'];
		$template_vars = $event['template_vars'];
		$template_vars['TOPIC_TAGS'] = $this->renderer->render(
			$this->ucp_tags[$topic_id] ?? [],
			(int) $event['forum_id']
		);
		$event['template_vars'] = $template_vars;
	}

	/**
	 * Load tooltip language once for display contexts.
	 *
	 * @return void
	 */
	protected function load_language(): void
	{
		if (!$this->language_loaded)
		{
			$this->language->add_lang('topic_prefixes', 'phpbb/topicprefixes');
			$this->language_loaded = true;
		}
	}
}
