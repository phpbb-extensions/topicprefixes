<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\tests\tags;

class renderer_test extends \phpbb_test_case
{
	/**
	 * Set up URL generation event dispatcher.
	 */
	protected function setUp(): void
	{
		parent::setUp();

		global $phpbb_dispatcher;
		$phpbb_dispatcher = new \phpbb_mock_event_dispatcher();
	}

	/**
	 * Test automatic foreground contrast.
	 */
	public function test_automatic_contrast()
	{
		$renderer = $this->renderer();
		self::assertSame('#FFFFFF', $renderer->contrast_color('000000'));
		self::assertSame('#000000', $renderer->contrast_color('FFFFFF'));
		self::assertSame('#FFFFFF', $renderer->contrast_color('1D70B8'));
		self::assertSame('#FFFFFF', $renderer->contrast_color('000000'));
	}

	/**
	 * Test filter URLs normalize IDs and preserve topic sorting.
	 */
	public function test_filter_url_preserves_sorting_and_normalizes_tags(): void
	{
		$renderer = $this->renderer();
		$url = $renderer->filter_url(2, [3, 1, 3, 0], [
			'st' => 7,
			'sk' => 't',
			'sd' => 'd',
		]);

		self::assertStringContainsString('f=2', $url);
		self::assertStringContainsString('st=7', $url);
		self::assertStringContainsString('sk=t', $url);
		self::assertStringContainsString('sd=d', $url);
		self::assertStringContainsString('tags=1%2C3', $url);
	}

	/**
	 * Test selected filter tag links remove that tag.
	 */
	public function test_rendered_filter_tags_toggle_selected_ids(): void
	{
		$tags = [[
			'prefix_id' => 1,
			'prefix_tag' => 'Bug',
			'prefix_color' => 'D4351C',
		]];
		$renderer = $this->renderer([1 => $tags[0]]);

		$rendered = $renderer->render($tags, 2, [1, 2], [], true);

		self::assertTrue($rendered[0]['S_SELECTED']);
		self::assertFalse($rendered[0]['S_RETAINED']);
		self::assertStringContainsString('tags=2', $rendered[0]['U_FILTER']);
		self::assertSame('#FFFFFF', $rendered[0]['TAG_TEXT_COLOR']);
	}

	/**
	 * Test tags without a forum context do not receive filter URLs.
	 */
	public function test_rendered_tags_without_forum_are_not_filterable(): void
	{
		$renderer = $this->renderer();
		$tags = [[
			'prefix_id' => 1,
			'prefix_tag' => 'Global',
			'prefix_color' => 'D4351C',
		]];

		$rendered = $renderer->render($tags);

		self::assertSame('', $rendered[0]['U_FILTER']);
	}

	public function test_rendered_tag_names_are_plain_text(): void
	{
		$renderer = $this->renderer();
		$rendered = $renderer->render([[
			'prefix_id' => 1,
			'prefix_tag' => '<strong>😇</strong>',
			'prefix_color' => '4A76A8',
		]]);

		self::assertSame('&lt;strong&gt;😇&lt;/strong&gt;', $rendered[0]['TAG_NAME']);
	}

	/**
	 * Test unavailable assigned tags are marked retained without repeated catalog lookups.
	 */
	public function test_retained_status_uses_cached_forum_availability(): void
	{
		$manager = $this->getMockBuilder('\phpbb\topicprefixes\tags\manager')
			->disableOriginalConstructor()
			->getMock();
		$manager->expects(self::once())
			->method('get_available_tags')
			->with(2)
			->willReturn([]);
		$renderer = new \phpbb\topicprefixes\tags\renderer($manager, './', 'php');
		$tags = [[
			'prefix_id' => 1,
			'prefix_tag' => 'Legacy',
			'prefix_color' => '4A76A8',
		]];

		self::assertTrue($renderer->render($tags, 2)[0]['S_RETAINED']);
		self::assertTrue($renderer->render($tags, 2)[0]['S_RETAINED']);
	}

	/**
	 * Create renderer with configured forum availability.
	 *
	 * @param array $available Available tags
	 * @return \phpbb\topicprefixes\tags\renderer
	 */
	protected function renderer(array $available = []): \phpbb\topicprefixes\tags\renderer
	{
		$manager = $this->getMockBuilder('\phpbb\topicprefixes\tags\manager')
			->disableOriginalConstructor()
			->getMock();
		$manager->method('get_available_tags')->willReturn($available);

		return new \phpbb\topicprefixes\tags\renderer($manager, './', 'php');
	}
}
