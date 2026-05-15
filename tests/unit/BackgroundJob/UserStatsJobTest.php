<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\NcwTools\BackgroundJob\UserStatsJob;
use OCA\NcwTools\Stats\StatsReporter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class UserStatsJobTest extends TestCase {
	private LoggerInterface&MockObject $logger;
	private ITimeFactory&MockObject $timeFactory;
	private IUserManager&MockObject $userManager;
	private StatsReporter&MockObject $reporter;
	private UserStatsJob $job;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->reporter = $this->createMock(StatsReporter::class);

		$this->job = new UserStatsJob(
			$this->logger,
			$this->timeFactory,
			$this->userManager,
			$this->reporter,
		);
	}

	public function testRunDelegatesToReporterOnSuccess(): void {
		$now = new DateTime('2026-01-01T00:00:00.000', new \DateTimeZone('UTC'));
		$this->userManager->method('countUsersTotal')->willReturn(42);
		$this->timeFactory->method('getDateTime')->willReturn($now);

		$this->reporter->expects($this->once())
			->method('reportUserCount')
			->with(42, $now);

		$this->logger->expects($this->never())->method('warning');
		$this->logger->expects($this->never())->method('error');

		$this->invokePrivate($this->job, 'run', [null]);
	}

	public function testRunLogsWarningAndSkipsReporterWhenCountFalse(): void {
		$this->userManager->method('countUsersTotal')->willReturn(false);

		$this->logger->expects($this->once())
			->method('warning')
			->with('UserStatsJob: could not retrieve user count');

		$this->reporter->expects($this->never())->method('reportUserCount');

		$this->invokePrivate($this->job, 'run', [null]);
	}
}
