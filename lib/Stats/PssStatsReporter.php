<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Stats;

use IONOS\NextcloudPSS\AddonsAPI\Client\Model\StatsUpdateRequest;
use IONOS\NextcloudPSS\AddonsAPI\Client\Model\UserStats;
use Psr\Log\LoggerInterface;

class PssStatsReporter implements StatsReporter {
	public function __construct(
		private PssConfigReader $configReader,
		private PssApiFactory $apiFactory,
		private LoggerInterface $logger,
	) {
	}

	public function reportUserCount(int $count, \DateTimeInterface $at): void {
		$config = $this->configReader->read();
		if ($config === null) {
			return;
		}

		$userStats = new UserStats();
		$userStats->setExistingUsers($count);

		$request = new StatsUpdateRequest();
		$request->setTimestamp($at instanceof \DateTime ? $at : \DateTime::createFromInterface($at));
		$request->setUsers($userStats);

		$api = $this->apiFactory->newStatsApi(
			$config->baseUrl,
			$config->username,
			$config->getPassword(),
		);

		// Narrow the catch to just updateStats() and log message-only (not the
		// full exception). Deep vendor frames can carry credential strings in
		// their stack-trace args; PHP 8.1 has no #[\SensitiveParameter] to
		// scrub them. We trade trace richness for credential safety.
		try {
			$api->updateStats($config->brand, $config->extRef, $request);
		} catch (\Throwable $e) {
			$this->logger->error('PssStatsReporter: failed to push stats to PSS', [
				'exceptionClass' => $e::class,
				'message' => $e->getMessage(),
			]);
			return;
		}

		$this->logger->info('PssStatsReporter: pushed user stats', [
			'existingUsers' => $count,
			'timestamp' => $at->format('Y-m-d\TH:i:s.v\Z'),
		]);
	}
}
