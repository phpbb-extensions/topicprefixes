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

class posting_listener_test extends \phpbb_test_case
{
	protected $manager;
	protected $assignments;
	protected $request;
	protected $language;

	protected function setUp(): void
	{
		parent::setUp();
		global $phpbb_root_path, $phpEx;
		$this->manager = $this->getMockBuilder('\phpbb\topicprefixes\tags\manager')->disableOriginalConstructor()->getMock();
		$this->assignments = $this->getMockBuilder('\phpbb\topicprefixes\tags\assignment_manager')->disableOriginalConstructor()->getMock();
		$this->request = $this->getMockBuilder('\phpbb\request\request')->disableOriginalConstructor()->getMock();
		$this->language = new \phpbb\language\language(new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx));
	}

	public function test_subscribed_events()
	{
		self::assertSame(array(
			'core.posting_modify_submission_errors',
			'core.posting_modify_template_vars',
			'core.posting_modify_submit_post_before',
			'core.submit_post_end',
		), array_keys(\phpbb\topicprefixes\event\posting_listener::getSubscribedEvents()));
	}

	public function test_new_topic_form_supports_multiple_selected_tags()
	{
		$tags = $this->tags();
		$this->manager->expects(self::once())->method('get_available_tags')->with(2)->willReturn($tags);
		$this->request->method('is_set_post')->willReturn(true);
		$this->request->method('variable')->willReturn(array(1, 2));
		$event = new \phpbb\event\data(array(
			'mode' => 'post', 'forum_id' => 2, 'topic_id' => 0, 'post_data' => array(), 'page_data' => array(),
		));

		$this->listener()->add_to_posting_form($event);
		self::assertTrue($event['page_data']['S_TOPIC_TAGS']);
		self::assertCount(2, $event['page_data']['TOPIC_TAGS']);
		self::assertTrue($event['page_data']['TOPIC_TAGS'][0]['S_SELECTED']);
		self::assertTrue($event['page_data']['TOPIC_TAGS'][1]['S_SELECTED']);
	}

	public function test_reply_form_is_untouched()
	{
		$this->manager->expects(self::never())->method('get_available_tags');
		$event = new \phpbb\event\data(array('mode' => 'reply', 'forum_id' => 2, 'topic_id' => 10, 'post_data' => array(), 'page_data' => array()));
		$this->listener()->add_to_posting_form($event);
		self::assertSame(array(), $event['page_data']);
	}

	public function test_first_post_edit_loads_existing_assignments()
	{
		$this->manager->method('get_available_tags')->willReturn($this->tags());
		$this->request->method('is_set_post')->willReturn(false);
		$this->assignments->expects(self::once())->method('get_topic_tag_ids')->with(10)->willReturn(array(2));
		$event = new \phpbb\event\data(array(
			'mode' => 'edit', 'forum_id' => 2, 'topic_id' => 10,
			'post_data' => array('post_id' => 100, 'topic_first_post_id' => 100), 'page_data' => array(),
		));
		$this->listener()->add_to_posting_form($event);
		self::assertFalse($event['page_data']['TOPIC_TAGS'][0]['S_SELECTED']);
		self::assertTrue($event['page_data']['TOPIC_TAGS'][1]['S_SELECTED']);
	}

	public function test_invalid_disabled_or_unavailable_ids_are_rejected()
	{
		$this->request->method('is_set_post')->willReturn(true);
		$this->request->method('variable')->willReturn(array(1, 3));
		$this->manager->expects(self::once())->method('get_assignable_tags')->with(2, array(1, 3))->willReturn(array(1 => $this->tags()[1]));
		$event = new \phpbb\event\data(array(
			'submit' => true, 'mode' => 'post', 'forum_id' => 2, 'post_data' => array(), 'error' => array(),
		));
		$this->listener()->validate_submission($event);
		self::assertCount(1, $event['error']);
	}

	public function test_valid_submission_saves_relationships_without_changing_subject()
	{
		$this->request->method('is_set_post')->willReturn(true);
		$this->request->method('variable')->willReturn(array(1, 2, 2));
		$this->manager->method('get_assignable_tags')->willReturn($this->tags());
		$this->assignments->expects(self::once())->method('set_topic_tags')->with(42, array(1, 2));
		$listener = $this->listener();
		$before = new \phpbb\event\data(array(
			'mode' => 'post', 'forum_id' => 2, 'post_data' => array('post_subject' => 'Plain title'),
		));
		$listener->capture_submission($before);
		self::assertSame('Plain title', $before['post_data']['post_subject']);
		$listener->save_assignments(new \phpbb\event\data(array('data' => array('topic_id' => 42))));
	}

	protected function listener()
	{
		return new \phpbb\topicprefixes\event\posting_listener(
			$this->manager,
			$this->assignments,
			new \phpbb\topicprefixes\tags\renderer('./', 'php'),
			$this->request,
			$this->language
		);
	}

	protected function tags()
	{
		return array(
			1 => array('prefix_id' => 1, 'prefix_tag' => 'Bug', 'prefix_color' => 'D4351C'),
			2 => array('prefix_id' => 2, 'prefix_tag' => 'PHP 8.4', 'prefix_color' => '1D70B8'),
		);
	}
}
