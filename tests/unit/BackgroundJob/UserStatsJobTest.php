<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Tests\Unit\BackgroundJob;

use DateTime;
use GuzzleHttp\Client;
use IONOS\NextcloudPSS\AddonsAPI\Client\Api\StatsAPIApi;
use IONOS\NextcloudPSS\AddonsAPI\Client\Model\StatsUpdateRequest;
use OCA\NcwTools\BackgroundJob\UserStatsJob;
use OCA\NcwTools\Service\ApiStatsClientService;
use OCA\NcwTools\Service\PssConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
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
		$this->apiClientService->method('newClient')->willReturn($this->createMock(Client::class));
		$this->apiClientService->method('newStatsAPIApi')->willReturn($this->statsApi);

		$this->configService = $this->createMock(PssConfigService::class);
		$this->configService->method('getBrand')->willReturn('IONOS');
		$this->configService->method('getExtRef')->willReturn('test-ext-ref');
		$this->configService->method('getBaseUrl')->willReturn('https://pss.example.com');
		$this->configService->method('getUsername')->willReturn('user');
		$this->configService->method('getPassword')->willReturn('pass');

		$this->job = new UserStatsJob(
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
			->with('User stats payload', $this->callback(function (array $context): bool {
				return $context['payload']['users']['existingUsers'] === 42
					&& preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $context['payload']['timestamp']) === 1;
			}));

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

	public function testRunLogsErrorWhenRequiredConfigMissing(): void {
		$this->userManager->method('countUsersTotal')->willReturn(5);

		$this->configService = $this->createMock(PssConfigService::class);
		$this->configService->method('getBrand')->willReturn('');
		$this->configService->method('getExtRef')->willReturn('test-ext-ref');
		$this->configService->method('getBaseUrl')->willReturn('https://pss.example.com');
		$this->configService->method('getUsername')->willReturn('user');
		$this->configService->method('getPassword')->willReturn('pass');

		$this->job = new UserStatsJob(
			$this->logger,
			$this->timeFactory,
			$this->userManager,
			$this->apiClientService,
			$this->configService,
		);

		$this->logger->expects($this->once())
			->method('error')
			->with('UserStatsJob: missing required PSS configuration, aborting');

		$this->statsApi->expects($this->never())->method('updateStats');

		$this->invokePrivate($this->job, 'run', [null]);
	}

	public function testRunLogsErrorWhenCredentialsMissing(): void {
		$this->userManager->method('countUsersTotal')->willReturn(5);

		$this->configService = $this->createMock(PssConfigService::class);
		$this->configService->method('getBrand')->willReturn('IONOS');
		$this->configService->method('getExtRef')->willReturn('test-ext-ref');
		$this->configService->method('getBaseUrl')->willReturn('https://pss.example.com');
		$this->configService->method('getUsername')->willReturn('');
		$this->configService->method('getPassword')->willReturn('pass');

		$this->job = new UserStatsJob(
			$this->logger,
			$this->timeFactory,
			$this->userManager,
			$this->apiClientService,
			$this->configService,
		);

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
