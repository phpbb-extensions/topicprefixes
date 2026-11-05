<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\tests\dbal;

require_once __DIR__ . '/tags_base.php';

class search_listener_dbal_test extends tags_base
{
	protected function setUp(): void
	{
		parent::setUp();
		$this->db->sql_query('DELETE FROM phpbb_posts WHERE post_id IN (8100, 8101, 8102, 8103)');
		$this->insert_explicit_rows('phpbb_posts', [
			['post_id' => 8100, 'topic_id' => 10, 'forum_id' => 2, 'post_subject' => 'Both tags', 'post_text' => ''],
			['post_id' => 8101, 'topic_id' => 11, 'forum_id' => 2, 'post_subject' => 'Bug only', 'post_text' => ''],
			['post_id' => 8102, 'topic_id' => 12, 'forum_id' => 2, 'post_subject' => 'PHP only', 'post_text' => ''],
			['post_id' => 8103, 'topic_id' => 13, 'forum_id' => 2, 'post_subject' => 'No tags', 'post_text' => ''],
		]);
	}

	/**
	 * Generated predefined-search SQL must execute and return tagged topics.
	 *
	 * @dataProvider predefined_query_data
	 */
	public function test_predefined_search_filters_executed_query(string $search_id, string $show_results, string $sql): void
	{
		$listener = $this->listener('1', 'all');
		$event = new \phpbb\event\data([
			'search_id' => $search_id,
			'show_results' => $show_results,
			'sql' => $sql,
		]);

		$listener->filter_predefined_search($event);

		self::assertSame([10, 11], $this->execute_ids($event['sql']));
	}

	public function predefined_query_data(): array
	{
		return [
			'active topics' => ['active_topics', 'topics', 'SELECT t.topic_id FROM phpbb_topics t WHERE t.forum_id = 2 ORDER BY t.topic_id'],
			'active topics without ordering' => ['active_topics', 'topics', 'SELECT t.topic_id FROM phpbb_topics t WHERE t.forum_id = 2'],
			'new topics' => ['newposts', 'topics', 'SELECT t.topic_id FROM phpbb_topics t WHERE t.forum_id = 2 ORDER BY t.topic_id'],
			'new posts' => ['newposts', 'posts', 'SELECT p.topic_id FROM phpbb_posts p WHERE p.forum_id = 2 AND p.post_id BETWEEN 8100 AND 8103 ORDER BY p.topic_id'],
			'unanswered topics' => ['unanswered', 'topics', 'SELECT t.topic_id FROM phpbb_topics t WHERE t.forum_id = 2 ORDER BY t.topic_id'],
			'unanswered posts' => ['unanswered', 'posts', 'SELECT p.topic_id FROM phpbb_posts p WHERE p.forum_id = 2 AND p.post_id BETWEEN 8100 AND 8103 ORDER BY p.topic_id'],
		];
	}

	public function test_unread_search_filters_executed_query(): void
	{
		$listener = $this->listener('1,2', 'all');
		$listener->filter_predefined_search(new \phpbb\event\data([
			'search_id' => 'unreadposts',
			'show_results' => 'topics',
			'sql' => '',
		]));
		$event = new \phpbb\event\data([
			'sql_array' => ['WHERE' => 't.forum_id = 2 ORDER BY t.topic_id'],
		]);

		$listener->filter_unread_topics($event);

		self::assertSame(
			[10],
			$this->execute_ids('SELECT t.topic_id FROM phpbb_topics t WHERE ' . $event['sql_array']['WHERE'])
		);
	}

	/**
	 * @dataProvider backend_result_type_data
	 */
	public function test_backend_condition_executes_with_any_semantics(string $type): void
	{
		$listener = $this->listener('1,2', 'any');
		$event = new \phpbb\event\data([
			'type' => $type,
			'post_visibility' => 'p.forum_id = 2 AND p.post_id BETWEEN 8100 AND 8103',
			'search_key_array' => [],
		]);

		$listener->filter_backend($event);

		self::assertSame(
			[10, 11, 12],
			$this->execute_ids('SELECT p.topic_id FROM phpbb_posts p WHERE ' . $event['post_visibility'])
		);
		self::assertSame(['topicprefixes:any:1,2'], $event['search_key_array']);
	}

	public function backend_result_type_data(): array
	{
		return [
			'topic results' => ['topics'],
			'post results' => ['posts'],
		];
	}

	protected function listener(string $requested_tags, string $match): \phpbb\topicprefixes\event\search_listener
	{
		$manager = $this->getMockBuilder('\phpbb\topicprefixes\tags\manager')->disableOriginalConstructor()->getMock();
		$manager->method('resolve_search_tags_for_forums')->willReturnCallback(static function (array $ids) {
			$tags = [];
			foreach ($ids as $id)
			{
				$tags[$id] = ['prefix_id' => $id, 'prefix_tag' => 'Tag ' . $id];
			}
			return $tags;
		});
		$request = $this->getMockBuilder('\phpbb\request\request')->disableOriginalConstructor()->getMock();
		$request->method('variable')->willReturnCallback(static function ($name, $default) use ($requested_tags, $match) {
			return $name === 'tags' ? $requested_tags : ($name === 'tag_match' ? $match : $default);
		});
		$visibility = $this->getMockBuilder('\phpbb\content_visibility')->disableOriginalConstructor()->getMock();
		$visibility->method('get_global_visibility_sql')->willReturn('1=1');
		$auth = $this->getMockBuilder('\phpbb\auth\auth')->disableOriginalConstructor()->getMock();
		$auth->method('acl_getf')->willReturn([2 => ['allowed' => true]]);
		$user = $this->getMockBuilder('\phpbb\user')->disableOriginalConstructor()->getMock();
		$user->method('get_passworded_forums')->willReturn([]);
		$filter = new \phpbb\topicprefixes\tags\filter(
			$this->db,
			$visibility,
			$auth,
			'phpbb_topic_prefixes_topics',
			'phpbb_topics',
			'phpbb_users'
		);

		return new \phpbb\topicprefixes\event\search_listener(
			$manager,
			$this->getMockBuilder('\phpbb\topicprefixes\tags\assignment_manager')->disableOriginalConstructor()->getMock(),
			$filter,
			$this->getMockBuilder('\phpbb\topicprefixes\tags\renderer')->disableOriginalConstructor()->getMock(),
			$request,
			$this->getMockBuilder('\phpbb\template\template')->disableOriginalConstructor()->getMock(),
			$this->getMockBuilder('\phpbb\language\language')->disableOriginalConstructor()->getMock(),
			$auth,
			$user,
			new \phpbb\config\config(['topicprefixes_search_enabled' => 1])
		);
	}

	protected function execute_ids(string $sql): array
	{
		$result = $this->db->sql_query($sql);
		$ids = array_map('intval', array_column($this->db->sql_fetchrowset($result), 'topic_id'));
		$this->db->sql_freeresult($result);
		sort($ids, SORT_NUMERIC);
		return $ids;
	}
}
