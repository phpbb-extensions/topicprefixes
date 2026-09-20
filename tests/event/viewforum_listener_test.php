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

class viewforum_listener_test extends \phpbb_test_case
{
	/**
	 * Test listener event subscriptions.
	 */
	public function test_subscribed_events(): void
	{
		self::assertSame(array(
			'core.viewforum_get_topic_data',
			'core.viewforum_get_announcement_topic_ids_data',
			'core.viewforum_get_topic_ids_data',
			'core.viewforum_modify_topics_data',
			'core.viewforum_modify_topicrow',
			'core.pagination_generate_page_link',
		), array_keys(\phpbb\topicprefixes\event\viewforum_listener::getSubscribedEvents()));
	}

	/**
	 * Test filter queries, counts, row rendering, and pagination.
	 */
	public function test_filter_modifies_ids_count_and_pagination()
	{
		$manager = $this->getMockBuilder('\phpbb\topicprefixes\tags\manager')->disableOriginalConstructor()->getMock();
		$assignments = $this->getMockBuilder('\phpbb\topicprefixes\tags\assignment_manager')->disableOriginalConstructor()->getMock();
		$filter = $this->getMockBuilder('\phpbb\topicprefixes\tags\filter')->disableOriginalConstructor()->getMock();
		$request = $this->getMockBuilder('\phpbb\request\request')->disableOriginalConstructor()->getMock();
		$template = $this->getMockBuilder('\phpbb\template\template')->disableOriginalConstructor()->getMock();
		$language = $this->getMockBuilder('\phpbb\language\language')->disableOriginalConstructor()->getMock();
		$renderer = $this->getMockBuilder('\phpbb\topicprefixes\tags\renderer')->disableOriginalConstructor()->getMock();
		$tags = array(
			1 => array('prefix_id' => 1, 'prefix_tag' => 'Bug', 'prefix_color' => 'D4351C', 'prefix_enabled' => 1, 'prefix_order' => 1),
			2 => array('prefix_id' => 2, 'prefix_tag' => 'PHP 8.4', 'prefix_color' => '1D70B8', 'prefix_enabled' => 1, 'prefix_order' => 2),
		);
		$manager->method('get_available_tags')->willReturnCallback(function ($forum_id, $enabled_only = true) use ($tags) {
			return array(1 => $tags[1]);
		});
		$manager->method('get_unavailable_tag_ids')->with(2)->willReturn([2]);
		$manager->method('get_tags_by_ids')->with([2])->willReturn(array(2 => $tags[2]));
		$assignments->expects(self::once())->method('get_tag_ids_for_forum')->with(2, [2])->willReturn([2]);
		$request->method('variable')->with('tags', '')->willReturn('1,2');
		$filter->expects(self::once())->method('count_topics')->with(2, array(1, 2), 0)->willReturn(7);
		$filter->method('condition')->willReturn('FILTER_CONDITION');
		$template->expects(self::once())->method('assign_vars');
		$renderer->method('render')->willReturn(array());
		$renderer->method('filter_url')->willReturn('./viewforum.php?f=2');
		$listener = new \phpbb\topicprefixes\event\viewforum_listener(
			$manager, $assignments, $filter, $renderer, $request, $template, $language
		);

		$config = new \phpbb\event\data(array('forum_id' => 2, 'topics_count' => 20, 'sort_days' => 0, 'sort_key' => 't', 'sort_dir' => 'd'));
		$listener->configure_filter($config);
		self::assertSame(7, $config['topics_count']);

		$ids = new \phpbb\event\data(array('sql_ary' => array('WHERE' => 't.forum_id = 2')));
		$listener->filter_topic_ids($ids);
		self::assertSame('t.forum_id = 2 AND FILTER_CONDITION', $ids['sql_ary']['WHERE']);

		$announcements = new \phpbb\event\data(array('sql_ary' => array('WHERE' => 't.topic_type = 3')));
		$listener->filter_announcements($announcements);
		self::assertSame('(t.topic_type = 3) AND FILTER_CONDITION', $announcements['sql_ary']['WHERE']);

		$assignments->expects(self::exactly(2))
			->method('get_tags_for_topics')
			->withConsecutive([array(10, 11)], [array(20)])
			->willReturnOnConsecutiveCalls(
				array(10 => array(1 => $tags[1])),
				array(20 => array(2 => $tags[2]))
			);
		$listener->load_topic_tags(new \phpbb\event\data(array(
			'rowset' => array(array('topic_id' => 10), array('topic_id' => 11)),
		)));
		$row = new \phpbb\event\data(array(
			'row' => array('topic_id' => 10),
			'topic_row' => array('TOPIC_TITLE' => 'Tagged topic'),
		));
		$listener->add_topic_tags($row);
		self::assertArrayHasKey('TOPIC_TAGS', $row['topic_row']);

		$listener->load_topic_tags(new \phpbb\event\data(array(
			'rowset' => array(array('topic_id' => 12, 'topic_moved_id' => 20)),
		)));
		$shadow = new \phpbb\event\data(array(
			'row' => array('topic_id' => 12, 'topic_moved_id' => 20),
			'topic_row' => array('TOPIC_TITLE' => 'Moved topic'),
		));
		$listener->add_topic_tags($shadow);
		self::assertArrayHasKey('TOPIC_TAGS', $shadow['topic_row']);

		$page = new \phpbb\event\data(array('base_url' => './viewforum.php?f=2', 'on_page' => 2, 'start_name' => 'start', 'per_page' => 25, 'generate_page_link_override' => false));
		$listener->preserve_pagination_filter($page);
		self::assertSame('./viewforum.php?f=2&amp;tags=1,2', $page['base_url']);

		$existing = new \phpbb\event\data(array('base_url' => './viewforum.php?f=2&amp;tags=1,2'));
		$listener->preserve_pagination_filter($existing);
		self::assertSame('./viewforum.php?f=2&amp;tags=1,2', $existing['base_url']);
	}

