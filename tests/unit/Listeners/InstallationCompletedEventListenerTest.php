<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Tests\Unit\Listeners;

use OCA\NcwTools\BackgroundJob\PostSetupJob;
use OCA\NcwTools\Listeners\InstallationCompletedEventListener;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use OCP\Install\Events\InstallationCompletedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class InstallationCompletedEventListenerTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private LoggerInterface&MockObject $logger;
	private IJobList&MockObject $jobList;
	private InstallationCompletedEventListener $listener;

	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->jobList = $this->createMock(IJobList::class);

		$this->listener = new InstallationCompletedEventListener(
			$this->appConfig,
			$this->logger,
			$this->jobList
		);
	}

	public function testHandleSetsAppConfigAndAddsJob(): void {
		$event = new InstallationCompletedEvent(
			'/var/www/html/data',
			'admin',
			'admin@example.com'
		);

		// Expect app config to be set for 'post_install'
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('ncw_tools', 'post_install', 'INIT');

		// Expect job to be added
		$this->jobList->expects($this->once())
			->method('add')
			->with(PostSetupJob::class, 'admin');

		// Expect debug logs
		$this->logger->expects($this->atLeastOnce())
			->method('debug');

		$this->listener->handle($event);
	}

	public function testHandleWithoutAdminUser(): void {
		$event = new InstallationCompletedEvent(
			'/var/www/html/data'
		);

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('ncw_tools', 'post_install', 'INIT');

		// Expect warning when no admin user
		$this->logger->expects($this->once())
			->method('warning')
			->with('No admin user provided in InstallationCompletedEvent');

		// Job should NOT be added
		$this->jobList->expects($this->never())
			->method('add');

		$this->listener->handle($event);
	}

	public function testHandleWithWrongEventType(): void {
		$event = $this->createMock(Event::class);

		// Should not process non-InstallationCompletedEvent events
		$this->appConfig->expects($this->never())
			->method('setValueString');

		$this->jobList->expects($this->never())
			->method('add');

		$this->listener->handle($event);
	}
}
