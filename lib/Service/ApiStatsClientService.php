<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Service;

use GuzzleHttp\Client;
use IONOS\NextcloudPSS\AddonsAPI\Client\Api\StatsAPIApi;
use IONOS\NextcloudPSS\AddonsAPI\Client\Configuration;

class ApiStatsClientService {

	public function newStatsAPIApi(string $baseUrl, string $username, string $password): StatsAPIApi {
		$client = new Client([
			'connect_timeout' => 5,
			'timeout' => 10,
		]);
		$config = new Configuration();
		$config->setHost($baseUrl);
		$config->setUsername($username);
		$config->setPassword($password);
		return new StatsAPIApi($client, $config);
	}
}
