<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../tests/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';

// Autoload test stubs for classes not available in the current OCP package
spl_autoload_register(function ($class) {
	$stubsDir = __DIR__ . '/stubs/';
	$file = $stubsDir . str_replace('\\', '/', $class) . '.php';
	if (file_exists($file)) {
		require_once $file;
	}
});

\OC_App::loadApp(OCA\NcwTools\AppInfo\Application::APP_ID);
OC_Hook::clear();
