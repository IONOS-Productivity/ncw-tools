<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Tests\Unit\BackgroundJob;

use OCA\NcwTools\BackgroundJob\PostSetupJob;
use OCA\NcwTools\Helper\WelcomeMailHelper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class PostSetupJobTest extends TestCase {
	private LoggerInterface&MockObject $logger;
	private IAppConfig&MockObject $appConfig;
	private IConfig&MockObject $config;
	private IUserManager&MockObject $userManager;
	private IClientService&MockObject $clientService;
	private ITimeFactory&MockObject $timeFactory;
	private IJobList&MockObject $jobList;
	private WelcomeMailHelper&MockObject $welcomeMailHelper;
	private PostSetupJob $job;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->config = $this->createMock(IConfig::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->welcomeMailHelper = $this->createMock(WelcomeMailHelper::class);

		$this->job = new PostSetupJob(
			$this->logger,
			$this->appConfig,
			$this->config,
			$this->userManager,
			$this->clientService,
			$this->timeFactory,
			$this->jobList,
			$this->welcomeMailHelper
		);
	}

	public function testRunWithJobAlreadyDone(): void {
		$this->appConfig->expects($this->once())
			->method('getValueString')
			->with('ncw_tools', 'post_install', 'UNKNOWN')
			->willReturn('DONE');

		$this->logger->expects($this->once())
			->method('debug')
			->with('Job was already successful, remove job from jobList');

		// Use reflection to call protected method
		$reflection = new \ReflectionClass($this->job);
		$method = $reflection->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($this->job, 'test-admin');
	}

	public function testRunWithUnknownStatus(): void {
		$this->appConfig->expects($this->once())
			->method('getValueString')
			->with('ncw_tools', 'post_install', 'UNKNOWN')
			->willReturn('UNKNOWN');

		$this->logger->expects($this->once())
			->method('debug')
			->with('Could not load job status from database, wait for another retry');

		// Use reflection to call protected method
		$reflection = new \ReflectionClass($this->job);
		$method = $reflection->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($this->job, 'test-admin');
	}

	public function testRunWithPendingStatus(): void {
		$this->appConfig->expects($this->once())
			->method('getValueString')
			->with('ncw_tools', 'post_install', 'UNKNOWN')
			->willReturn('PENDING');

		$this->config->expects($this->once())
			->method('getSystemValue')
			->with('overwrite.cli.url')
			->willReturn('https://example.com');

		$client = $this->createMock(IClient::class);
		$response = $this->createMock(IResponse::class);
		$response->expects($this->atLeastOnce())
			->method('getStatusCode')
			->willReturn(200);

		$client->expects($this->once())
			->method('get')
			->with('https://example.com/status.php')
			->willReturn($response);

		$this->clientService->expects($this->once())
			->method('newClient')
			->willReturn($client);

		$this->userManager->expects($this->once())
			->method('userExists')
			->with('test-admin')
			->willReturn(false);

		// Expect 4 debug calls:
		// 1. "Post install job started"
		// 2. "Check URL: ..."
		// 3. (conditional - only if URL check fails) "domain is not ready yet..."
		// 4. "Post install job finished"
		$this->logger->expects($this->atLeastOnce())
			->method('debug');

		$this->logger->expects($this->once())
			->method('warning')
			->with('Could not find install user, skip sending welcome mail');

		// setValueString should NOT be called when user doesn't exist (job will retry)
		$this->appConfig->expects($this->never())
			->method('setValueString');

		// Use reflection to call protected method
		$reflection = new \ReflectionClass($this->job);
		$method = $reflection->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($this->job, 'test-admin');
	}

	public function testRunWithUrlNotAvailable(): void {
		$this->appConfig->expects($this->once())
			->method('getValueString')
			->with('ncw_tools', 'post_install', 'UNKNOWN')
			->willReturn('PENDING');

		$this->config->expects($this->once())
			->method('getSystemValue')
			->with('overwrite.cli.url')
			->willReturn('https://example.com');

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('get')
			->with('https://example.com/status.php')
			->willThrowException(new \Exception('Connection failed'));

		$this->clientService->expects($this->once())
			->method('newClient')
			->willReturn($client);

		// Expect 4 debug calls:
		// 1. "Post install job started"
		// 2. "Checking URL availability"
		// 3. "domain is not ready yet..."
		// 4. "Post install job finished"
		$this->logger->expects($this->exactly(4))
			->method('debug');

		// Expect 1 info call for the exception
		$this->logger->expects($this->once())
			->method('info')
			->with('URL not yet accessible', $this->anything());

		$this->appConfig->expects($this->never())
			->method('setValueString');

		// Use reflection to call protected method
		$reflection = new \ReflectionClass($this->job);
		$method = $reflection->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($this->job, 'test-admin');
	}

	public function testRunWithSuccessfulMailSend(): void {
		$this->appConfig->expects($this->once())
			->method('getValueString')
			->with('ncw_tools', 'post_install', 'UNKNOWN')
			->willReturn('PENDING');

		$this->config->expects($this->once())
			->method('getSystemValue')
			->with('overwrite.cli.url')
			->willReturn('https://example.com');

		$client = $this->createMock(IClient::class);
		$response = $this->createMock(IResponse::class);
		$response->expects($this->atLeastOnce())
			->method('getStatusCode')
			->willReturn(200);

		$client->expects($this->once())
			->method('get')
			->with('https://example.com/status.php')
			->willReturn($response);

		$this->clientService->expects($this->once())
			->method('newClient')
			->willReturn($client);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('test-admin');
		$user->method('getEMailAddress')->willReturn('admin@example.com');
		$user->method('getBackendClassName')->willReturn('Database');

		$this->userManager->expects($this->once())
			->method('userExists')
			->with('test-admin')
			->willReturn(true);

		$this->userManager->expects($this->once())
			->method('get')
			->with('test-admin')
			->willReturn($user);

		// Expect debug logs (allow flexible count due to URL checking)
		$this->logger->expects($this->atLeastOnce())
			->method('debug');

		// Expect welcome mail to be sent
		$this->welcomeMailHelper->expects($this->once())
			->method('sendWelcomeMail')
			->with($user, true);

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('ncw_tools', 'post_install', 'DONE');

		// Use reflection to call protected method
		$reflection = new \ReflectionClass($this->job);
		$method = $reflection->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($this->job, 'test-admin');
	}
}
