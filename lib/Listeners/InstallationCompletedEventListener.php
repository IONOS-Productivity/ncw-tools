<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Listeners;

use OCA\NcwTools\AppInfo\Application;
use OCA\NcwTools\BackgroundJob\PostSetupJob;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event>
 */
class InstallationCompletedEventListener implements IEventListener {
	private const ADMIN_CONFIG_PATH = '/vault/secrets/adminconfig';
	private const ADMIN_USER_KEY = 'NEXTCLOUD_ADMIN_USER';

	private string $adminConfigPath = self::ADMIN_CONFIG_PATH;

	private array $quotesArray = ['\\\'', '"', '\''];

	/**
	 * @psalm-suppress PossiblyUnusedMethod - Constructor called by DI container
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private IJobList $jobList,
	) {
	}

	public function handle(Event $event): void {

		$this->appConfig->setValueString(Application::APP_ID, PostSetupJob::JOB_STATUS_CONFIG_KEY, PostSetupJob::JOB_STATUS_INIT);

		$this->logger->debug('post Setup: init admin user');
		$adminUserId = $this->initAdminUser();
		$this->logger->debug('post Setup: admin user configured');

		$this->logger->debug('post Setup: add send initial welcome mail job');
		$this->jobList->add(PostSetupJob::class, $adminUserId);
		$this->logger->debug('post Setup: job added');
	}


	protected function initAdminUser(): string {

		// Read the configuration file line by line
		$adminConfigLines = file($this->adminConfigPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

		// Initialize the associative array for the configuration
		$adminConfig = [];

		// Iterate through the lines and extract the variables
		foreach ($adminConfigLines as $line) {
			$parts = explode('=', $line, 2);
			if (count($parts) === 2) {
				[$key, $value] = $parts;
				$adminConfig[trim($key)] = trim($value);
			}
		}

		$adminUser = $adminConfig[self::ADMIN_USER_KEY] ?? '';
		/** @psalm-suppress MixedArgumentTypeCoercion */
		return str_replace($this->quotesArray, '', $adminUser);
	}
}
