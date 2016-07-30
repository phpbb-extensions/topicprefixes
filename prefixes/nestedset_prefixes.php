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
			'TOPIC_PREFIXES_',
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

	/**
	 * Set additional sql where restrictions to use the forum id
	 *
	 * @param int $forum_id The forum identifier
	 * @return nestedset_prefixes $this object for chaining calls
	 */
	public function where_forum_id($forum_id)
	{
		$this->sql_where = '%sforum_id = ' . (int) $forum_id;

		return $this;
	}

	/**
	 * Update a nested item
	 *
	 * @param int   $item_id   The item identifier
	 * @param array $item_data SQL array of data to update
	 * @return mixed Number of the affected rows updated, or false
	 * @throws \OutOfBoundsException
	 */
	public function update_item($item_id, array $item_data)
	{
		if (!$item_id)
		{
			throw new \OutOfBoundsException($this->message_prefix . 'INVALID_ITEM');
		}

		$sql = 'UPDATE ' . $this->table_name . '
			SET ' . $this->db->sql_build_array('UPDATE', $item_data) . '
			WHERE ' . $this->column_item_id . ' = ' . (int) $item_id;
		$this->db->sql_query($sql);

		return $this->db->sql_affectedrows();
	}
}
