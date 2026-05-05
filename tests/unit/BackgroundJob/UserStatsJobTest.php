<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\NcwTools\BackgroundJob\UserStatsJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class UserStatsJobTest extends TestCase {
	private LoggerInterface&MockObject $logger;
	private ITimeFactory&MockObject $timeFactory;
	private IUserManager&MockObject $userManager;
	private UserStatsJob $job;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getDateTime')->willReturn(new DateTime('2026-01-01T00:00:00.000 UTC'));
		$this->userManager = $this->createMock(IUserManager::class);

		$this->job = new UserStatsJob(
			$this->logger,
			$this->timeFactory,
			$this->userManager,
		);
	}

	public function testRunLogsPayloadOnSuccess(): void {
		$this->userManager->method('countUsersTotal')->willReturn(42);

		$this->logger->expects($this->once())
			->method('info')
			->with(
				'User stats payload',
				$this->callback(function (array $context): bool {
					return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $context['payload']['timestamp']) === 1
						&& $context['payload']['users']['existingUsers'] === 42;
				})
			);

		$this->logger->expects($this->never())->method('warning');

		$this->invokePrivate($this->job, 'run', [null]);
	}

	public function testRunLogsWarningWhenCountFalse(): void {
		$this->userManager->method('countUsersTotal')->willReturn(false);

		$this->logger->expects($this->once())
			->method('warning')
			->with('UserStatsJob: could not retrieve user count');

		$this->logger->expects($this->never())->method('info');

		$this->invokePrivate($this->job, 'run', [null]);
	}
}
