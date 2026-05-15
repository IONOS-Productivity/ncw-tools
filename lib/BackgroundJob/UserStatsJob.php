<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\BackgroundJob;

use IONOS\NextcloudPSS\AddonsAPI\Client\Model\StatsUpdateRequest;
use IONOS\NextcloudPSS\AddonsAPI\Client\Model\UserStats;
use OCA\NcwTools\Service\ApiStatsClientService;
use OCA\NcwTools\Service\PssConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class UserStatsJob extends QueuedJob {

	public function __construct(
		private LoggerInterface $logger,
		private ITimeFactory $timeFactory,
		private IUserManager $userManager,
		private ApiStatsClientService $apiClientService,
		private PssConfigService $configService,
	) {
		parent::__construct($timeFactory);
	}

	protected function run(mixed $argument): void {
		$userTotalCount = $this->userManager->countUsersTotal();
		if ($userTotalCount === false) {
			$this->logger->warning('UserStatsJob: could not retrieve user count');
			return;
		}

		$brand = $this->configService->getBrand();
		$extRef = $this->configService->getExtRef();
		$baseUrl = $this->configService->getBaseUrl();
		$username = $this->configService->getUsername();
		$password = $this->configService->getPassword();

		if ($brand === '' || $extRef === '' || $baseUrl === '' || $username === '' || $password === '') {
			$this->logger->error('UserStatsJob: missing required PSS configuration, aborting');
			return;
		}

		$timestamp = $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'));

		$userStats = new UserStats();
		$userStats->setExistingUsers($userTotalCount);

		$request = new StatsUpdateRequest();
		$request->setTimestamp($timestamp);
		$request->setUsers($userStats);

		$this->logger->info('UserStatsJob: pushing user stats', [
			'existingUsers' => $userTotalCount,
			'timestamp' => $timestamp->format('Y-m-d\TH:i:s.v\Z'),
		]);

		try {
			$api = $this->apiClientService->newStatsAPIApi($baseUrl, $username, $password);
			$api->updateStats($brand, $extRef, $request);
		} catch (\Throwable $e) {
			$this->logger->error('UserStatsJob: failed to push stats to PSS', [
				'exception' => $e,
			]);
		}
	}
}
