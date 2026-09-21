<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2016 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\tests\functional;

/** @group functional */
class functional_test extends \phpbb_functional_test_case
{
	const FORUM_ID = 2;

	protected static function setup_extensions()
	{
		return array('phpbb/topicprefixes');
	}

	protected function setUp(): void
	{
		parent::setUp();
		$this->add_lang_ext('phpbb/topicprefixes', array('acp_topic_prefixes', 'info_acp_topic_prefixes', 'topic_prefixes'));
	}

	public function test_acp_module()
	{
		$this->login();
		$this->admin_login();
		$crawler = $this->acp_page();
		$this->assertContainsLang('TOPIC_TAGS', $crawler->filter('#main')->text());
		self::assertCount(1, $crawler->filter('input[type="color"]'));
		self::assertCount(1, $crawler->filter('select[name="forum_ids[]"][multiple]'));

		return true;
	}

	/**
	 * @depends test_acp_module
	 */
	public function test_create_shared_tagged_topic($module_ready)
	{
		self::assertTrue($module_ready);
		$this->login();
		$this->admin_login();
		$bug_id = $this->create_tag('Bug filter', '#d4351c', array(self::FORUM_ID));
		$php_id = $this->create_tag('PHP 8.4 filter', '#1d70b8', array(self::FORUM_ID));
		$topic = $this->create_topic(self::FORUM_ID, 'Structured tag title', 'Tagged first post', array(
			'topic_tags' => array($bug_id, $php_id),
			'topic_tags_present' => 1,
		));

		self::assertGreaterThan(0, $bug_id);
		self::assertGreaterThan(0, $php_id);
		self::assertNotEmpty($topic['topic_id']);
		self::assertNotEmpty($topic['post_id']);

		return array(
			'bug_id' => $bug_id,
			'php_id' => $php_id,
			'topic_id' => (int) $topic['topic_id'],
			'post_id' => (int) $topic['post_id'],
		);
	}

	/**
	 * @depends test_create_shared_tagged_topic
	 */
	public function test_acp_edit_tag($fixture)
	{
		$this->login();
		$this->admin_login();
		$tag_id = $fixture['bug_id'];

		$crawler = $this->acp_page('action=edit&tag_id=' . $tag_id);
		$form = $crawler->selectButton($this->lang('SUBMIT'))->form(array(
			'tag_name' => 'Confirmed bug filter',
			'tag_color' => '#aa00cc',
			'tag_enabled' => 1,
			'forum_ids' => array(self::FORUM_ID),
		));
		self::submit($form);

		$this->get_db();
		$result = $this->db->sql_query('SELECT prefix_tag, prefix_color FROM phpbb_topic_prefixes WHERE prefix_id = ' . $tag_id);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		self::assertSame('Confirmed bug filter', $row['prefix_tag']);
		self::assertSame('AA00CC', $row['prefix_color']);

		return $fixture;
	}

	/**
	 * @depends test_acp_edit_tag
	 */
	public function test_posting_form_displays_tag_controls($fixture)
	{
		$this->login();
		$crawler = self::request('GET', 'posting.php?mode=post&f=' . self::FORUM_ID . "&sid={$this->sid}");
		self::assertGreaterThanOrEqual(2, $crawler->filter('input[name="topic_tags[]"]')->count());
		self::assertStringContainsString('Select or deselect', $crawler->filter('.topic-tag-choices label.topic-tag')->first()->attr('title'));

		return $fixture;
	}

	/**
	 * @depends test_posting_form_displays_tag_controls
	 */
	public function test_topic_title_is_not_modified($fixture)
	{
		$this->get_db();
		$result = $this->db->sql_query('SELECT topic_title FROM phpbb_topics WHERE topic_id = ' . $fixture['topic_id']);
		self::assertSame('Structured tag title', $this->db->sql_fetchfield('topic_title'));
		$this->db->sql_freeresult($result);

		return $fixture;
	}

	/**
	 * @depends test_topic_title_is_not_modified
	 */
	public function test_first_post_subject_is_not_modified($fixture)
	{
		$this->get_db();
		$result = $this->db->sql_query('SELECT post_subject FROM phpbb_posts WHERE post_id = ' . $fixture['post_id']);
		self::assertSame('Structured tag title', $this->db->sql_fetchfield('post_subject'));
		$this->db->sql_freeresult($result);

		return $fixture;
	}

