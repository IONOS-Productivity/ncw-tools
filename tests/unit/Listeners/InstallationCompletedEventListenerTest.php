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
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class InstallationCompletedEventListenerTest extends TestCase {
	private IAppConfig&MockObject $appConfig;
	private LoggerInterface&MockObject $logger;
	private IJobList&MockObject $jobList;
	private InstallationCompletedEventListener $listener;

	private string $testAdminConfigPath;

	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->jobList = $this->createMock(IJobList::class);

		// Create temporary test config file
		$this->testAdminConfigPath = sys_get_temp_dir() . '/test_adminconfig_' . uniqid();

		$this->listener = new InstallationCompletedEventListener(
			$this->appConfig,
			$this->logger,
			$this->jobList
		);

		// Use reflection to override config path for testing
		$adminPathProperty = new \ReflectionProperty(InstallationCompletedEventListener::class, 'adminConfigPath');
		$adminPathProperty->setAccessible(true);
		$adminPathProperty->setValue($this->listener, $this->testAdminConfigPath);
	}

	protected function tearDown(): void {
		// Clean up temporary file
		if (file_exists($this->testAdminConfigPath)) {
			unlink($this->testAdminConfigPath);
		}

		parent::tearDown();
	}

	public function testHandleSetsAppConfigAndAddsJob(): void {
		// Create test config file
		$adminConfig = <<<'EOT'
NEXTCLOUD_ADMIN_USER=admin
EOT;
		file_put_contents($this->testAdminConfigPath, $adminConfig);

		$event = $this->createMock(Event::class);

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

	public function testHandleWithQuotedValues(): void {
		// Create test config file with quoted values
		$adminConfig = <<<'EOT'
NEXTCLOUD_ADMIN_USER="admin"
EOT;
		file_put_contents($this->testAdminConfigPath, $adminConfig);

		$event = $this->createMock(Event::class);

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('ncw_tools', 'post_install', 'INIT');

		// Expect job to be added with admin user (quotes should be stripped)
		$this->jobList->expects($this->once())
			->method('add')
			->with(PostSetupJob::class, 'admin');


		$this->listener->handle($event);
	}
}
