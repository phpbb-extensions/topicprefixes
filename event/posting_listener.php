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
use phpbb\topicprefixes\tags\assignment_manager;
use phpbb\topicprefixes\tags\manager;
use phpbb\topicprefixes\tags\renderer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Integrate topic tag selection with new topics and first-post edits.
 */
class posting_listener implements EventSubscriberInterface
{
	/** @var manager Topic tag manager */
	protected $manager;

	/** @var assignment_manager Topic/tag assignment manager */
	protected $assignments;

	/** @var renderer Topic tag renderer */
	protected $renderer;

	/** @var request Request object */
	protected $request;

	/** @var language Language object */
	protected $language;

	/** @var array|null Valid submitted tag identifiers */
	protected $submitted_tag_ids;

	/**
	 * Constructor.
	 *
	 * @param manager            $manager     Topic tag manager
	 * @param assignment_manager $assignments Topic/tag assignment manager
	 * @param renderer           $renderer    Topic tag renderer
	 * @param request            $request     Request object
	 * @param language           $language    Language object
	 */
	public function __construct(manager $manager, assignment_manager $assignments, renderer $renderer, request $request, language $language)
	{
		$this->manager = $manager;
		$this->assignments = $assignments;
		$this->renderer = $renderer;
		$this->request = $request;
		$this->language = $language;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'core.posting_modify_submission_errors' => 'validate_submission',
			'core.posting_modify_template_vars' => 'add_to_posting_form',
			'core.posting_modify_submit_post_before' => 'capture_submission',
			'core.submit_post_end' => 'save_assignments',
		];
	}

	/**
	 * Reject disabled, unavailable, or unknown submitted tag IDs.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function validate_submission($event): void
	{
		if (empty($event['submit']) || !$this->is_topic_form($event['mode'], $event['post_data']))
		{
			return;
		}
		if (!$this->request->is_set_post('topic_tags_present') && !$this->request->is_set_post('topic_tags'))
		{
			return;
		}

		$this->language->add_lang('topic_prefixes', 'phpbb/topicprefixes');
		$submitted = $this->get_submitted_tag_ids();
		$valid = $this->get_valid_tag_ids(
			(int) $event['forum_id'],
			$submitted,
			$event['mode'],
			(int) ($event['topic_id'] ?? 0)
		);
		sort($submitted, SORT_NUMERIC);
		if ($submitted !== $valid)
		{
			$error = $event['error'];
			$error[] = $this->language->lang('TOPIC_TAGS_INVALID');
			$event['error'] = $error;
			return;
		}

		$this->submitted_tag_ids = $valid;
	}

	/**
	 * Add accessible tag checkboxes to eligible posting forms.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function add_to_posting_form($event): void
	{
		if (!$this->is_topic_form($event['mode'], $event['post_data']))
		{
			return;
		}

		$this->language->add_lang('topic_prefixes', 'phpbb/topicprefixes');
		$available = $this->manager->get_available_tags($event['forum_id']);
		$assigned = [];
		if ($event['mode'] === 'edit')
		{
			$topic_tags = $this->assignments->get_tags_for_topics([$event['topic_id']]);
			$assigned = $topic_tags[(int) $event['topic_id']] ?? [];
			$available += $assigned;
			uasort($available, [$this, 'compare_tags']);
		}

		if (!$available)
		{
			return;
		}

		if ($this->request->is_set_post('topic_tags_present'))
		{
			$selected = $this->get_submitted_tag_ids();
		}
		else if ($event['mode'] === 'edit')
		{
			$selected = array_keys($assigned);
		}
		else
		{
			$selected = [];
		}

		$page_data = $event['page_data'];
		$page_data['TOPIC_TAGS'] = $this->renderer->render($available, 0, $selected);
		$page_data['S_TOPIC_TAGS'] = true;
		$event['page_data'] = $page_data;
	}

	/**
	 * Capture valid tag IDs before phpBB submits topic data.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function capture_submission($event): void
	{
		if (!$this->is_topic_form($event['mode'], $event['post_data']))
		{
			return;
		}
		if (!$this->request->is_set_post('topic_tags_present') && !$this->request->is_set_post('topic_tags'))
		{
			return;
		}

		if ($this->submitted_tag_ids === null)
		{
			$submitted = $this->get_submitted_tag_ids();
			$this->submitted_tag_ids = $this->get_valid_tag_ids(
				(int) $event['forum_id'],
				$submitted,
				$event['mode'],
				(int) ($event['topic_id'] ?? 0)
			);
		}
	}

	/**
	 * Save captured relationships after topic submission.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function save_assignments($event): void
	{
		if ($this->submitted_tag_ids === null || empty($event['data']['topic_id']))
		{
			return;
		}

		$this->assignments->set_topic_tags((int) $event['data']['topic_id'], $this->submitted_tag_ids);
		$this->submitted_tag_ids = null;
	}

	/**
	 * Check whether form creates a topic or edits its first post.
	 *
	 * @param string $mode      Posting mode
	 * @param array  $post_data Posting data
	 * @return bool
	 */
	protected function is_topic_form(string $mode, array $post_data): bool
	{
		return $mode === 'post' || ($mode === 'edit' && isset($post_data['post_id'], $post_data['topic_first_post_id']) && (int) $post_data['post_id'] === (int) $post_data['topic_first_post_id']);
	}

	/**
	 * Normalize submitted tag identifiers.
	 *
	 * @return array Tag identifiers
	 */
	protected function get_submitted_tag_ids(): array
	{
		$ids = $this->request->variable('topic_tags', [0]);
		return array_values(array_unique(array_filter(array_map('intval', $ids))));
	}

	/**
	 * Get submitted IDs valid for a new topic or first-post edit.
	 *
	 * Existing relationships remain valid on edit even when a topic has moved to
	 * a forum where those tags cannot be assigned to new topics.
	 *
	 * @param int    $forum_id Forum identifier
	 * @param array  $submitted Submitted tag identifiers
	 * @param string $mode Posting mode
	 * @param int    $topic_id Topic identifier
	 * @return array Valid tag identifiers
	 */
	protected function get_valid_tag_ids(int $forum_id, array $submitted, string $mode, int $topic_id): array
	{
		$valid = array_keys($this->manager->get_assignable_tags($forum_id, $submitted));
		if ($mode === 'edit' && $topic_id)
		{
			$current = $this->assignments->get_topic_tag_ids($topic_id);
			$valid = array_unique(array_merge($valid, array_intersect($submitted, $current)));
		}
		sort($valid, SORT_NUMERIC);

		return $valid;
	}

	/**
	 * Compare tags by administrator order then identifier.
	 *
	 * @param array $left Left tag
	 * @param array $right Right tag
	 * @return int Comparison result
	 */
	protected function compare_tags(array $left, array $right): int
	{
		$order = (int) ($left['prefix_order'] ?? 0) <=> (int) ($right['prefix_order'] ?? 0);

		return $order ?: (int) $left['prefix_id'] <=> (int) $right['prefix_id'];
	}
}
