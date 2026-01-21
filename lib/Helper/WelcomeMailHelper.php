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
	/**
	 * @psalm-suppress PossiblyUnusedMethod - Constructor called by DI container
	 */
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
	 * @psalm-suppress UndefinedClass - Using internal Nextcloud classes
	 * @psalm-suppress MixedAssignment
	 * @psalm-suppress MixedMethodCall
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
		$newUserMailHelper->sendMail($user, $mailTmpl);
	}
}
