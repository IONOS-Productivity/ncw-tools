<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class UserStatsJob extends QueuedJob {

	public function __construct(
		private LoggerInterface $logger,
		private ITimeFactory $timeFactory,
		private IUserManager $userManager,
	) {
		parent::__construct($timeFactory);
	}

	protected function run(mixed $argument): void {
		$userTotalCount = $this->userManager->countUsersTotal();
		if ($userTotalCount === false) {
			$this->logger->warning('UserStatsJob: could not retrieve user count');
			return;
		}

		$payload = [
			'timestamp' => $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
			'users' => ['existingUsers' => $userTotalCount],
		];

		$this->logger->info('User stats payload', ['payload' => $payload]);
	}
}
