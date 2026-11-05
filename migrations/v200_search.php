<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\migrations;

/**
 * Add opt-in topic tag search configuration.
 */
class v200_search extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return isset($this->config['topicprefixes_search_enabled']);
	}

	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return ['\phpbb\topicprefixes\migrations\v200_cleanup'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return [
			['config.add', ['topicprefixes_search_enabled', 0]],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function revert_data()
	{
		return [
			['config.remove', ['topicprefixes_search_enabled']],
		];
	}
}
