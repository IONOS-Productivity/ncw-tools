<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Tests\Unit\BackgroundJob;

use DateTime;
use IONOS\NextcloudPSS\AddonsAPI\Client\Api\StatsAPIApi;
use IONOS\NextcloudPSS\AddonsAPI\Client\Model\StatsUpdateRequest;
use OCA\NcwTools\BackgroundJob\UserStatsJob;
use OCA\NcwTools\Service\ApiStatsClientService;
use OCA\NcwTools\Service\PssConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class UserStatsJobTest extends TestCase {
	private LoggerInterface&MockObject $logger;
	private ITimeFactory&MockObject $timeFactory;
	private IUserManager&MockObject $userManager;
	private ApiStatsClientService&MockObject $apiClientService;
	private PssConfigService&MockObject $configService;
	private StatsAPIApi&MockObject $statsApi;
	private UserStatsJob $job;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getDateTime')->willReturn(new DateTime('2026-01-01T00:00:00.000 UTC'));
		$this->userManager = $this->createMock(IUserManager::class);

		$this->statsApi = $this->createMock(StatsAPIApi::class);
		$this->apiClientService = $this->createMock(ApiStatsClientService::class);
		$this->apiClientService->method('newStatsAPIApi')->willReturn($this->statsApi);

		$this->configService = $this->createConfigService('IONOS', 'test-ext-ref', 'https://pss.example.com', 'user', 'pass');

		$this->job = $this->buildJob();
	}

	private function createConfigService(string $brand, string $extRef, string $baseUrl, string $username, string $password): PssConfigService&MockObject {
		$config = $this->createMock(PssConfigService::class);
		$config->method('getBrand')->willReturn($brand);
		$config->method('getExtRef')->willReturn($extRef);
		$config->method('getBaseUrl')->willReturn($baseUrl);
		$config->method('getUsername')->willReturn($username);
		$config->method('getPassword')->willReturn($password);
		return $config;
	}

	private function buildJob(): UserStatsJob {
		return new UserStatsJob(
			$this->logger,
			$this->timeFactory,
			$this->userManager,
			$this->apiClientService,
			$this->configService,
		);
	}

	public function testRunCallsApiWithCorrectPayload(): void {
		$this->userManager->method('countUsersTotal')->willReturn(42);

		$this->logger->expects($this->once())
			->method('info')
			->with('UserStatsJob: pushing user stats', $this->callback(function (array $context): bool {
				return $context['existingUsers'] === 42
					&& preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $context['timestamp']) === 1;
			}));
		$this->logger->expects($this->never())->method('error');
		$this->logger->expects($this->never())->method('warning');

		$this->statsApi->expects($this->once())
			->method('updateStats')
			->with(
				'IONOS',
				'test-ext-ref',
				$this->callback(function (StatsUpdateRequest $req): bool {
					return $req->getUsers()?->getExistingUsers() === 42;
				})
			);

		$this->invokePrivate($this->job, 'run', [null]);
	}

	public function testRunLogsErrorWhenApiThrows(): void {
		$this->userManager->method('countUsersTotal')->willReturn(42);
		$this->statsApi->method('updateStats')->willThrowException(new \Exception('connection refused'));

		$this->logger->expects($this->once())
			->method('error')
			->with('UserStatsJob: failed to push stats to PSS', $this->callback(function (array $ctx): bool {
				return $ctx['exception'] instanceof \Exception
					&& str_contains($ctx['exception']->getMessage(), 'connection refused');
			}));

		$this->invokePrivate($this->job, 'run', [null]);
	}

	public static function provideMissingConfig(): array {
		return [
			'missing brand' => ['', 'test-ext-ref', 'https://pss.example.com', 'user', 'pass'],
			'missing ext_ref' => ['IONOS', '', 'https://pss.example.com', 'user', 'pass'],
			'missing baseUrl' => ['IONOS', 'test-ext-ref', '', 'user', 'pass'],
			'missing username' => ['IONOS', 'test-ext-ref', 'https://pss.example.com', '', 'pass'],
			'missing password' => ['IONOS', 'test-ext-ref', 'https://pss.example.com', 'user', ''],
		];
	}

	#[DataProvider('provideMissingConfig')]
	public function testRunLogsErrorWhenConfigMissing(string $brand, string $extRef, string $baseUrl, string $username, string $password): void {
		$this->userManager->method('countUsersTotal')->willReturn(5);
		$this->configService = $this->createConfigService($brand, $extRef, $baseUrl, $username, $password);
		$this->job = $this->buildJob();

		$this->logger->expects($this->once())
			->method('error')
			->with('UserStatsJob: missing required PSS configuration, aborting');

		$this->statsApi->expects($this->never())->method('updateStats');

		$this->invokePrivate($this->job, 'run', [null]);
	}

	public function testRunLogsWarningWhenCountFalse(): void {
		$this->userManager->method('countUsersTotal')->willReturn(false);

		$this->logger->expects($this->once())
			->method('warning')
			->with('UserStatsJob: could not retrieve user count');

		$this->statsApi->expects($this->never())->method('updateStats');

		$this->invokePrivate($this->job, 'run', [null]);
	}
}
