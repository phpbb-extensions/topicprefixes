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
	static public function getSubscribedEvents()
	{
		return [
			'core.posting_modify_template_vars'			=> 'update_posting_form',
			'core.posting_modify_submit_post_before'	=> 'update_posted_title',
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
	public function update_posting_form($event)
	{
		if (!$this->is_new_topic($event))
		{
			return;
		}

		$this->user->add_lang_ext('phpbb/topicprefixes', 'topic_prefixes');

		$event['page_data'] = array_merge($event['page_data'], [
			'PREFIXES'			=> $this->manager->get_active_prefixes($event['forum_id']),
			'SELECTED_PREFIX'	=> $this->request->variable('topic_prefix', '', true),
		]);
	}

	/**
	 * Append the topic prefix to the topic before submitting
	 * This is performed if the prefix was not already appended by javascript
	 *
	 * @param \phpbb\event\data $event Event data object
	 * @return null
	 */
	public function update_posted_title($event)
	{
		if (!$this->is_new_topic($event))
		{
			return;
		}

		$post_data = $event['post_data'];
		$prefix = $this->request->variable('topic_prefix', '', true);

		if (strpos($post_data['post_subject'], $prefix) === 0)
		{
			return;
		}

		$post_data['post_subject'] = $prefix . ' ' . $post_data['post_subject'];
		$event['post_data'] = $post_data;
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
