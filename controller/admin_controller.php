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

use phpbb\request\request;
use phpbb\template\template;
use phpbb\topicprefixes\prefixes\manager;
use phpbb\user;

/**
 * Class admin_controller
 */
class admin_controller implements admin_controller_interface
{
	/** @var manager */
	protected $manager;

	/** @var request */
	protected $request;

	/** @var template */
	protected $template;

	/** @var user */
	protected $user;

	/** @var string */
	protected $form_key;

	/** @var int */
	protected $forum_id;

	/** @var string */
	protected $u_action;

	/**
	 * Constructor
	 *
	 * @param manager  $manager
	 * @param request  $request
	 * @param template $template
	 * @param user     $user
	 */
	public function __construct(manager $manager, request $request, template $template, user $user)
	{
		$this->manager = $manager;
		$this->request = $request;
		$this->template = $template;
		$this->user = $user;
	}

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
	 * @inheritdoc
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
	 * @inheritdoc
	 */
	public function add_prefix()
	{
		if ($this->request->is_set_post('submit'))
		{
			if (!check_form_key($this->form_key))
			{
				$this->trigger_message('FORM_INVALID', E_USER_WARNING);
			}

			$prefix = $this->request->variable('prefix_tag', '', true);
			$this->manager->add_prefix($prefix, $this->forum_id);
		}
	}

	/**
	 * @inheritdoc
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
			$this->manager->update_prefix($prefix['prefix_id'], ['prefix_enabled' => !$prefix['prefix_enabled']]);
		}
		catch (\OutOfBoundsException $e)
		{
			$this->trigger_message($e->getMessage(), E_USER_WARNING);
		}
	}

	/**
	 * @inheritdoc
	 */
	public function delete_prefix($prefix_id)
	{
		if (confirm_box(true))
		{
			try
			{
				$this->manager->delete_prefix($prefix_id);
			}
			catch (\OutOfBoundsException $e)
			{
				$this->trigger_message($e->getMessage(), E_USER_WARNING);
			}

			$this->trigger_message('TOPIC_PREFIX_DELETED');
		}

		confirm_box(false, $this->user->lang('DELETE_TOPIC_PREFIX_CONFIRM'), build_hidden_fields([
			'mode'		=> 'manage',
			'action'	=> 'delete',
			'prefix_id'	=> $prefix_id,
			'forum_id'	=> $this->forum_id,
		]));
	}

	/**
	 * @inheritdoc
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
	 * @inheritdoc
	 */
	public function set_u_action($u_action)
	{
		$this->u_action = $u_action;
		return $this;
	}

	/**
	 * @param int $forum_id
	 */
	public function set_forum_id($forum_id)
	{
		$this->forum_id = $forum_id;
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
	 * @param string $error   Error type constant, optional
	 * @return null
	 */
	protected function trigger_message($message = '', $error = E_USER_NOTICE)
	{
		trigger_error($this->user->lang($message) . adm_back_link("{$this->u_action}&amp;forum_id={$this->forum_id}"), $error);
	}
}
