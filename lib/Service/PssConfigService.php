<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Service;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

class PssConfigService {

	public function __construct(
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	public function getBrand(): string {
		$value = $this->config->getSystemValueString('ncw_tools.pss.brand');
		if ($value === '') {
			$this->logger->error('PssConfigService: ncw_tools.pss.brand is not configured');
		}
		return $value;
	}

	public function getExtRef(): string {
		$value = $this->config->getSystemValueString('ncw_tools.pss.ext_ref');
		if ($value === '') {
			$this->logger->error('PssConfigService: ncw_tools.pss.ext_ref is not configured');
		}
		return $value;
	}

	public function getBaseUrl(): string {
		$value = $this->config->getSystemValueString('ncw_tools.pss.base_url');
		if ($value === '') {
			$this->logger->error('PssConfigService: ncw_tools.pss.base_url is not configured');
		}
		return $value;
	}

	public function getUsername(): string {
		$value = $this->config->getSystemValueString('ncw_tools.pss.username');
		if ($value === '') {
			$this->logger->error('PssConfigService: ncw_tools.pss.username is not configured');
		}
		return $value;
	}

	public function getPassword(): string {
		$value = $this->config->getSystemValueString('ncw_tools.pss.password');
		if ($value === '') {
			$this->logger->error('PssConfigService: ncw_tools.pss.password is not configured');
		}
		return $value;
	}
}
