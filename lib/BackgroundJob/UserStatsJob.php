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

		if ($brand === '' || $extRef === '' || $baseUrl === '') {
			$this->logger->error('UserStatsJob: missing required PSS configuration, aborting');
			return;
		}

		$timestamp = $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');

		$payload = [
			'timestamp' => $timestamp,
			'users' => ['existingUsers' => $userTotalCount],
		];
		$this->logger->info('User stats payload', ['payload' => $payload]);

		$userStats = new UserStats();
		$userStats->setExistingUsers($userTotalCount);

		try {
			$request = new StatsUpdateRequest();
			$request->setTimestamp(new \DateTime($timestamp));
			$request->setUsers($userStats);

			$client = $this->apiClientService->newClient();
			$api = $this->apiClientService->newStatsAPIApi(
				$client,
				$baseUrl,
				$this->configService->getUsername(),
				$this->configService->getPassword(),
			);
			$api->updateStats($brand, $extRef, $request);
		} catch (\Throwable $e) {
			$this->logger->error('UserStatsJob: failed to push stats to PSS', [
				'exception' => $e->getMessage(),
			]);
		}
	}
}
