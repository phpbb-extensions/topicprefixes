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

use phpbb\json_response;
use phpbb\language\language;
use phpbb\log\log;
use phpbb\request\request;
use phpbb\template\template;
use phpbb\topicprefixes\tags\manager;
use phpbb\topicprefixes\tags\renderer;
use phpbb\user;

/**
 * ACP topic tag management.
 */
class admin_controller
{
	/** @var manager Topic tag manager */
	protected $manager;

	/** @var renderer Topic tag renderer */
	protected $renderer;

	/** @var language Language object */
	protected $language;

	/** @var log Log object */
	protected $log;

	/** @var request Request object */
	protected $request;

	/** @var template Template object */
	protected $template;

	/** @var user User object */
	protected $user;

	/** @var string Form key */
	protected $form_key = 'acp_topic_tags';

	/** @var string Module action URL */
	protected $u_action = '';

	/**
	 * Constructor.
	 *
	 * @param manager  $manager  Topic tag manager
	 * @param renderer $renderer Topic tag renderer
	 * @param language $language Language object
	 * @param log      $log      Log object
	 * @param request  $request  Request object
	 * @param template $template Template object
	 * @param user     $user     User object
	 */
	public function __construct(manager $manager, renderer $renderer, language $language, log $log, request $request, template $template, user $user)
	{
		$this->manager = $manager;
		$this->renderer = $renderer;
		$this->language = $language;
		$this->log = $log;
		$this->request = $request;
		$this->template = $template;
		$this->user = $user;
	}

	/**
	 * Handle ACP actions and display tag settings.
	 *
	 * @return void
	 */
	public function main(): void
	{
		add_form_key($this->form_key);
		$action = $this->request->variable('action', '');
		$tag_id = $this->request->variable('tag_id', 0);
		$editing = false;

		switch ($action)
		{
			case 'save':
				$this->save_tag($tag_id);
			break;

			case 'edit':
				$editing = $this->manager->get_tag($tag_id);
				if (!$editing)
				{
					$this->trigger_message('TOPIC_TAG_NOT_FOUND', E_USER_WARNING);
				}
			break;

			case 'delete':
				$this->delete_tag($tag_id);
			break;

			case 'toggle':
				$this->toggle_tag($tag_id);
			break;

			case 'move_up':
			case 'move_down':
				$this->move_tag($tag_id, str_replace('move_', '', $action));
			break;
		}

		$this->display_settings($editing);
	}

	/**
	 * Assign tag list and editor template variables.
	 *
	 * @param array|false $editing Tag being edited
	 * @return void
	 */
	public function display_settings($editing = false): void
	{
		$forum_names = $this->manager->get_forum_names_by_tag();
		foreach ($this->manager->get_tags() as $tag)
		{
			$tag_id = (int) $tag['prefix_id'];
			$this->template->assign_block_vars('tags', [
				'TAG_ID' => $tag_id,
				'TAG_NAME' => utf8_htmlspecialchars($tag['prefix_tag']),
				'TAG_COLOR' => '#' . $tag['prefix_color'],
				'TAG_TEXT_COLOR' => $this->renderer->contrast_color($tag['prefix_color']),
				'TAG_ENABLED' => (bool) $tag['prefix_enabled'],
				'FORUM_NAMES' => $forum_names[$tag_id] ?? [],
				'U_EDIT' => $this->u_action . '&amp;action=edit&amp;tag_id=' . $tag_id,
				'U_DELETE' => $this->u_action . '&amp;action=delete&amp;tag_id=' . $tag_id,
				'U_TOGGLE' => $this->u_action . '&amp;action=toggle&amp;tag_id=' . $tag_id . '&amp;hash=' . generate_link_hash('toggle' . $tag_id),
				'U_MOVE_UP' => $this->u_action . '&amp;action=move_up&amp;tag_id=' . $tag_id . '&amp;hash=' . generate_link_hash('up' . $tag_id),
				'U_MOVE_DOWN' => $this->u_action . '&amp;action=move_down&amp;tag_id=' . $tag_id . '&amp;hash=' . generate_link_hash('down' . $tag_id),
			]);
		}

		$editing = $editing ?: [
			'prefix_id' => 0,
			'prefix_tag' => '',
			'prefix_color' => manager::DEFAULT_COLOR,
			'prefix_enabled' => 1,
			'forum_ids' => [],
		];
		$this->template->assign_vars([
			'U_ACTION' => $this->u_action,
			'S_EDIT_TAG' => !empty($editing['prefix_id']),
			'TAG_ID' => (int) $editing['prefix_id'],
			'TAG_NAME' => utf8_htmlspecialchars($editing['prefix_tag']),
			'TAG_COLOR' => '#' . $editing['prefix_color'],
			'TAG_ENABLED' => (bool) $editing['prefix_enabled'],
			'S_FORUM_OPTIONS' => make_forum_select($editing['forum_ids'], false, false, true),
		]);
	}

