<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2016 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\prefixes;

interface manager_interface
{
	/**
	 * Get topic prefixes from the specified forum, otherwise get all
	 *
	 * @param int|null $forum_id Forum identifier
	 *
	 * @return array An array of topic prefix data
	 */
	public function get_prefixes($forum_id = null);

	/**
	 * Get enabled topic prefixes from the specified forum, otherwise get all
	 *
	 * @param int|null $forum_id Forum identifier
	 *
	 * @return array An array of topic prefix data
	 */
	public function get_active_prefixes($forum_id = null);

	/**
	 * Add a topic prefix to the database
	 *
	 * @param string $tag      Topic prefix tag/name
	 * @param int    $forum_id Forum identifier
	 *
	 * @return int Return the new topic prefix identifier, or 0 on failure
	 */
	public function add_prefix($tag, $forum_id);

	/**
	 * Delete a topic prefix from the database
	 *
	 * @param int $id Topic prefix identifier
	 *
	 * @return bool True on success, false otherwise
	 */
	public function delete_prefix($id);

	/**
	 * Check if a topic prefix is enabled
	 *
	 * @param array $row A row of topic prefix data
	 *
	 * @return bool True if enabled, or false
	 */
	public function is_enabled(array $row);
}
