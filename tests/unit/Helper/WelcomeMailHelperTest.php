<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Tests\Unit\Helper;

use OCA\NcwTools\Helper\WelcomeMailHelper;
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
		);
	}

	public function testSendWelcomeMailWithPasswordResetToken(): void {
		$user = $this->createMock(IUser::class);
		$user->expects($this->atLeastOnce())
			->method('getUID')
			->willReturn('testuser');
		$user->expects($this->atLeastOnce())
			->method('getEMailAddress')
			->willReturn('testuser@example.com');

		// The method should not throw any exceptions
		try {
			$this->welcomeMailHelper->sendWelcomeMail($user, true);
			$this->assertTrue(true); // Explicit assertion that we got here without exception
		} catch (\Exception $e) {
			$this->fail('sendWelcomeMail should not throw exceptions: ' . $e->getMessage());
		}
	}

	public function testSendWelcomeMailWithoutPasswordResetToken(): void {
		$user = $this->createMock(IUser::class);
		$user->expects($this->atLeastOnce())
			->method('getUID')
			->willReturn('testuser');
		$user->expects($this->atLeastOnce())
			->method('getEMailAddress')
			->willReturn('testuser@example.com');

		// The method should not throw any exceptions
		try {
			$this->welcomeMailHelper->sendWelcomeMail($user, false);
			$this->assertTrue(true); // Explicit assertion that we got here without exception
		} catch (\Exception $e) {
			$this->fail('sendWelcomeMail should not throw exceptions: ' . $e->getMessage());
		}
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
		);

		$this->assertInstanceOf(WelcomeMailHelper::class, $helper);
	}
}
