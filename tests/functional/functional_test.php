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

use \Symfony\Component\DomCrawler\Crawler;

/**
 * @group functional
 */
class functional_test extends \phpbb_functional_test_case
{
	const FORUM_ID = 2;

	protected static function setup_extensions()
	{
		return array('phpbb/topicprefixes');
	}

	public function setUp()
	{
		parent::setUp();

		$this->add_lang_ext('phpbb/topicprefixes', [
			'acp_topic_prefixes',
			'info_acp_topic_prefixes',
			'topic_prefixes'
		]);
	}

	public function test_acp_module_installation()
	{
		$this->login();
		$this->admin_login();

		$crawler = self::request('GET', "adm/index.php?i=\\phpbb\\topicprefixes\\acp\\topic_prefixes_module&mode=manage&sid={$this->sid}");

		// Assert module appears in sidebar
		$this->assertContainsLang('ACP_TOPIC_PREFIXES', $crawler->filter('.menu-block')->text());
		$this->assertContainsLang('ACP_MANAGE_PREFIXES', $crawler->filter('#activemenu')->text());

		// Assert module content appears
		$this->assertContainsLang('TOPIC_PREFIXES', $crawler->filter('#main h1')->text());
		$this->assertContainsLang('TOPIC_PREFIXES_EXPLAIN', $crawler->filter('#main')->text());

		// Jump to the create page
		$form = $crawler->selectButton($this->lang('GO'))->form(['forum_id' => self::FORUM_ID]);
		$crawler = self::submit($form);

		// Assert we're on create page and prefixes are currently empty
		$this->assertContainsLang('TOPIC_PREFIXES_EMPTY', $crawler->text());
	}

	public function test_acp_create_prefix()
	{
		$this->login();
		$this->admin_login();

		$this->assertEquals(1, $this->create_prefix('[foo1]', self::FORUM_ID));
	}

	public function test_acp_disable_prefix()
	{
		$this->login();
		$this->admin_login();

		$prefix = $this->create_prefix('[foo2]', self::FORUM_ID);
		$hash = $this->mock_link_hash('edit' . $prefix);

		// Disable the prefix
		$crawler = self::request('GET', 'adm/index.php?i=\phpbb\topicprefixes\acp\topic_prefixes_module&mode=manage&action=edit&forum_id=' . self::FORUM_ID . "&prefix_id={$prefix}&hash={$hash}&sid={$this->sid}");
		$this->assertCount(1, $crawler->filter('.never'));

		// Enable the prefix
		$crawler = self::request('GET', 'adm/index.php?i=\phpbb\topicprefixes\acp\topic_prefixes_module&mode=manage&action=edit&forum_id=' . self::FORUM_ID . "&prefix_id={$prefix}&hash={$hash}&sid={$this->sid}");
		$this->assertCount(0, $crawler->filter('.never'));
	}

	public function test_acp_delete_prefix()
	{
		$this->login();
		$this->admin_login();

		$prefix = $this->create_prefix('[foo3]', self::FORUM_ID);

		// Delete the prefix
		$crawler = self::request('GET', 'adm/index.php?i=\phpbb\topicprefixes\acp\topic_prefixes_module&mode=manage&action=delete&forum_id=' . self::FORUM_ID . "&prefix_id={$prefix}&sid={$this->sid}");

		// Confirm delete
		$form = $crawler->selectButton('confirm')->form();
		$crawler = self::submit($form);

		// Assert deletion was success
		$this->assertGreaterThan(0, $crawler->filter('.successbox')->count());
		$this->assertContainsLang('TOPIC_PREFIX_DELETED', $crawler->text());
	}

	/**
	 * Create a new topic prefix
	 *
	 * @param string $prefix_tag The name of the tag
	 * @param int    $forum_id   The forum identifier
	 * @return int The new topic prefix identifier
	 */
	protected function create_prefix($prefix_tag, $forum_id)
	{
		$crawler = self::request('GET', 'adm/index.php?i=\phpbb\topicprefixes\acp\topic_prefixes_module&mode=manage&forum_id=' . self::FORUM_ID . "&sid={$this->sid}");

		$form = $crawler->selectButton($this->lang('SUBMIT'))->form(array(
			'prefix_tag'	=> $prefix_tag,
			'forum_id'		=> $forum_id,
		));

		/** @var Crawler $crawler */
		$crawler = self::submit($form);

		// Assert new tag appears
		$this->assertContains($prefix_tag, $crawler->filter('table')->text());

		// Get and return the new tag's id
		$crawler = $crawler
			->filter('table > tbody > tr')
			->reduce(function (Crawler $node) use ($prefix_tag) {
				return $node->filter('strong')->text() === $prefix_tag;
			});
		$url = $crawler->selectLink($this->lang('ENABLED'))->link()->getUri();

		return (int) $this->get_parameter_from_link($url, 'prefix_id');
	}

	/**
	 * Create a link hash for the user 'admin'
	 *
	 * @param string  $link_name The name of the link
	 * @return string the hash
	 */
	protected function mock_link_hash($link_name)
	{
		$this->get_db();

		$sql = "SELECT user_form_salt
			FROM phpbb_users
			WHERE username = 'admin'";
		$result = $this->db->sql_query($sql);
		$user_form_salt = $this->db->sql_fetchfield('user_form_salt');
		$this->db->sql_freeresult($result);

		return substr(sha1($user_form_salt . $link_name), 0, 8);
	}

	public function test_posting()
	{
		$this->login();

		$prefix = [
			'id'  => 1,
			'tag' => '[foo1]',
		];

		// Check the new posting page
		$crawler = self::request('GET', 'posting.php?mode=post&f=' . self::FORUM_ID . "&sid={$this->sid}");
		$this->assertContainsLang('TOPIC_PREFIX', $crawler->filter('#postingbox')->text());

		// Check posting a new topic
		$topic = $this->create_topic(
			self::FORUM_ID,
			"{$prefix['tag']} topic prefix test",
			'This is a test topic',
			['topic_prefix' => $prefix['id']]
		);

		// Check editing the topic page
		$crawler = self::request('GET', 'posting.php?mode=edit&f=' . self::FORUM_ID . "&p={$topic['topic_id']}&sid={$this->sid}");
		$form = $crawler->selectButton($this->lang('SUBMIT'))->form();
		$values = $form->getValues();
		$this->assertEquals($prefix['id'], $values['topic_prefix']);
	}
}
