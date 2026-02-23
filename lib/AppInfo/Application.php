<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\AppInfo;

use OCA\NcwTools\Capabilities;
use OCA\NcwTools\Listeners\InstallationCompletedEventListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Install\Events\InstallationCompletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'ncw_tools';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(InstallationCompletedEvent::class, InstallationCompletedEventListener::class);
		$context->registerCapability(Capabilities::class);
	}

	public function boot(IBootContext $context): void {
	}
}
