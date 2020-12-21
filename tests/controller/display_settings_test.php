<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2016 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\controller;

/**
 * We need to manually include the base file because auto loading won't work since the base
 * uses the same namespace as the controller being tested (to override global scope functions).
 */
require_once __DIR__ . '/admin_controller_base.php';

class display_settings_test extends admin_controller_base
{
	/**
	 * Data for test_display_settings
	 *
	 * @return array
	 */
	public function data_display_settings()
	{
		return [
			[[], 1],
			[[1 => [
				'prefix_id'      => 1,
				'prefix_tag'     => 'foo',
				'prefix_enabled' => true,
			]], 2],
			[[1 => [
				'prefix_id'      => 1,
				'prefix_tag'     => 'foo',
				'prefix_enabled' => true,
			], 2 => [
				'prefix_id'      => 2,
				'prefix_tag'     => 'bar',
				'prefix_enabled' => true,
			]], 3],
		];
	}

	/**
	 * Test display_settings()
	 *
	 * @dataProvider data_display_settings
	 * @param $prefixes
	 * @param $forum_id
	 */
	public function test_display_settings($prefixes, $forum_id)
	{
		$this->manager->expects(static::once())
			->method('get_prefixes')
			->willReturn($prefixes);

		$this->template->expects(static::exactly(count($prefixes)))
			->method('assign_block_vars');

		$this->template->expects(static::once())
			->method('assign_vars')
			->with(array(
				'S_FORUM_OPTIONS'	=> '#select menu#',
				'FORUM_ID'			=> $forum_id,
				'U_ACTION'			=> 'foo',
			));

		$this->controller
			->set_u_action('foo')
			->set_forum_id($forum_id)
			->display_settings();
	}
}
