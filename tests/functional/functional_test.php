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

use Symfony\Component\DomCrawler\Crawler;

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

	public function test_acp_module_and_create_edit_tag()
	{
		$this->login();
		$this->admin_login();
		$crawler = $this->acp_page();
		$this->assertContainsLang('TOPIC_TAGS', $crawler->filter('#main')->text());
		self::assertCount(1, $crawler->filter('input[type="color"]'));
		self::assertCount(1, $crawler->filter('select[name="forum_ids[]"][multiple]'));

		$tag_id = $this->create_tag('Bug', '#d4351c', array(self::FORUM_ID));
		$crawler = $this->acp_page('action=edit&tag_id=' . $tag_id);
		$form = $crawler->selectButton($this->lang('SUBMIT'))->form(array(
			'tag_name' => 'Confirmed bug',
			'tag_color' => '#aa00cc',
			'tag_enabled' => 1,
			'forum_ids' => array(self::FORUM_ID),
		));
		self::submit($form);

		$this->get_db();
		$result = $this->db->sql_query('SELECT prefix_tag, prefix_color FROM phpbb_topic_prefixes WHERE prefix_id = ' . $tag_id);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		self::assertSame('Confirmed bug', $row['prefix_tag']);
		self::assertSame('AA00CC', $row['prefix_color']);
	}

	public function test_multiple_tags_posting_display_and_filtering()
	{
		$this->login();
		$this->admin_login();
		$bug_id = $this->create_tag('Bug filter', '#d4351c', array(self::FORUM_ID));
		$php_id = $this->create_tag('PHP 8.4 filter', '#1d70b8', array(self::FORUM_ID));

		$crawler = self::request('GET', 'posting.php?mode=post&f=' . self::FORUM_ID . "&sid={$this->sid}");
		self::assertGreaterThanOrEqual(2, $crawler->filter('input[name="topic_tags[]"]')->count());

		$topic = $this->create_topic(self::FORUM_ID, 'Structured tag title', 'Tagged first post', array(
			'topic_tags' => array($bug_id, $php_id),
			'topic_tags_present' => 1,
		));
		$this->get_db();
		$result = $this->db->sql_query('SELECT topic_title, topic_first_post_id FROM phpbb_topics WHERE topic_id = ' . (int) $topic['topic_id']);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		self::assertSame('Structured tag title', $row['topic_title']);
		$result = $this->db->sql_query('SELECT post_subject FROM phpbb_posts WHERE post_id = ' . (int) $row['topic_first_post_id']);
		self::assertSame('Structured tag title', $this->db->sql_fetchfield('post_subject'));
		$this->db->sql_freeresult($result);
		$result = $this->db->sql_query('SELECT prefix_id FROM phpbb_topic_prefixes_topics WHERE topic_id = ' . (int) $topic['topic_id'] . ' ORDER BY prefix_id');
		self::assertSame(array($bug_id, $php_id), array_map('intval', array_column($this->db->sql_fetchrowset($result), 'prefix_id')));
		$this->db->sql_freeresult($result);

		$crawler = self::request('GET', 'posting.php?mode=edit&f=' . self::FORUM_ID . '&p=' . (int) $row['topic_first_post_id'] . "&sid={$this->sid}");
		$form = $crawler->selectButton($this->lang('SUBMIT'))->form();
		$values = $form->getPhpValues();
		$values['topic_tags'] = array((string) $php_id);
		$values['topic_tags_present'] = '1';
		self::$client->request('POST', $form->getUri(), $values);
		self::assert_response_html();
		$result = $this->db->sql_query('SELECT prefix_id FROM phpbb_topic_prefixes_topics WHERE topic_id = ' . (int) $topic['topic_id'] . ' ORDER BY prefix_id');
		self::assertSame(array($php_id), array_map('intval', array_column($this->db->sql_fetchrowset($result), 'prefix_id')));
		$this->db->sql_freeresult($result);

		$crawler = self::request('GET', 'viewtopic.php?t=' . (int) $topic['topic_id'] . "&sid={$this->sid}");
		self::assertCount(1, $crawler->filter('h2.topic-title .topic-tag'));
		self::assertStringContainsString('PHP 8.4 filter', $crawler->filter('h2.topic-title .topic-tag')->text());
		self::assertStringContainsString('topic-tags', $crawler->filter('h2.topic-title')->children()->eq(0)->attr('class'));

		$crawler = self::request('GET', 'viewforum.php?f=' . self::FORUM_ID . '&tags=' . $php_id . "&sid={$this->sid}");
		self::assertStringContainsString('Structured tag title', $crawler->filter('.topiclist.topics')->text());
		self::assertCount(1, $crawler->filter('.topic-tag-filter-panel .topic-tag-selected'));

		$crawler = self::request('GET', 'search.php?author_id=2&sr=posts' . "&sid={$this->sid}");
		self::assertStringContainsString('PHP 8.4 filter', $crawler->filter('.postprofile .topic-tag')->text());

		$crawler = self::request('GET', 'mcp.php?i=main&mode=forum_view&f=' . self::FORUM_ID . "&sid={$this->sid}");
		self::assertStringContainsString('PHP 8.4 filter', $crawler->filter('ul.topiclist .topic-tag')->text());

		$this->db->sql_query('DELETE FROM phpbb_topics_watch
			WHERE topic_id = ' . (int) $topic['topic_id'] . '
				AND user_id = 2');
		$this->db->sql_query('INSERT INTO phpbb_topics_watch ' . $this->db->sql_build_array('INSERT', array(
			'topic_id' => (int) $topic['topic_id'],
			'user_id' => 2,
			'notify_status' => 0,
		)));
		$this->db->sql_query('DELETE FROM phpbb_bookmarks
			WHERE topic_id = ' . (int) $topic['topic_id'] . '
				AND user_id = 2');
		$this->db->sql_query('INSERT INTO phpbb_bookmarks ' . $this->db->sql_build_array('INSERT', array(
			'topic_id' => (int) $topic['topic_id'],
			'user_id' => 2,
		)));

		$crawler = self::request('GET', 'ucp.php?i=ucp_main&mode=subscribed' . "&sid={$this->sid}");
		self::assertStringContainsString('PHP 8.4 filter', $crawler->filter('ul.topiclist .topic-tag')->text());
		$crawler = self::request('GET', 'ucp.php?i=ucp_main&mode=bookmarks' . "&sid={$this->sid}");
		self::assertStringContainsString('PHP 8.4 filter', $crawler->filter('ul.topiclist .topic-tag')->text());

		$this->db->sql_query('UPDATE phpbb_topics
			SET topic_type = 3
			WHERE topic_id = ' . (int) $topic['topic_id']);
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
		/** @var Crawler $crawler */
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
