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
use phpbb\request\request;
use phpbb\template\template;
use phpbb\topicprefixes\tags\assignment_manager;
use phpbb\topicprefixes\tags\filter;
use phpbb\topicprefixes\tags\manager;
use phpbb\topicprefixes\tags\renderer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Integrate tag filtering and badges with forum topic lists.
 */
class viewforum_listener implements EventSubscriberInterface
{
	/** @var manager Topic tag manager */
	protected $manager;

	/** @var assignment_manager Topic/tag assignment manager */
	protected $assignments;

	/** @var filter Topic tag SQL filter */
	protected $filter;

	/** @var renderer Topic tag renderer */
	protected $renderer;

	/** @var request Request object */
	protected $request;

	/** @var template Template object */
	protected $template;

	/** @var language Language object */
	protected $language;

	/** @var int Forum identifier */
	protected $forum_id = 0;

	/** @var array Selected tag identifiers */
	protected $selected_ids = [];

	/** @var array Current topic sort parameters */
	protected $sort_params = [];

	/** @var array Tags grouped by topic identifier */
	protected $topic_tags = [];

	/** @var array Effective tag-bearing topic IDs keyed by displayed topic ID */
	protected $tag_topic_ids = [];

	/**
	 * Constructor.
	 *
	 * @param manager            $manager     Topic tag manager
	 * @param assignment_manager $assignments Topic/tag assignment manager
	 * @param filter             $filter      Topic tag SQL filter
	 * @param renderer           $renderer    Topic tag renderer
	 * @param request            $request     Request object
	 * @param template           $template    Template object
	 * @param language           $language    Language object
	 */
	public function __construct(manager $manager, assignment_manager $assignments, filter $filter, renderer $renderer, request $request, template $template, language $language)
	{
		$this->manager = $manager;
		$this->assignments = $assignments;
		$this->filter = $filter;
		$this->renderer = $renderer;
		$this->request = $request;
		$this->template = $template;
		$this->language = $language;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'core.viewforum_get_topic_data' => 'configure_filter',
			'core.viewforum_get_announcement_topic_ids_data' => 'filter_announcements',
			'core.viewforum_get_topic_ids_data' => 'filter_topic_ids',
			'core.viewforum_modify_topics_data' => 'load_topic_tags',
			'core.viewforum_modify_topicrow' => 'add_topic_tags',
			'core.pagination_generate_page_link' => 'preserve_pagination_filter',
		];
	}

	/**
	 * Read and validate filters before phpBB builds topic queries.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function configure_filter($event): void
	{
		$this->forum_id = (int) $event['forum_id'];
		$this->language->add_lang('topic_prefixes', 'phpbb/topicprefixes');

		$forum_tags = $this->manager->get_available_tags($this->forum_id, false);
		$available = array_filter($forum_tags, function ($tag) {
			return !empty($tag['prefix_enabled']);
		});
		$unavailable_ids = $this->manager->get_unavailable_tag_ids($this->forum_id);
		$assigned_ids = $unavailable_ids
			? $this->assignments->get_tag_ids_for_forum($this->forum_id, $unavailable_ids)
			: [];
		$assigned = $this->manager->get_tags_by_ids($assigned_ids);
		$filterable = $forum_tags + $assigned;

		// Include tags preserved on topics moved from another forum.
		foreach ($assigned as $tag_id => $tag)
		{
			if (!isset($forum_tags[$tag_id]))
			{
				$available[$tag_id] = $tag;
			}
		}
		$this->selected_ids = array_values(array_intersect($this->get_requested_ids(), array_keys($filterable)));

		// Keep disabled selected tags visible so users can remove active filters.
		foreach ($this->selected_ids as $tag_id)
		{
			if (!isset($available[$tag_id]))
			{
				$available[$tag_id] = $filterable[$tag_id];
			}
		}
		uasort($available, [$this, 'compare_tags']);

		$this->sort_params = [
			'st' => (int) $event['sort_days'],
			'sk' => $event['sort_key'],
			'sd' => $event['sort_dir'],
		];

		if ($this->selected_ids)
		{
			$event['topics_count'] = $this->filter->count_topics($this->forum_id, $this->selected_ids, $event['sort_days']);
		}

		$this->template->assign_vars([
			'S_TOPIC_TAG_FILTERS' => !empty($available),
			'TOPIC_TAG_FILTERS' => $this->renderer->render($available, $this->forum_id, $this->selected_ids, $this->sort_params, true),
			'U_CLEAR_TOPIC_TAG_FILTERS' => $this->renderer->filter_url($this->forum_id, [], $this->sort_params),
			'S_TOPIC_TAG_FILTERED' => !empty($this->selected_ids),
			'S_FORUM_ACTION' => $this->renderer->filter_url($this->forum_id, $this->selected_ids),
		]);
	}

	/**
	 * Apply selected tags to announcement query.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function filter_announcements($event): void
	{
		if (!$this->selected_ids)
		{
			return;
		}

		$sql = $event['sql_ary'];
		$sql['WHERE'] = '(' . $sql['WHERE'] . ') AND ' . $this->filter->condition('t', $this->selected_ids);
		$event['sql_ary'] = $sql;
	}

	/**
	 * Apply selected tags to regular topic ID query.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function filter_topic_ids($event): void
	{
		if (!$this->selected_ids)
		{
			return;
		}

		$sql = $event['sql_ary'];
		$sql['WHERE'] .= ' AND ' . $this->filter->condition('t', $this->selected_ids);
		$event['sql_ary'] = $sql;
	}

	/**
	 * Batch-load tags for displayed topic rows.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function load_topic_tags($event): void
	{
		$topic_ids = [];
		$this->tag_topic_ids = [];
		foreach ($event['rowset'] as $row)
		{
			$topic_id = (int) $row['topic_id'];
			$tag_topic_id = !empty($row['topic_moved_id']) ? (int) $row['topic_moved_id'] : $topic_id;
			$this->tag_topic_ids[$topic_id] = $tag_topic_id;
			$topic_ids[] = $tag_topic_id;
		}
		$this->topic_tags = $this->assignments->get_tags_for_topics($topic_ids);
	}

	/**
	 * Add tag badge data to one topic row.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function add_topic_tags($event): void
	{
		$topic_id = (int) $event['row']['topic_id'];
		$tag_topic_id = $this->tag_topic_ids[$topic_id] ?? $topic_id;
		$tags = $this->topic_tags[$tag_topic_id] ?? [];
		$topic_row = $event['topic_row'];
		$topic_row['TOPIC_TAGS'] = $this->renderer->render($tags, $this->forum_id, $this->selected_ids, $this->sort_params);
		$event['topic_row'] = $topic_row;
	}

	/**
	 * Preserve selected tags in phpBB pagination URLs.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function preserve_pagination_filter($event): void
	{
		if (!$this->selected_ids || !is_string($event['base_url']) || strpos($event['base_url'], 'viewforum.') === false || strpos($event['base_url'], 'tags=') !== false)
		{
			return;
		}

		$delimiter = strpos($event['base_url'], '?') === false ? '?' : '&amp;';
		$event['base_url'] .= $delimiter . 'tags=' . implode(',', $this->selected_ids);
	}

	/**
	 * Parse comma-separated tag identifiers from request.
	 *
	 * @return array Tag identifiers
	 */
	protected function get_requested_ids(): array
	{
		$value = trim($this->request->variable('tags', ''));
		if ($value === '' || !preg_match('/^\d+(?:,\d+)*$/D', $value))
		{
			return [];
		}

		return array_values(array_unique(array_filter(array_map('intval', explode(',', $value)))));
	}

	/**
	 * Sort tags by administrator order then identifier.
	 *
	 * @param array $left  Left tag
	 * @param array $right Right tag
	 * @return int Comparison result
	 */
	protected function compare_tags(array $left, array $right): int
	{
		$order = (int) $left['prefix_order'] <=> (int) $right['prefix_order'];

		return $order ?: (int) $left['prefix_id'] <=> (int) $right['prefix_id'];
	}
}
