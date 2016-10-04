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
	 * Get a topic prefix by its identifier
	 *
	 * @param int $id Topic prefix identifier
	 * @return mixed Array of data, or false if no data found
	 */
	public function get_prefix($id);

	/**
	 * Get topic prefixes from the specified forum, otherwise get all
	 *
	 * @param int $forum_id Forum identifier
	 * @return array An array of topic prefix data
	 */
	public function get_prefixes($forum_id = 0);

	/**
	 * Get enabled topic prefixes from the specified forum, otherwise get all
	 *
	 * @param int $forum_id Forum identifier
	 * @return array An array of topic prefix data
	 */
	public function get_active_prefixes($forum_id = 0);

	/**
	 * Add a topic prefix to the database
	 *
	 * @param string $tag      Topic prefix tag/name
	 * @param int    $forum_id Forum identifier
	 * @return mixed Array with new prefix data as set in the database, false on error
	 */
	public function add_prefix($tag, $forum_id);

	/**
	 * Delete a topic prefix from the database
	 *
	 * @param int $id Topic prefix identifier
	 * @return array Item ids that have been deleted
	 * @throws \OutOfBoundsException
	 */
	public function delete_prefix($id);

	/**
	 * Update a topic prefix in the database
	 *
	 * @param int   $id   The item identifier
	 * @param array $data SQL array of data to update
	 * @return mixed Number of the affected rows updated, or false
	 * @throws \OutOfBoundsException
	 */
	public function update_prefix($id, array $data);

	/**
	 * Move a prefix up/down
	 *
	 * @param int    $id        The prefix identifier to move
	 * @param string $direction The direction (up|down)
	 * @param int    $amount    The number of places to move the rule
	 * @return void
	 * @throws \OutOfBoundsException
	 */
	public function move_prefix($id, $direction = 'up', $amount = 1);

	/**
	 * Check if a topic prefix is enabled
	 *
	 * @param array $row A row of topic prefix data
	 * @return bool True if enabled, or false
	 */
	public function is_enabled(array $row);

	/**
	 * Prepend a topic prefix to a topic title/subject
	 *
	 * @param string $prefix  A topic prefix
	 * @param string $subject A topic title/subject
	 * @return string Updated topic title/subject
	 */
	public function prepend_prefix($prefix, $subject);
}