	/**
	 * Provide preserved-tag filter visibility cases.
	 *
	 * @return array Requested filters, visible choices, and active filters
	 */
	public function preserved_tag_visibility_data(): array
	{
		return array(
			array('', array(1, 2), array()),
			array('3', array(1, 2, 3), array(3)),
		);
	}

	/**
	 * Disabled preserved tags stay hidden unless selected.
	 *
	 * @dataProvider preserved_tag_visibility_data
	 */
	public function test_preserved_filter_visibility(string $requested, array $visible_ids, array $selected_ids): void
	{
		$manager = $this->getMockBuilder('\phpbb\topicprefixes\tags\manager')->disableOriginalConstructor()->getMock();
		$assignments = $this->getMockBuilder('\phpbb\topicprefixes\tags\assignment_manager')->disableOriginalConstructor()->getMock();
		$filter = $this->getMockBuilder('\phpbb\topicprefixes\tags\filter')->disableOriginalConstructor()->getMock();
		$renderer = $this->getMockBuilder('\phpbb\topicprefixes\tags\renderer')->disableOriginalConstructor()->getMock();
		$request = $this->getMockBuilder('\phpbb\request\request')->disableOriginalConstructor()->getMock();
		$template = $this->getMockBuilder('\phpbb\template\template')->disableOriginalConstructor()->getMock();
		$language = $this->getMockBuilder('\phpbb\language\language')->disableOriginalConstructor()->getMock();
		$forum_tags = array(
			1 => array('prefix_id' => 1, 'prefix_tag' => 'Forum tag', 'prefix_color' => 'D4351C', 'prefix_enabled' => 1, 'prefix_order' => 1),
			4 => array('prefix_id' => 4, 'prefix_tag' => 'Disabled forum tag', 'prefix_color' => '505A5F', 'prefix_enabled' => 0, 'prefix_order' => 4),
		);
		$preserved_tags = array(
			2 => array('prefix_id' => 2, 'prefix_tag' => 'Preserved tag', 'prefix_color' => '1D70B8', 'prefix_enabled' => 1, 'prefix_order' => 2),
			3 => array('prefix_id' => 3, 'prefix_tag' => 'Disabled preserved tag', 'prefix_color' => '505A5F', 'prefix_enabled' => 0, 'prefix_order' => 3),
		);

		$manager->method('get_available_tags')->with(2, false)->willReturn($forum_tags);
		$manager->method('get_unavailable_tag_ids')->with(2)->willReturn(array(2, 3));
		$manager->method('get_tags_by_ids')->with(array(2, 3))->willReturn($preserved_tags);
		$assignments->method('get_tag_ids_for_forum')->with(2, array(2, 3))->willReturn(array(2, 3));
		$request->method('variable')->with('tags', '')->willReturn($requested);
		$renderer->expects(self::once())->method('render')->with(
			self::callback(function ($tags) use ($visible_ids) {
				return array_keys($tags) === $visible_ids;
			}),
			2,
			$selected_ids,
			array('st' => 0, 'sk' => 't', 'sd' => 'd'),
			true
		)->willReturn(array());
		$renderer->method('filter_url')->willReturn('./viewforum.php?f=2');
		if ($selected_ids)
		{
			$filter->expects(self::once())->method('count_topics')->with(2, $selected_ids, 0)->willReturn(1);
		}
		else
		{
			$filter->expects(self::never())->method('count_topics');
		}

		$listener = new \phpbb\topicprefixes\event\viewforum_listener(
			$manager, $assignments, $filter, $renderer, $request, $template, $language
		);
		$listener->configure_filter(new \phpbb\event\data(array(
			'forum_id' => 2, 'topics_count' => 20, 'sort_days' => 0, 'sort_key' => 't', 'sort_dir' => 'd',
		)));
	}

