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

class listener_test extends \phpbb_test_case
{
	/**
	 * @var \phpbb\topicprefixes\event\listener
	 */
	protected $listener;

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\topicprefixes\prefixes\manager
	 */
	protected $manager;

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\request\request
	 */
	protected $request;

	/**
	 * @var \phpbb\language\language
	 */
	protected $language;

	/**
	 * @inheritdoc
	 */
	protected function setUp(): void
	{
		parent::setUp();

		global $phpbb_root_path, $phpEx;

		$this->manager = $this->getMockBuilder('\phpbb\topicprefixes\prefixes\manager')
			->setMethods(array('get_active_prefixes', 'get_prefix'))
			->disableOriginalConstructor()
			->getMock();

		$this->request = $this->getMockBuilder('\phpbb\request\request')
			->disableOriginalConstructor()
			->getMock();

		$this->language = new \phpbb\language\language(new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx));
	}

	/**
	 * Get basic event data for testing
	 *
	 * @return array
	 */
	protected function get_event_data()
	{
		return array(
			'forum_id'				=> 2,
			'page_data'				=> array(),
			'data'					=> array(),
			'mode'					=> 'post',
			'post_data'				=> array(
				'post_id'				=> 0,
				'topic_first_post_id'	=> 0,
			),
		);
	}

	/**
	 * Create our event listener
	 */
	protected function set_listener()
	{
		$this->listener = new \phpbb\topicprefixes\event\listener(
			$this->manager,
			$this->request,
			$this->language
		);
	}

	/**
	 * Test the event listener is constructed correctly
	 */
	public function test_construct()
	{
		$this->set_listener();
		self::assertInstanceOf('\Symfony\Component\EventDispatcher\EventSubscriberInterface', $this->listener);
	}

	/**
	 * Test the event listener is subscribing events
	 */
	public function test_getSubscribedEvents()
	{
		self::assertEquals(array(
			'core.posting_modify_template_vars',
			'core.posting_modify_submit_post_before',
			'core.submit_post_modify_sql_data',
		), array_keys(\phpbb\topicprefixes\event\listener::getSubscribedEvents()));
	}

	/**
	 * Data set for test_add_to_posting_form
	 *
	 * @return array
	 */
	public function data_add_to_posting_form()
	{
		$prefix_data = array(
			1 => array(
				'prefix_id' => 1,
				'prefix_tag' => '[foo]',
				'prefix_enabled' => 1,
			)
		);

		return array(
			array( // test no changes when not in expected mode
				$prefix_data,
				array('mode' => 'reply'),
				0,
				array(),
			),
			array( // test no changes when editing a reply post
				$prefix_data,
				array(
					'mode'		=> 'edit',
					'post_data'	=> array(
						'post_id'             => 10,
						'topic_first_post_id' => 5,
					),
				),
				0,
				array(),
			),
			array( // test adding prefixes form when posting new topic
				$prefix_data,
				array('mode' => 'post'),
				0,
				array(
					'PREFIXES' 			=> $prefix_data,
					'SELECTED_PREFIX'	=> '',
				),
			),
			array( // test adding prefixes when editing first post
				$prefix_data,
				array('mode' => 'edit'),
				0,
				array(
					'PREFIXES'			=> $prefix_data,
					'SELECTED_PREFIX'	=> '',
				),
			),
			array( // test finding existing prefixes when editing first post
				   $prefix_data,
				   array(
					   'mode' => 'edit',
					   'post_data'	=> array(
						   'post_id'             => 15,
						   'topic_first_post_id' => 15,
						   'topic_prefix_id' => '1',
					   ),
				   ),
				   0,
				   array(
					   'PREFIXES'			=> $prefix_data,
					   'SELECTED_PREFIX'	=> '[foo]',
				   ),
			),
			array( // test finding prefixes in title when editing first post
				$prefix_data,
				array(
					'mode' => 'edit',
					'post_data'	=> array(
						'post_id'             => 20,
						'topic_first_post_id' => 20,
						'topic_title' => '[foo] bar was here',
					),
				),
				0,
				array(
					'PREFIXES'			=> $prefix_data,
					'SELECTED_PREFIX'	=> '[foo]',
				),
			),
			array( // test not finding prefixes in title when editing first post
				$prefix_data,
				array(
					'mode' => 'edit',
					'post_data'	=> array(
						'post_id'             => 30,
						'topic_first_post_id' => 30,
						'topic_title' => '[bar] bar was here',
					),
				),
				0,
				array(
					'PREFIXES'			=> $prefix_data,
					'SELECTED_PREFIX'	=> '',
				),
			),
			array( // test adding selected prefix when posting new topic
				$prefix_data,
				array('mode' => 'post'),
				1,
				array(
					'PREFIXES'			=> $prefix_data,
					'SELECTED_PREFIX'	=> '[foo]',
				),
			),
			array( // test adding selected prefix when previewing a new topic
				$prefix_data,
				array(
					'mode' => 'post',
					'preview' => true,
				),
				1,
				array(
					'PREFIXES'			=> $prefix_data,
					'SELECTED_PREFIX'	=> '[foo]',
				),
			),
			array( // test adding null selected prefix when posting new topic with bad data
				$prefix_data,
				array('mode' => 'post'),
				2,
				array(
					'PREFIXES'			=> $prefix_data,
					'SELECTED_PREFIX'	=> '',
				),
			),
			array( // test adding null selected prefix when previewing new topic with bad data
				$prefix_data,
				array(
					'mode' => 'post',
					'preview' => true,
				),
				2,
				array(
					'PREFIXES'			=> $prefix_data,
					'SELECTED_PREFIX'	=> '',
				),
			),
			array( // test post preview mode shows the expected prefix when no prefix is selected
				$prefix_data,
				array(
					'mode' => 'edit',
					'preview' => true,
				),
				0,
				array(
					'PREFIXES'			=> $prefix_data,
					'SELECTED_PREFIX'	=> '',
				),
			),
			array( // test post preview mode shows the expected prefix when a prefix is selected
				$prefix_data,
				array(
					'mode' => 'edit',
					'preview' => true,
				),
				1,
				array(
					'PREFIXES'			=> $prefix_data,
					'SELECTED_PREFIX'	=> '[foo]',
				),
			),
		);
	}

	/**
	 * Test add_to_posting_form() method
	 *
	 * @dataProvider data_add_to_posting_form
	 * @param array  $prefixes
	 * @param array  $parameters
	 * @param string $selected
	 * @param array  $expected
	 *
	 */
	public function test_add_to_posting_form($prefixes, $parameters, $selected, $expected)
	{
		$this->set_listener();

		$test_data = array_merge($this->get_event_data(), $parameters);

		$data = new \phpbb\event\data($test_data);

		$this->manager->expects(self::atMost(1))
			->method('get_active_prefixes')
			->willReturn($prefixes);

		$this->request->expects(isset($expected['SELECTED_PREFIX']) ? self::once() : self::never())
			->method('variable')
			->willReturn($selected);

		$this->listener->add_to_posting_form($data);

		self::assertSame($expected, $data['page_data']);
	}

	/**
	 * Data for test_submit_prefix_data
	 *
	 * @return array
	 */
	public function data_submit_prefix_data()
	{
		return array(
			array( // test adding prefix to a subject when posting
				array(
					'prefix_id' => 1,
					'prefix_tag' => '[foo]'
				),
				array(
					'post_data'	=> array(
						'post_subject'	=> 'test subject',
					),
				),
				array(
					'data'		=> array('topic_prefix_id' => 1),
					'post_data'	=> array('post_subject' => '[foo] test subject'),
				),
			),
			array( // test adding prefix to a subject when editing
				array(
					'prefix_id' => 2,
					'prefix_tag' => '[bar]'
				),
				array(
					'mode' => 'edit',
					'post_data'	=> array(
						'post_subject'	=> 'test subject',
					),
				),
				array(
					'data'		=> array('topic_prefix_id' => 2),
					'post_data'	=> array('post_subject' => '[bar] test subject'),
				),
			),
			array( // test not adding prefix to a subject that already has the prefix
				   array(
					   'prefix_id' => 3,
					   'prefix_tag' => '[foobar]'
				   ),
				   array(
					   'mode' => 'post',
					   'post_data'	=> array(
						   'post_subject'	=> '[foobar] test subject',
					   ),
				   ),
				   array(
					   'data'		=> array('topic_prefix_id' => 3),
					   'post_data'	=> array('post_subject' => '[foobar] test subject'),
				   ),
			),
			array( // test no changes happen when no prefix was found
				false,
				array(
					'post_data'	=> array(
						'post_subject'	=> 'test subject',
					),
				),
				array(
					'data'		=> array('topic_prefix_id' => 0),
					'post_data'	=> array('post_subject' => 'test subject'),
				),
			),
			array( // test no changes happen when not posting or editing
				false,
				array(
					'mode' => 'reply',
					'post_data'	=> array(
						'post_subject'	=> 'test subject',
					),
				),
				array(
					'data'		=> array('topic_prefix_id' => 0),
					'post_data'	=> array('post_subject' => 'test subject'),
				),
			),
		);
	}

	/**
	 * Test the submit_prefix_data() method
	 *
	 * @dataProvider data_submit_prefix_data
	 * @param $prefix
	 * @param $parameters
	 * @param $expected
	 */
	public function test_submit_prefix_data($prefix, $parameters, $expected)
	{
		$this->set_listener();

		$test_data = array_merge($this->get_event_data(), $parameters);

		$data = new \phpbb\event\data($test_data);

		$prefix_id = $prefix['prefix_id'] ?? 0;

		$this->request->expects(self::once())
			->method('variable')
			->willReturnMap(array(
				array('topic_prefix', 0, false, \phpbb\request\request_interface::REQUEST, $prefix_id),
			));

		$this->manager->expects(self::atMost(1))
			->method('get_prefix')
			->willReturn($prefix);

		$this->listener->submit_prefix_data($data);

		self::assertSame($expected['data'], $data['data']);
		self::assertSame($expected['post_data'], $data['post_data']);
	}

	/**
	 * Data for test_save_prefix_to_topic
	 *
	 * @return array
	 */
	public function data_save_prefix_to_topic()
	{
		return array(
			array( // test updating the sql when posting new topic
				array(
					'post_mode'	=> 'post',
					'sql_data'	=> array(TOPICS_TABLE => array('sql' => array())),
					'data'		=> array('topic_prefix_id' => 1),
				),
				array(TOPICS_TABLE => array('sql' => array('topic_prefix_id' => 1))),
			),
			array( // test updating the sql when editing topic
				array(
					'post_mode'	=> 'edit_topic',
					'sql_data'	=> array(TOPICS_TABLE => array('sql' => array())),
					'data'		=> array('topic_prefix_id' => 2),
				),
				array(TOPICS_TABLE => array('sql' => array('topic_prefix_id' => 2))),
			),
			array( // test updating the sql when editing the first topic
				array(
					'post_mode'	=> 'edit_first_post',
					'sql_data'	=> array(TOPICS_TABLE => array('sql' => array())),
					'data'		=> array('topic_prefix_id' => 3),
				),
				array(TOPICS_TABLE => array('sql' => array('topic_prefix_id' => 3))),
			),
			array( // test not updating the sql when post mode is unexpected
				array(
					'post_mode'	=> '',
					'sql_data'	=> array(TOPICS_TABLE => array('sql' => array())),
					'data'		=> array('topic_prefix_id' => 1),
				),
				array(TOPICS_TABLE => array('sql' => array())),
			),
		);
	}

	/**
	 * Test the save_prefix_to_topic() method
	 *
	 * @dataProvider data_save_prefix_to_topic
	 * @param $event_data
	 * @param $expected
	 */
	public function test_save_prefix_to_topic($event_data, $expected)
	{
		$this->set_listener();

		$data = new \phpbb\event\data($event_data);

		$this->listener->save_prefix_to_topic($data);

		self::assertSame($expected, $data['sql_data']);
	}
}