	/**
	 * @depends test_first_post_subject_is_not_modified
	 */
	public function test_multiple_tag_assignments_are_stored($fixture)
	{
		$this->get_db();
		$result = $this->db->sql_query('SELECT prefix_id FROM phpbb_topic_prefixes_topics WHERE topic_id = ' . $fixture['topic_id'] . ' ORDER BY prefix_id');
		self::assertSame(array($fixture['bug_id'], $fixture['php_id']), array_map('intval', array_column($this->db->sql_fetchrowset($result), 'prefix_id')));
		$this->db->sql_freeresult($result);

		return $fixture;
	}

	/**
	 * @depends test_multiple_tag_assignments_are_stored
	 */
	public function test_first_post_edit_updates_tag_assignments($fixture)
	{
		$this->login();
		$crawler = self::request('GET', 'posting.php?mode=edit&f=' . self::FORUM_ID . '&p=' . $fixture['post_id'] . "&sid={$this->sid}");
		$form = $crawler->selectButton($this->lang('SUBMIT'))->form();
		$values = $form->getPhpValues();
		$values['topic_tags'] = array((string) $fixture['php_id']);
		$values['topic_tags_present'] = '1';
		self::$client->request('POST', $form->getUri(), $values);
		self::assert_response_html();
		$this->get_db();
		$result = $this->db->sql_query('SELECT prefix_id FROM phpbb_topic_prefixes_topics WHERE topic_id = ' . $fixture['topic_id'] . ' ORDER BY prefix_id');
		self::assertSame(array($fixture['php_id']), array_map('intval', array_column($this->db->sql_fetchrowset($result), 'prefix_id')));
		$this->db->sql_freeresult($result);

		return $fixture;
	}

	/**
	 * @depends test_first_post_edit_updates_tag_assignments
	 */
	public function test_viewtopic_displays_tags($fixture)
	{
		$this->login();
		$crawler = self::request('GET', 'viewtopic.php?t=' . $fixture['topic_id'] . "&sid={$this->sid}");
		$tag = $crawler->filter('h2.topic-title .topic-tag');
		self::assertCount(1, $tag);
		self::assertStringContainsString('PHP 8.4 filter', $tag->text());
		self::assertSame('Filter topics by “PHP 8.4 filter”', $tag->attr('title'));
		self::assertSame('Filter topics by “PHP 8.4 filter”', $tag->attr('aria-label'));
		self::assertStringContainsString('topic-tags', $crawler->filter('h2.topic-title')->children()->eq(0)->attr('class'));
	}

	/**
	 * @depends test_first_post_edit_updates_tag_assignments
	 */
	public function test_viewforum_filters_by_tag($fixture)
	{
		$this->login();
		$crawler = self::request('GET', 'viewforum.php?f=' . self::FORUM_ID . '&tags=' . $fixture['php_id'] . "&sid={$this->sid}");
		self::assertStringContainsString('Structured tag title', $crawler->filter('.topiclist.topics')->text());
		$selected = $crawler->filter('.topic-tag-filter-panel .topic-tag-selected');
		self::assertCount(1, $selected);
		self::assertSame('Remove “PHP 8.4 filter” from topic filters', $selected->attr('title'));
		self::assertSame('Remove “PHP 8.4 filter” from topic filters', $selected->attr('aria-label'));
	}

	/**
	 * @depends test_first_post_edit_updates_tag_assignments
	 */
	public function test_search_topic_results_display_tags($fixture)
	{
		$this->login();
		$crawler = self::request('GET', 'search.php?author_id=2&sr=topics' . "&sid={$this->sid}");
		self::assertStringContainsString('PHP 8.4 filter', $crawler->filter('ul.topiclist .topic-tag')->text());
	}

	/**
	 * @depends test_first_post_edit_updates_tag_assignments
	 */
	public function test_search_post_results_display_tags($fixture)
	{
		$this->markTestSkipped('Requires search_results_topic_title_prepend to be added to phpBB core.');
		$this->login();
		$crawler = self::request('GET', 'search.php?author_id=2&sr=posts' . "&sid={$this->sid}");
		self::assertStringContainsString('PHP 8.4 filter', $crawler->filter('.postprofile .topic-tag')->text());
	}

