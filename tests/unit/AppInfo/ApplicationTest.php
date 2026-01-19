<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Tests\Unit\AppInfo;

use OCA\NcwTools\AppInfo\Application;
use OCA\NcwTools\Listeners\InstallationCompletedEventListener;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Install\Events\InstallationCompletedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

final class ApplicationTest extends TestCase {
	private Application $application;
	private IRegistrationContext|MockObject $registrationContext;
	private IBootContext|MockObject $bootContext;

	protected function setUp(): void {
		parent::setUp();
		$this->application = new Application();
		$this->registrationContext = $this->createMock(IRegistrationContext::class);
		$this->bootContext = $this->createMock(IBootContext::class);
	}

	public function testAppIdConstant(): void {
		$this->assertSame('ncw_tools', Application::APP_ID);
	}

	public function testConstructorInitializesWithCorrectAppId(): void {
		$app = new Application();
		$this->assertInstanceOf(Application::class, $app);
	}

	public function testRegisterMethodExists(): void {
		// Test that register method registers the InstallationCompletedEvent listener
		$this->registrationContext
			->expects($this->once())
			->method('registerEventListener')
			->with(
				InstallationCompletedEvent::class,
				InstallationCompletedEventListener::class
			);

		$this->application->register($this->registrationContext);
	}
}
