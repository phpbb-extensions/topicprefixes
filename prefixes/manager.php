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

use phpbb\db\driver\driver_interface;

class manager implements manager_interface
{
	/**
	 * @var driver_interface Database object
	 */
	protected $db;

	/**
	 * @var string Topic prefixes data table name
	 */
	protected $prefixes_table;

	/**
	 * Listener constructor
	 *
	 * @param driver_interface $db             Database object
	 * @param string           $prefixes_table Topic prefixes data table name
	 */
	public function __construct(driver_interface $db, $prefixes_table)
	{
		$this->db = $db;
		$this->prefixes_table = $prefixes_table;
	}

	/**
	 * @inheritdoc
	 */
	public function get_prefix($id)
	{
		$sql = 'SELECT prefix_id, prefix_tag, prefix_enabled 
			FROM ' . $this->prefixes_table . ' 
			WHERE prefix_id = ' . (int) $id;
		$result = $this->db->sql_query_limit($sql, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row;
	}

	/**
	 * @inheritdoc
	 */
	public function get_prefixes($forum_id = 0)
	{
		$prefixes = [];

		$sql = 'SELECT prefix_id, prefix_tag, prefix_enabled 
			FROM ' . $this->prefixes_table .
			(($forum_id !== null) ? ' WHERE forum_id = ' . (int) $forum_id : '');
		$result = $this->db->sql_query($sql, 3600);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$prefixes[$row['prefix_id']] = $row;
		}
		$this->db->sql_freeresult($result);

		return $prefixes;
	}

	/**
	 * @inheritdoc
	 */
	public function get_active_prefixes($forum_id = 0)
	{
		return array_filter($this->get_prefixes($forum_id), [$this, 'is_enabled']);
	}

	/**
	 * @inheritdoc
	 */
	public function add_prefix($tag, $forum_id)
	{
	}

	/**
	 * @inheritdoc
	 */
	public function delete_prefix($id)
	{
	}

	/**
	 * @inheritdoc
	 */
	public function is_enabled(array $row)
	{
		return $row['prefix_enabled'];
	}
}
