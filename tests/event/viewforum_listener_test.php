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

class viewforum_listener_test extends \phpbb_test_case
{
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
			1 => array('prefix_id' => 1, 'prefix_tag' => 'Bug', 'prefix_color' => 'D4351C', 'prefix_order' => 1),
			2 => array('prefix_id' => 2, 'prefix_tag' => 'PHP 8.4', 'prefix_color' => '1D70B8', 'prefix_order' => 2),
		);
		$manager->method('get_available_tags')->willReturn($tags);
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

		$page = new \phpbb\event\data(array('base_url' => './viewforum.php?f=2', 'on_page' => 2, 'start_name' => 'start', 'per_page' => 25, 'generate_page_link_override' => false));
		$listener->preserve_pagination_filter($page);
		self::assertSame('./viewforum.php?f=2&amp;tags=1,2', $page['base_url']);
	}
}
