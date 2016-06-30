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
	/** @var manager Topic prefixes manager */
	protected $manager;

	/** @var request Request object */
	protected $request;

	/** @var user $user object */
	protected $user;

	/**
	 * @inheritdoc
	 */
	static public function getSubscribedEvents()
	{
		return [
			'core.posting_modify_template_vars'		=> 'update_posting_form'
		];
	}

	/**
	 * Listener constructor
	 *
	 * @param manager  $manager  Topic prefixes manager
	 * @param request  $request  Request object
	 * @param user     $user     User object
	 */
	public function __construct(manager $manager, request $request, user $user)
	{
		$this->manager = $manager;
		$this->request = $request;
		$this->user    = $user;
	}

	/**
	 * Update the posting page form with template vars
	 * for the topic prefix drop-down menu.
	 *
	 * @param \phpbb\event\data $event Event data object
	 */
	public function update_posting_form($event)
	{
		if (!$this->is_new_topic($event))
		{
			return;
		}

		$this->user->add_lang_ext('phpbb/topicprefixes', 'topic_prefixes');

		$prefixes = $this->manager->get_active_prefixes($event['forum_id']);
		$prefixes = $this->get_selected_prefixes($prefixes);

		$event['page_data'] = array_merge($event['page_data'], [
			'PREFIXES'		=> $prefixes,
		]);
	}

	/**
	 * Is a new topic being posted/edited?
	 *
	 * @param \phpbb\event\data $event Event data object
	 * @return bool
	 */
	protected function is_new_topic($event)
	{
		return ($event['mode'] === 'post' || ($event['mode'] === 'edit' && $event['topic_first_post_id'] == $event['post_id']));
	}

	/**
	 * Get the selected topic prefixes from the form
	 *
	 * @param array $prefixes
	 * @return array
	 */
	protected function get_selected_prefixes(array $prefixes)
	{
		$selected = '';

		foreach ($prefixes as $id => $prefix)
		{
			if ($this->manager->is_parent($prefix))
			{
				$selected = $this->request->variable("topic_prefix_$id", '', true);
			}

			$prefixes[$id]['selected'] = ($prefix['prefix_tag'] === $selected);
		}

		return $prefixes;
	}
}
