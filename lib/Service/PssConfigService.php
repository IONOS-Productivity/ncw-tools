<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Service;

use OCP\IConfig;

class PssConfigService {

	public function __construct(
		private IConfig $config,
	) {
	}

	public function getBrand(): string {
		return $this->config->getSystemValueString('ncw_tools.pss.brand');
	}

	public function getExtRef(): string {
		return $this->config->getSystemValueString('ncw_tools.pss.ext_ref');
	}

	public function getBaseUrl(): string {
		return $this->config->getSystemValueString('ncw_tools.pss.base_url');
	}

	public function getUsername(): string {
		return $this->config->getSystemValueString('ncw_tools.pss.username');
	}

	public function getPassword(): string {
		return $this->config->getSystemValueString('ncw_tools.pss.password');
	}
}
