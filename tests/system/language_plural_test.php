<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\tests\system;

class language_plural_test extends \phpbb_test_case
{
	public function test_cli_count_translations_define_matching_singular_and_plural_forms(): void
	{
		$lang = [];
		include dirname(__DIR__, 2) . '/language/en/cli_topic_prefixes.php';
		$plural_keys = [
			'CLI_TOPIC_TAGS_REPAIR_SOURCE_TOPICS',
			'CLI_TOPIC_TAGS_REPAIR_SOURCE_FORUMS',
			'CLI_TOPIC_TAGS_REPAIR_SPLIT_PREVIEW_TOPICS',
			'CLI_TOPIC_TAGS_REPAIR_SPLIT_PREVIEW_FORUMS',
			'CLI_TOPIC_TAGS_REPAIR_MERGE_PREVIEW_TOPICS',
			'CLI_TOPIC_TAGS_REPAIR_MERGE_PREVIEW_FORUMS',
			'CLI_TOPIC_TAGS_REPAIR_SPLIT_SUCCESS',
			'CLI_TOPIC_TAGS_REPAIR_MERGE_SUCCESS',
			'CLI_TOPIC_TAGS_REPAIR_SUMMARY_SPLITS',
			'CLI_TOPIC_TAGS_REPAIR_SUMMARY_MERGES',
			'CLI_TOPIC_TAGS_REPAIR_SUMMARY_SKIPS',
		];

		foreach ($plural_keys as $key)
		{
			self::assertSame([1, 2], array_keys($lang[$key]), $key);
			preg_match_all('/%(?:\d+\$)?[ds]/', $lang[$key][1], $singular_placeholders);
			preg_match_all('/%(?:\d+\$)?[ds]/', $lang[$key][2], $plural_placeholders);
			self::assertSame($singular_placeholders[0], $plural_placeholders[0], $key);
		}
	}
}
