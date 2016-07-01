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

use phpbb\request\request;
use phpbb\topicprefixes\prefixes\manager;
use phpbb\user;
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
	 * @var user User object
	 */
	protected $user;

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
	 * @param user    $user    Language object
	 * @param request $request Request object
	 * @param manager $manager Topic prefixes manager
	 */
	public function __construct(manager $manager, request $request, user $user)
	{
		$this->manager = $manager;
		$this->request = $request;
		$this->user = $user;
	}

	/**
	 * Update the posting page form with template vars
	 * for the topic prefix drop-down menu.
	 *
	 * @param \phpbb\event\data $event Event data object
	 * @return null
	 */
	public function add_to_posting_form($event)
	{
		if (!$this->is_new_topic($event))
		{
			return;
		}

		$this->user->add_lang_ext('phpbb/topicprefixes', 'topic_prefixes');

		// Get prefixes for the current forum
		$prefixes = $this->manager->get_active_prefixes($event['forum_id']);

		// Get the current prefix (if editing an existing post,
		// get it from post_data, otherwise get it from the form posted)
		$selected = !empty($event['post_data']['topic_prefix_id']) ? $event['post_data']['topic_prefix_id'] : $this->request->variable('topic_prefix', 0);

		$event['page_data'] = array_merge($event['page_data'], [
			'PREFIXES'			=> $prefixes,
			'SELECTED_PREFIX'	=> $selected ? $prefixes[$selected]['prefix_tag'] : '',
		]);
	}

	/**
	 * Prepare topic prefix data for post submission
	 *
	 * @param \phpbb\event\data $event Event data object
	 * @return null
	 */
	public function submit_prefix_data($event)
	{
		if (!$this->is_new_topic($event))
		{
			return;
		}

		// Get data for the prefix selected by the user
		$prefix = $this->manager->get_prefix($this->request->variable('topic_prefix', 0));

		// First, add the topic prefix id to the data to be stored with the db
		$data = $event['data'];
		$data['topic_prefix_id'] = (int) $prefix['prefix_id'];
		$event['data'] = $data;

		// Next, prepend the topic prefix to the subject (if necessary)
		$post_data = $event['post_data'];
		$post_data['post_subject'] = $this->manager->prepend_prefix($prefix['prefix_tag'], $post_data['post_subject']);
		$event['post_data'] = $post_data;
	}

	/**
	 * Save the topic prefix id with the associated topic
	 *
	 * @param \phpbb\event\data $event Event data object
	 * @return null
	 */
	public function save_prefix_to_topic($event)
	{
		if (!in_array($event['post_mode'], ['edit_first_post', 'edit_topic', 'post']))
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
	 * @return bool
	 */
	protected function is_new_topic($event)
	{
		return ($event['mode'] === 'post' || ($event['mode'] === 'edit' && $event['post_data']['topic_first_post_id'] == $event['post_data']['post_id']));
	}
}
