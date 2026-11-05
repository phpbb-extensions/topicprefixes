<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\console\command;

use phpbb\config\config;
use phpbb\console\command\command;
use phpbb\language\language;
use phpbb\log\log_interface;
use phpbb\topicprefixes\tags\manager;
use phpbb\topicprefixes\tags\repairer;
use phpbb\user;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Interactive topic tag repair tool.
 */
class repair_tags extends command
{
	/** @var config Configuration service */
	protected $config;

	/** @var language Language service */
	protected $language;

	/** @var manager Topic tag manager */
	protected $tag_manager;

	/** @var repairer Topic tag repair service */
	protected $repairer;

	/** @var log_interface Administrator audit logger */
	protected $log;

	/**
	 * Constructor.
	 *
	 * @param user          $user        phpBB user
	 * @param config        $config      Configuration service
	 * @param language      $language    Language service
	 * @param manager       $tag_manager Topic tag manager
	 * @param repairer      $repairer    Topic tag repair service
	 * @param log_interface $log         Administrator audit logger
	 */
	public function __construct(user $user, config $config, language $language, manager $tag_manager, repairer $repairer, log_interface $log)
	{
		$this->config = $config;
		$this->language = $language;
		$this->tag_manager = $tag_manager;
		$this->repairer = $repairer;
		$this->log = $log;
		$this->language->add_lang('cli_topic_prefixes', 'phpbb/topicprefixes');

		parent::__construct($user);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function configure(): void
	{
		$this
			->setName('topicprefixes:repair-tags')
			->setDescription($this->language->lang('CLI_TOPIC_TAGS_REPAIR_DESCRIPTION'))
			->addOption(
				'force',
				null,
				InputOption::VALUE_NONE,
				$this->language->lang('CLI_TOPIC_REPAIR_FORCE')
			)
			->addOption(
				'tag-id',
				null,
				InputOption::VALUE_REQUIRED,
				$this->language->lang('CLI_TOPIC_TAGS_REPAIR_TAG_ID')
			);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		if (!$input->isInteractive())
		{
			$io->error($this->language->lang('CLI_TOPIC_TAGS_REPAIR_INTERACTIVE_REQUIRED'));
			return 1;
		}
		$force = (bool) $input->getOption('force');
		if ($force)
		{
			$io->warning($this->language->lang('CLI_TOPIC_REPAIR_FORCE_WARNING'));
		}
		if (!$force && empty($this->config['board_disable']))
		{
			$io->error($this->language->lang('CLI_TOPIC_TAGS_REPAIR_BOARD_ENABLED'));
			return 1;
		}

		$io->warning($this->language->lang('CLI_TOPIC_TAGS_REPAIR_WARNING'));
		if (!$io->confirm($this->language->lang('CLI_TOPIC_TAGS_REPAIR_BACKUP_CONFIRM'), false))
		{
			$io->note($this->language->lang('CLI_TOPIC_TAGS_REPAIR_CANCELLED'));
			return 0;
		}

		$catalog = $this->tag_manager->get_tags();
		$tags = $catalog;
		$tag_id = $input->getOption('tag-id');
		if ($tag_id !== null)
		{
			$tag_id = (int) $tag_id;
			if ($tag_id <= 0 || !isset($tags[$tag_id]))
			{
				$io->error($this->language->lang('CLI_TOPIC_TAGS_REPAIR_TAG_NOT_FOUND'));
				return 1;
			}
			$tags = [$tag_id => $tags[$tag_id]];
		}
		if (!$tags)
		{
			$io->note($this->language->lang('CLI_TOPIC_TAGS_REPAIR_NO_TAGS'));
			return 0;
		}

		$io->title($this->language->lang('CLI_TOPIC_TAGS_REPAIR_TITLE'));
		$split = 0;
		$merged = 0;
		$skipped = 0;
		foreach ($tags as $current_id => $tag)
		{
			$details = $this->repairer->inspect((int) $current_id);
			$io->section($this->language->lang(
				'CLI_TOPIC_TAGS_REPAIR_SOURCE',
				$current_id,
				OutputFormatter::escape($tag['prefix_tag']),
				!empty($tag['prefix_enabled'])
					? $this->language->lang('CLI_TOPIC_TAGS_REPAIR_ENABLED')
					: $this->language->lang('CLI_TOPIC_TAGS_REPAIR_DISABLED'),
				$this->language->lang('CLI_TOPIC_TAGS_REPAIR_SOURCE_TOPICS', $details['topic_count']),
				$this->language->lang('CLI_TOPIC_TAGS_REPAIR_SOURCE_FORUMS', $details['forum_count'])
			));

			$choices = [
				$this->language->lang('CLI_TOPIC_TAGS_REPAIR_ACTION_SKIP'),
				$this->language->lang('CLI_TOPIC_TAGS_REPAIR_ACTION_SPLIT'),
				$this->language->lang('CLI_TOPIC_TAGS_REPAIR_ACTION_MERGE'),
				$this->language->lang('CLI_TOPIC_TAGS_REPAIR_ACTION_QUIT'),
			];
			$action = $io->choice($this->language->lang('CLI_TOPIC_TAGS_REPAIR_ACTION'), $choices, $choices[0]);
			if ($action === $choices[3])
			{
				break;
			}
			if ($action === $choices[0])
			{
				$skipped++;
				continue;
			}

			$is_merge = $action === $choices[2];
			$replacements = [];
			$target_id = null;
			if ($is_merge)
			{
				$target_id = $this->ask_merge_target($io, (int) $current_id, $catalog);
				if ($target_id === null)
				{
					$skipped++;
					continue;
				}
			}
			else
			{
				$replacements = $this->ask_replacements($io, $tag['prefix_tag']);
				if (!$replacements)
				{
					$skipped++;
					continue;
				}
			}

			try
			{
				$preview = $is_merge
					? $this->repairer->preview_merge((int) $current_id, $target_id)
					: $this->repairer->preview((int) $current_id, $replacements);
			}
			catch (\InvalidArgumentException $e)
			{
				$io->error($this->language->lang($e->getMessage()));
				$skipped++;
				continue;
			}
			$this->display_preview($io, $preview, $is_merge);
			$confirm_key = $is_merge
				? 'CLI_TOPIC_TAGS_REPAIR_MERGE_APPLY_CONFIRM'
				: 'CLI_TOPIC_TAGS_REPAIR_SPLIT_APPLY_CONFIRM';
			if (!$io->confirm($this->language->lang($confirm_key), false))
			{
				$io->note($this->language->lang('CLI_TOPIC_TAGS_REPAIR_SKIPPED'));
				$skipped++;
				continue;
			}
			if (!$force && empty($this->config['board_disable']))
			{
				$io->error($this->language->lang('CLI_TOPIC_TAGS_REPAIR_BOARD_ENABLED'));
				return 1;
			}

			try
			{
				$result = $is_merge
					? $this->repairer->merge((int) $current_id, $target_id)
					: $this->repairer->repair((int) $current_id, $replacements);
			}
			catch (\Exception $e)
			{
				$failure_key = $is_merge
					? 'CLI_TOPIC_TAGS_REPAIR_MERGE_FAILED'
					: 'CLI_TOPIC_TAGS_REPAIR_SPLIT_FAILED';
				$io->error($this->language->lang($failure_key, OutputFormatter::escape($e->getMessage())));
				return 1;
			}

			$target_names = array_column($result['targets'], 'prefix_tag');
			$log_key = $is_merge ? 'ACP_LOG_TAG_MERGED' : 'ACP_LOG_TAG_SPLIT';
			$this->log->add(
				'admin',
				ANONYMOUS,
				'',
				$log_key,
				time(),
				[
					utf8_encode_ucr(utf8_htmlspecialchars($result['source']['prefix_tag'])),
					utf8_encode_ucr(utf8_htmlspecialchars(implode(', ', $target_names))),
				]
			);
			$success_key = $is_merge
				? 'CLI_TOPIC_TAGS_REPAIR_MERGE_SUCCESS'
				: 'CLI_TOPIC_TAGS_REPAIR_SPLIT_SUCCESS';
			$io->success($this->language->lang(
				$success_key,
				OutputFormatter::escape($result['source']['prefix_tag']),
				OutputFormatter::escape(implode(', ', $target_names)),
				$result['topic_count']
			));
			if ($is_merge)
			{
				$merged++;
			}
			else
			{
				$split++;
			}
			unset($catalog[$current_id]);
			foreach ($result['targets'] as $target)
			{
				$target_id = (int) $target['prefix_id'];
				$catalog[$target_id] = array_merge($catalog[$target_id] ?? [], $target);
			}
		}

		$io->success($this->language->lang(
			'CLI_TOPIC_TAGS_REPAIR_SUMMARY',
			$this->language->lang('CLI_TOPIC_TAGS_REPAIR_SUMMARY_SPLITS', $split),
			$this->language->lang('CLI_TOPIC_TAGS_REPAIR_SUMMARY_MERGES', $merged),
			$this->language->lang('CLI_TOPIC_TAGS_REPAIR_SUMMARY_SKIPS', $skipped)
		));
		return 0;
	}

	/**
	 * Collect normalized replacement names one at a time.
	 *
	 * @param SymfonyStyle $io          Console input/output helper
	 * @param string       $source_name Display-form source tag name
	 * @return array Display-form replacement names
	 */
	protected function ask_replacements(SymfonyStyle $io, string $source_name): array
	{
		$replacements = [];
		$seen = [];
		while (true)
		{
			$prompt = $replacements
				? $this->language->lang('CLI_TOPIC_TAGS_REPAIR_ANOTHER_TAG')
				: $this->language->lang('CLI_TOPIC_TAGS_REPAIR_FIRST_TAG');
			$value = $io->ask($prompt, false);
			if ($value === false || $value === null || trim($value) === '')
			{
				if (!$replacements)
				{
					$io->note($this->language->lang('CLI_TOPIC_TAGS_REPAIR_SKIPPED'));
				}
				break;
			}

			$stored_name = manager::normalize_name($value);
			if ($stored_name === '')
			{
				$io->error($this->language->lang('CLI_TOPIC_TAGS_REPAIR_INVALID_TAG'));
				continue;
			}
			$name = manager::decode_name($stored_name);
			$key = base64_encode($name);
			if ($name === $source_name)
			{
				$io->error($this->language->lang('CLI_TOPIC_TAGS_REPAIR_SOURCE_TARGET'));
				continue;
			}
			if (isset($seen[$key]))
			{
				$io->error($this->language->lang('CLI_TOPIC_TAGS_REPAIR_DUPLICATE_TAG'));
				continue;
			}

			$seen[$key] = true;
			$replacements[] = $name;
		}

		return $replacements;
	}

	/**
	 * Select one existing destination tag by identifier.
	 *
	 * @param SymfonyStyle $io        Console input/output helper
	 * @param int          $source_id Source tag identifier
	 * @param array        $catalog   Tag catalog
	 * @return int|null Selected target identifier, or null when cancelled
	 */
	protected function ask_merge_target(SymfonyStyle $io, int $source_id, array $catalog): ?int
	{
		$target_ids = [];
		$choices = [];
		foreach ($catalog as $tag_id => $tag)
		{
			$tag_id = (int) $tag_id;
			if ($tag_id === $source_id)
			{
				continue;
			}

			$label = $this->language->lang(
				'CLI_TOPIC_TAGS_REPAIR_MERGE_TARGET_OPTION',
				$tag_id,
				OutputFormatter::escape($tag['prefix_tag'])
			);
			$choices[] = $label;
			$target_ids[$label] = $tag_id;
		}

		if (!$choices)
		{
			$io->note($this->language->lang('CLI_TOPIC_TAGS_REPAIR_MERGE_NO_TARGETS'));
			return null;
		}

		$cancel = $this->language->lang('CLI_TOPIC_TAGS_REPAIR_MERGE_CANCEL');
		$choices[] = $cancel;
		$selected = $io->choice(
			$this->language->lang('CLI_TOPIC_TAGS_REPAIR_MERGE_TARGET'),
			$choices,
			$cancel
		);

		return $selected === $cancel ? null : $target_ids[$selected];
	}

	/**
	 * Display all effects known before mutation.
	 *
	 * @param SymfonyStyle $io       Console input/output helper
	 * @param array        $preview  Validated repair preview
	 * @param bool         $is_merge Whether preview describes a merge
	 * @return void
	 */
	protected function display_preview(SymfonyStyle $io, array $preview, bool $is_merge): void
	{
		$rows = [];
		foreach ($preview['targets'] as $target)
		{
			$rows[] = [
				OutputFormatter::escape($target['prefix_tag']),
				$target['existing']
					? $this->language->lang('CLI_TOPIC_TAGS_REPAIR_EXISTING_TAG', $target['prefix_id'])
					: $this->language->lang('CLI_TOPIC_TAGS_REPAIR_NEW_TAG'),
			];
		}

		$io->section($this->language->lang(
			$is_merge
				? 'CLI_TOPIC_TAGS_REPAIR_MERGE_PREVIEW'
				: 'CLI_TOPIC_TAGS_REPAIR_SPLIT_PREVIEW'
		));
		$io->table([
			$this->language->lang('CLI_TOPIC_TAGS_REPAIR_REPLACEMENT'),
			$this->language->lang('CLI_TOPIC_TAGS_REPAIR_STATUS'),
		], $rows);
		$topics_key = $is_merge
			? 'CLI_TOPIC_TAGS_REPAIR_MERGE_PREVIEW_TOPICS'
			: 'CLI_TOPIC_TAGS_REPAIR_SPLIT_PREVIEW_TOPICS';
		$forums_key = $is_merge
			? 'CLI_TOPIC_TAGS_REPAIR_MERGE_PREVIEW_FORUMS'
			: 'CLI_TOPIC_TAGS_REPAIR_SPLIT_PREVIEW_FORUMS';
		$effects = [
			$this->language->lang($topics_key, $preview['topic_count']),
			$this->language->lang($forums_key, $preview['forum_count']),
			$this->language->lang('CLI_TOPIC_TAGS_REPAIR_NO_TEXT_CHANGES'),
			$this->language->lang('CLI_TOPIC_TAGS_REPAIR_PREVIEW_DELETE'),
		];
		if ($is_merge && !empty($preview['source']['prefix_enabled']) && empty($preview['targets'][0]['prefix_enabled']))
		{
			array_splice($effects, 2, 0, [$this->language->lang('CLI_TOPIC_TAGS_REPAIR_MERGE_PREVIEW_ENABLE')]);
		}
		$io->listing($effects);
	}
}
