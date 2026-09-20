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
 * Add relational topic tag schema.
 */
class v200_schema extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'topic_prefixes_topics')
			&& $this->db_tools->sql_table_exists($this->table_prefix . 'topic_prefixes_forums')
			&& $this->db_tools->sql_column_exists($this->table_prefix . 'topic_prefixes', 'prefix_color')
			&& $this->db_tools->sql_column_exists($this->table_prefix . 'topic_prefixes', 'prefix_order');
	}

	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return ['\phpbb\topicprefixes\migrations\install_module'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_schema()
	{
		return [
			'add_columns' => [
				$this->table_prefix . 'topic_prefixes' => [
					'prefix_color' => ['VCHAR:6', '4A76A8'],
					'prefix_order' => ['UINT', 0],
				],
			],
			'add_index' => [
				$this->table_prefix . 'topic_prefixes' => [
					'prefix_state_order' => ['prefix_enabled', 'prefix_order'],
				],
			],
			'add_tables' => [
				$this->table_prefix . 'topic_prefixes_forums' => [
					'COLUMNS' => [
						'forum_id' => ['UINT', 0],
						'prefix_id' => ['UINT', 0],
					],
					'PRIMARY_KEY' => ['forum_id', 'prefix_id'],
					'KEYS' => [
						'prefix_id' => ['INDEX', ['prefix_id']],
					],
				],
				$this->table_prefix . 'topic_prefixes_topics' => [
					'COLUMNS' => [
						'topic_id' => ['UINT', 0],
						'prefix_id' => ['UINT', 0],
					],
					'PRIMARY_KEY' => ['topic_id', 'prefix_id'],
					'KEYS' => [
						'prefix_topic' => ['INDEX', ['prefix_id', 'topic_id']],
					],
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
			'drop_keys' => [
				$this->table_prefix . 'topic_prefixes' => ['prefix_state_order'],
			],
			'drop_columns' => [
				$this->table_prefix . 'topic_prefixes' => ['prefix_color', 'prefix_order'],
			],
			'drop_tables' => [
				$this->table_prefix . 'topic_prefixes_forums',
				$this->table_prefix . 'topic_prefixes_topics',
			],
		];
	}
}
