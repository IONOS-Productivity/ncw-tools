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
use OCP\Install\Events\InstallationCompletedEvent;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<InstallationCompletedEvent>
 */
class InstallationCompletedEventListener implements IEventListener {

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
		if (!$event instanceof InstallationCompletedEvent) {
			return;
		}

		$this->appConfig->setValueString(Application::APP_ID, PostSetupJob::JOB_STATUS_CONFIG_KEY, PostSetupJob::JOB_STATUS_INIT);

		$adminUserId = $event->getAdminUsername();
		if ($adminUserId === null) {
			$this->logger->warning('No admin user provided in InstallationCompletedEvent');
			return;
		}

		$this->logger->info('Scheduling welcome email job', ['adminUserId' => $adminUserId]);
		$this->jobList->add(PostSetupJob::class, $adminUserId);
		$this->logger->debug('Welcome email job scheduled successfully', ['adminUserId' => $adminUserId]);
	}
}