	/**
	 * Test invalid request values leave phpBB queries unchanged.
	 */
	public function test_invalid_filter_is_ignored(): void
	{
		$manager = $this->getMockBuilder('\phpbb\topicprefixes\tags\manager')->disableOriginalConstructor()->getMock();
		$assignments = $this->getMockBuilder('\phpbb\topicprefixes\tags\assignment_manager')->disableOriginalConstructor()->getMock();
		$filter = $this->getMockBuilder('\phpbb\topicprefixes\tags\filter')->disableOriginalConstructor()->getMock();
		$renderer = $this->getMockBuilder('\phpbb\topicprefixes\tags\renderer')->disableOriginalConstructor()->getMock();
		$request = $this->getMockBuilder('\phpbb\request\request')->disableOriginalConstructor()->getMock();
		$template = $this->getMockBuilder('\phpbb\template\template')->disableOriginalConstructor()->getMock();
		$language = $this->getMockBuilder('\phpbb\language\language')->disableOriginalConstructor()->getMock();

		$manager->method('get_available_tags')->willReturn(array());
		$manager->method('get_unavailable_tag_ids')->willReturn(array());
		$manager->method('get_tags_by_ids')->willReturn(array());
		$assignments->method('get_tag_ids_for_forum')->willReturn(array());
		$request->method('variable')->with('tags', '')->willReturn('1,invalid');
		$filter->expects(self::never())->method('count_topics');
		$renderer->method('render')->willReturn(array());
		$renderer->method('filter_url')->willReturn('./viewforum.php?f=2');
		$listener = new \phpbb\topicprefixes\event\viewforum_listener(
			$manager, $assignments, $filter, $renderer, $request, $template, $language
		);
		$listener->configure_filter(new \phpbb\event\data(array(
			'forum_id' => 2, 'topics_count' => 20, 'sort_days' => 0, 'sort_key' => 't', 'sort_dir' => 'd',
		)));

		$sql = new \phpbb\event\data(array('sql_ary' => array('WHERE' => 't.forum_id = 2')));
		$listener->filter_topic_ids($sql);
		$listener->filter_announcements($sql);
		self::assertSame('t.forum_id = 2', $sql['sql_ary']['WHERE']);
	}
}
