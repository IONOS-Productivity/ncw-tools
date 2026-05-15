<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Service;

use OCP\IConfig;

class PssConfigService {
	private const KEY_BRAND = 'ncw_tools.pss.brand';
	private const KEY_EXT_REF = 'ncw_tools.pss.ext_ref';
	private const KEY_BASE_URL = 'ncw_tools.pss.base_url';
	private const KEY_USERNAME = 'ncw_tools.pss.username';
	private const KEY_PASSWORD = 'ncw_tools.pss.password';

	public function __construct(
		private IConfig $config,
	) {
	}

	public function getBrand(): string {
		return $this->config->getSystemValueString(self::KEY_BRAND);
	}

	public function getExtRef(): string {
		return $this->config->getSystemValueString(self::KEY_EXT_REF);
	}

	public function getBaseUrl(): string {
		return $this->config->getSystemValueString(self::KEY_BASE_URL);
	}

	public function getUsername(): string {
		return $this->config->getSystemValueString(self::KEY_USERNAME);
	}

	public function getPassword(): string {
		return $this->config->getSystemValueString(self::KEY_PASSWORD);
	}
}
