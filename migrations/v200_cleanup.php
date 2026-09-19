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
 * Remove obsolete single-prefix schema after relational data migration.
 */
class v200_cleanup extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return !$this->db_tools->sql_column_exists($this->table_prefix . 'topics', 'topic_prefix_id')
			&& !$this->db_tools->sql_column_exists($this->table_prefix . 'topic_prefixes', 'forum_id')
			&& !$this->db_tools->sql_column_exists($this->table_prefix . 'topic_prefixes', 'prefix_left_id');
	}

	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return ['\phpbb\topicprefixes\migrations\v200_data'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_schema()
	{
		return [
			'drop_columns' => [
				$this->table_prefix . 'topics' => ['topic_prefix_id'],
				$this->table_prefix . 'topic_prefixes' => [
					'prefix_parent_id',
					'prefix_left_id',
					'prefix_right_id',
					'prefix_parents',
					'forum_id',
				],
			],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function revert_schema()
	{
		return [
			'add_columns' => [
				$this->table_prefix . 'topics' => [
					'topic_prefix_id' => ['UINT', 0],
				],
				$this->table_prefix . 'topic_prefixes' => [
					'prefix_parent_id' => ['UINT', 0],
					'prefix_left_id' => ['UINT', 0],
					'prefix_right_id' => ['UINT', 0],
					'prefix_parents' => ['MTEXT_UNI', ''],
					'forum_id' => ['UINT', 0],
				],
			],
		];
	}
}
