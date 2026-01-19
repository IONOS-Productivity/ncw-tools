<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCP\Install\Events;

use OCP\EventDispatcher\Event;

/**
 * Mock class for InstallationCompletedEvent
 * This is a stub for testing purposes when the actual class is not available in the OCP package
 *
 * @since 33.0.0
 */
class InstallationCompletedEvent extends Event {
	/**
	 * @since 33.0.0
	 */
	public function __construct(
		private string $dataDirectory,
		private ?string $adminUsername = null,
		private ?string $adminEmail = null,
	) {
		parent::__construct();
	}

	/**
	 * Get the configured data directory path
	 *
	 * @since 33.0.0
	 */
	public function getDataDirectory(): string {
		return $this->dataDirectory;
	}

	/**
	 * Get the admin username if an admin user was created
	 *
	 * @since 33.0.0
	 */
	public function getAdminUsername(): ?string {
		return $this->adminUsername;
	}

	/**
	 * Get the admin email if configured
	 *
	 * @since 33.0.0
	 */
	public function getAdminEmail(): ?string {
		return $this->adminEmail;
	}

	/**
	 * Check if an admin user was created during installation
	 *
	 * @since 33.0.0
	 */
	public function hasAdminUser(): bool {
		return $this->adminUsername !== null;
	}
}
