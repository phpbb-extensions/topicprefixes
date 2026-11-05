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
		self::assertGreaterThanOrEqual(1, $crawler->filter('input[type="checkbox"][name="forum_ids[]"]')->count());
		self::assertCount(1, $crawler->filter('.topic-tag-workspace .topic-tag-inspector'));
		self::assertCount(0, $crawler->filter('details.topic-tag-maintenance'));
		self::assertCount(1, $crawler->filter('#topic-tag-notices'));
		self::assertCount(1, $crawler->filter('#topic-tag-notice-template .topic-tag-notice-close'));
		self::assertCount(1, $crawler->filter('input[name="tag_color"] + input#tag_color_text:not([pattern])'));
		self::assertGreaterThanOrEqual(3, $crawler->filter('.tp-button')->count());
		self::assertCount(0, $crawler->filter('.topic-tag-actions .tp-button'));
		self::assertCount(4, $crawler->filter('#topic-tag-row-template .topic-tag-actions a'));
		self::assertSame('50', $crawler->filter('input[name="tag_name"]')->attr('maxlength'));

		return true;
	}

	/**
	 * @depends test_acp_module
	 */
	public function test_board_feature_search_setting_is_opt_in_and_can_be_enabled($module_ready)
	{
		self::assertTrue($module_ready);
		$this->login();
		$this->admin_login();
		$this->get_db();
		$result = $this->db->sql_query("SELECT config_value FROM phpbb_config WHERE config_name = 'topicprefixes_search_enabled'");
		self::assertSame('0', (string) $this->db->sql_fetchfield('config_value'));
		$this->db->sql_freeresult($result);

		$crawler = self::request('GET', 'adm/index.php?i=acp_board&mode=features&sid=' . $this->sid);
		$this->assertContainsLang('ALLOW_TOPIC_TAG_SEARCH', $crawler->filter('form#acp_board')->text());
		self::assertSame('checked', $crawler->filter('input[name="config[topicprefixes_search_enabled]"][value="0"]')->attr('checked'));

		$form = $crawler->selectButton($this->lang('SUBMIT'))->form();
		$values = $form->getPhpValues();
		$values['config']['topicprefixes_search_enabled'] = '1';
		$crawler = self::$client->request($form->getMethod(), $form->getUri(), $values);
		self::assert_response_html();
		$this->assertContainsLang('CONFIG_UPDATED', $crawler->text());
		$this->purge_cache();

		return true;
	}

	/**
	 * @depends test_acp_module
	 */
	public function test_acp_decodes_forum_name_entities($module_ready)
	{
		self::assertTrue($module_ready);
		$this->login();
		$this->admin_login();
		$tag_name = 'Forum emoji display';
		$this->create_tag($tag_name, '#4a76a8', array(self::FORUM_ID));

		$this->get_db();
		$result = $this->db->sql_query('SELECT forum_name FROM phpbb_forums WHERE forum_id = ' . self::FORUM_ID);
		$original_name = $this->db->sql_fetchfield('forum_name');
		$this->db->sql_freeresult($result);
		$stored_name = 'YOUR FIRST CATEGORY EMOJI &#128512; TITLE &amp; MORE';
		$this->db->sql_query("UPDATE phpbb_forums
			SET forum_name = '" . $this->db->sql_escape($stored_name) . "'
			WHERE forum_id = " . self::FORUM_ID);
		$this->purge_cache();

		try
		{
			$crawler = $this->acp_page();
		}
		finally
		{
			$this->db->sql_query("UPDATE phpbb_forums
				SET forum_name = '" . $this->db->sql_escape($original_name) . "'
				WHERE forum_id = " . self::FORUM_ID);
			$this->purge_cache();
		}

		$expected_name = 'YOUR FIRST CATEGORY EMOJI 😀 TITLE & MORE';
		$forum_option = $crawler->filter('#topic-tag-forums .topic-tag-forum-option')->reduce(function ($node) {
			return $node->filter('input[value="' . self::FORUM_ID . '"]')->count() > 0;
		});
		self::assertCount(1, $forum_option);
		self::assertStringContainsString($expected_name, $forum_option->text());
		self::assertStringNotContainsString('&#128512;', $forum_option->text());
		$summary = $crawler->filter('.topic-tag-item[data-tag-name="' . $tag_name . '"] .topic-tag-forum-preview');
		self::assertCount(1, $summary);
		self::assertStringContainsString($expected_name, $summary->text());
		self::assertSame($expected_name, $summary->attr('title'));
		$row = $crawler->filter('.topic-tag-item[data-tag-name="' . $tag_name . '"]');
		self::assertSame('true', $row->filter('.topic-tag-move-up')->attr('aria-disabled'));
		self::assertNull($row->filter('.topic-tag-move-up')->attr('href'));
		self::assertSame('true', $row->filter('.topic-tag-move-down')->attr('aria-disabled'));
		self::assertNull($row->filter('.topic-tag-move-down')->attr('href'));
	}

	/**
	 * @depends test_acp_module
	 */
	public function test_acp_accepts_emoji_tag($module_ready)
	{
		self::assertTrue($module_ready);
		$emoji_name = str_repeat('😇', 6);
		$this->login();
		$this->admin_login();
		$crawler = $this->acp_page();
		$form = $crawler->selectButton($this->lang('TOPIC_TAG_SAVE'))->form(array(
			'tag_name' => $emoji_name,
			'tag_color' => '#4a76a8',
			'tag_enabled' => 1,
		));
		$crawler = $this->submit_tag_form($form, array(self::FORUM_ID));
		$this->assertContainsLang('TOPIC_TAG_SAVED', $crawler->text());

		$this->get_db();
		$result = $this->db->sql_query("SELECT prefix_id
			FROM phpbb_topic_prefixes
			WHERE prefix_tag = '" . str_repeat('&#128519;', 6) . "'");
		$tag_id = (int) $this->db->sql_fetchfield('prefix_id');
		$this->db->sql_freeresult($result);
		self::assertGreaterThan(0, $tag_id);

		$crawler = $this->acp_page();
		$badge_names = $crawler->filter('.topic-tag')->each(function ($badge) {
			return $badge->text();
		});
		self::assertContains($emoji_name, $badge_names);
		self::assertGreaterThanOrEqual(1, $crawler->filter('.topic-tag-item .topic-tag-forum-summary')->count());
		$rows = $crawler->filter('.topic-tag-item:not(.topic-tag-row-template)');
		$first = $rows->eq(0);
		$last = $rows->eq($rows->count() - 1);
		self::assertSame('true', $first->filter('.topic-tag-move-up')->attr('aria-disabled'));
		self::assertNull($first->filter('.topic-tag-move-up')->attr('href'));
		self::assertSame('false', $first->filter('.topic-tag-move-down')->attr('aria-disabled'));
		self::assertNotNull($first->filter('.topic-tag-move-down')->attr('href'));
		self::assertSame('false', $last->filter('.topic-tag-move-up')->attr('aria-disabled'));
		self::assertNotNull($last->filter('.topic-tag-move-up')->attr('href'));
		self::assertSame('true', $last->filter('.topic-tag-move-down')->attr('aria-disabled'));
		self::assertNull($last->filter('.topic-tag-move-down')->attr('href'));

		$topic = $this->create_topic(self::FORUM_ID, 'Emoji tag topic', 'Emoji tag post', array(
			'topic_tags' => array($tag_id),
			'topic_tags_present' => 1,
		));
		$crawler = self::request('GET', 'viewtopic.php?t=' . $topic['topic_id'] . "&sid={$this->sid}");
		self::assertStringContainsString($emoji_name, $crawler->filter('h2.topic-title .topic-tag')->text());
		$crawler = self::request('GET', 'viewforum.php?f=' . self::FORUM_ID . "&sid={$this->sid}");
		self::assertStringContainsString($emoji_name, $crawler->filter('ul.topiclist .topic-tag')->text());
	}

	/**
	 * @depends test_acp_module
	 */
	public function test_acp_tag_name_round_trip_preserves_plain_and_literal_entities($module_ready)
	{
		self::assertTrue($module_ready);
		$name = 'R&D <Tag> &amp;';
		$this->login();
		$this->admin_login();
		$tag_id = $this->create_tag($name, '#4a76a8', array(self::FORUM_ID));

		$this->get_db();
		$result = $this->db->sql_query('SELECT prefix_tag FROM phpbb_topic_prefixes WHERE prefix_id = ' . $tag_id);
		self::assertSame('R&amp;D &lt;Tag&gt; &amp;amp;', $this->db->sql_fetchfield('prefix_tag'));
		$this->db->sql_freeresult($result);

		$crawler = $this->acp_page();
		$badge_names = $crawler->filter('.topic-tag')->each(function ($badge) {
			return $badge->text();
		});
		self::assertContains($name, $badge_names);
		$crawler = $this->acp_page('action=edit&tag_id=' . $tag_id);
		self::assertSame($name, $crawler->filter('input[name="tag_name"]')->attr('value'));
		$form = $crawler->selectButton($this->lang('TOPIC_TAG_SAVE'))->form();
		$crawler = self::submit($form);
		$this->assertContainsLang('TOPIC_TAG_SAVED', $crawler->text());

		$result = $this->db->sql_query('SELECT prefix_tag FROM phpbb_topic_prefixes WHERE prefix_id = ' . $tag_id);
		self::assertSame('R&amp;D &lt;Tag&gt; &amp;amp;', $this->db->sql_fetchfield('prefix_tag'));
		$this->db->sql_freeresult($result);
	}

	/**
	 * @depends test_board_feature_search_setting_is_opt_in_and_can_be_enabled
	 */
	public function test_create_shared_tagged_topic($search_enabled)
	{
		self::assertTrue($search_enabled);
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
		$form = $crawler->selectButton($this->lang('TOPIC_TAG_SAVE'))->form(array(
			'tag_name' => 'Confirmed bug filter',
			'tag_color' => '#aa00cc',
			'tag_enabled' => 1,
		));
		$this->submit_tag_form($form, array(self::FORUM_ID));

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
		self::assertNotSame('', $crawler->filter('.topic-tag-choices label.topic-tag')->first()->attr('title'));

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
		self::assertNotSame('', $tag->attr('title'));
		self::assertSame($tag->attr('title'), $tag->attr('aria-label'));
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
		self::assertNotSame('', $selected->attr('title'));
		self::assertSame($selected->attr('title'), $selected->attr('aria-label'));
		$row_tag = $crawler->filter('ul.topiclist.topics .topic-row-tags .topic-tag-selected')->first();
		self::assertCount(1, $row_tag);
		self::assertNotSame('', $row_tag->attr('title'));
		self::assertSame($row_tag->attr('title'), $row_tag->attr('aria-label'));
		self::assertSame('true', $row_tag->attr('aria-current'));
		self::assertStringNotContainsString('tags=', $row_tag->attr('href'));
	}

	/**
	 * @depends test_first_post_edit_updates_tag_assignments
	 */
	public function test_active_topics_filters_with_visible_tag_links($fixture)
	{
		$this->login();
		$this->get_db();
		$result = $this->db->sql_query('SELECT forum_id, forum_flags
			FROM phpbb_forums
			WHERE ' . $this->db->sql_in_set('forum_id', array(1, self::FORUM_ID)));
		$original_flags = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$original_flags[(int) $row['forum_id']] = (int) $row['forum_flags'];
		}
		$this->db->sql_freeresult($result);

		try
		{
			foreach ($original_flags as $forum_id => $flags)
			{
				$this->db->sql_query('UPDATE phpbb_forums
					SET forum_flags = ' . ($flags | FORUM_FLAG_ACTIVE_TOPICS) . '
					WHERE forum_id = ' . $forum_id);
			}

			$crawler = self::request('GET', 'viewforum.php?f=1&tags=' . $fixture['php_id'] . "&sid={$this->sid}");
			$topic_list = $crawler->filter('ul.topiclist.topics');
			self::assertStringContainsString('Structured tag title', $topic_list->text());
			self::assertStringContainsString('PHP 8.4 filter', $topic_list->text());
			self::assertGreaterThanOrEqual(1, $topic_list->filter('a.topic-tag')->count());
			self::assertCount(1, $crawler->filter('.topic-tag-filter-panel .topic-tag-selected'));
		}
		finally
		{
			foreach ($original_flags as $forum_id => $flags)
			{
				$this->db->sql_query('UPDATE phpbb_forums
					SET forum_flags = ' . $flags . '
					WHERE forum_id = ' . $forum_id);
			}
		}
	}

	/**
	 * @depends test_first_post_edit_updates_tag_assignments
	 */
	public function test_search_topic_results_filter_with_visible_tag_links($fixture)
	{
		$this->login();
		$crawler = self::request('GET', "search.php?sid={$this->sid}");
		self::assertCount(1, $crawler->filter('#topic-tag-search-input'));
		$this->assertContainsLang('TOPIC_TAG_SEARCH', $crawler->filter('dl.topic-tag-search')->text());
		self::assertStringContainsString('PHP 8.4 filter', $crawler->filter('#topic-tag-search-catalog')->text());

		$crawler = self::request('GET', 'search.php?submit=1&tags=' . $fixture['php_id'] . "&sid={$this->sid}");
		self::assertStringContainsString('Structured tag title', $crawler->filter('ul.topiclist.topics')->text());
		self::assertCount(1, $crawler->filter('.topic-tag-filter-panel .topic-tag-selected'));

		$crawler = self::request('GET', 'search.php?author_id=2&sr=topics&tags=' . $fixture['php_id'] . "&sid={$this->sid}");
		self::assertStringContainsString('PHP 8.4 filter', $crawler->filter('ul.topiclist .topic-tag')->text());
		self::assertGreaterThanOrEqual(1, $crawler->filter('ul.topiclist a.topic-tag')->count());
		self::assertCount(1, $crawler->filter('.topic-tag-filter-panel .topic-tag-selected'));
		$row_tag = $crawler->filter('ul.topiclist.topics .topic-row-tags .topic-tag-selected')->first();
		self::assertCount(1, $row_tag);
		self::assertNotSame('', $row_tag->attr('title'));
		self::assertSame('true', $row_tag->attr('aria-current'));
		self::assertStringNotContainsString('tags=', $row_tag->attr('href'));

		$crawler = self::request('GET', 'search.php?keywords=Structured&sr=topics&tags=' . $fixture['php_id'] . "&sid={$this->sid}");
		self::assertStringContainsString('Structured tag title', $crawler->filter('ul.topiclist.topics')->text());
		self::assertCount(1, $crawler->filter('.topic-tag-filter-panel .topic-tag-selected'));
	}

	/**
	 * @depends test_first_post_edit_updates_tag_assignments
	 */
	public function test_search_post_results_display_tags($fixture)
	{
		$this->login();
		$crawler = self::request('GET', 'search.php?author_id=2&sr=posts&tags=' . $fixture['php_id'] . "&sid={$this->sid}");
		self::assertStringContainsString('PHP 8.4 filter', $crawler->filter('.postprofile .topic-tag')->text());
		self::assertGreaterThanOrEqual(1, $crawler->filter('.postprofile a.topic-tag')->count());
		self::assertCount(1, $crawler->filter('.topic-tag-filter-panel .topic-tag-selected'));
	}

	/**
	 * @depends test_first_post_edit_updates_tag_assignments
	 */
	public function test_predefined_searches_filter_in_place($fixture)
	{
		$this->login();
		$control = $this->create_topic(self::FORUM_ID, 'Untagged predefined search control', 'Untagged control post');
		$this->get_db();

		$result = $this->db->sql_query('SELECT forum_flags
			FROM phpbb_forums
			WHERE forum_id = ' . self::FORUM_ID);
		$original_flags = (int) $this->db->sql_fetchfield('forum_flags');
		$this->db->sql_freeresult($result);
		$result = $this->db->sql_query('SELECT user_lastvisit, user_lastmark
			FROM phpbb_users
			WHERE user_id = 2');
		$original_user_times = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		try
		{
			$this->db->sql_query('UPDATE phpbb_forums
				SET forum_flags = ' . ($original_flags | FORUM_FLAG_ACTIVE_TOPICS) . '
				WHERE forum_id = ' . self::FORUM_ID);
			$this->db->sql_query('UPDATE phpbb_users
				SET user_lastvisit = 0, user_lastmark = 0
				WHERE user_id = 2');
			$this->db->sql_query('DELETE FROM phpbb_topics_track
				WHERE user_id = 2
					AND ' . $this->db->sql_in_set('topic_id', [$fixture['topic_id'], (int) $control['topic_id']]));
			$this->db->sql_query('DELETE FROM phpbb_forums_track
				WHERE user_id = 2
					AND forum_id = ' . self::FORUM_ID);

			$searches = [
				'active topics' => 'search.php?search_id=active_topics',
				'new topics' => 'search.php?search_id=newposts&sr=topics',
				'new posts' => 'search.php?search_id=newposts&sr=posts',
				'unanswered topics' => 'search.php?search_id=unanswered&sr=topics',
				'unanswered posts' => 'search.php?search_id=unanswered&sr=posts',
				'unread topics' => 'search.php?search_id=unreadposts',
			];

			foreach ($searches as $name => $url)
			{
				$crawler = self::request('GET', $url . '&tags=' . $fixture['php_id'] . "&sid={$this->sid}");
				$page = $crawler->filter('#page-body')->text();
				self::assertStringContainsString('Structured tag title', $page, $name);
				self::assertStringNotContainsString('Untagged predefined search control', $page, $name);
				self::assertCount(1, $crawler->filter('.topic-tag-filter-panel .topic-tag-selected'), $name);
			}
		}
		finally
		{
			$this->db->sql_query('UPDATE phpbb_forums
				SET forum_flags = ' . $original_flags . '
				WHERE forum_id = ' . self::FORUM_ID);
			$this->db->sql_query('UPDATE phpbb_users
				SET user_lastvisit = ' . (int) $original_user_times['user_lastvisit'] . ',
					user_lastmark = ' . (int) $original_user_times['user_lastmark'] . '
				WHERE user_id = 2');
		}
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
		$form = $crawler->selectButton($this->lang('TOPIC_TAG_SAVE'))->form(array(
			'tag_name' => $name,
			'tag_color' => $color,
			'tag_enabled' => 1,
		));
		$crawler = $this->submit_tag_form($form, $forum_ids);
		$this->assertContainsLang('TOPIC_TAG_SAVED', $crawler->text());

		$this->get_db();
		$stored_name = \phpbb\topicprefixes\tags\manager::normalize_name($name);
		$sql = "SELECT prefix_id FROM phpbb_topic_prefixes WHERE prefix_tag = '" . $this->db->sql_escape($stored_name) . "' ORDER BY prefix_id DESC";
		$result = $this->db->sql_query_limit($sql, 1);
		$tag_id = (int) $this->db->sql_fetchfield('prefix_id');
		$this->db->sql_freeresult($result);
		return $tag_id;
	}

	/**
	 * Submit the ACP checkbox form with selected forum identifiers.
	 */
	protected function submit_tag_form(\Symfony\Component\DomCrawler\Form $form, array $forum_ids)
	{
		$values = $form->getPhpValues();
		$values['forum_ids'] = $forum_ids;
		$crawler = self::$client->request($form->getMethod(), $form->getUri(), $values);
		self::assert_response_html();

		return $crawler;
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
