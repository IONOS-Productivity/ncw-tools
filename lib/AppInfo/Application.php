<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\AppInfo;

use OCA\NcwTools\Listeners\InstallationCompletedEventListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\InstallationCompletedEvent;

/**
 * @psalm-suppress UnusedClass
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'ncw_tools';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(InstallationCompletedEvent::class, InstallationCompletedEventListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
