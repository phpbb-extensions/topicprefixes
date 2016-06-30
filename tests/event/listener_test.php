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
	 * @var \PHPUnit_Framework_MockObject_MockObject|\phpbb\topicprefixes\prefixes\manager
	 */
	protected $manager;

	/**
	 * @var \PHPUnit_Framework_MockObject_MockObject|\phpbb\request\request
	 */
	protected $request;

	/**
	 * @var \PHPUnit_Framework_MockObject_MockObject|\phpbb\user
	 */
	protected $user;

	/**
	 * @var array Basic event data for testing
	 */
	protected $event_data = array(
		'forum_id'				=> 2,
		'page_data'				=> array(),
		'mode'					=> 'reply',
		'post_id'				=> 0,
		'topic_first_post_id'	=> 0,
	);

	/**
	 * @inheritdoc
	 */
	public function setUp()
	{
		parent::setUp();

		global $phpbb_root_path, $phpEx;

		$this->manager = $this->getMockBuilder('\phpbb\topicprefixes\prefixes\manager')
			->disableOriginalConstructor()
			->getMock();

		$this->request = $this->getMock('\phpbb\request\request');

		$this->user = $this->getMock('\phpbb\user', array(), array(
			new \phpbb\language\language(new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx)),
			'\phpbb\datetime'
		));

	}

	/**
	 * Create our event listener
	 */
	protected function set_listener()
	{
		$this->listener = new \phpbb\topicprefixes\event\listener(
			$this->manager,
			$this->request,
			$this->user
		);
	}

	/**
	 * Test the event listener is constructed correctly
	 */
	public function test_construct()
	{
		$this->set_listener();
		$this->assertInstanceOf('\Symfony\Component\EventDispatcher\EventSubscriberInterface', $this->listener);
	}

	/**
	 * Test the event listener is subscribing events
	 */
	public function test_getSubscribedEvents()
	{
		$this->assertEquals(array(
			'core.posting_modify_template_vars',
		), array_keys(\phpbb\topicprefixes\event\listener::getSubscribedEvents()));
	}

	/**
	 * Data set for test_update_posting_form
	 *
	 * @return array
	 */
	public function update_posting_form_test_data()
	{
		return array(
			array(
				array(array('prefix_tag' => 'foo')),
				array(
					'mode' => 'reply',
				),
				'',
				array(),
			),
			array(
				array(array('prefix_tag' => 'foo')),
				array(
					'mode'                => 'edit',
					'post_id'             => 10,
					'topic_first_post_id' => 5,
				),
				'',
				array(),
			),
			array(
				array(array('prefix_tag' => 'foo')),
				array(
					'mode' => 'post',
				),
				'',
				array('PREFIXES' => array(array('prefix_tag' => 'foo', 'selected' => false))),
			),
			array(
				array(array('prefix_tag' => 'foo')),
				array(
					'mode' => 'edit',
				),
				'',
				array('PREFIXES' => array(array('prefix_tag' => 'foo', 'selected' => false))),
			),
			array(
				array(array('prefix_tag' => 'foo')),
				array(
					'mode' => 'post',
				),
				'foo',
				array('PREFIXES' => array(array('prefix_tag' => 'foo', 'selected' => true))),
			),
			array(
				array(array('prefix_tag' => 'foo')),
				array(
					'mode' => 'post',
				),
				'bar',
				array('PREFIXES' => array(array('prefix_tag' => 'foo', 'selected' => false))),
			),
		);
	}

	/**
	 * Test update_posting_form() method
	 *
	 * @param array  $prefixes
	 * @param array  $test_data
	 * @param string $selected
	 * @param array  $expected
	 *
	 * @dataProvider update_posting_form_test_data
	 */
	public function test_update_posting_form($prefixes, $test_data, $selected, $expected)
	{
		$this->set_listener();

		$test_data = array_merge($this->event_data, $test_data);

		$data = new \phpbb\event\data($test_data);

		$this->manager->expects($this->any())
			->method('get_active_prefixes')
			->will($this->returnValue($prefixes));

		$this->manager->expects($this->any())
			->method('is_parent')
			->will($this->returnValue($selected));

		$this->request->expects($this->any())
			->method('variable')
			->will($this->returnValue($selected));

		$this->listener->update_posting_form($data);

		$this->assertSame($expected, $data['page_data']);
	}
}
