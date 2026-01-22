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

		// Mock IL10N for l10NFactory
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(function ($text) {
			return $text;
		});
		$this->l10NFactory->method('get')->willReturn($l10n);

		// Mock email template
		$emailTemplate = $this->createMock(IEMailTemplate::class);
		$emailTemplate->method('addHeader')->willReturnSelf();
		$emailTemplate->method('addHeading')->willReturnSelf();
		$emailTemplate->method('addBodyText')->willReturnSelf();
		$emailTemplate->method('addBodyButton')->willReturnSelf();
		$emailTemplate->method('addBodyButtonGroup')->willReturnCallback(function () use ($emailTemplate) {
			return $emailTemplate;
		});
		$emailTemplate->method('addFooter')->willReturnSelf();
		$emailTemplate->method('setSubject')->willReturnSelf();
		$this->mailer->method('createEMailTemplate')->willReturn($emailTemplate);

		// Mock IConfig to return a client download URL
		$this->config->method('getSystemValue')->willReturnCallback(function ($key, $default) {
			if ($key === 'customclient_desktop') {
				return 'https://nextcloud.com/install/#install-clients';
			}
			return $default;
		});

		// Mock message
		$message = $this->createMock(IMessage::class);
		$message->method('setTo')->willReturnSelf();
		$message->method('setFrom')->willReturnSelf();
		$message->method('useTemplate')->willReturnSelf();
		$this->mailer->method('createMessage')->willReturn($message);

		// Mock defaults
		$this->defaults->method('getName')->willReturn('Nextcloud');

		// Mock URL generator
		$this->urlGenerator->method('getAbsoluteURL')->willReturn('https://example.com');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.com/reset');

		// Mock secure random and crypto
		$this->secureRandom->method('generate')->willReturn('random-token');
		$this->crypto->method('encrypt')->willReturn('encrypted-token');

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

		// Mock l10n for getUserLanguage
		$this->l10NFactory->expects($this->once())
			->method('getUserLanguage')
			->with($user)
			->willReturn('en');

		// Mock time factory for password reset token generation
		$this->timeFactory->expects($this->once())
			->method('getTime')
			->willReturn(1234567890);

		// Mock config for setUserValue (password reset token storage)
		$this->config->expects($this->once())
			->method('setUserValue')
			->with('testuser', 'core', 'lostpassword', $this->anything());

		// Mock config for getSystemValue (secret key)
		$this->config->expects($this->atLeastOnce())
			->method('getSystemValue')
			->willReturnMap([
				['customclient_desktop', 'https://nextcloud.com/install/#install-clients', 'https://nextcloud.com/install/#install-clients'],
				['secret', '', 'test-secret'],
			]);

		// Expect email to be sent
		$this->mailer->expects($this->once())
			->method('send')
			->with($this->isInstanceOf(IMessage::class));

		$this->welcomeMailHelper->sendWelcomeMail($user, true);
	}

	public function testSendWelcomeMailWithoutPasswordResetToken(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$user->method('getDisplayName')->willReturn('Test User');
		$user->method('getEMailAddress')->willReturn('testuser@example.com');
		$user->method('getBackendClassName')->willReturn('Database');

		// Mock l10n for getUserLanguage
		$this->l10NFactory->expects($this->once())
			->method('getUserLanguage')
			->with($user)
			->willReturn('en');

		// Should not generate password reset token
		$this->timeFactory->expects($this->never())
			->method('getTime');

		$this->config->expects($this->never())
			->method('setUserValue');

		// Expect email to be sent
		$this->mailer->expects($this->once())
			->method('send')
			->with($this->isInstanceOf(IMessage::class));

		$this->welcomeMailHelper->sendWelcomeMail($user, false);
	}

	public function testSendWelcomeMailDoesNotSendToUserWithoutEmail(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$user->method('getDisplayName')->willReturn('Test User');
		$user->method('getEMailAddress')->willReturn(null);
		$user->method('getBackendClassName')->willReturn('Database');

		// Mock l10n for getUserLanguage
		$this->l10NFactory->expects($this->once())
			->method('getUserLanguage')
			->with($user)
			->willReturn('en');

		// Should not send email if user has no email address
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
