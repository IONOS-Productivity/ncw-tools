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
		$jobStatus = $this->appConfig->getValueString(Application::APP_ID, 'post_install', 'UNKNOWN');
		if ($jobStatus === 'DONE') {
			$this->logger->debug('Job was already successful, remove job from jobList');
			$this->jobList->remove($this);
			return;
		}

		if ($jobStatus === 'UNKNOWN') {
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
		if (! $this->isUrlAvailable($client, $overwriteUrl)) {
			$this->logger->debug('domain is not ready yet, retry with cron until ' . $overwriteUrl . ' is accessible');
			return;
		}
		if (! $this->userManager->userExists($adminUserId)) {
			$this->logger->warning('Could not find install user, skip sending welcome mail');
		} else {
			$initAdminUser = $this->userManager->get($adminUserId);
			if ($initAdminUser !== null) {
				$this->welcomeMailHelper->sendWelcomeMail($initAdminUser, true);
			}
		}
		$this->appConfig->setValueString(Application::APP_ID, 'post_install', 'DONE');
		$this->jobList->remove($this);
	}

	private function isUrlAvailable(IClient $client, string $baseUrl): bool {

		$url = $baseUrl . '/status.php';
		try {
			$this->logger->debug('Check URL: ' . $url);
			$response = $client->get($url);
			return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;

		} catch (\Exception $ex) {
			$this->logger->debug('Exception for ' . $url . '. Reason: ' . $ex->getMessage());
			return false;
		}
	}
}
