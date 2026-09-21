<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\controller;

require_once __DIR__ . '/../../../../../includes/functions_acp.php';

class admin_controller_test extends \phpbb_test_case
{
	public static $valid_hash = true;
	public static $valid_form = true;
	public static $confirmed = false;
	public static $triggered_message;
	public static $throw_on_trigger = false;

	protected $manager;
	protected $renderer;
	protected $language;
	protected $log;
	protected $request;
	protected $template;
	protected $user;
	protected $controller;

	protected function setUp(): void
	{
		parent::setUp();
		global $phpbb_root_path, $phpEx, $user;
		$this->manager = $this->getMockBuilder('\phpbb\topicprefixes\tags\manager')->disableOriginalConstructor()->getMock();
		$this->renderer = $this->getMockBuilder('\phpbb\topicprefixes\tags\renderer')->disableOriginalConstructor()->getMock();
		$this->language = new \phpbb\language\language(new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx));
		$this->language->add_lang('acp_topic_prefixes', 'phpbb/topicprefixes');
		$this->log = $this->getMockBuilder('\phpbb\log\log')->disableOriginalConstructor()->getMock();
		$this->request = $this->getMockBuilder('\phpbb\request\request')->disableOriginalConstructor()->getMock();
		$this->template = $this->getMockBuilder('\phpbb\template\template')->disableOriginalConstructor()->getMock();
		$this->user = $this->getMockBuilder('\phpbb\user')->disableOriginalConstructor()->getMock();
		$this->user->data = array('user_id' => 2, 'user_form_salt' => 'salt');
		$this->user->ip = '127.0.0.1';
		$this->user->lang = array('BACK_TO_PREV' => 'Back');
		self::$valid_hash = true;
		self::$valid_form = true;
		self::$confirmed = false;
		self::$triggered_message = null;
		self::$throw_on_trigger = false;
		$user = $this->user;
		$this->controller = new admin_controller(
			$this->manager, $this->renderer, $this->language, $this->log, $this->request, $this->template, $this->user
		);
		$this->controller->set_u_action('adm.php?mode=manage');
	}

	public function test_display_settings_lists_tags_and_edit_form()
	{
		$this->manager->method('get_forum_names_by_tag')->willReturn(array(1 => array('Forum Two')));
		$this->manager->method('get_tags')->willReturn(array(1 => array(
			'prefix_id' => 1, 'prefix_tag' => '<Bug>', 'prefix_color' => 'D4351C', 'prefix_enabled' => 1,
		)));
		$this->renderer->method('contrast_color')->with('D4351C')->willReturn('#FFFFFF');
		$this->template->expects(self::once())->method('assign_block_vars')->with('tags', self::callback(function ($row) {
			return $row['TAG_NAME'] === '&lt;Bug&gt;' && $row['FORUM_NAMES'] === array('Forum Two') && $row['TAG_TEXT_COLOR'] === '#FFFFFF';
		}));
		$this->template->expects(self::once())->method('assign_vars')->with(self::callback(function ($vars) {
			return $vars['TAG_ID'] === 0 && $vars['S_FORUM_OPTIONS'] === '#forum options#';
		}));
		$this->controller->display_settings();
	}

	public function test_toggle_updates_enabled_state()
	{
		$this->request->method('variable')->willReturn('valid');
		$this->request->method('is_ajax')->willReturn(false);
		$this->manager->expects(self::once())->method('get_tag')->with(1)->willReturn(array('prefix_enabled' => 1));
		$this->manager->expects(self::once())->method('set_enabled')->with(1, false)->willReturn(true);
		$this->controller->toggle_tag(1);
	}

	public function test_ajax_toggle_sends_success_response(): void
	{
		$this->request->method('variable')->willReturn('valid');
		$this->request->method('is_ajax')->willReturn(true);
		$this->manager->expects(self::once())->method('get_tag')->with(1)->willReturn(array('prefix_enabled' => 1));
		$this->manager->expects(self::once())->method('set_enabled')->with(1, false)->willReturn(true);

		self::assertSame('{"success":true}', $this->capture_json_response(function () {
			$this->controller->toggle_tag(1);
		}));
	}

	public function test_move_updates_order()
	{
		$this->request->method('variable')->willReturn('valid');
		$this->request->method('is_ajax')->willReturn(false);
		$this->manager->expects(self::once())->method('move_tag')->with(2, 'down')->willReturn(true);
		$this->controller->move_tag(2, 'down');
	}

	public function test_ajax_move_sends_success_response(): void
	{
		$this->request->method('variable')->willReturn('valid');
		$this->request->method('is_ajax')->willReturn(true);
		$this->manager->expects(self::once())->method('move_tag')->with(2, 'down')->willReturn(true);

		self::assertSame('{"success":true}', $this->capture_json_response(function () {
			$this->controller->move_tag(2, 'down');
		}));
	}

	public function test_delete_requires_confirmation()
	{
		self::$confirmed = false;
		$this->manager->expects(self::once())->method('get_tag')->with(1)->willReturn(array('prefix_tag' => 'Bug'));
		$this->manager->expects(self::never())->method('delete_tag');
		$this->controller->delete_tag(1);
	}

	/**
	 * Test creating a tag validates input, logs, and reports success.
	 */
	public function test_save_creates_tag(): void
	{
		$this->request->method('is_set_post')->with('submit')->willReturn(true);
		$this->request->method('variable')->willReturnCallback(function ($name, $default) {
			return array(
				'tag_name' => 'Security',
				'tag_color' => '#AA00CC',
				'tag_enabled' => 1,
				'forum_ids' => array(2, 3),
			)[$name] ?? $default;
		});
		$this->manager->expects(self::once())->method('normalize_color')->with('#AA00CC')->willReturn('AA00CC');
		$this->manager->expects(self::once())
			->method('add_tag')
			->with('Security', '#AA00CC', 1, array(2, 3))
			->willReturn(array('prefix_tag' => 'Security'));
		$this->log->expects(self::once())->method('add')->with(
			'admin', 2, '127.0.0.1', 'ACP_LOG_TAG_ADDED', self::isType('int'), array('Security')
		);

		$this->controller->save_tag(0);

		self::assertStringContainsString('TOPIC_TAG_SAVED', self::$triggered_message);
	}

	public function test_save_passes_valid_emoji_name_to_manager_once(): void
	{
		$name = str_repeat('😇', 6);
		$this->request->method('is_set_post')->with('submit')->willReturn(true);
		$this->request->method('variable')->willReturnCallback(function ($key, $default) use ($name) {
			return array(
				'tag_name' => $name,
				'tag_color' => '#4A76A8',
				'tag_enabled' => 1,
				'forum_ids' => array(2),
			)[$key] ?? $default;
		});
		$this->manager->method('normalize_color')->willReturn('4A76A8');
		$this->manager->expects(self::once())
			->method('add_tag')
			->with($name, '#4A76A8', 1, array(2))
			->willReturn(array('prefix_tag' => $name));

		$this->controller->save_tag(0);

		self::assertStringContainsString('TOPIC_TAG_SAVED', self::$triggered_message);
	}

	/**
	 * Test saving an existing tag uses update path.
	 */
	public function test_save_updates_tag(): void
	{
		$this->request->method('is_set_post')->with('submit')->willReturn(true);
		$this->request->method('variable')->willReturnCallback(function ($name, $default) {
			return array(
				'tag_name' => 'Updated',
				'tag_color' => '#00AA00',
				'tag_enabled' => 0,
				'forum_ids' => array(3),
			)[$name] ?? $default;
		});
		$this->manager->method('normalize_color')->willReturn('00AA00');
		$this->manager->expects(self::once())
			->method('update_tag')
			->with(1, 'Updated', '#00AA00', 0, array(3))
			->willReturn(array('prefix_tag' => 'Updated'));
		$this->log->expects(self::once())->method('add')->with(
			'admin', 2, '127.0.0.1', 'ACP_LOG_TAG_UPDATED', self::isType('int'), array('Updated')
		);

		$this->controller->save_tag(1);

		self::assertStringContainsString('TOPIC_TAG_SAVED', self::$triggered_message);
	}

	/**
	 * Test confirmed deletion removes tag and writes ACP log.
	 */
	public function test_confirmed_delete_removes_tag(): void
	{
		self::$confirmed = true;
		$this->manager->method('get_tag')->with(1)->willReturn(array('prefix_tag' => 'Bug'));
		$this->manager->expects(self::once())->method('delete_tag')->with(1);
		$this->log->expects(self::once())->method('add')->with(
			'admin', 2, '127.0.0.1', 'ACP_LOG_TAG_DELETED', self::isType('int'), array('Bug')
		);

		$this->controller->delete_tag(1);

		self::assertStringContainsString('TOPIC_TAG_DELETED', self::$triggered_message);
	}

	/**
	 * Test main action dispatch loads edit form.
	 */
	public function test_main_dispatches_edit_action(): void
	{
		$tag = array(
			'prefix_id' => 1,
			'prefix_tag' => 'Bug',
			'prefix_color' => 'D4351C',
			'prefix_enabled' => 1,
			'forum_ids' => array(2),
		);
		$this->request->method('variable')->willReturnCallback(function ($name, $default) {
			return array('action' => 'edit', 'tag_id' => 1)[$name] ?? $default;
		});
		$this->manager->expects(self::once())->method('get_tag')->with(1)->willReturn($tag);
		$this->manager->method('get_forum_names_by_tag')->willReturn(array());
		$this->manager->method('get_tags')->willReturn(array());
		$this->template->expects(self::once())->method('assign_vars')->with(self::callback(function ($vars) {
			return $vars['S_EDIT_TAG'] && $vars['TAG_ID'] === 1;
		}));

		$this->controller->main();
	}

	/**
	 * Test edit action rejects missing tag records.
	 */
	public function test_main_rejects_missing_edit_tag(): void
	{
		self::$throw_on_trigger = true;
		$this->request->method('variable')->willReturnCallback(function ($name, $default) {
			return array('action' => 'edit', 'tag_id' => 999)[$name] ?? $default;
		});
		$this->manager->method('get_tag')->with(999)->willReturn(false);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('TOPIC_TAG_NOT_FOUND');

		$this->controller->main();
	}

	/**
	 * Provide non-edit ACP action routing cases.
	 *
	 * @return array Action, expected method, and expected arguments
	 */
	public function main_action_data(): array
	{
		return array(
			array('save', 'save_tag', array(2)),
			array('delete', 'delete_tag', array(2)),
			array('toggle', 'toggle_tag', array(2)),
			array('move_up', 'move_tag', array(2, 'up')),
			array('move_down', 'move_tag', array(2, 'down')),
		);
	}

	/**
	 * Test main routes each ACP action to focused handler.
	 *
	 * @dataProvider main_action_data
	 */
	public function test_main_routes_actions(string $action, string $method, array $arguments): void
	{
		$this->request->method('variable')->willReturnCallback(function ($name, $default) use ($action) {
			return array('action' => $action, 'tag_id' => 2)[$name] ?? $default;
		});
		$controller = $this->getMockBuilder(admin_controller::class)
			->setConstructorArgs(array(
				$this->manager, $this->renderer, $this->language, $this->log,
				$this->request, $this->template, $this->user,
			))
			->setMethods(array('save_tag', 'delete_tag', 'toggle_tag', 'move_tag', 'display_settings'))
			->getMock();
		$controller->expects(self::once())->method($method)->with(...$arguments);
		$controller->expects(self::once())->method('display_settings')->with(false);

		$controller->main();
	}

	/**
	 * Test invalid form submission stops before tag writes.
	 */
	public function test_save_rejects_invalid_form(): void
	{
		self::$valid_form = false;
		self::$throw_on_trigger = true;
		$this->request->method('is_set_post')->with('submit')->willReturn(true);
		$this->manager->expects(self::never())->method('add_tag');
		$this->expectException(\RuntimeException::class);

		$this->controller->save_tag(0);
	}

	/**
	 * Provide ACP validation and missing-record error cases.
	 *
	 * @return array Scenario and expected language key
	 */
	public function error_path_data(): array
	{
		return array(
			array('missing_name', 'TOPIC_TAG_NAME_REQUIRED'),
			array('name_too_long', 'TOPIC_TAG_NAME_TOO_LONG'),
			array('invalid_color', 'TOPIC_TAG_COLOR_INVALID'),
			array('failed_update', 'TOPIC_TAG_NOT_FOUND'),
			array('missing_delete', 'TOPIC_TAG_NOT_FOUND'),
			array('invalid_toggle_hash', 'submitted form was invalid'),
			array('missing_toggle', 'TOPIC_TAG_NOT_FOUND'),
			array('invalid_move_hash', 'submitted form was invalid'),
			array('missing_move', 'TOPIC_TAG_NOT_FOUND'),
		);
	}

	/**
	 * Test ACP handlers stop on invalid input or missing records.
	 *
	 * @dataProvider error_path_data
	 */
	public function test_error_paths(string $scenario, string $message): void
	{
		self::$throw_on_trigger = true;
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage($message);

		switch ($scenario)
		{
			case 'missing_name':
				$this->configure_save_request('', '#AA00CC');
				$this->controller->save_tag(0);
			break;

			case 'name_too_long':
				$this->configure_save_request(str_repeat('😇', 29), '#AA00CC');
				$this->controller->save_tag(0);
			break;

			case 'invalid_color':
				$this->configure_save_request('Bug', 'invalid');
				$this->manager->method('normalize_color')->willReturn('');
				$this->controller->save_tag(0);
			break;

			case 'failed_update':
				$this->configure_save_request('Bug', '#AA00CC');
				$this->manager->method('normalize_color')->willReturn('AA00CC');
				$this->manager->method('update_tag')->willReturn(false);
				$this->controller->save_tag(999);
			break;

			case 'missing_delete':
				$this->manager->method('get_tag')->willReturn(false);
				$this->controller->delete_tag(999);
			break;

			case 'invalid_toggle_hash':
				self::$valid_hash = false;
				$this->controller->toggle_tag(999);
			break;

			case 'missing_toggle':
				$this->manager->method('get_tag')->willReturn(false);
				$this->controller->toggle_tag(999);
			break;

			case 'invalid_move_hash':
				self::$valid_hash = false;
				$this->controller->move_tag(999, 'up');
			break;

			case 'missing_move':
				$this->manager->method('move_tag')->willReturn(false);
				$this->controller->move_tag(999, 'up');
			break;
		}
	}

	/**
	 * Configure valid form request values for save error tests.
	 *
	 * @param string $name  Tag name
	 * @param string $color Tag color
	 */
	protected function configure_save_request(string $name, string $color): void
	{
		$this->request->method('is_set_post')->with('submit')->willReturn(true);
		$this->request->method('variable')->willReturnCallback(function ($key, $default) use ($name, $color) {
			return array(
				'tag_name' => $name,
				'tag_color' => $color,
				'tag_enabled' => 1,
				'forum_ids' => array(2),
			)[$key] ?? $default;
		});
	}

	/**
	 * Run phpBB's real JSON response without terminating PHPUnit.
	 *
	 * @param callable $callback Controller action that sends a JSON response
	 * @return string Captured response body
	 */
	protected function capture_json_response(callable $callback): string
	{
		global $cache, $db, $phpbb_dispatcher, $phpbb_hook;

		$previous_globals = array($cache ?? null, $db ?? null, $phpbb_dispatcher ?? null, $phpbb_hook ?? null);
		$cache = $db = $phpbb_dispatcher = null;
		$phpbb_hook = new class
		{
			public function call_hook($name)
			{
				return $name === 'exit_handler';
			}

			public function hook_return($name)
			{
				return $name === 'exit_handler';
			}

			public function hook_return_result($name)
			{
				return null;
			}
		};

		$previous_error_handler = null;
		$previous_error_handler = set_error_handler(static function ($severity, $message, $file, $line, $context = null) use (&$previous_error_handler) {
			if (strpos($message, 'Cannot modify header information') === 0)
			{
				return true;
			}

			return $previous_error_handler
				? call_user_func($previous_error_handler, $severity, $message, $file, $line, $context)
				: false;
		});
		$buffer_level = ob_get_level();
		ob_start();
		try
		{
			$callback();
			return ob_get_clean();
		}
		finally
		{
			while (ob_get_level() > $buffer_level)
			{
				ob_end_clean();
			}
			restore_error_handler();
			list($cache, $db, $phpbb_dispatcher, $phpbb_hook) = $previous_globals;
		}
	}
}

function add_form_key()
{
}

function check_form_key()
{
	return admin_controller_test::$valid_form;
}

function trigger_error($message, $error = E_USER_NOTICE)
{
	admin_controller_test::$triggered_message = $message;
	if (admin_controller_test::$throw_on_trigger)
	{
		throw new \RuntimeException($message, $error);
	}
}

function make_forum_select()
{
	return '#forum options#';
}

function check_link_hash()
{
	return admin_controller_test::$valid_hash;
}

function confirm_box($check)
{
	return $check ? admin_controller_test::$confirmed : false;
}
