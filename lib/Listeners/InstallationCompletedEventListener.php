<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Listeners;

use OCA\NcwTools\AppInfo\Application;
use OCA\NcwTools\BackgroundJob\PostSetupJob;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class InstallationCompletedEventListener implements IEventListener {
	private string $smtpConfigPath = '/vault/secrets/smtpconfig';

	private string $adminConfigPath = '/vault/secrets/adminconfig';

	private array $quotesArray = ['\\\'', '"', '\''];

	public function __construct(
		private IConfig $config,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private IJobList $jobList,
	) {
	}

	public function handle(Event $event): void {

		$this->appConfig->setValueString(Application::APP_ID, 'post_install', 'INIT');

		$this->logger->debug('post Setup: init admin user');
		$adminUserId = $this->initAdminUser();
		$this->logger->debug('post Setup: admin user configured');

		$this->logger->debug('post Setup: set send mail account');
		$this->setSendMailAccount();
		$this->logger->debug('post Setup: send mail account configured');

		$this->logger->debug('post Setup: add send initial welcome mail job');
		$this->jobList->add(PostSetupJob::class, $adminUserId);
		$this->logger->debug('post Setup: job added');
	}


	protected function initAdminUser(): string {

		// Read the configuration file line by line
		$adminConfigLines = file($this->adminConfigPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

		// Initialize the associative array for the configuration
		$adminConfig = [];

		// Iterate through the lines and extract the variables
		foreach ($adminConfigLines as $line) {
			[$key, $value] = explode('=', $line, 2);
			$adminConfig[trim($key)] = trim($value);
		}

		return str_replace($this->quotesArray, '', $adminConfig['NEXTCLOUD_ADMIN_USER']);
	}

	protected function setSendMailAccount(): void {
		$smtpConfigLines = file($this->smtpConfigPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

		$smtpConfig = [];

		// Iterate through the lines and extract the variables
		foreach ($smtpConfigLines as $line) {
			[$key, $value] = explode('=', $line, 2);
			$smtpConfig[trim($key)] = trim($value);
		}

		// Access the loaded variables
		$smtpHost = str_replace($this->quotesArray, '', $smtpConfig['SMTP_HOST']);
		$smtpPort = str_replace($this->quotesArray, '', $smtpConfig['SMTP_PORT']);

		if (isset($smtpConfig['SMTP_SEC'])) {
			$smtpSec = str_replace($this->quotesArray, '', $smtpConfig['SMTP_SEC']);
		} else {
			$smtpSec = 'tls';
		}

		$smtpName = str_replace($this->quotesArray, '', $smtpConfig['SMTP_NAME']);
		$smtpPassword = str_replace($this->quotesArray, '', $smtpConfig['SMTP_PASSWORD']);
		$mailFromAddress = str_replace($this->quotesArray, '', $smtpConfig['MAIL_FROM_ADDRESS']);
		$mailDomain = str_replace($this->quotesArray, '', $smtpConfig['MAIL_DOMAIN']);

		$this->config->setSystemValues([
			'mail_smtpmode' => 'smtp',
			'mail_smtphost' => $smtpHost,
			'mail_smtpport' => $smtpPort,
			'mail_smtpsecure' => $smtpSec,
			'mail_smtpauth' => 'true',
			'mail_smtpauthtype' => 'LOGIN',
			'mail_smtpname' => $smtpName,
			'mail_smtppassword' => $smtpPassword,
			'mail_from_address' => $mailFromAddress,
			'mail_domain' => $mailDomain
		]);
	}
}
