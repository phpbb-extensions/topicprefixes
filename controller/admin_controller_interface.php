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
 * Interface admin_controller_interface
 */
interface admin_controller_interface
{
	/**
	 * Main handler, called by the ACP module
	 *
	 * @return null
	 */
	public function main();

	/**
	 * Display topic prefix settings
	 *
	 * @return null
	 */
	public function display_settings();

	/**
	 * Add a prefix
	 *
	 * @return null
	 */
	public function add_prefix();

	/**
	 * Edit a prefix
	 *
	 * @param int $prefix_id The prefix identifier to edit
	 * @return null
	 */
	public function edit_prefix($prefix_id);

	/**
	 * Delete a prefix
	 *
	 * @param int $prefix_id The prefix identifier to delete
	 * @return null
	 */
	public function delete_prefix($prefix_id);

	/**
	 * Move a prefix up/down
	 *
	 * @param int    $prefix_id The prefix identifier to move
	 * @param string $action The action (move_up|move_down)
	 * @return null
	 */
	public function move_prefix($prefix_id, $action);

	/**
	 * Set u_action
	 *
	 * @param string $u_action Custom form action
	 * @return null
	 */
	public function set_u_action($u_action);

	/**
	 * Set forum ID
	 *
	 * @param int $forum_id Forum identifier
	 */
	public function set_forum_id($forum_id);
}
