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
				if ($this->request->is_ajax())
				{
					return;
				}
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
				if ($this->request->is_ajax())
				{
					return;
				}
			break;

			case 'toggle':
				$this->toggle_tag($tag_id);
				if ($this->request->is_ajax())
				{
					return;
				}
			break;

			case 'reorder':
				$this->reorder_tags();
				if ($this->request->is_ajax())
				{
					return;
				}
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
			$tag_data = $this->manager->get_tag($tag_id);
			$tag_forum_names = $forum_names[$tag_id] ?? [];
			$this->template->assign_block_vars('tags', [
				'TAG_ID' => $tag_id,
				'TAG_NAME' => utf8_htmlspecialchars($tag['prefix_tag']),
				'TAG_COLOR' => '#' . $tag['prefix_color'],
				'TAG_TEXT_COLOR' => $this->renderer->contrast_color($tag['prefix_color']),
				'TAG_ENABLED' => (bool) $tag['prefix_enabled'],
				'FORUM_IDS' => implode(',', array_map('intval', $tag_data['forum_ids'] ?? [])),
				'FORUM_COUNT' => count($tag_forum_names),
				'FORUM_NAMES' => array_map('utf8_htmlspecialchars', $tag_forum_names),
				'SEARCH_TEXT' => utf8_htmlspecialchars($tag['prefix_tag'] . ' ' . implode(' ', $tag_forum_names)),
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

		$forum_rows = make_forum_select($editing['forum_ids'], false, true, false, true, false, true);
		foreach ($forum_rows as $forum)
		{
			$forum_name = manager::decode_name($forum['forum_name']);
			$this->template->assign_block_vars('forums', [
				'FORUM_ID' => (int) $forum['forum_id'],
				'FORUM_NAME' => utf8_htmlspecialchars($forum_name),
				'DEPTH' => substr_count($forum['padding'], '&nbsp; &nbsp;'),
				'S_POSTABLE' => (int) $forum['forum_type'] === FORUM_POST && !$forum['disabled'],
				'S_SELECTED' => (bool) $forum['selected'],
			]);
		}

		$this->template->assign_vars([
			'U_ACTION' => $this->u_action,
			'S_EDIT_TAG' => !empty($editing['prefix_id']),
			'TAG_ID' => (int) $editing['prefix_id'],
			'TAG_NAME' => utf8_htmlspecialchars($editing['prefix_tag']),
			'TAG_COLOR' => '#' . $editing['prefix_color'],
			'TAG_ENABLED' => (bool) $editing['prefix_enabled'],
			'TAG_TEXT_COLOR' => $this->renderer->contrast_color($editing['prefix_color']),
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
			$this->respond_error('FORM_INVALID');
			return;
		}

		// phpBB's request API HTML-escapes strings. The manager accepts semantic
		// text and applies the extension's storage encoding exactly once.
		$name = htmlspecialchars_decode($this->request->variable('tag_name', '', true), ENT_COMPAT);
		$color = $this->request->variable('tag_color', manager::DEFAULT_COLOR);
		$enabled = $this->request->variable('tag_enabled', 0);
		$forum_ids = $this->request->variable('forum_ids', [0]);
		if (trim($name) === '')
		{
			$this->respond_error('TOPIC_TAG_NAME_REQUIRED', 'tag_name');
			return;
		}
		if (manager::normalize_name($name) === '')
		{
			$this->respond_error('TOPIC_TAG_NAME_TOO_LONG', 'tag_name');
			return;
		}
		if (manager::normalize_color($color) === '')
		{
			$this->respond_error('TOPIC_TAG_COLOR_INVALID', 'tag_color_text');
			return;
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
			$this->respond_error('TOPIC_TAG_NOT_FOUND');
			return;
		}

		$this->log($tag['prefix_tag'], $message);
		if ($this->request->is_ajax())
		{
			$this->send_json_response([
				'success' => true,
				'message' => $this->language->lang('TOPIC_TAG_SAVED'),
				'tag' => $this->present_tag($tag),
			]);
			return;
		}
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
			$this->respond_error('TOPIC_TAG_NOT_FOUND');
			return;
		}

		if (confirm_box(true))
		{
			$this->manager->delete_tag($tag_id);
			$this->log($tag['prefix_tag'], 'ACP_LOG_TAG_DELETED');
			if ($this->request->is_ajax())
			{
				$this->send_json_response([
					'success' => true,
					'message' => $this->language->lang('TOPIC_TAG_DELETED'),
					'tag_id' => $tag_id,
				]);
				return;
			}
			$this->trigger_message('TOPIC_TAG_DELETED');
			return;
		}

		confirm_box(false, $this->language->lang('DELETE_TOPIC_TAG_CONFIRM', utf8_htmlspecialchars($tag['prefix_tag'])), build_hidden_fields([
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
		$is_post = $this->request->is_set_post('enabled');
		if (($is_post && !check_form_key($this->form_key)) || (!$is_post && !$this->check_hash('toggle' . $tag_id)))
		{
			$this->respond_error('FORM_INVALID');
			return;
		}
		$tag = $this->manager->get_tag($tag_id);
		$enabled = $is_post ? (bool) $this->request->variable('enabled', 0) : empty($tag['prefix_enabled']);
		if (!$tag || !$this->manager->set_enabled($tag_id, $enabled))
		{
			$this->respond_error('TOPIC_TAG_NOT_FOUND');
			return;
		}
		if ($this->request->is_ajax())
		{
			$this->send_json_response([
				'success' => true,
				'message' => $this->language->lang($enabled ? 'TOPIC_TAG_ENABLED_NOTICE' : 'TOPIC_TAG_DISABLED_NOTICE'),
				'tag_id' => $tag_id,
				'enabled' => $enabled,
			]);
		}
	}

	/**
	 * Replace the complete tag order from the AJAX drag-and-drop list.
	 *
	 * @return void
	 */
	public function reorder_tags(): void
	{
		if (!check_form_key($this->form_key))
		{
			$this->respond_error('FORM_INVALID');
			return;
		}

		$tag_ids = $this->request->variable('tag_ids', [0]);
		if (!$this->manager->reorder_tags($tag_ids))
		{
			$this->respond_error('TOPIC_TAG_ORDER_STALE');
			return;
		}

		if ($this->request->is_ajax())
		{
			$this->send_json_response([
				'success' => true,
				'message' => $this->language->lang('TOPIC_TAG_ORDER_SAVED'),
				'order' => array_map('intval', $tag_ids),
			]);
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
			$this->respond_error('FORM_INVALID');
			return;
		}
		if (!$this->manager->move_tag($tag_id, $direction))
		{
			$this->respond_error('TOPIC_TAG_NOT_FOUND');
			return;
		}
		if ($this->request->is_ajax())
		{
			$this->send_json_response(['success' => true]);
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
	 * Build canonical tag data for client-side list updates.
	 *
	 * @param array $tag Tag data including forum identifiers
	 * @return array
	 */
	protected function present_tag(array $tag): array
	{
		$tag_id = (int) $tag['prefix_id'];
		$forum_names = $this->manager->get_forum_names_by_tag();
		$action = str_replace('&amp;', '&', $this->u_action);

		return [
			'id' => $tag_id,
			'name' => $tag['prefix_tag'],
			'color' => '#' . $tag['prefix_color'],
			'text_color' => $this->renderer->contrast_color($tag['prefix_color']),
			'enabled' => (bool) $tag['prefix_enabled'],
			'forum_ids' => array_map('intval', $tag['forum_ids'] ?? []),
			'forum_names' => array_values($forum_names[$tag_id] ?? []),
			'urls' => [
				'edit' => $action . '&action=edit&tag_id=' . $tag_id,
				'delete' => $action . '&action=delete&tag_id=' . $tag_id,
				'toggle' => $action . '&action=toggle&tag_id=' . $tag_id . '&hash=' . generate_link_hash('toggle' . $tag_id),
				'move_up' => $action . '&action=move_up&tag_id=' . $tag_id . '&hash=' . generate_link_hash('up' . $tag_id),
				'move_down' => $action . '&action=move_down&tag_id=' . $tag_id . '&hash=' . generate_link_hash('down' . $tag_id),
			],
		];
	}

	/**
	 * Return an inline AJAX error or phpBB's standard fallback message.
	 *
	 * @param string $message Language key
	 * @param string $field   Related form field identifier
	 * @return void
	 */
	protected function respond_error(string $message, string $field = ''): void
	{
		if ($this->request->is_ajax())
		{
			$this->send_json_response([
				'success' => false,
				'message' => $this->language->lang($message),
				'field' => $field,
			], 422);
			return;
		}

		$this->trigger_message($message, E_USER_WARNING);
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
		$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, $message, time(), [
			utf8_encode_ucr(utf8_htmlspecialchars($tag)),
		]);
	}

	/**
	 * Send AJAX action result.
	 *
	 * @param array $content Response data
	 * @param int   $status  HTTP status code
	 * @return void
	 */
	protected function send_json_response(array $content, int $status = 200): void
	{
		http_response_code($status);
		$response = new json_response;
		$response->send($content);
	}
}
