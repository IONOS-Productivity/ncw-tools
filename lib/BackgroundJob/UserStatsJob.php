<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\BackgroundJob;

use OCA\NcwTools\Stats\StatsReporter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class UserStatsJob extends QueuedJob {

	public function __construct(
		private LoggerInterface $logger,
		ITimeFactory $timeFactory,
		private IUserManager $userManager,
		private StatsReporter $reporter,
	) {
		parent::__construct($timeFactory);
	}

	protected function run(mixed $argument): void {
		$count = $this->userManager->countUsersTotal();
		if ($count === false) {
			$this->logger->warning('UserStatsJob: could not retrieve user count');
			return;
		}
		$this->reporter->reportUserCount(
			$count,
			$this->time->getDateTime('now', new \DateTimeZone('UTC')),
		);
	}
}
