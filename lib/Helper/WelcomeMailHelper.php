<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Helper;

use OCA\Settings\Mailer\NewUserMailHelper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Defaults;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use OCP\Util;

class WelcomeMailHelper {
	public function __construct(
		private Defaults $defaults,
		private ICrypto $crypto,
		private IMailer $mailer,
		private IURLGenerator $urlGenerator,
		private IFactory $l10NFactory,
		private ISecureRandom $secureRandom,
		private IConfig $config,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Send a welcome email to a user with optional password reset token
	 *
	 * Creates a NewUserMailHelper instance and uses it to generate and send
	 * the welcome email template to the specified user.
	 *
	 * @param IUser $user The user to send the welcome email to
	 * @param bool $generatePasswordResetToken Whether to include a password reset token in the email
	 * @throws \Exception If email generation or sending fails
	 */
	public function sendWelcomeMail(IUser $user, bool $generatePasswordResetToken): void {
		$newUserMailHelper = new NewUserMailHelper(
			$this->defaults,
			$this->urlGenerator,
			$this->l10NFactory,
			$this->mailer,
			$this->secureRandom,
			$this->timeFactory,
			$this->config,
			$this->crypto,
			Util::getDefaultEmailAddress('no-reply')
		);

		$mailTmpl = $newUserMailHelper->generateTemplate($user, $generatePasswordResetToken);
		if ($mailTmpl === null) {
			// User has no email address, cannot send welcome mail
			return;
		}
		$newUserMailHelper->sendMail($user, $mailTmpl);
	}
}
