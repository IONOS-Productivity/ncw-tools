<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools;

use OCP\Capabilities\ICapability;

class Capabilities implements ICapability {
	/**
	 * Override support capabilities to always show valid subscription
	 * This ensures the support capability is present even if the support app
	 * returns an empty array when subscription check fails.
	 *
	 * @return array{
	 *     support: array{
	 *         hasValidSubscription: bool,
	 *         desktopEnterpriseChannel?: string,
	 *     }
	 * }
	 */
	public function getCapabilities(): array {
		return [
			'support' => [
				'hasValidSubscription' => true,
				'desktopEnterpriseChannel' => 'stable',
			],
		];
	}
}
