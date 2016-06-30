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

/**
* Nested set class for Topic Prefixes
*/
class nestedset_prefixes extends \phpbb\tree\nestedset
{
	/**
	 * Construct
	 *
	 * @param \phpbb\db\driver\driver_interface $db         Database connection
	 * @param \phpbb\lock\db                    $lock       Lock class used to lock the table when moving forums around
	 * @param string                            $table_name Table name
	 */
	public function __construct(\phpbb\db\driver\driver_interface $db, \phpbb\lock\db $lock, $table_name)
	{
		parent::__construct(
			$db,
			$lock,
			$table_name,
			'',
			'',
			[],
			[
				'item_id'		=> 'prefix_id',
				'parent_id'		=> 'prefix_parent_id',
				'left_id'		=> 'prefix_left_id',
				'right_id'		=> 'prefix_right_id',
				'item_parents'	=> 'prefix_parents',
			]
		);
	}
}
