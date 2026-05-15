<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Tests\Unit\Stats;

use OCA\NcwTools\Stats\PssConfigReader;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class PssConfigReaderTest extends TestCase {
	private IConfig&MockObject $config;
	private LoggerInterface&MockObject $logger;
	private PssConfigReader $reader;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->reader = new PssConfigReader($this->config, $this->logger);
	}

	public function testReadReturnsConfigWhenAllKeysPresent(): void {
		$this->stubValues([
			'ncw_tools.pss.brand' => 'IONOS',
			'ncw_tools.pss.ext_ref' => 'tenant-1',
			'ncw_tools.pss.base_url' => 'https://pss.example.com',
			'ncw_tools.pss.username' => 'alice',
			'ncw_tools.pss.password' => 'secret',
		]);

		$this->logger->expects($this->never())->method('error');

		$result = $this->reader->read();
		$this->assertNotNull($result);
		$this->assertSame('IONOS', $result->brand);
		$this->assertSame('tenant-1', $result->extRef);
		$this->assertSame('https://pss.example.com', $result->baseUrl);
		$this->assertSame('alice', $result->username);
		$this->assertSame('secret', $result->getPassword());
	}

	public static function provideMissingKey(): array {
		$full = [
			'ncw_tools.pss.brand' => 'IONOS',
			'ncw_tools.pss.ext_ref' => 'tenant-1',
			'ncw_tools.pss.base_url' => 'https://pss.example.com',
			'ncw_tools.pss.username' => 'alice',
			'ncw_tools.pss.password' => 'secret',
		];
		$cases = [];
		foreach (array_keys($full) as $missing) {
			$values = $full;
			$values[$missing] = '';
			$cases[$missing] = [$values, [$missing]];
		}
		$cases['all missing'] = [
			array_fill_keys(array_keys($full), ''),
			array_keys($full),
		];
		return $cases;
	}

	#[DataProvider('provideMissingKey')]
	public function testReadReturnsNullAndLogsMissingKeys(array $values, array $expectedMissing): void {
		$this->stubValues($values);

		$this->logger->expects($this->once())
			->method('error')
			->with(
				'PssConfigReader: missing required PSS configuration',
				$this->callback(fn (array $ctx): bool => $ctx['keys'] === $expectedMissing),
			);

		$this->assertNull($this->reader->read());
	}

	public function testPasswordRedactedFromDebugInfo(): void {
		$this->stubValues([
			'ncw_tools.pss.brand' => 'IONOS',
			'ncw_tools.pss.ext_ref' => 'tenant-1',
			'ncw_tools.pss.base_url' => 'https://pss.example.com',
			'ncw_tools.pss.username' => 'alice',
			'ncw_tools.pss.password' => 'super-secret-value',
		]);
		$config = $this->reader->read();
		$this->assertNotNull($config);

		$dump = print_r($config, true);
		$this->assertStringNotContainsString('super-secret-value', $dump);
		$this->assertStringContainsString('***', $dump);
	}

	private function stubValues(array $values): void {
		$this->config
			->method('getSystemValueString')
			->willReturnCallback(fn (string $key, string $default = '') => $values[$key] ?? $default);
	}
}
