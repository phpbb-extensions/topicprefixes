<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2016 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\controller;

use phpbb\language\language;
use phpbb\log\log;
use phpbb\request\request;
use phpbb\template\template;
use phpbb\topicprefixes\prefixes\manager;
use phpbb\user;

/**
 * Class admin_controller
 */
class admin_controller
{
	/** @var manager Topic prefixes manager object */
	protected $manager;

	/** @var language phpBB language object */
	protected $language;

	/** @var log phpBB log object */
	protected $log;

	/** @var request phpBB request object */
	protected $request;

	/** @var template phpBB template object */
	protected $template;

	/** @var user phpBB user object */
	protected $user;

	/** @var string phpBB root path */
	protected $root_path;

	/** @var string PHP extension */
	protected $php_ext;

	/** @var string Form key used for form validation */
	protected $form_key;

	/** @var int Forum identifier */
	protected $forum_id;

	/** @var string Custom form action */
	protected $u_action;

	/**
	 * Constructor
	 *
	 * @param manager  $manager         Topic prefixes manager object
	 * @param language $language        phpBB language object
	 * @param log      $log             phpBB log object
	 * @param request  $request         phpBB request object
	 * @param template $template        phpBB template object
	 * @param user     $user            phpBB user object
	 * @param string   $phpbb_root_path phpBB root path
	 * @param string   $phpEx           PHP extension
	 */
	public function __construct(manager $manager, language $language, log $log, request $request, template $template, user $user, $phpbb_root_path, $phpEx)
	{
		$this->manager = $manager;
		$this->language = $language;
		$this->log = $log;
		$this->request = $request;
		$this->template = $template;
		$this->user = $user;
		$this->root_path = $phpbb_root_path;
		$this->php_ext = $phpEx;
	}

	/**
	 * Main handler, called by the ACP module
	 *
	 * @return void
	 */
	public function main()
	{
		$this->form_key = 'acp_topic_prefixes';
		add_form_key($this->form_key);

		$action = $this->request->variable('action', '');
		$prefix_id = $this->request->variable('prefix_id', 0);
		$this->set_forum_id($this->request->variable('forum_id', 0));

		switch ($action)
		{
			case 'add':
				$this->add_prefix();
			break;

			case 'edit':
			case 'delete':
				$this->{$action . '_prefix'}($prefix_id);
			break;

			case 'move_up':
			case 'move_down':
				$this->move_prefix($prefix_id, str_replace('move_', '', $action));
			break;
		}

		$this->display_settings();
	}

	/**
	 * Display topic prefix settings
	 *
	 * @return void
	 */
	public function display_settings()
	{
		foreach ($this->manager->get_prefixes($this->forum_id) as $prefix)
		{
			$this->template->assign_block_vars('prefixes', [
				'PREFIX_TAG'		=> $prefix['prefix_tag'],
				'PREFIX_ENABLED'	=> (int) $prefix['prefix_enabled'],
				'U_EDIT'			=> "{$this->u_action}&amp;action=edit&amp;prefix_id=" . $prefix['prefix_id'] . '&amp;forum_id=' . $this->forum_id . '&amp;hash=' . generate_link_hash('edit' . $prefix['prefix_id']),
				'U_DELETE'			=> "{$this->u_action}&amp;action=delete&amp;prefix_id=" . $prefix['prefix_id'] . '&amp;forum_id=' . $this->forum_id,
				'U_MOVE_UP'			=> "{$this->u_action}&amp;action=move_up&amp;prefix_id=" . $prefix['prefix_id'] . '&amp;forum_id=' . $this->forum_id . '&amp;hash=' . generate_link_hash('up' . $prefix['prefix_id']),
				'U_MOVE_DOWN'		=> "{$this->u_action}&amp;action=move_down&amp;prefix_id=" . $prefix['prefix_id'] . '&amp;forum_id=' . $this->forum_id . '&amp;hash=' . generate_link_hash('down' . $prefix['prefix_id']),
			]);
		}

		$this->template->assign_vars([
			'S_FORUM_OPTIONS'	=> make_forum_select($this->forum_id, false, false, true),
			'FORUM_ID'			=> $this->forum_id,
			'U_ACTION'			=> $this->u_action,
		]);
	}

	/**
	 * Add a prefix
	 *
	 * @return void
	 */
	public function add_prefix()
	{
		if ($this->request->is_set_post('submit'))
		{
			if (!check_form_key($this->form_key))
			{
				$this->trigger_message('FORM_INVALID', E_USER_WARNING);
			}

			$tag = $this->request->variable('prefix_tag', '', true);
			$prefix = $this->manager->add_prefix($tag, $this->forum_id);

			if ($prefix)
			{
				$this->log($prefix['prefix_tag'], 'ACP_LOG_PREFIX_ADDED');
			}
		}
	}

