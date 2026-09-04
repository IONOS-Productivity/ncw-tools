<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Stub for symfony/console, which is provided by the Nextcloud server at runtime
 * but is not a dependency of this app. Only the members used by this app are
 * declared. At runtime the real class is used.
 */

declare(strict_types=1);

namespace Symfony\Component\Console\Input;

class InputOption {
	public const VALUE_NONE = 1;
	public const VALUE_REQUIRED = 2;
	public const VALUE_OPTIONAL = 4;
	public const VALUE_IS_ARRAY = 8;
	public const VALUE_NEGATABLE = 16;
}
