<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Tests\Unit\Helper;

use OCA\NcwTools\Helper\WelcomeMailHelper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Defaults;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class WelcomeMailHelperTest extends TestCase {
	private Defaults&MockObject $defaults;
	private ICrypto&MockObject $crypto;
	private IMailer&MockObject $mailer;
	private IURLGenerator&MockObject $urlGenerator;
	private IFactory&MockObject $l10NFactory;
	private ISecureRandom&MockObject $secureRandom;
	private IConfig&MockObject $config;
	private ITimeFactory&MockObject $timeFactory;
	private WelcomeMailHelper $welcomeMailHelper;

	protected function setUp(): void {
		parent::setUp();

		$this->defaults = $this->createMock(Defaults::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->mailer = $this->createMock(IMailer::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->l10NFactory = $this->createMock(IFactory::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->config = $this->createMock(IConfig::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);

		// Setup mocks required by NewUserMailHelper
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->l10NFactory->method('get')->willReturn($l10n);
		$this->l10NFactory->method('getUserLanguage')->willReturn('en');

		$emailTemplate = $this->createMock(IEMailTemplate::class);
		$emailTemplate->method('addHeader')->willReturnSelf();
		$emailTemplate->method('addHeading')->willReturnSelf();
		$emailTemplate->method('addBodyText')->willReturnSelf();
		$emailTemplate->method('addBodyButton')->willReturnSelf();
		$emailTemplate->method('addBodyButtonGroup')->willReturnSelf();
		$emailTemplate->method('addFooter')->willReturnSelf();
		$emailTemplate->method('setSubject')->willReturnSelf();
		$this->mailer->method('createEMailTemplate')->willReturn($emailTemplate);

		$message = $this->createMock(IMessage::class);
		$message->method('setTo')->willReturnSelf();
		$message->method('setFrom')->willReturnSelf();
		$message->method('useTemplate')->willReturnSelf();
		$message->method('setAutoSubmitted')->willReturnSelf();
		$this->mailer->method('createMessage')->willReturn($message);

		$this->defaults->method('getName')->willReturn('Nextcloud');
		$this->urlGenerator->method('getAbsoluteURL')->willReturn('https://example.com');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.com/reset');
		$this->secureRandom->method('generate')->willReturn('test-token');
		$this->crypto->method('encrypt')->willReturn('encrypted-token');
		$this->config->method('getSystemValue')->willReturnMap([
			['customclient_desktop', 'https://nextcloud.com/install/#install-clients', 'https://nextcloud.com/install/#install-clients'],
			['secret', '', 'test-secret'],
		]);

		$this->welcomeMailHelper = new WelcomeMailHelper(
			$this->defaults,
			$this->crypto,
			$this->mailer,
			$this->urlGenerator,
			$this->l10NFactory,
			$this->secureRandom,
			$this->config,
			$this->timeFactory
		);
	}

	public function testSendWelcomeMailWithPasswordResetToken(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$user->method('getDisplayName')->willReturn('Test User');
		$user->method('getEMailAddress')->willReturn('testuser@example.com');
		$user->method('getBackendClassName')->willReturn('Database');

		$this->timeFactory->expects($this->once())
			->method('getTime')
			->willReturn(1234567890);

		$this->config->expects($this->once())
			->method('setUserValue')
			->with('testuser', 'core', 'lostpassword', $this->anything());

		$this->mailer->expects($this->once())
			->method('send');

		$this->welcomeMailHelper->sendWelcomeMail($user, true);
	}

	public function testSendWelcomeMailWithoutPasswordResetToken(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$user->method('getDisplayName')->willReturn('Test User');
		$user->method('getEMailAddress')->willReturn('testuser@example.com');
		$user->method('getBackendClassName')->willReturn('Database');

		$this->timeFactory->expects($this->never())
			->method('getTime');

		$this->config->expects($this->never())
			->method('setUserValue');

		$this->mailer->expects($this->once())
			->method('send');

		$this->welcomeMailHelper->sendWelcomeMail($user, false);
	}

	public function testSendWelcomeMailWithUserWithoutEmail(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$user->method('getDisplayName')->willReturn('Test User');
		$user->method('getEMailAddress')->willReturn(null);
		$user->method('getBackendClassName')->willReturn('Database');

		$this->mailer->expects($this->never())
			->method('send');

		$this->welcomeMailHelper->sendWelcomeMail($user, false);
	}

	public function testConstructorInitializesCorrectly(): void {
		$helper = new WelcomeMailHelper(
			$this->defaults,
			$this->crypto,
			$this->mailer,
			$this->urlGenerator,
			$this->l10NFactory,
			$this->secureRandom,
			$this->config,
			$this->timeFactory
		);

		$this->assertInstanceOf(WelcomeMailHelper::class, $helper);
	}
}
