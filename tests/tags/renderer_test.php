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
		$renderer = new \phpbb\topicprefixes\tags\renderer('./', 'php');
		self::assertSame('#FFFFFF', $renderer->contrast_color('000000'));
		self::assertSame('#000000', $renderer->contrast_color('FFFFFF'));
		self::assertSame('#FFFFFF', $renderer->contrast_color('1D70B8'));
	}

	/**
	 * Test filter URLs normalize IDs and preserve topic sorting.
	 */
	public function test_filter_url_preserves_sorting_and_normalizes_tags(): void
	{
		$renderer = new \phpbb\topicprefixes\tags\renderer('./', 'php');
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
		$renderer = new \phpbb\topicprefixes\tags\renderer('./', 'php');
		$tags = [[
			'prefix_id' => 1,
			'prefix_tag' => 'Bug',
			'prefix_color' => 'D4351C',
		]];

		$rendered = $renderer->render($tags, 2, [1, 2], [], true);

		self::assertTrue($rendered[0]['S_SELECTED']);
		self::assertStringContainsString('tags=2', $rendered[0]['U_FILTER']);
		self::assertSame('#FFFFFF', $rendered[0]['TAG_TEXT_COLOR']);
	}
}
