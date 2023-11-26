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

use phpbb\language\language;
use phpbb\request\request;
use phpbb\topicprefixes\prefixes\manager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event listener
 */
class listener implements EventSubscriberInterface
{
	/**
	 * @var manager Topic prefixes manager
	 */
	protected $manager;

	/**
	 * @var request Request object
	 */
	protected $request;

	/**
	 * @var language Language object
	 */
	protected $language;

	/**
	 * @var array An array of topic prefixes
	 */
	protected $prefixes;

	/**
	 * @inheritdoc
	 */
	public static function getSubscribedEvents()
	{
		return [
			'core.posting_modify_template_vars'			=> 'add_to_posting_form',
			'core.posting_modify_submit_post_before'	=> 'submit_prefix_data',
			'core.submit_post_modify_sql_data'			=> 'save_prefix_to_topic',
		];
	}

	/**
	 * Listener constructor
	 *
	 * @param manager  $manager  Topic prefixes manager
	 * @param request  $request  Request object
	 * @param language $language Language object
	 */
	public function __construct(manager $manager, request $request, language $language)
	{
		$this->manager = $manager;
		$this->request = $request;
		$this->language = $language;
	}

	/**
	 * Update the posting page form with template vars
	 * for the topic prefix drop-down menu.
	 *
	 * @param \phpbb\event\data $event Event data object
	 * @return void
	 */
	public function add_to_posting_form($event)
	{
		if (!$this->is_new_topic($event))
		{
			return;
		}

		$this->language->add_lang('topic_prefixes', 'phpbb/topicprefixes');

		// Get prefixes for the current forum
		$this->prefixes = $this->manager->get_active_prefixes($event['forum_id']);

		// Get the current prefix selected
		$selected = $this->get_selected_prefix($event);

		$event['page_data'] = array_merge($event['page_data'], [
			'PREFIXES'			=> $this->prefixes,
			'SELECTED_PREFIX'	=> array_key_exists($selected, $this->prefixes) ? $this->prefixes[$selected]['prefix_tag'] : '',
		]);
	}

	/**
	 * Prepare topic prefix data for post submission
	 *
	 * @param \phpbb\event\data $event Event data object
	 * @return void
	 */
	public function submit_prefix_data($event)
	{
		$selected = $this->request->variable('topic_prefix', 0);

		// Get data for the prefix selected by the user
		$prefix = $this->manager->get_prefix($selected);

		// First, add the topic prefix id to the data to be stored with the db
		$data = $event['data'];
		$data['topic_prefix_id'] = $prefix ? (int) $prefix['prefix_id'] : 0;
		$event['data'] = $data;

		// Next, prepend the topic prefix to the subject (if necessary)
		if (isset($prefix['prefix_tag']))
		{
			$post_data = $event['post_data'];
			$post_data['post_subject'] = $this->manager->prepend_prefix($prefix['prefix_tag'], $post_data['post_subject']);
			$event['post_data'] = $post_data;
		}
	}

	/**
	 * Save the topic prefix id with the associated topic
	 *
	 * @param \phpbb\event\data $event Event data object
	 * @return void
	 */
	public function save_prefix_to_topic($event)
	{
		if (!array_key_exists('topic_prefix_id', $event['data']) || !in_array($event['post_mode'], ['edit_first_post', 'edit_topic', 'post'], true))
		{
			return;
		}

		$sql_data = $event['sql_data'];
		$sql_data[TOPICS_TABLE]['sql']['topic_prefix_id'] = $event['data']['topic_prefix_id'];
		$event['sql_data'] = $sql_data;
	}

	/**
	 * Is a new topic being posted/edited?
	 *
	 * @param \phpbb\event\data $event Event data object
	 * @return bool Return true if starting a new post or editing the first post, false otherwise
	 */
	protected function is_new_topic($event)
	{
		return ($event['mode'] === 'post' || ($event['mode'] === 'edit' && $event['post_data']['topic_first_post_id'] == $event['post_data']['post_id']));
	}

	/**
	 * Get the current prefix for the selection menu
	 *
	 * @param \phpbb\event\data $event Event data object
	 * @return int Identifier for the selected prefix
	 */
	protected function get_selected_prefix($event)
	{
		// Get the prefix from the select menu
		$prefix_id = $this->request->variable('topic_prefix', 0);

		// If we are in preview mode, send back the prefix from the form
		if (!empty($event['preview']))
		{
			return $prefix_id;
		}

		// If no prefix was selected, get one if it already exists (ie: editing a post)
		if (!$prefix_id && !empty($event['post_data']['topic_prefix_id']))
		{
			$prefix_id = (int) $event['post_data']['topic_prefix_id'];
		}

		// If still no prefix was identified, look in existing topic title (ie: editing a post)
		if (!$prefix_id && !empty($event['post_data']['topic_title']))
		{
			$prefix_id = $this->find_prefix_in_title($event['post_data']['topic_title']);
		}

		return $prefix_id;
	}

	/**
	 * Find an active topic prefix in the topic title
	 *
	 * @param string $title The post title
	 * @return int   Identifier for the found prefix
	 */
	protected function find_prefix_in_title($title)
	{
		foreach ($this->prefixes as $prefix_id => $prefix_data)
		{
			if (strpos($title, $prefix_data['prefix_tag']) === 0)
			{
				return (int) $prefix_id;
			}
		}

		return 0;
	}
}
