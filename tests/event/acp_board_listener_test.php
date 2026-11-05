<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\tests\event;

class acp_board_listener_test extends \phpbb_test_case
{
	public function test_subscribed_events(): void
	{
		self::assertSame([
			'core.acp_board_config_edit_add' => 'add_search_setting',
		], \phpbb\topicprefixes\event\acp_board_listener::getSubscribedEvents());
	}

	public function test_search_setting_is_added_before_submit_legend(): void
	{
		$language = $this->getMockBuilder('\phpbb\language\language')->disableOriginalConstructor()->getMock();
		$language->expects(self::once())->method('add_lang')->with('acp_topic_prefixes', 'phpbb/topicprefixes');
		$listener = new \phpbb\topicprefixes\event\acp_board_listener($language);
		$event = new \phpbb\event\data([
			'mode' => 'features',
			'display_vars' => [
				'title' => 'ACP_BOARD_FEATURES',
				'vars' => [
					'legend1' => 'ACP_BOARD_FEATURES',
					'allow_quick_reply' => ['lang' => 'ALLOW_QUICK_REPLY'],
					'legend2' => 'ACP_SUBMIT_CHANGES',
				],
			],
			'submit' => false,
		]);

		$listener->add_search_setting($event);

		$display_vars = $event['display_vars'];
		self::assertSame([
			'legend1',
			'allow_quick_reply',
			'topicprefixes_search_enabled',
			'legend2',
		], array_keys($display_vars['vars']));
		self::assertSame([
			'lang' => 'ALLOW_TOPIC_TAG_SEARCH',
			'validate' => 'bool',
			'type' => 'radio:yes_no',
			'explain' => true,
		], $display_vars['vars']['topicprefixes_search_enabled']);
		self::assertSame('ACP_SUBMIT_CHANGES', $display_vars['vars']['legend2']);
	}

	public function test_other_board_modes_are_ignored(): void
	{
		$language = $this->getMockBuilder('\phpbb\language\language')->disableOriginalConstructor()->getMock();
		$language->expects(self::never())->method('add_lang');
		$listener = new \phpbb\topicprefixes\event\acp_board_listener($language);
		$display_vars = [
			'title' => 'ACP_BOARD_SETTINGS',
			'vars' => ['legend1' => 'ACP_BOARD_SETTINGS'],
		];
		$event = new \phpbb\event\data([
			'mode' => 'settings',
			'display_vars' => $display_vars,
			'submit' => false,
		]);

		$listener->add_search_setting($event);

		self::assertSame($display_vars, $event['display_vars']);
	}
}
