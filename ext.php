<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2016 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes;

/**
 * This ext class is optional and can be omitted if left empty.
 * However, you can add special (un)installation commands in the
 * methods enable_step(), disable_step() and purge_step(). As it is,
 * these methods are defined in \phpbb\extension\base, which this
 * class extends, but you can overwrite them to give special
 * instructions for those cases.
 */
class ext extends \phpbb\extension\base
{
	/**
	 * Check whether the extension can be enabled.
	 * The current phpBB version should meet or exceed
	 * the minimum version required by this extension:
	 *
	 * Requires phpBB 3.3.19 and PHP 7.2
	 *
	 * @return bool|string
	 */
	public function is_enableable()
	{
		if (!$this->meets_requirements())
		{
			return false;
		}

		$config = $this->container->get('config');
		if (!empty($config['board_disable']) || !$this->is_legacy_upgrade())
		{
			return true;
		}

		$language = $this->container->get('language');
		$language->add_lang('info_acp_topic_prefixes', $this->extension_name);

		return $language->lang('TOPIC_PREFIXES_UPGRADE_BOARD_ENABLED');
	}

	/**
	 * Check runtime requirements separately so unsupported environments can be tested.
	 *
	 * @return bool Whether PHP and phpBB satisfy minimum versions
	 */
	protected function meets_requirements(): bool
	{
		return PHP_VERSION_ID >= 70200 && phpbb_version_compare(PHPBB_VERSION, '3.3.19', '>=');
	}

	/**
	 * Check for a legacy installation that has not started enabling v2.
	 *
	 * A fresh installation temporarily creates the legacy schema because the
	 * v2 migrations depend on the original install migrations. Its serialized
	 * "true" extension state identifies an enable operation already in progress.
	 *
	 * @return bool
	 */
	protected function is_legacy_upgrade(): bool
	{
		$db_tools = $this->container->get('dbal.tools');
		$topics_table = $this->container->getParameter('tables.topics');

		if (!$db_tools->sql_column_exists($topics_table, 'topic_prefix_id'))
		{
			return false;
		}

		$configured = $this->container->get('ext.manager')->all_configured(false);
		if (!isset($configured[$this->extension_name]['ext_state']))
		{
			return true;
		}

		return $configured[$this->extension_name]['ext_state'] !== serialize(true);
	}
}
