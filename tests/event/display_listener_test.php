<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2016 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\tests\event;

class display_listener_test extends \phpbb_test_case
{
	/**
	 * Test listener subscribes to all display events.
	 */
	public function test_subscribed_events(): void
	{
		self::assertSame([
			'core.viewtopic_assign_template_vars_before',
			'core.search_modify_rowset',
			'core.search_modify_tpl_ary',
			'core.mcp_forum_topic_data_modify_sql',
			'core.mcp_view_forum_modify_topicrow',
		], array_keys(\phpbb\topicprefixes\event\display_listener::getSubscribedEvents()));
	}

	/**
	 * Test viewtopic tags are assigned through template block API.
	 */
	public function test_viewtopic_tags_use_template_block(): void
	{
		$assignments = $this->getMockBuilder('\phpbb\topicprefixes\tags\assignment_manager')
			->disableOriginalConstructor()
			->getMock();
		$renderer = $this->getMockBuilder('\phpbb\topicprefixes\tags\renderer')
			->disableOriginalConstructor()
			->getMock();
		$template = $this->getMockBuilder('\phpbb\template\template')
			->getMock();

		$tag = [
			'prefix_id' => 1,
			'prefix_tag' => 'Bug',
			'prefix_color' => 'D4351C',
		];
		$rendered = [[
			'TAG_ID' => 1,
			'TAG_NAME' => 'Bug',
			'TAG_COLOR' => '#D4351C',
		]];

		$assignments->expects(self::once())
			->method('get_tags_for_topics')
			->with([42])
			->willReturn([42 => [1 => $tag]]);
		$renderer->expects(self::once())
			->method('render')
			->with([1 => $tag], 2)
			->willReturn($rendered);
		$template->expects(self::once())
			->method('assign_block_vars_array')
			->with('topic_tags', $rendered);
		$template->expects(self::never())
			->method('assign_var');

		$listener = new \phpbb\topicprefixes\event\display_listener($assignments, $renderer, $template);
		$event = new \phpbb\event\data(['topic_id' => 42, 'forum_id' => 2]);
		$listener->add_viewtopic_tags($event);
	}

	/**
	 * Test search tags are batch-loaded and assigned to result rows.
	 */
	public function test_search_tags_are_batch_loaded_and_rendered(): void
	{
		$assignments = $this->getMockBuilder('\phpbb\topicprefixes\tags\assignment_manager')
			->disableOriginalConstructor()
			->getMock();
		$renderer = $this->getMockBuilder('\phpbb\topicprefixes\tags\renderer')
			->disableOriginalConstructor()
			->getMock();
		$template = $this->getMockBuilder('\phpbb\template\template')->getMock();
		$tag = ['prefix_id' => 1, 'prefix_tag' => 'Bug', 'prefix_color' => 'D4351C'];
		$rendered = [['TAG_ID' => 1, 'TAG_NAME' => 'Bug']];

		$assignments->expects(self::once())
			->method('get_tags_for_topics')
			->with([42, 43])
			->willReturn([42 => [1 => $tag]]);
		$renderer->expects(self::once())
			->method('render')
			->with([1 => $tag], 2)
			->willReturn($rendered);

		$listener = new \phpbb\topicprefixes\event\display_listener($assignments, $renderer, $template);
		$listener->load_search_tags(new \phpbb\event\data([
			'rowset' => [['topic_id' => 42], ['topic_id' => 43]],
			'show_results' => 'topics',
		]));
		$event = new \phpbb\event\data([
			'row' => ['topic_id' => 42, 'forum_id' => 2],
			'tpl_ary' => ['TOPIC_TITLE' => 'Tagged topic'],
			'show_results' => 'topics',
		]);
		$listener->add_search_tags($event);

		self::assertSame($rendered, $event['tpl_ary']['TOPIC_TAGS']);
	}

	/**
	 * Test post-mode search results receive their topic tags.
	 */
	public function test_search_post_tags_are_batch_loaded_and_rendered(): void
	{
		$assignments = $this->getMockBuilder('\phpbb\topicprefixes\tags\assignment_manager')
			->disableOriginalConstructor()
			->getMock();
		$renderer = $this->getMockBuilder('\phpbb\topicprefixes\tags\renderer')
			->disableOriginalConstructor()
			->getMock();
		$template = $this->getMockBuilder('\phpbb\template\template')->getMock();
		$tag = ['prefix_id' => 1, 'prefix_tag' => 'Bug', 'prefix_color' => 'D4351C'];
		$rendered = [['TAG_ID' => 1, 'TAG_NAME' => 'Bug']];

		$assignments->expects(self::once())
			->method('get_tags_for_topics')
			->with([42])
			->willReturn([42 => [1 => $tag]]);
		$renderer->expects(self::once())
			->method('render')
			->with([1 => $tag], 2)
			->willReturn($rendered);

		$listener = new \phpbb\topicprefixes\event\display_listener($assignments, $renderer, $template);
		$listener->load_search_tags(new \phpbb\event\data([
			'rowset' => [['topic_id' => 42]],
			'show_results' => 'posts',
		]));
		$event = new \phpbb\event\data([
			'row' => ['topic_id' => 42, 'forum_id' => 2],
			'tpl_ary' => ['TOPIC_TITLE' => 'Tagged topic'],
			'show_results' => 'posts',
		]);
		$listener->add_search_tags($event);

		self::assertSame($rendered, $event['tpl_ary']['TOPIC_TAGS']);
	}

	/**
	 * Test MCP topic tags are batch-loaded and rendered.
	 */
	public function test_mcp_tags_are_batch_loaded_and_rendered(): void
	{
		$assignments = $this->getMockBuilder('\phpbb\topicprefixes\tags\assignment_manager')
			->disableOriginalConstructor()
			->getMock();
		$renderer = $this->getMockBuilder('\phpbb\topicprefixes\tags\renderer')
			->disableOriginalConstructor()
			->getMock();
		$template = $this->getMockBuilder('\phpbb\template\template')->getMock();
		$tag = ['prefix_id' => 1, 'prefix_tag' => 'Bug', 'prefix_color' => 'D4351C'];
		$rendered = [['TAG_ID' => 1, 'TAG_NAME' => 'Bug']];

		$assignments->expects(self::once())
			->method('get_tags_for_topics')
			->with([42, 43])
			->willReturn([42 => [1 => $tag]]);
		$renderer->expects(self::once())
			->method('render')
			->with([1 => $tag], 2)
			->willReturn($rendered);

		$listener = new \phpbb\topicprefixes\event\display_listener($assignments, $renderer, $template);
		$listener->load_mcp_tags(new \phpbb\event\data(['topic_list' => [42, 43]]));
		$event = new \phpbb\event\data([
			'row' => ['topic_id' => 42, 'forum_id' => 2],
			'topic_row' => ['TOPIC_TITLE' => 'Tagged topic'],
		]);
		$listener->add_mcp_tags($event);

		self::assertSame($rendered, $event['topic_row']['MCP_TOPIC_TAGS']);
	}
}
