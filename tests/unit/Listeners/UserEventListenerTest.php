<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Tests\Unit\Listeners;

use OCA\NcwTools\BackgroundJob\UserStatsJob;
use OCA\NcwTools\Listeners\UserEventListener;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\User\Events\UserCreatedEvent;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class UserEventListenerTest extends TestCase {
	private LoggerInterface&MockObject $logger;
	private IJobList&MockObject $jobList;
	private UserEventListener $listener;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->jobList = $this->createMock(IJobList::class);

		$this->listener = new UserEventListener(
			$this->logger,
			$this->jobList,
		);
	}

	public function testHandleUserCreatedEvent(): void {
		$event = $this->createMock(UserCreatedEvent::class);
		$event->method('getUid')->willReturn('testuser');

		$this->logger->expects($this->once())
			->method('info')
			->with('User added', ['uid' => 'testuser']);

		$this->jobList->expects($this->once())
			->method('has')
			->with(UserStatsJob::class, null)
			->willReturn(false);

		$this->jobList->expects($this->once())
			->method('add')
			->with(UserStatsJob::class);

		$this->listener->handle($event);
	}

	public function testHandleUserDeletedEvent(): void {
		$event = $this->createMock(UserDeletedEvent::class);
		$event->method('getUid')->willReturn('testuser');

		$this->logger->expects($this->once())
			->method('info')
			->with('User deleted', ['uid' => 'testuser']);

		$this->jobList->expects($this->once())
			->method('has')
			->with(UserStatsJob::class, null)
			->willReturn(false);

		$this->jobList->expects($this->once())
			->method('add')
			->with(UserStatsJob::class);

		$this->listener->handle($event);
	}

	public function testHandleSkipsAddWhenJobAlreadyQueued(): void {
		$event = $this->createMock(UserCreatedEvent::class);
		$event->method('getUid')->willReturn('testuser');

		$this->jobList->expects($this->once())
			->method('has')
			->with(UserStatsJob::class, null)
			->willReturn(true);

		$this->jobList->expects($this->never())
			->method('add');

		$this->listener->handle($event);
	}

	public function testHandleWrongEventType(): void {
		$event = $this->createMock(Event::class);

		$this->logger->expects($this->never())->method('info');
		$this->jobList->expects($this->never())->method('has');
		$this->jobList->expects($this->never())->method('add');

		$this->listener->handle($event);
	}
}