	/**
	 * @depends test_first_post_edit_updates_tag_assignments
	 */
	public function test_mcp_forum_displays_tags($fixture)
	{
		$this->login();
		$crawler = self::request('GET', 'mcp.php?i=main&mode=forum_view&f=' . self::FORUM_ID . "&sid={$this->sid}");
		self::assertStringContainsString('PHP 8.4 filter', $crawler->filter('ul.topiclist .topic-tag')->text());
	}

	/**
	 * @depends test_first_post_edit_updates_tag_assignments
	 */
	public function test_ucp_subscribed_topics_display_tags($fixture)
	{
		$this->markTestSkipped('Requires topiclist_row_prepend to be added to phpBB core ucp_main_subscribed.html.');
		$this->login();
		$this->get_db();
		$this->db->sql_query('DELETE FROM phpbb_topics_watch
			WHERE topic_id = ' . $fixture['topic_id'] . '
				AND user_id = 2');
		$this->db->sql_query('INSERT INTO phpbb_topics_watch ' . $this->db->sql_build_array('INSERT', array(
			'topic_id' => $fixture['topic_id'],
			'user_id' => 2,
			'notify_status' => 0,
		)));
		$crawler = self::request('GET', 'ucp.php?i=ucp_main&mode=subscribed' . "&sid={$this->sid}");
		self::assertStringContainsString('PHP 8.4 filter', $crawler->filter('ul.topiclist .topic-tag')->text());
	}

	/**
	 * @depends test_first_post_edit_updates_tag_assignments
	 */
	public function test_ucp_bookmarks_display_tags($fixture)
	{
		$this->markTestSkipped('Requires topiclist_row_prepend to be added to phpBB core ucp_main_bookmarks.html.');
		$this->login();
		$this->get_db();
		$this->db->sql_query('DELETE FROM phpbb_bookmarks
			WHERE topic_id = ' . $fixture['topic_id'] . '
				AND user_id = 2');
		$this->db->sql_query('INSERT INTO phpbb_bookmarks ' . $this->db->sql_build_array('INSERT', array(
			'topic_id' => $fixture['topic_id'],
			'user_id' => 2,
		)));
		$crawler = self::request('GET', 'ucp.php?i=ucp_main&mode=bookmarks' . "&sid={$this->sid}");
		self::assertStringContainsString('PHP 8.4 filter', $crawler->filter('ul.topiclist .topic-tag')->text());
	}

	/**
	 * @depends test_first_post_edit_updates_tag_assignments
	 */
	public function test_ucp_front_displays_tags($fixture)
	{
		$this->markTestSkipped('Requires topiclist_row_prepend to be added to phpBB core ucp_main_front.html.');
		$this->login();
		$this->get_db();
		$this->db->sql_query('UPDATE phpbb_topics
			SET topic_type = ' . POST_GLOBAL . '
			WHERE topic_id = ' . $fixture['topic_id']);
		$crawler = self::request('GET', 'ucp.php?i=ucp_main&mode=front' . "&sid={$this->sid}");
		self::assertStringContainsString('PHP 8.4 filter', $crawler->filter('ul.topiclist .topic-tag')->text());
	}

	protected function create_tag($name, $color, array $forum_ids)
	{
		$crawler = $this->acp_page();
		$form = $crawler->selectButton($this->lang('SUBMIT'))->form(array(
			'tag_name' => $name,
			'tag_color' => $color,
			'tag_enabled' => 1,
			'forum_ids' => $forum_ids,
		));
		$crawler = self::submit($form);
		$this->assertContainsLang('TOPIC_TAG_SAVED', $crawler->text());

		$this->get_db();
		$sql = "SELECT prefix_id FROM phpbb_topic_prefixes WHERE prefix_tag = '" . $this->db->sql_escape($name) . "' ORDER BY prefix_id DESC";
		$result = $this->db->sql_query_limit($sql, 1);
		$tag_id = (int) $this->db->sql_fetchfield('prefix_id');
		$this->db->sql_freeresult($result);
		return $tag_id;
	}

	protected function acp_page($params = '')
	{
		$url = 'adm/index.php?i=\\phpbb\\topicprefixes\\acp\\topic_prefixes_module&mode=manage';
		if ($params !== '')
		{
			$url .= '&' . $params;
		}
		return self::request('GET', $url . "&sid={$this->sid}");
	}
}
