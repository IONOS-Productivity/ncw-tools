<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Stats;

use GuzzleHttp\Client;
use IONOS\NextcloudPSS\AddonsAPI\Client\Api\StatsAPIApi;
use IONOS\NextcloudPSS\AddonsAPI\Client\Configuration;
use OCP\IConfig;

class PssApiFactory {
	private const KEY_CONNECT_TIMEOUT = 'ncw_tools.pss.connect_timeout';
	private const KEY_TIMEOUT = 'ncw_tools.pss.timeout';
	private const KEY_ALLOW_INSECURE = 'ncw_tools.pss.allow_insecure';

	private const DEFAULT_CONNECT_TIMEOUT_S = 5;
	private const DEFAULT_TIMEOUT_S = 10;

	private int $connectTimeout;
	private int $timeout;
	private bool $allowInsecure;

	public function __construct(IConfig $config) {
		$this->connectTimeout = $config->getSystemValueInt(self::KEY_CONNECT_TIMEOUT, self::DEFAULT_CONNECT_TIMEOUT_S);
		$this->timeout = $config->getSystemValueInt(self::KEY_TIMEOUT, self::DEFAULT_TIMEOUT_S);
		$this->allowInsecure = $config->getSystemValueBool(self::KEY_ALLOW_INSECURE, false);
	}

	public function newStatsApi(string $baseUrl, string $username, string $password): StatsAPIApi {
		$client = new Client([
			'connect_timeout' => $this->connectTimeout,
			'timeout' => $this->timeout,
			'verify' => !$this->allowInsecure,
		]);
		$config = new Configuration();
		$config->setHost($baseUrl);
		$config->setUsername($username);
		$config->setPassword($password);
		return new StatsAPIApi($client, $config);
	}
}
