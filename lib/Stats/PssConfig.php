<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Stats;

final class PssConfig {
	public function __construct(
		public readonly string $brand,
		public readonly string $extRef,
		public readonly string $baseUrl,
		public readonly string $username,
		private readonly string $password,
	) {
	}

	public function getPassword(): string {
		return $this->password;
	}

	public function __debugInfo(): array {
		return [
			'brand' => $this->brand,
			'extRef' => $this->extRef,
			'baseUrl' => $this->baseUrl,
			'username' => $this->username,
			'password' => '***',
		];
	}
}
