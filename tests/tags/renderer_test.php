<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2016 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\tests\tags;

class renderer_test extends \phpbb_test_case
{
	public function test_automatic_contrast()
	{
		$renderer = new \phpbb\topicprefixes\tags\renderer('./', 'php');
		self::assertSame('#FFFFFF', $renderer->contrast_color('000000'));
		self::assertSame('#000000', $renderer->contrast_color('FFFFFF'));
		self::assertSame('#FFFFFF', $renderer->contrast_color('1D70B8'));
	}
}
