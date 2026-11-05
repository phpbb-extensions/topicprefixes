<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2016 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\tests\system;

class simple_test extends \phpbb_test_case
{
	/** @var \PHPUnit\Framework\MockObject\MockObject|\Symfony\Component\DependencyInjection\ContainerInterface */
	protected $container;

	/** @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\finder */
	protected $extension_finder;

	/** @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\db\migrator */
	protected $migrator;

	/** @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\db\tools\tools_interface */
	protected $db_tools;

	/** @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\extension\manager */
	protected $extension_manager;

	/** @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\language\language */
	protected $language;

	/**
	 * @inheritdoc
	 */
	protected function setUp(): void
	{
		parent::setUp();

		// Stub the container
		$this->container = $this->getMockBuilder('\Symfony\Component\DependencyInjection\ContainerInterface')
			->disableOriginalConstructor()
			->getMock();

		// Stub the ext finder and disable its constructor
		$this->extension_finder = $this->getMockBuilder('\phpbb\finder')
			->disableOriginalConstructor()
			->getMock();

		// Stub the migrator and disable its constructor
		$this->migrator = $this->getMockBuilder('\phpbb\db\migrator')
			->disableOriginalConstructor()
			->getMock();

		$this->db_tools = $this->createMock('\phpbb\db\tools\tools_interface');

		$this->extension_manager = $this->getMockBuilder('\phpbb\extension\manager')
			->disableOriginalConstructor()
			->getMock();

		$this->language = $this->getMockBuilder('\phpbb\language\language')
			->disableOriginalConstructor()
			->getMock();
	}

	/**
	 * Test the extension can only be enabled when the minimum
	 * phpBB version requirement is satisfied.
	 */
	public function test_ext()
	{
		$this->configure_container(false, false);

		self::assertTrue($this->create_extension()->is_enableable(), 'Asserting that the extension is enableable.');
	}

	public function test_legacy_upgrade_requires_disabled_board()
	{
		$message = 'Back up database and disable board.';
		$this->configure_container(true, false, serialize(false));
		$this->language->expects(self::once())
			->method('add_lang')
			->with('info_acp_topic_prefixes', 'phpbb/topicprefixes');
		$this->language->expects(self::once())
			->method('lang')
			->with('TOPIC_PREFIXES_UPGRADE_BOARD_ENABLED')
			->willReturn($message);

		self::assertSame($message, $this->create_extension()->is_enableable());
	}

	public function test_legacy_upgrade_without_extension_state_requires_disabled_board()
	{
		$message = 'Back up database and disable board.';
		$this->configure_container(true, false);
		$this->language->expects(self::once())
			->method('add_lang')
			->with('info_acp_topic_prefixes', 'phpbb/topicprefixes');
		$this->language->expects(self::once())
			->method('lang')
			->with('TOPIC_PREFIXES_UPGRADE_BOARD_ENABLED')
			->willReturn($message);

		self::assertSame($message, $this->create_extension()->is_enableable());
	}

	public function test_unsupported_runtime_is_not_enableable(): void
	{
		$extension = new unsupported_runtime_ext(
			$this->container,
			$this->extension_finder,
			$this->migrator,
			'phpbb/topicprefixes',
			''
		);

		self::assertFalse($extension->is_enableable());
	}

	public function test_legacy_upgrade_allows_disabled_board()
	{
		$this->configure_container(true, true, serialize(false));

		self::assertTrue($this->create_extension()->is_enableable());
	}

	public function test_fresh_install_allows_enabled_board()
	{
		$this->configure_container(false, false);

		self::assertTrue($this->create_extension()->is_enableable());
	}

	public function test_fresh_install_in_progress_allows_enabled_board()
	{
		$this->configure_container(true, false, serialize(true));

		self::assertTrue($this->create_extension()->is_enableable());
	}

	public function test_completed_upgrade_allows_enabled_board()
	{
		$this->configure_container(false, false, serialize(false));

		self::assertTrue($this->create_extension()->is_enableable());
	}

	protected function configure_container(bool $legacy_column, bool $board_disabled, ?string $extension_state = null): void
	{
		$configured = [];
		if ($extension_state !== null)
		{
			$configured['phpbb/topicprefixes'] = [
				'ext_state' => $extension_state,
			];
		}

		$this->db_tools->method('sql_column_exists')
			->with('phpbb_topics', 'topic_prefix_id')
			->willReturn($legacy_column);
		$this->extension_manager->method('all_configured')
			->with(false)
			->willReturn($configured);
		$this->container->method('getParameter')
			->with('tables.topics')
			->willReturn('phpbb_topics');
		$services = [
			'config' => new \phpbb\config\config(['board_disable' => $board_disabled]),
			'dbal.tools' => $this->db_tools,
			'ext.manager' => $this->extension_manager,
			'language' => $this->language,
		];
		$this->container->method('get')
			->willReturnCallback(function ($service) use ($services) {
				return $services[$service];
			});
	}

	protected function create_extension(): \phpbb\topicprefixes\ext
	{
		return new \phpbb\topicprefixes\ext(
			$this->container,
			$this->extension_finder,
			$this->migrator,
			'phpbb/topicprefixes',
			''
		);
	}
}

class unsupported_runtime_ext extends \phpbb\topicprefixes\ext
{
	protected function meets_requirements(): bool
	{
		return false;
	}
}
