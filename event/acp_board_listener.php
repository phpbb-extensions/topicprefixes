<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\event;

use phpbb\language\language;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Add the topic tag search toggle to phpBB board features.
 */
class acp_board_listener implements EventSubscriberInterface
{
	/** @var language */
	protected $language;

	public function __construct(language $language)
	{
		$this->language = $language;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'core.acp_board_config_edit_add' => 'add_search_setting',
		];
	}

	/**
	 * Add topic tag search to phpBB's native Board Features settings.
	 *
	 * @param \phpbb\event\data $event Event data
	 * @return void
	 */
	public function add_search_setting($event): void
	{
		if ($event['mode'] !== 'features')
		{
			return;
		}

		$this->language->add_lang('acp_topic_prefixes', 'phpbb/topicprefixes');

		$display_vars = $event['display_vars'];
		$submit_legend = $display_vars['vars']['legend2'];
		unset($display_vars['vars']['legend2']);

		$display_vars['vars']['topicprefixes_search_enabled'] = [
			'lang' => 'ALLOW_TOPIC_TAG_SEARCH',
			'validate' => 'bool',
			'type' => 'radio:yes_no',
			'explain' => true,
		];
		$display_vars['vars']['legend2'] = $submit_legend;

		$event['display_vars'] = $display_vars;
	}
}
