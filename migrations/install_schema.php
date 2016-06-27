<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2016 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\migrations;

/**
 * Class install_schema
 */
class install_schema extends \phpbb\db\migration\migration
{
	/**
	 * @inheritdoc
	 */
	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'topic_prefixes');
	}

	/**
	 * @inheritdoc
	 */
	static public function depends_on()
	{
		return ['\phpbb\db\migration\data\v320\dev'];
	}

	/**
	 * Add the topic prefixes table schema to the database:
	 *    topic_prefixes:
	 *        id       Prefix identifier
	 *        name     Language selection
	 *        enabled  Boolean enabled/disabled status of the prefix
	 *        forum_id The forum identifier associated with the prefix
	 *
	 * @return array Array of table schema
	 * @access public
	 */
	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'topic_prefixes' => [
					'COLUMNS'     => [
						'id'			=> ['UINT', null, 'auto_increment'],
						'prefix'		=> ['VCHAR_UNI', ''],
						'enabled'		=> ['BOOL', 1],
						'forum_id'		=> ['UINT', 0],
					],
					'PRIMARY_KEY'		=> 'id',
				],
			],
		];
	}

	/**
	 * Drop the topic prefixes table schema from the database
	 *
	 * @return array Array of table schema
	 * @access public
	 */
	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'topic_prefixes',
			],
		];
	}
}
