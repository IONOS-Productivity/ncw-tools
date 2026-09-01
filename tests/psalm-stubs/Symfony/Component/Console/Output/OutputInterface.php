<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Stub for symfony/console, which is provided by the Nextcloud server at runtime
 * but is not a dependency of this app. Only the members used by this app are
 * declared. At runtime the real interface is used.
 */

declare(strict_types=1);

namespace Symfony\Component\Console\Output;

interface OutputInterface {
	/**
	 * @param string|iterable<string> $messages
	 */
	public function writeln($messages, int $options = 0): void;

	/**
	 * @param string|iterable<string> $messages
	 */
	public function write($messages, bool $newline = false, int $options = 0): void;
}
