<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\BackgroundJob;

use OCA\NcwTools\AppInfo\Application;
use OCA\NcwTools\Helper\WelcomeMailHelper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class PostSetupJob extends TimedJob {
	public const JOB_STATUS_INIT = 'INIT';
	public const JOB_STATUS_DONE = 'DONE';
	public const JOB_STATUS_UNKNOWN = 'UNKNOWN';
	public const JOB_STATUS_CONFIG_KEY = 'post_install';

	/**
	 * @psalm-suppress PossiblyUnusedMethod - Constructor called by DI container
	 */
	public function __construct(
		private LoggerInterface $logger,
		private IAppConfig $appConfig,
		private IConfig $config,
		private IUserManager $userManager,
		private IClientService $clientService,
		ITimeFactory $timeFactory,
		private IJobList $jobList,
		private WelcomeMailHelper $welcomeMailHelper,
	) {
		parent::__construct($timeFactory);
		// Interval every 2 seconds
		$this->setInterval(2);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		// string post install variable
		// used to check if job has already run
		$jobStatus = $this->appConfig->getValueString(Application::APP_ID, self::JOB_STATUS_CONFIG_KEY, self::JOB_STATUS_UNKNOWN);
		if ($jobStatus === self::JOB_STATUS_DONE) {
			$this->logger->debug('Job was already successful, remove job from jobList');
			$this->jobList->remove($this);
			return;
		}

		if ($jobStatus === self::JOB_STATUS_UNKNOWN) {
			$this->logger->debug('Could not load job status from database, wait for another retry');
			return;
		}

		$this->logger->debug('Post install job started');
		$initAdminId = (string)$argument;
		$this->sendInitialWelcomeMail($initAdminId);
		$this->logger->debug('Post install job finished');
	}

	protected function sendInitialWelcomeMail(string $adminUserId): void {
		$client = $this->clientService->newClient();
		$overwriteUrl = (string)$this->config->getSystemValue('overwrite.cli.url');

		if (empty($overwriteUrl)) {
			$this->logger->warning('overwrite.cli.url is not configured, skip sending welcome mail');
			return;
		}

		if (! $this->isUrlAvailable($client, $overwriteUrl)) {
			$this->logger->debug('domain is not ready yet, retry with cron until ' . $overwriteUrl . ' is accessible');
			return;
		}
		if (! $this->userManager->userExists($adminUserId)) {
			$this->logger->warning('Could not find install user, skip sending welcome mail');
			return;
		}

		$initAdminUser = $this->userManager->get($adminUserId);
		if ($initAdminUser !== null) {
			$this->welcomeMailHelper->sendWelcomeMail($initAdminUser, true);
		}
		$this->appConfig->setValueString(Application::APP_ID, self::JOB_STATUS_CONFIG_KEY, self::JOB_STATUS_DONE);
		$this->jobList->remove($this);
	}

	private function isUrlAvailable(IClient $client, string $baseUrl): bool {
		$url = $baseUrl . '/status.php';
		try {
			$this->logger->debug('Check URL: ' . $url);
			$response = $client->get($url);
			$statusCode = $response->getStatusCode();
			return $statusCode >= 200 && $statusCode < 300;
		} catch (\Exception $ex) {
			$this->logger->debug('Exception for ' . $url . '. Reason: ' . $ex->getMessage());
			return false;
		}
	}
}
