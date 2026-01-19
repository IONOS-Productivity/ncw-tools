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
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class PostInstallEventListenerTest extends TestCase {
	private IConfig&MockObject $config;
	private IAppConfig&MockObject $appConfig;
	private LoggerInterface&MockObject $logger;
	private IJobList&MockObject $jobList;
	private InstallationCompletedEventListener $listener;

	private string $testSmtpConfigPath;
	private string $testAdminConfigPath;

	protected function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->jobList = $this->createMock(IJobList::class);

		// Create temporary test config files
		$this->testSmtpConfigPath = sys_get_temp_dir() . '/test_smtpconfig_' . uniqid();
		$this->testAdminConfigPath = sys_get_temp_dir() . '/test_adminconfig_' . uniqid();

		$this->listener = new InstallationCompletedEventListener(
			$this->config,
			$this->appConfig,
			$this->logger,
			$this->jobList
		);

		// Use reflection to override config paths for testing
		$smtpPathProperty = new \ReflectionProperty(InstallationCompletedEventListener::class, 'smtpConfigPath');
		$smtpPathProperty->setAccessible(true);
		$smtpPathProperty->setValue($this->listener, $this->testSmtpConfigPath);

		$adminPathProperty = new \ReflectionProperty(InstallationCompletedEventListener::class, 'adminConfigPath');
		$adminPathProperty->setAccessible(true);
		$adminPathProperty->setValue($this->listener, $this->testAdminConfigPath);
	}

	protected function tearDown(): void {
		// Clean up temporary files
		if (file_exists($this->testSmtpConfigPath)) {
			unlink($this->testSmtpConfigPath);
		}
		if (file_exists($this->testAdminConfigPath)) {
			unlink($this->testAdminConfigPath);
		}

		parent::tearDown();
	}

	public function testHandleSetsAppConfigAndAddsJob(): void {
		// Create test config files
		$smtpConfig = <<<'EOT'
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_SEC=tls
SMTP_NAME=noreply@example.com
SMTP_PASSWORD=secret123
MAIL_FROM_ADDRESS=noreply
MAIL_DOMAIN=example.com
EOT;
		file_put_contents($this->testSmtpConfigPath, $smtpConfig);

		$adminConfig = <<<'EOT'
NEXTCLOUD_ADMIN_USER=admin
EOT;
		file_put_contents($this->testAdminConfigPath, $adminConfig);

		$event = $this->createMock(Event::class);

		// Expect app config to be set for 'post_install'
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('ncw_tools', 'post_install', 'INIT');

		// Expect system config to be set for SMTP settings
		$this->config->expects($this->once())
			->method('setSystemValues')
			->with($this->callback(function ($values) {
				$expectedKeys = [
					'mail_smtpmode',
					'mail_smtphost',
					'mail_smtpport',
					'mail_smtpsecure',
					'mail_smtpauth',
					'mail_smtpauthtype',
					'mail_smtpname',
					'mail_smtppassword',
					'mail_from_address',
					'mail_domain',
				];
				foreach ($expectedKeys as $key) {
					if (!array_key_exists($key, $values)) {
						return false;
					}
				}
				return $values['mail_smtphost'] === 'smtp.example.com'
					&& $values['mail_smtpport'] === '587'
					&& $values['mail_smtpsecure'] === 'tls';
			}));

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
		// Create test config files with quoted values
		$smtpConfig = <<<'EOT'
SMTP_HOST="smtp.example.com"
SMTP_PORT='587'
SMTP_NAME='noreply@example.com'
SMTP_PASSWORD="secret123"
MAIL_FROM_ADDRESS="noreply"
MAIL_DOMAIN='example.com'
EOT;
		file_put_contents($this->testSmtpConfigPath, $smtpConfig);

		$adminConfig = <<<'EOT'
NEXTCLOUD_ADMIN_USER="admin"
EOT;
		file_put_contents($this->testAdminConfigPath, $adminConfig);

		$event = $this->createMock(Event::class);

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('ncw_tools', 'post_install', 'INIT');

		$this->config->expects($this->once())
			->method('setSystemValues')
			->with($this->callback(function ($values) {
				// Verify that quotes are stripped
				return $values['mail_smtphost'] === 'smtp.example.com'
					&& $values['mail_smtpport'] === '587'
					&& $values['mail_smtpname'] === 'noreply@example.com';
			}));

		$this->jobList->expects($this->once())
			->method('add')
			->with(PostSetupJob::class, 'admin');

		$this->listener->handle($event);
	}

	public function testHandleWithDefaultSmtpSec(): void {
		// Create test config files without SMTP_SEC
		$smtpConfig = <<<'EOT'
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_NAME=noreply@example.com
SMTP_PASSWORD=secret123
MAIL_FROM_ADDRESS=noreply
MAIL_DOMAIN=example.com
EOT;
		file_put_contents($this->testSmtpConfigPath, $smtpConfig);

		$adminConfig = <<<'EOT'
NEXTCLOUD_ADMIN_USER=admin
EOT;
		file_put_contents($this->testAdminConfigPath, $adminConfig);

		$event = $this->createMock(Event::class);

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('ncw_tools', 'post_install', 'INIT');

		// Verify that default 'tls' is used when SMTP_SEC is not set
		$this->config->expects($this->once())
			->method('setSystemValues')
			->with($this->callback(function ($values) {
				return $values['mail_smtpsecure'] === 'tls';
			}));

		$this->jobList->expects($this->once())
			->method('add');

		$this->listener->handle($event);
	}
}
