<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Stub based on Nextcloud server stable31
 * https://github.com/nextcloud/server/blob/stable31/apps/settings/lib/Mailer/NewUserMailHelper.php
 */

declare(strict_types=1);

namespace OCA\Settings\Mailer;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Defaults;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;

class NewUserMailHelper {
	/**
	 * @param Defaults $themingDefaults
	 * @param IURLGenerator $urlGenerator
	 * @param IFactory $l10nFactory
	 * @param IMailer $mailer
	 * @param ISecureRandom $secureRandom
	 * @param ITimeFactory $timeFactory
	 * @param IConfig $config
	 * @param ICrypto $crypto
	 * @param string $fromAddress
	 */
	public function __construct(
		private Defaults $themingDefaults,
		private IURLGenerator $urlGenerator,
		private IFactory $l10nFactory,
		private IMailer $mailer,
		private ISecureRandom $secureRandom,
		private ITimeFactory $timeFactory,
		private IConfig $config,
		private ICrypto $crypto,
		private $fromAddress,
	) {
	}

	/**
	 * @param IUser $user
	 * @param bool $generatePasswordResetToken
	 * @return IEMailTemplate|null
	 */
	public function generateTemplate(IUser $user, $generatePasswordResetToken = false): ?IEMailTemplate {
		// Return null if user has no email address (stub behavior)
		if ($user->getEMailAddress() === null) {
			return null;
		}

		$userId = $user->getUID();

		if ($generatePasswordResetToken) {
			$token = $this->secureRandom->generate(
				21,
				ISecureRandom::CHAR_ALPHANUMERIC
			);
			$tokenValue = $this->timeFactory->getTime() . ':' . $token;
			$mailAddress = ($user->getEMailAddress() !== null) ? $user->getEMailAddress() : '';
			$encryptedValue = $this->crypto->encrypt($tokenValue, $mailAddress . $this->config->getSystemValue('secret'));
			$this->config->setUserValue($user->getUID(), 'core', 'lostpassword', $encryptedValue);
		}

		// Return a mock email template
		return $this->mailer->createEMailTemplate('settings.Welcome');
	}

	/**
	 * Sends a welcome mail to $user
	 *
	 * @param IUser $user
	 * @param IEMailTemplate $emailTemplate
	 * @throws \Exception If mail could not be sent
	 */
	public function sendMail(IUser $user, IEMailTemplate $emailTemplate): void {
		// Return early if user has no email (stub behavior)
		if ($user->getEMailAddress() === null) {
			return;
		}

		// Simulate sending the mail
		$message = $this->mailer->createMessage();
		$message->setTo([$user->getEMailAddress() => $user->getDisplayName()]);
		$message->setFrom([$this->fromAddress => $this->themingDefaults->getName()]);
		$message->useTemplate($emailTemplate);
		$this->mailer->send($message);
	}
}
