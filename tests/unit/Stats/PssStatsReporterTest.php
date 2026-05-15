<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Tests\Unit\Stats;

use DateTime;
use IONOS\NextcloudPSS\AddonsAPI\Client\Api\StatsAPIApi;
use IONOS\NextcloudPSS\AddonsAPI\Client\Model\StatsUpdateRequest;
use OCA\NcwTools\Stats\PssApiFactory;
use OCA\NcwTools\Stats\PssConfig;
use OCA\NcwTools\Stats\PssConfigReader;
use OCA\NcwTools\Stats\PssStatsReporter;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class PssStatsReporterTest extends TestCase {
	private PssConfigReader&MockObject $configReader;
	private PssApiFactory&MockObject $apiFactory;
	private StatsAPIApi&MockObject $statsApi;
	private LoggerInterface&MockObject $logger;
	private PssStatsReporter $reporter;

	protected function setUp(): void {
		parent::setUp();
		$this->configReader = $this->createMock(PssConfigReader::class);
		$this->apiFactory = $this->createMock(PssApiFactory::class);
		$this->statsApi = $this->createMock(StatsAPIApi::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->apiFactory->method('newStatsApi')->willReturn($this->statsApi);

		$this->reporter = new PssStatsReporter(
			$this->configReader,
			$this->apiFactory,
			$this->logger,
		);
	}

	public function testReportSkipsWhenConfigNull(): void {
		$this->configReader->method('read')->willReturn(null);

		$this->statsApi->expects($this->never())->method('updateStats');
		$this->logger->expects($this->never())->method('error');
		$this->logger->expects($this->never())->method('info');

		$this->reporter->reportUserCount(42, new DateTime('2026-01-01T00:00:00.000', new \DateTimeZone('UTC')));
	}

	public function testReportPostsAndLogsOnSuccess(): void {
		$at = new DateTime('2026-01-01T12:34:56.789', new \DateTimeZone('UTC'));
		$this->configReader->method('read')->willReturn(new PssConfig(
			'IONOS',
			'tenant-1',
			'https://pss.example.com',
			'alice',
			'secret',
		));

		$this->apiFactory->expects($this->once())
			->method('newStatsApi')
			->with('https://pss.example.com', 'alice', 'secret')
			->willReturn($this->statsApi);

		$this->statsApi->expects($this->once())
			->method('updateStats')
			->with(
				'IONOS',
				'tenant-1',
				$this->callback(function (StatsUpdateRequest $req) use ($at): bool {
					return $req->getUsers()?->getExistingUsers() === 42
						&& $req->getTimestamp() == $at;
				}),
			);

		$this->logger->expects($this->once())
			->method('info')
			->with('PssStatsReporter: pushed user stats', $this->callback(function (array $ctx): bool {
				return $ctx['existingUsers'] === 42
					&& $ctx['timestamp'] === '2026-01-01T12:34:56.789Z';
			}));
		$this->logger->expects($this->never())->method('error');

		$this->reporter->reportUserCount(42, $at);
	}

	public function testReportLogsErrorOnThrowableWithoutLeakingException(): void {
		$this->configReader->method('read')->willReturn(new PssConfig(
			'IONOS',
			'tenant-1',
			'https://pss.example.com',
			'alice',
			'secret',
		));
		$this->statsApi->method('updateStats')->willThrowException(new \RuntimeException('connection refused'));

		$this->logger->expects($this->once())
			->method('error')
			->with('PssStatsReporter: failed to push stats to PSS', $this->callback(function (array $ctx): bool {
				return $ctx['exceptionClass'] === \RuntimeException::class
					&& $ctx['message'] === 'connection refused'
					&& !array_key_exists('exception', $ctx);
			}));
		$this->logger->expects($this->never())->method('info');

		$this->reporter->reportUserCount(42, new DateTime('2026-01-01T00:00:00.000', new \DateTimeZone('UTC')));
	}
}
