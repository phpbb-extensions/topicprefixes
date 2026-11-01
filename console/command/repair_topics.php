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

use phpbb\console\command\command;
use phpbb\language\language;
use phpbb\log\log_interface;
use phpbb\topicprefixes\tags\manager;
use phpbb\topicprefixes\tags\reply_repairer;
use phpbb\topicprefixes\tags\title_repairer;
use phpbb\topicprefixes\tags\topic_repairer;
use phpbb\user;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Guide topic recovery and optional old prefix cleanup.
 */
class repair_topics extends command
{
	/** @var language Language service */
	protected $language;

	/** @var topic_repairer Missing topic tag repair service */
	protected $topic_repairer;

	/** @var title_repairer Topic title repair service */
	protected $title_repairer;

	/** @var reply_repairer Reply subject repair service */
	protected $reply_repairer;

	/** @var log_interface Administrator audit logger */
	protected $log;

	/** @var bool Whether maintenance mode checks may be bypassed */
	protected $force = false;

	/**
	 * Constructor.
	 *
	 * @param user             $user           phpBB user
	 * @param language         $language       Language service
	 * @param topic_repairer   $topic_repairer Missing topic tag repair service
	 * @param title_repairer   $title_repairer Topic title repair service
	 * @param reply_repairer   $reply_repairer Reply subject repair service
	 * @param log_interface    $log            Administrator audit logger
	 */
	public function __construct(user $user, language $language, topic_repairer $topic_repairer, title_repairer $title_repairer, reply_repairer $reply_repairer, log_interface $log)
	{
		$this->language = $language;
		$this->topic_repairer = $topic_repairer;
		$this->title_repairer = $title_repairer;
		$this->reply_repairer = $reply_repairer;
		$this->log = $log;
		$language->add_lang('cli_topic_prefixes', 'phpbb/topicprefixes');
		parent::__construct($user);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function configure(): void
	{
		$this->setName('topicprefixes:repair-topics')
			->setDescription($this->language->lang('CLI_TOPIC_REPAIR_DESCRIPTION'))
			->addOption(
				'force',
				null,
				InputOption::VALUE_NONE,
				$this->language->lang('CLI_TOPIC_REPAIR_FORCE')
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
			$io->error($this->language->lang('CLI_TOPIC_REPAIR_INTERACTIVE_REQUIRED'));
			return 1;
		}
		$this->force = (bool) $input->getOption('force');
		if ($this->force)
		{
			$io->warning($this->language->lang('CLI_TOPIC_REPAIR_FORCE_WARNING'));
		}
		try
		{
			if (!$this->force)
			{
				$this->topic_repairer->assert_board_disabled();
			}
		}
		catch (\Throwable $e)
		{
			$io->error(OutputFormatter::escape($this->language->lang($e->getMessage())));
			return 1;
		}

		$failed = false;
		$choices = [
			$this->language->lang('CLI_TOPIC_REPAIR_ACTION_RECOVER'),
			$this->language->lang('CLI_TOPIC_REPAIR_ACTION_TITLES'),
			$this->language->lang('CLI_TOPIC_REPAIR_ACTION_REPLIES'),
			$this->language->lang('CLI_TOPIC_REPAIR_ACTION_EXIT'),
		];
		while (true)
		{
			$action = $io->choice($this->language->lang('CLI_TOPIC_REPAIR_ACTION'), $choices, $choices[3]);
			if ($action === $choices[3])
			{
				break;
			}
			try
			{
				if ($action === $choices[0])
				{
					$failed = $this->recover($io) || $failed;
				}
				else if ($action === $choices[1])
				{
					$failed = $this->repair_text($io, $this->title_repairer, 'TITLES') || $failed;
				}
				else
				{
					$failed = $this->repair_text($io, $this->reply_repairer, 'REPLIES') || $failed;
				}
			}
			catch (\Throwable $e)
			{
				$failed = true;
				$io->error(OutputFormatter::escape($this->language->lang($e->getMessage())));
			}
		}

		return $failed ? 1 : 0;
	}

	/**
	 * Recover assignments and migration-owned topic text from surviving titles.
	 *
	 * @param SymfonyStyle $io Console input/output helper
	 * @return bool Whether any recovery conflicts occurred
	 * @throws \Throwable When preview storage or a repair fails
	 */
	protected function recover(SymfonyStyle $io): bool
	{
		$approved_stream = tmpfile();
		$ambiguous_stream = tmpfile();
		if ($approved_stream === false || $ambiguous_stream === false)
		{
			throw new \RuntimeException('CLI_TOPIC_RECOVERY_FAILED');
		}

		$counts = [
			'assignments' => 0,
			'topics' => 0,
			'subjects' => 0,
			'caches' => 0,
			'skipped' => 0,
			'conflicts' => 0,
		];
		$matches = 0;
		$eligible = 0;
		$ambiguous = 0;
		$approved = 0;
		$approved_groups = 0;
		$repaired = 0;
		$groups = [];
		try
		{
			foreach ($this->topic_repairer->preview_recovery() as $plan)
			{
				$matches++;
				$is_eligible = $plan['reason'] === 'eligible';
				$eligible += (int) $is_eligible;
				$ambiguous += (int) !$is_eligible;
				if ($is_eligible)
				{
					$group_values = [$plan['forum_id'], $plan['prefix'], $plan['tag_ids']];
					$key = base64_encode(json_encode($group_values));
					if (!isset($groups[$key]))
					{
						$group_stream = tmpfile();
						if ($group_stream === false)
						{
							throw new \RuntimeException('CLI_TOPIC_RECOVERY_FAILED');
						}
						$groups[$key] = [
							'count' => 0,
							'plans' => $group_stream,
							'forum_id' => $plan['forum_id'],
							'prefix' => $plan['prefix'],
							'tag_names' => $plan['tag_names'],
						];
					}

					$this->write_recovery_plan($groups[$key]['plans'], $plan);
					$groups[$key]['count']++;
				}
				else
				{
					$this->write_recovery_plan($ambiguous_stream, $plan);
				}
			}

			$io->note($this->language->lang('CLI_TOPIC_RECOVERY_MATCHES', $matches, $eligible, $ambiguous));
			if ($ambiguous)
			{
				$io->section($this->language->lang('CLI_TOPIC_RECOVERY_AMBIGUOUS_GROUP', $ambiguous));
				$this->review_recovery_stream($io, $ambiguous_stream, $ambiguous);
			}
			if (!$eligible)
			{
				return false;
			}
			$io->warning($this->language->lang('CLI_TOPIC_RECOVERY_WARNING'));
			foreach ($groups as $group)
			{
				$prefix = $this->format_prefix_label(manager::decode_name($group['prefix']));
				$tag_names = OutputFormatter::escape(implode(', ', $group['tag_names']));
				$io->section($this->language->lang(
					'CLI_TOPIC_RECOVERY_GROUP',
					$prefix,
					$group['forum_id'],
					$tag_names,
					$group['count']
				));
				$reviewed = $this->review_recovery_stream($io, $group['plans'], $group['count']);
				$apply_prompt = $this->language->lang(
					'CLI_TOPIC_RECOVERY_GROUP_APPLY',
					$group['count'],
					$reviewed,
					$group['count'] - $reviewed
				);
				if (!$io->confirm($apply_prompt, false))
				{
					continue;
				}

				rewind($group['plans']);
				while (($line = fgets($group['plans'])) !== false)
				{
					if (fwrite($approved_stream, $line) !== strlen($line))
					{
						throw new \RuntimeException('CLI_TOPIC_RECOVERY_FAILED');
					}
				}
				$approved += $group['count'];
				$approved_groups++;
			}

			if (!$approved)
			{
				$io->note($this->language->lang('CLI_TOPIC_TAGS_REPAIR_CANCELLED'));
				return false;
			}

			if (!$this->force)
			{
				$this->topic_repairer->assert_board_disabled();
			}
			if (!$io->confirm($this->language->lang('CLI_TOPIC_TAGS_REPAIR_BACKUP_CONFIRM'), false)
				|| !$io->confirm($this->language->lang('CLI_TOPIC_RECOVERY_APPLY', $approved), false))
			{
				$io->note($this->language->lang('CLI_TOPIC_TAGS_REPAIR_CANCELLED'));
				return false;
			}

			rewind($approved_stream);
			$io->progressStart($approved);
			$batch = [];
			while (($line = fgets($approved_stream)) !== false)
			{
				$plan = json_decode($line, true);
				if (!is_array($plan))
				{
					throw new \RuntimeException('CLI_TOPIC_RECOVERY_FAILED');
				}
				$batch[] = $plan;
				if (count($batch) === 100)
				{
					$this->apply_recovery_batch($batch, $counts, $repaired);
					$io->progressAdvance(count($batch));
					$batch = [];
				}
			}
			if ($batch)
			{
				$this->apply_recovery_batch($batch, $counts, $repaired);
				$io->progressAdvance(count($batch));
			}
			$io->progressFinish();

			$log_data = [
				$approved_groups,
				$repaired,
				$counts['assignments'],
				$counts['conflicts'],
			];
			if (!$this->audit('ACP_LOG_TOPIC_PREFIX_RECOVERY_SUMMARY', $log_data))
			{
				$io->error($this->language->lang('CLI_TOPIC_MAINTENANCE_LOG_FAILED'));
				return true;
			}
			$io->success($this->language->lang('CLI_TOPIC_RECOVERY_RESULT', $repaired, $counts['conflicts']));

			return $counts['conflicts'] > 0;
		}
		finally
		{
			fclose($approved_stream);
			fclose($ambiguous_stream);
			foreach ($groups as $group)
			{
				fclose($group['plans']);
			}
			if ($counts['assignments'] || $counts['topics'] || $counts['subjects'] || $counts['caches'])
			{
				$this->topic_repairer->invalidate_caches();
			}
			if ($counts['subjects'])
			{
				$io->warning($this->language->lang('CLI_TOPIC_TITLES_SEARCH'));
			}
		}
	}

	/**
	 * Display optional 20-row pages from one recovery group.
	 *
	 * @param SymfonyStyle $io     Console input/output helper
	 * @param resource     $stream Recovery plan stream
	 * @param int          $total  Number of plans in the stream
	 * @return int Number of plans shown
	 * @throws \RuntimeException When stored preview data is invalid
	 */
	protected function review_recovery_stream(SymfonyStyle $io, $stream, int $total): int
	{
		rewind($stream);
		$reviewed = 0;
		while ($reviewed < $total)
		{
			$page = [];
			while (count($page) < 20 && ($line = fgets($stream)) !== false)
			{
				$plan = json_decode($line, true);
				if (!is_array($plan))
				{
					throw new \RuntimeException('CLI_TOPIC_RECOVERY_FAILED');
				}
				$page[] = [
					$plan['topic_id'],
					$plan['forum_id'],
					OutputFormatter::escape(implode(', ', $plan['tag_names'])),
					OutputFormatter::escape(manager::decode_name($plan['before'])),
					OutputFormatter::escape(manager::decode_name($plan['after'])),
					$this->language->lang('CLI_TOPIC_RECOVERY_STATUS_' . strtoupper($plan['reason'])),
				];
			}
			if (!$page)
			{
				break;
			}

			$this->display_recovery_page($io, $page);
			$reviewed += count($page);
			if ($reviewed < $total && !$io->confirm($this->language->lang('CLI_TOPIC_TITLES_MORE'), false))
			{
				break;
			}
		}

		return $reviewed;
	}

	/**
	 * Write one recovery plan to a temporary stream.
	 *
	 * @param resource $stream Recovery plan stream
	 * @param array    $plan   Recovery plan
	 * @return void
	 * @throws \RuntimeException When encoding or writing fails
	 */
	protected function write_recovery_plan($stream, array $plan): void
	{
		$encoded = json_encode($plan);
		if ($encoded === false || fwrite($stream, $encoded . "\n") !== strlen($encoded) + 1)
		{
			throw new \RuntimeException('CLI_TOPIC_RECOVERY_FAILED');
		}
	}

	/**
	 * Display one automatic recovery preview page.
	 *
	 * @param SymfonyStyle $io   Console input/output helper
	 * @param array        $rows Preview table rows
	 * @return void
	 */
	protected function display_recovery_page(SymfonyStyle $io, array $rows): void
	{
		$io->table([
			$this->language->lang('CLI_TOPIC_TITLES_TOPIC'),
			$this->language->lang('CLI_TOPIC_RECOVERY_FORUM'),
			$this->language->lang('CLI_TOPIC_RECOVERY_TAGS'),
			$this->language->lang('CLI_TOPIC_TITLES_BEFORE'),
			$this->language->lang('CLI_TOPIC_TITLES_AFTER'),
			$this->language->lang('CLI_TOPIC_RECOVERY_STATUS'),
		], $rows);
	}

	/**
	 * Run existing literal title or reply cleanup inside the unified menu.
	 *
	 * @param SymfonyStyle   $io       Console input/output helper
	 * @param title_repairer $repairer Selected text repair service
	 * @param string         $kind     Language key group: TITLES or REPLIES
	 * @return bool Whether any repair conflicts occurred
	 * @throws \Throwable When preview storage, validation or a repair fails
	 */
	protected function repair_text(SymfonyStyle $io, title_repairer $repairer, string $kind): bool
	{
		$stream = tmpfile();
		if ($stream === false)
		{
			throw new \RuntimeException('CLI_TOPIC_' . $kind . '_FAILED');
		}

		$counts = [
			'topics' => 0,
			'subjects' => 0,
			'caches' => 0,
			'skipped' => 0,
			'conflicts' => 0,
		];
		try
		{
			$prefix = $io->ask($this->text($kind, 'PREFIX'), null, function ($answer) {
				$answer = (string) $answer;
				if (isset($answer[0]) && $answer[0] === '"')
				{
					$decoded = json_decode($answer, true);
					if (!is_string($decoded))
					{
						throw new \InvalidArgumentException($this->language->lang('CLI_TOPIC_TITLES_INVALID_PREFIX'));
					}
					$answer = $decoded;
				}

				try
				{
					title_repairer::validate_prefix($answer);
				}
				catch (\InvalidArgumentException $e)
				{
					throw new \InvalidArgumentException($this->language->lang($e->getMessage()));
				}

				return $answer;
			});

			$forums = $repairer->get_forums();
			if (!$forums)
			{
				$io->note($this->language->lang('CLI_TOPIC_TITLES_NO_FORUMS'));
				return false;
			}

			$choices = [];
			foreach ($forums as $id => $forum)
			{
				$choices[$id] = OutputFormatter::escape($forum['label']);
			}
			$selected = $io->choice($this->language->lang('CLI_TOPIC_TITLES_FORUM'), $choices);
			$forum_id = (int) array_search($selected, $choices, true);
			$forum_label = OutputFormatter::escape($forums[$forum_id]['plain_label']);
			$prefix_label = $this->format_prefix_label($prefix);
			$io->section($this->language->lang('CLI_TOPIC_TITLES_SELECTION', $prefix_label, $forum_label));

			$page = [];
			$review = true;
			$reviewed = 0;
			$matches = 0;
			$eligible = 0;
			foreach ($repairer->preview($forum_id, $prefix) as $plan)
			{
				$matches++;
				$eligible += (int) !empty($plan['changes']);
				$encoded = json_encode($plan);
				if ($encoded === false || fwrite($stream, $encoded . "\n") !== strlen($encoded) + 1)
				{
					throw new \RuntimeException('CLI_TOPIC_' . $kind . '_FAILED');
				}
				if ($review)
				{
					$page[] = $this->text_preview_row($plan, $kind);
					if (count($page) === 20)
					{
						$this->display_text_page($io, $page, $kind);
						$reviewed += count($page);
						$page = [];
						$review = $io->confirm($this->language->lang('CLI_TOPIC_TITLES_MORE'), false);
					}
				}
			}

			if ($page)
			{
				$this->display_text_page($io, $page, $kind);
				$reviewed += count($page);
			}

			$io->note($this->text($kind, 'MATCHES', $matches, $eligible));
			if (!$eligible)
			{
				return false;
			}

			$preview_counts = $this->language->lang(
				'CLI_TOPIC_PREVIEW_COUNTS',
				$matches,
				$reviewed,
				$matches - $reviewed
			);
			$io->note($preview_counts);
			$io->warning($this->text($kind, 'WARNING'));
			if (!$io->confirm($this->language->lang('CLI_TOPIC_TITLES_ORIGIN', $prefix_label), false))
			{
				return false;
			}

			if (!$this->force)
			{
				$repairer->assert_board_disabled();
			}
			if (!$io->confirm($this->language->lang('CLI_TOPIC_TAGS_REPAIR_BACKUP_CONFIRM'), false)
				|| !$io->confirm($this->text($kind, 'APPLY', $prefix_label, $forum_label, $eligible), false))
			{
				$io->note($this->language->lang('CLI_TOPIC_TAGS_REPAIR_CANCELLED'));
				return false;
			}

			rewind($stream);
			$io->progressStart($eligible);
			$batch = [];
			while (($line = fgets($stream)) !== false)
			{
				$plan = json_decode($line, true);
				if (!is_array($plan) || !$plan['changes'])
				{
					if (is_array($plan))
					{
						$counts['skipped'] += $plan['skipped'];
						continue;
					}
					throw new \RuntimeException('CLI_TOPIC_' . $kind . '_FAILED');
				}

				$batch[] = $plan;
				if (count($batch) === 100)
				{
					$this->apply_text_batch($repairer, $batch, $counts);
					$io->progressAdvance(count($batch));
					$batch = [];
				}
			}
			if ($batch)
			{
				$this->apply_text_batch($repairer, $batch, $counts);
				$io->progressAdvance(count($batch));
			}
			$io->progressFinish();

			$not_changed = $counts['skipped'] + $counts['conflicts'];
			$changed = $kind === 'REPLIES' ? $counts['subjects'] : $counts['topics'];
			$log_key = $kind === 'REPLIES'
				? 'ACP_LOG_TOPIC_PREFIX_REPLY_SUMMARY'
				: 'ACP_LOG_TOPIC_PREFIX_TITLE_SUMMARY';
			$log_data = [
				utf8_encode_ucr(utf8_htmlspecialchars($prefix)),
				$forum_id,
				$changed,
				$not_changed,
			];
			if (!$this->audit($log_key, $log_data))
			{
				$io->error($this->language->lang('CLI_TOPIC_MAINTENANCE_LOG_FAILED'));
				return true;
			}

			$io->success($this->text($kind, 'RESULT', $changed, $not_changed));

			return $counts['conflicts'] > 0;
		}
		finally
		{
			fclose($stream);
			if ($counts['topics'] || $counts['subjects'] || $counts['caches'])
			{
				$repairer->invalidate_caches();
			}
			if ($counts['subjects'])
			{
				$io->warning($this->language->lang('CLI_TOPIC_TITLES_SEARCH'));
			}
		}
	}

	/**
	 * Format one topic title or reply subject preview row.
	 *
	 * @param array  $plan Repair snapshot
	 * @param string $kind Language key group: TITLES or REPLIES
	 * @return array Escaped preview table row
	 */
	protected function text_preview_row(array $plan, string $kind): array
	{
		if ($kind === 'REPLIES')
		{
			return [
				$plan['topic_id'],
				$plan['post_id'],
				OutputFormatter::escape(manager::decode_name($plan['title'])),
				OutputFormatter::escape(manager::decode_name($plan['before'])),
				OutputFormatter::escape(manager::decode_name($plan['after'])),
				$this->text($kind, 'STATUS_' . strtoupper($plan['reason'])),
			];
		}

		$status = $plan['skipped']
			? $this->language->lang('CLI_TOPIC_TITLES_STATUS_BLANK')
			: $this->language->lang('CLI_TOPIC_TITLES_STATUS_READY');
		return [
			$plan['topic_id'],
			OutputFormatter::escape(manager::decode_name($plan['before'])),
			OutputFormatter::escape(manager::decode_name($plan['after'])),
			$status,
		];
	}

	/**
	 * Display one topic title or reply subject preview page.
	 *
	 * @param SymfonyStyle $io   Console input/output helper
	 * @param array        $rows Preview table rows
	 * @param string       $kind Language key group: TITLES or REPLIES
	 * @return void
	 */
	protected function display_text_page(SymfonyStyle $io, array $rows, string $kind): void
	{
		$headers = $kind === 'REPLIES'
			? [
				$this->language->lang('CLI_TOPIC_TITLES_TOPIC'),
				$this->text($kind, 'POST'),
				$this->text($kind, 'TITLE'),
				$this->language->lang('CLI_TOPIC_TITLES_BEFORE'),
				$this->language->lang('CLI_TOPIC_TITLES_AFTER'),
				$this->text($kind, 'STATUS'),
			]
			: [
				$this->language->lang('CLI_TOPIC_TITLES_TOPIC'),
				$this->language->lang('CLI_TOPIC_TITLES_BEFORE'),
				$this->language->lang('CLI_TOPIC_TITLES_AFTER'),
				$this->language->lang('CLI_TOPIC_TITLES_SKIPPED'),
			];
		$io->table($headers, $rows);
	}

	/**
	 * Translate a topic title or reply subject language key.
	 *
	 * @param string $kind         Language key group: TITLES or REPLIES
	 * @param string $suffix       Language key suffix
	 * @param mixed  ...$arguments Language substitution arguments
	 * @return string Translated text
	 */
	protected function text(string $kind, string $suffix, ...$arguments): string
	{
		return $this->language->lang('CLI_TOPIC_' . $kind . '_' . $suffix, ...$arguments);
	}

	/**
	 * Format exact prefix text for safe terminal display.
	 *
	 * Printable UTF-8 remains unchanged. Quotes, backslashes and characters that
	 * could alter terminal layout are escaped inside double quotes.
	 *
	 * @param string $prefix Display-form literal prefix
	 * @return string Escaped quoted prefix
	 * @throws \RuntimeException When the prefix cannot be encoded
	 */
	protected function format_prefix_label(string $prefix): string
	{
		$prefix = json_encode($prefix, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($prefix === false)
		{
			throw new \RuntimeException('CLI_TOPIC_TITLES_INVALID_PREFIX');
		}

		return OutputFormatter::escape($prefix);
	}

	/**
	 * Apply one recovery batch and merge its counts.
	 *
	 * @param array $batch    Confirmed recovery snapshots
	 * @param array $counts   Running totals, updated by reference
	 * @param int   $repaired Repaired topic count, updated by reference
	 * @return void
	 * @throws \Throwable When a repair fails
	 */
	protected function apply_recovery_batch(array $batch, array &$counts, int &$repaired): void
	{
		$result = $this->topic_repairer->apply_batch($batch, false, $this->force);
		$repaired += count($batch) - $result['conflicts'];
		foreach ($counts as $key => $count)
		{
			$counts[$key] += $result[$key];
		}
	}

	/**
	 * Apply one text repair batch and merge its counts.
	 *
	 * @param title_repairer $repairer Selected text repair service
	 * @param array          $batch    Confirmed repair snapshots
	 * @param array          $counts   Running totals, updated by reference
	 * @return void
	 * @throws \Throwable When a repair fails
	 */
	protected function apply_text_batch(title_repairer $repairer, array $batch, array &$counts): void
	{
		$result = $repairer->apply_batch($batch, false, $this->force);
		foreach ($counts as $key => $count)
		{
			$counts[$key] += $result[$key];
		}
	}

	/**
	 * Write one administrator audit entry.
	 *
	 * @param string $key  Language key for the log entry
	 * @param array  $data Language substitution data
	 * @return bool Whether the log entry was written
	 */
	protected function audit(string $key, array $data): bool
	{
		return $this->log->add('admin', ANONYMOUS, '', $key, time(), $data) !== false;
	}
}