	/**
	 * Create or update one tag.
	 *
	 * @param int $tag_id Tag identifier, or zero for new tag
	 * @return void
	 */
	public function save_tag(int $tag_id): void
	{
		if (!$this->request->is_set_post('submit') || !check_form_key($this->form_key))
		{
			$this->trigger_message('FORM_INVALID', E_USER_WARNING);
		}

		$name = $this->request->variable('tag_name', '', true);
		$color = $this->request->variable('tag_color', manager::DEFAULT_COLOR);
		$enabled = $this->request->variable('tag_enabled', 0);
		$forum_ids = $this->request->variable('forum_ids', [0]);
		if (trim($name) === '')
		{
			$this->trigger_message('TOPIC_TAG_NAME_REQUIRED', E_USER_WARNING);
		}
		if (manager::normalize_name($name) === '')
		{
			$this->trigger_message('TOPIC_TAG_NAME_TOO_LONG', E_USER_WARNING);
		}
		if (manager::normalize_color($color) === '')
		{
			$this->trigger_message('TOPIC_TAG_COLOR_INVALID', E_USER_WARNING);
		}

		if ($tag_id)
		{
			$tag = $this->manager->update_tag($tag_id, $name, $color, $enabled, $forum_ids);
			$message = 'ACP_LOG_TAG_UPDATED';
		}
		else
		{
			$tag = $this->manager->add_tag($name, $color, $enabled, $forum_ids);
			$message = 'ACP_LOG_TAG_ADDED';
		}
		if (!$tag)
		{
			$this->trigger_message('TOPIC_TAG_NOT_FOUND', E_USER_WARNING);
		}

		$this->log($tag['prefix_tag'], $message);
		$this->trigger_message('TOPIC_TAG_SAVED');
	}

	/**
	 * Delete one tag after confirmation.
	 *
	 * @param int $tag_id Tag identifier
	 * @return void
	 */
	public function delete_tag(int $tag_id): void
	{
		$tag = $this->manager->get_tag($tag_id);
		if (!$tag)
		{
			$this->trigger_message('TOPIC_TAG_NOT_FOUND', E_USER_WARNING);
		}

		if (confirm_box(true))
		{
			$this->manager->delete_tag($tag_id);
			$this->log($tag['prefix_tag'], 'ACP_LOG_TAG_DELETED');
			$this->trigger_message('TOPIC_TAG_DELETED');
		}

		confirm_box(false, $this->language->lang('DELETE_TOPIC_TAG_CONFIRM'), build_hidden_fields([
			'mode' => 'manage',
			'action' => 'delete',
			'tag_id' => $tag_id,
		]));
	}

	/**
	 * Toggle one tag's enabled state.
	 *
	 * @param int $tag_id Tag identifier
	 * @return void
	 */
	public function toggle_tag(int $tag_id): void
	{
		if (!$this->check_hash('toggle' . $tag_id))
		{
			$this->trigger_message('FORM_INVALID', E_USER_WARNING);
		}
		$tag = $this->manager->get_tag($tag_id);
		if (!$tag || !$this->manager->set_enabled($tag_id, !$tag['prefix_enabled']))
		{
			$this->trigger_message('TOPIC_TAG_NOT_FOUND', E_USER_WARNING);
		}
		if ($this->request->is_ajax())
		{
			$this->send_json_response(true);
		}
	}

	/**
	 * Move one tag in display order.
	 *
	 * @param int    $tag_id    Tag identifier
	 * @param string $direction Move direction
	 * @return void
	 */
	public function move_tag(int $tag_id, string $direction): void
	{
		if (!$this->check_hash($direction . $tag_id))
		{
			$this->trigger_message('FORM_INVALID', E_USER_WARNING);
		}
		if (!$this->manager->move_tag($tag_id, $direction))
		{
			$this->trigger_message('TOPIC_TAG_NOT_FOUND', E_USER_WARNING);
		}
		if ($this->request->is_ajax())
		{
			$this->send_json_response(true);
		}
	}

	/**
	 * Set ACP module action URL.
	 *
	 * @param string $u_action Module action URL
	 * @return admin_controller
	 */
	public function set_u_action(string $u_action): self
	{
		$this->u_action = $u_action;
		return $this;
	}

	/**
	 * Validate action link hash.
	 *
	 * @param string $hash Expected hash key
	 * @return bool
	 */
	protected function check_hash(string $hash): bool
	{
		return check_link_hash($this->request->variable('hash', ''), $hash);
	}

	/**
	 * Display localized ACP message and return link.
	 *
	 * @param string $message Language key
	 * @param int    $error   PHP user error level
	 * @return void
	 */
	protected function trigger_message(string $message, int $error = E_USER_NOTICE): void
	{
		trigger_error($this->language->lang($message) . adm_back_link($this->u_action), $error);
	}

	/**
	 * Add ACP log entry.
	 *
	 * @param string $tag     Tag text
	 * @param string $message Log language key
	 * @return void
	 */
	protected function log(string $tag, string $message): void
	{
		$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, $message, time(), [utf8_encode_ucr($tag)]);
	}

	/**
	 * Send AJAX action result.
	 *
	 * @param bool $content Action status
	 * @return void
	 */
	protected function send_json_response(bool $content): void
	{
		$response = new json_response;
		$response->send(['success' => $content]);
	}
}
