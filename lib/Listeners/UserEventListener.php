<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Listeners;

use OCA\NcwTools\BackgroundJob\UserStatsJob;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserCreatedEvent;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<UserCreatedEvent|UserDeletedEvent>
 */
class UserEventListener implements IEventListener {

	public function __construct(
		private LoggerInterface $logger,
		private IJobList $jobList,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserCreatedEvent && !$event instanceof UserDeletedEvent) {
			return;
		}

		if ($event instanceof UserCreatedEvent) {
			$this->logger->info('User added', ['uid' => $event->getUid()]);
		} else {
			$this->logger->info('User deleted', ['uid' => $event->getUid()]);
		}

		if (!$this->jobList->has(UserStatsJob::class, null)) {
			$this->jobList->add(UserStatsJob::class);
		}
	}
}
