<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Stats;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

class PssConfigReader {
	private const KEYS = [
		'brand' => 'ncw_tools.pss.brand',
		'extRef' => 'ncw_tools.pss.ext_ref',
		'baseUrl' => 'ncw_tools.pss.base_url',
		'username' => 'ncw_tools.pss.username',
		'password' => 'ncw_tools.pss.password',
	];

	public function __construct(
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	public function read(): ?PssConfig {
		$values = [];
		$missing = [];
		foreach (self::KEYS as $field => $key) {
			$value = $this->config->getSystemValueString($key);
			if ($value === '') {
				$missing[] = $key;
			}
			$values[$field] = $value;
		}
		if ($missing !== []) {
			$this->logger->error(
				'PssConfigReader: missing required PSS configuration',
				['keys' => $missing],
			);
			return null;
		}
		return new PssConfig(
			$values['brand'],
			$values['extRef'],
			$values['baseUrl'],
			$values['username'],
			$values['password'],
		);
	}
}
