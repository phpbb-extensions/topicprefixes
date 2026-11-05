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

use phpbb\auth\auth;
use phpbb\config\config;
use phpbb\language\language;
use phpbb\request\request;
use phpbb\template\template;
use phpbb\topicprefixes\tags\assignment_manager;
use phpbb\topicprefixes\tags\filter;
use phpbb\topicprefixes\tags\manager;
use phpbb\topicprefixes\tags\renderer;
use phpbb\user;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Filter SQL-backed topic searches and display filterable search badges.
 */
class search_listener implements EventSubscriberInterface
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

	/** @var auth Authorization service */
	protected $auth;

	/** @var user Current user and session */
	protected $user;

	/** @var config Board configuration */
	protected $config;

	/** @var array|null Selected exact tag records */
	protected $selected_tags;

	/** @var string all or any */
	protected $match = 'all';

	/** @var bool Whether current request is tag-only */
	protected $tag_only = false;

	/** @var array Tags grouped by topic identifier */
	protected $search_tags = [];

	/** @var bool Whether current search supports topic tag filtering */
	protected $filterable = false;

	/** @var string Unfiltered search URL */
	protected $filter_url = '';

	/** @var string URL with no tag filter or match mode */
	protected $clear_filter_url = '';

	/** @var bool Whether extension language has been loaded */
	protected $language_loaded = false;

	/** @var string Current predefined search identifier */
	protected $predefined_search = '';

	/** @var array|null Searchable forum IDs for current session */
	protected $searchable_forum_ids;

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
	 * @param auth               $auth        Authorization service
	 * @param user               $user        Current user and session
	 * @param config             $config      Board configuration
	 */
	public function __construct(manager $manager, assignment_manager $assignments, filter $filter, renderer $renderer, request $request, template $template, language $language, auth $auth, user $user, config $config)
	{
		$this->manager = $manager;
		$this->assignments = $assignments;
		$this->filter = $filter;
		$this->renderer = $renderer;
		$this->request = $request;
		$this->template = $template;
		$this->language = $language;
		$this->auth = $auth;
		$this->user = $user;
		$this->config = $config;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'core.search_modify_submit_parameters' => 'validate_submission',
			'core.search_modify_param_before' => 'configure_tag_only_search',
			'core.search_native_by_keyword_modify_search_key' => 'filter_backend',
			'core.search_native_by_author_modify_search_key' => 'filter_backend',
			'core.search_mysql_by_keyword_modify_search_key' => 'filter_backend',
			'core.search_mysql_by_author_modify_search_key' => 'filter_backend',
			'core.search_postgres_by_keyword_modify_search_key' => 'filter_backend',
			'core.search_postgres_by_author_modify_search_key' => 'filter_backend',
			'core.search_backend_search_after' => 'search_by_tags',
			'core.search_modify_param_after' => 'filter_predefined_search',
			'core.get_unread_topics_modify_sql' => 'filter_unread_topics',
			'core.search_modify_url_parameters' => 'preserve_filter_url',
			'core.search_modify_rowset' => 'load_search_tags',
			'core.search_modify_tpl_ary' => 'add_search_tags',
			'core.search_modify_forum_select_list' => 'configure_search_form',
		];
	}

	/**
	 * Make tag-only submissions enter phpBB search and reject unsupported Sphinx combinations.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function validate_submission($event): void
	{
		if (empty($this->config['topicprefixes_search_enabled']))
		{
			return;
		}

		if (!$this->get_selected_tags())
		{
			return;
		}

		$this->filterable = true;
		$event['submit'] = true;
		$combined = trim((string) $event['keywords']) !== ''
			|| trim((string) $event['author']) !== ''
			|| !empty($event['author_id']);
		if ($combined && (string) $this->config['search_type'] === '\\phpbb\\search\\fulltext_sphinx')
		{
			$this->load_language();
			trigger_error($this->language->lang('TOPIC_TAG_SEARCH_SPHINX_UNSUPPORTED'));
		}
	}

	/**
	 * Force tag-only searches to topic results.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function configure_tag_only_search($event): void
	{
		if (empty($this->config['topicprefixes_search_enabled']))
		{
			return;
		}

		$this->tag_only = !empty($this->get_selected_tags())
			&& (string) $event['keywords'] === ''
			&& empty($event['author_id_ary'])
			&& (string) $event['search_id'] === '';
		if ($this->tag_only)
		{
			$event['show_results'] = 'topics';
			$sort_by_sql = $event['sort_by_sql'];
			$sort_by_sql['a'] = 'u.username_clean';
			$sort_by_sql['t'] = 't.topic_last_post_time';
			$sort_by_sql['f'] = 'f.forum_id';
			$sort_by_sql['i'] = 't.topic_title';
			$sort_by_sql['s'] = 't.topic_title';
			$event['sort_by_sql'] = $sort_by_sql;
			$this->filterable = true;
		}
	}

	/**
	 * Supply IDs for searches containing tags but no keywords or author.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function search_by_tags($event): void
	{
		if (empty($this->config['topicprefixes_search_enabled']) || !$this->tag_only)
		{
			return;
		}

		[$ids, $total, $start] = $this->filter->search_topic_ids(
			$this->get_selected_ids(),
			$this->get_match(),
			$event['ex_fid_ary'],
			(string) $event['sort_key'],
			(string) $event['sort_dir'],
			(int) $event['sort_days'],
			(int) $event['start'],
			(int) $event['per_page']
		);
		$event['id_ary'] = $ids;
		$event['total_match_count'] = $total;
		$event['start'] = $start;
	}

	/**
	 * Preload permission-filtered tags for advanced search.
	 *
	 * @return void
	 */
	public function configure_search_form(): void
	{
		if (empty($this->config['topicprefixes_search_enabled']))
		{
			return;
		}

		$this->load_language();
		$forum_ids = $this->get_searchable_forum_ids();
		$catalog = $this->manager->get_search_catalog($forum_ids);

		// Retain explicitly selected historical tags when reopening search.
		foreach ($this->get_selected_tags() as $tag_id => $tag)
		{
			$catalog[$tag_id] = $tag;
		}
		uasort($catalog, static function ($a, $b) {
			return $a['prefix_order'] <=> $b['prefix_order'];
		});

		$payload = [];
		foreach ($catalog as $tag)
		{
			$payload[] = [
				(int) $tag['prefix_id'],
				$tag['prefix_tag'],
				strtoupper($tag['prefix_color']),
				$tag['search_name'],
				$this->renderer->contrast_color($tag['prefix_color']),
			];
		}

		$this->template->assign_vars([
			'S_TOPIC_TAG_SEARCH' => !empty($payload),
			'TOPIC_TAG_SEARCH_CATALOG' => json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
			'TOPIC_TAG_SEARCH_SELECTED' => implode(',', $this->get_selected_ids()),
			'TOPIC_TAG_SEARCH_MATCH' => $this->get_match(),
		]);
	}

	/**
	 * Filter SQL-backed predefined searches before result IDs are collected.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function filter_predefined_search($event): void
	{
		$search_id = (string) $event['search_id'];
		$this->predefined_search = $search_id;

		if ($search_id === 'unreadposts')
		{
			// get_unread_topics() owns this query and exposes its own SQL event.
			return;
		}

		if (!in_array($search_id, ['active_topics', 'newposts', 'unanswered'], true))
		{
			return;
		}

		$this->filterable = true;
		$selected_ids = $this->get_selected_ids();
		if (!$selected_ids)
		{
			return;
		}

		$topic_id = $event['show_results'] === 'posts' ? 'p.topic_id' : 't.topic_id';
		$event['sql'] = $this->add_condition_before_order_by(
			(string) $event['sql'],
			$this->filter->topic_id_tags_condition($topic_id, $selected_ids, $this->get_match())
		);
	}

	/**
	 * Filter unread-topic IDs inside phpBB's read-tracking query.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function filter_unread_topics($event): void
	{
		if ($this->predefined_search !== 'unreadposts')
		{
			return;
		}

		$this->filterable = true;
		$selected_ids = $this->get_selected_ids();
		if (!$selected_ids)
		{
			return;
		}

		$sql_array = $event['sql_array'];
		$sql_array['WHERE'] = $this->add_condition_before_order_by(
			$sql_array['WHERE'],
			$this->filter->topic_id_tags_condition('t.topic_id', $selected_ids, $this->get_match())
		);
		$event['sql_array'] = $sql_array;
	}

	/**
	 * Intersect phpBB's post visibility SQL with selected topic tags.
	 *
	 * The modified visibility expression participates in both result counting
	 * and ID selection. A separate cache-key component prevents collisions.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function filter_backend($event): void
	{
		if (!in_array($event['type'], ['posts', 'topics'], true))
		{
			return;
		}

		$this->filterable = true;
		$selected_ids = $this->get_selected_ids();
		if (!$selected_ids)
		{
			return;
		}

		$post_visibility = (string) $event['post_visibility'];
		$event['post_visibility'] = '(' . $post_visibility . ') AND ' . $this->filter->topic_id_tags_condition('p.topic_id', $selected_ids, $this->get_match());
		$search_key = $event['search_key_array'];
		$search_key[] = 'topicprefixes:' . $this->get_match() . ':' . implode(',', $this->get_selected_ids());
		$event['search_key_array'] = $search_key;
	}

	/**
	 * Preserve selected tags in search sorting and pagination URLs.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function preserve_filter_url($event): void
	{
		if (!$this->filterable)
		{
			return;
		}

		$this->clear_filter_url = (string) $event['u_search'];
		$this->filter_url = $this->get_match() === 'any'
			? $this->append_url_parameter($this->clear_filter_url, 'tag_match=any')
			: $this->clear_filter_url;
		$event['u_search'] = $this->renderer->url_with_tags($this->filter_url, $this->get_selected_ids());
	}

	/**
	 * Batch-load tags only for rows phpBB already authorized and selected.
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

		if (!$this->filterable || !$this->filter_url)
		{
			return;
		}

		$selected_ids = $this->get_selected_ids();
		$selected_tags = $this->get_selected_tags();
		$this->template->assign_vars([
			'S_SEARCH_TOPIC_TAG_FILTERED' => !empty($selected_ids),
			'SEARCH_TOPIC_TAG_FILTERS' => $this->renderer->render_for_url($selected_tags, $this->filter_url, $selected_ids),
			'U_CLEAR_SEARCH_TOPIC_TAG_FILTERS' => $this->clear_filter_url,
		]);
	}

	/**
	 * Add plain or filterable tag badges to one search result row.
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
		$tpl['TOPIC_TAGS'] = $this->filterable && $this->filter_url
			? $this->renderer->render_for_url($tags, $this->filter_url, $this->get_selected_ids())
			: $this->renderer->render($tags);
		$event['tpl_ary'] = $tpl;
	}

	/**
	 * Parse and resolve selected exact tags once.
	 *
	 * @return array Search-ready tags keyed by identifier
	 */
	protected function get_selected_tags(): array
	{
		if ($this->selected_tags !== null)
		{
			return $this->selected_tags;
		}

		$value = trim($this->request->variable('tags', ''));
		if ($value === '' || !preg_match('/^\d+(?:,\d+)*$/D', $value))
		{
			return $this->selected_tags = [];
		}

		$requested_ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $value)))));
		$this->selected_tags = $requested_ids
			? $this->manager->resolve_search_tags_for_forums($requested_ids, $this->get_searchable_forum_ids())
			: [];
		return $this->selected_tags;
	}

	/**
	 * Get forums current user may search and has unlocked for this session.
	 *
	 * @return array Forum identifiers
	 */
	protected function get_searchable_forum_ids(): array
	{
		if ($this->searchable_forum_ids !== null)
		{
			return $this->searchable_forum_ids;
		}

		$readable = array_keys($this->auth->acl_getf('f_read', true));
		$searchable = array_keys($this->auth->acl_getf('f_search', true));
		$listable = array_keys($this->auth->acl_getf('f_list_topics', true));
		$forum_ids = array_diff(
			array_intersect($readable, $searchable, $listable),
			$this->user->get_passworded_forums()
		);
		$forum_ids = array_values(array_unique(array_map('intval', $forum_ids)));
		sort($forum_ids, SORT_NUMERIC);

		return $this->searchable_forum_ids = $forum_ids;
	}

	/**
	 * Get selected tag identifiers.
	 *
	 * @return array Tag identifiers
	 */
	protected function get_selected_ids(): array
	{
		$ids = array_map('intval', array_keys($this->get_selected_tags()));
		sort($ids, SORT_NUMERIC);
		return $ids;
	}

	/**
	 * Get requested matching mode.
	 *
	 * @return string all or any
	 */
	protected function get_match(): string
	{
		$match = (string) $this->request->variable('tag_match', 'all');
		return $this->match = $match === 'any' ? 'any' : 'all';
	}

	/**
	 * Append one HTML-safe URL parameter.
	 *
	 * @param string $url       Base URL
	 * @param string $parameter Encoded name=value pair
	 * @return string URL
	 */
	protected function append_url_parameter(string $url, string $parameter): string
	{
		return $url . (strpos($url, '?') === false ? '?' : '&amp;') . $parameter;
	}

	/**
	 * Load tooltip language once for search results.
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

	/**
	 * Append a WHERE condition without disturbing predefined search sorting.
	 *
	 * @param string $sql       Existing SQL condition and ordering
	 * @param string $condition Topic tag condition
	 * @return string Combined SQL fragment
	 */
	protected function add_condition_before_order_by(string $sql, string $condition): string
	{
		$position = strripos($sql, 'ORDER BY');
		if ($position === false)
		{
			return $sql . ' AND ' . $condition;
		}

		return substr($sql, 0, $position) . 'AND ' . $condition . ' ' . substr($sql, $position);
	}
}
