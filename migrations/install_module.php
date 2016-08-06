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
 * Class install_module
 */
class install_module extends \phpbb\db\migration\migration
{
	/**
	 * @inheritdoc
	 */
	public function effectively_installed()
	{
		$sql = 'SELECT module_id
			FROM ' . $this->table_prefix . "modules
			WHERE module_class = 'acp'
				AND module_langname = 'ACP_MANAGE_PREFIXES'";
		$result = $this->db->sql_query($sql);
		$module_id = $this->db->sql_fetchfield('module_id');
		$this->db->sql_freeresult($result);

		return $module_id !== false;
	}

	/**
	 * @inheritdoc
	 */
	public static function depends_on()
	{
		return ['\phpbb\topicprefixes\migrations\install_schema'];
	}

	/**
	 * @inheritdoc
	 */
	public function update_data()
	{
		return [
			['module.add', ['acp', 'ACP_CAT_DOT_MODS', 'ACP_TOPIC_PREFIXES']],
			['module.add', ['acp', 'ACP_TOPIC_PREFIXES', [
				'module_basename'	=> '\phpbb\topicprefixes\acp\topic_prefixes_module',
				'modes'				=> ['manage'],
			]]],
		];
	}
}