	/**
	 * Edit a prefix
	 *
	 * @param int $prefix_id The prefix identifier to edit
	 * @return void
	 */
	public function edit_prefix($prefix_id)
	{
		if (!$this->check_hash('edit' . $prefix_id))
		{
			$this->trigger_message('FORM_INVALID', E_USER_WARNING);
		}

		try
		{
			$prefix = $this->manager->get_prefix($prefix_id);
			$this->manager->update_prefix(!$prefix ?: $prefix['prefix_id'], !$prefix ? [] : ['prefix_enabled' => !$prefix['prefix_enabled']]);
		}
		catch (\OutOfBoundsException $e)
		{
			$this->trigger_message($e->getMessage(), E_USER_WARNING);
		}

		if ($this->request->is_ajax())
		{
			$json_response = new \phpbb\json_response;
			$json_response->send(['success' => true]);
		}
	}

	/**
	 * Delete a prefix
	 *
	 * @param int $prefix_id The prefix identifier to delete
	 * @return void
	 */
	public function delete_prefix($prefix_id)
	{
		if (confirm_box(true))
		{
			try
			{
				$prefix = $this->manager->get_prefix($prefix_id);
				$this->manager->delete_prefix(!$prefix ?: $prefix['prefix_id']);
				$this->log($prefix['prefix_tag'], 'ACP_LOG_PREFIX_DELETED');
			}
			catch (\OutOfBoundsException $e)
			{
				$this->trigger_message($e->getMessage(), E_USER_WARNING);
			}

			$this->trigger_message('TOPIC_PREFIX_DELETED');
		}

		confirm_box(false, $this->language->lang('DELETE_TOPIC_PREFIX_CONFIRM'), build_hidden_fields([
			'mode'		=> 'manage',
			'action'	=> 'delete',
			'prefix_id'	=> $prefix_id,
			'forum_id'	=> $this->forum_id,
		]));
	}

	/**
	 * Move a prefix up/down
	 *
	 * @param int    $prefix_id The prefix identifier to move
	 * @param string $direction The direction (up|down)
	 * @param int    $amount    The amount of places to move (default: 1)
	 * @return void
	 */
	public function move_prefix($prefix_id, $direction, $amount = 1)
	{
		if (!$this->check_hash($direction . $prefix_id))
		{
			$this->trigger_message('FORM_INVALID', E_USER_WARNING);
		}

		try
		{
			$this->manager->move_prefix($prefix_id, $direction, $amount);
		}
		catch (\OutOfBoundsException $e)
		{
			$this->trigger_message($e->getMessage(), E_USER_WARNING);
		}

		if ($this->request->is_ajax())
		{
			$json_response = new \phpbb\json_response;
			$json_response->send(['success' => true]);
		}
	}

	/**
	 * Set u_action
	 *
	 * @param string $u_action Custom form action
	 * @return admin_controller
	 */
	public function set_u_action($u_action)
	{
		$this->u_action = $u_action;
		return $this;
	}

	/**
	 * Set forum ID
	 *
	 * @param int $forum_id Forum identifier
	 * @return admin_controller
	 */
	public function set_forum_id($forum_id)
	{
		$this->forum_id = $forum_id;
		return $this;
	}

	/**
	 * Check link hash helper
	 *
	 * @param string $hash A hashed string
	 * @return bool True if hash matches, false if not
	 */
	protected function check_hash($hash)
	{
		return check_link_hash($this->request->variable('hash', ''), $hash);
	}

	/**
	 * Trigger a message and back link for error/success dialogs
	 *
	 * @param string $message A language key
	 * @param int    $error   Error type constant, optional
	 * @return void
	 */
	protected function trigger_message($message = '', $error = E_USER_NOTICE)
	{
		trigger_error($this->language->lang($message) . adm_back_link("{$this->u_action}&amp;forum_id={$this->forum_id}"), $error);
	}

	/**
	 * Helper for logging topic prefix admin actions
	 *
	 * @param string $tag     The topic prefix tag
	 * @param string $message The log action language key
	 * @return void
	 */
	protected function log($tag, $message)
	{
		$forum_data = $this->get_forum_info($this->forum_id);

		$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, $message, time(), [$tag, $forum_data['forum_name']]);
	}

	/**
	 * Get a forum's information
	 *
	 * @param int $forum_id
	 * @return mixed Array with the current row, false, if the row does not exist
	 */
	protected function get_forum_info($forum_id)
	{
		if (!class_exists('acp_forums'))
		{
			include $this->root_path . 'includes/acp/acp_forums.' . $this->php_ext;
		}

		$acp_forums = new \acp_forums();

		return $acp_forums->get_forum_info($forum_id);
	}
}
