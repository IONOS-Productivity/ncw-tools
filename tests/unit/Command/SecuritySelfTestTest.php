<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Tests\Unit\Command;

use OCA\NcwTools\Command\SecuritySelfTest;
use OCA\NcwTools\Security\SecuritySelfTest as SecuritySelfTestService;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Test\TestCase;

/**
 * Covers the command's side of the contract the deployment wrapper depends on:
 * which exit code means what, and that stdout carries the artifact and nothing
 * else. The evidence collection itself is covered against the service.
 */
class SecuritySelfTestTest extends TestCase {
	private const EXIT_PASS = 0;
	private const EXIT_FAIL = 1;
	private const EXIT_USAGE = 2;
	private const EXIT_INTERNAL_ERROR = 3;

	private SecuritySelfTestService&MockObject $selfTest;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->selfTest = $this->createMock(SecuritySelfTestService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	public function testWritesTheArtifactAndExitsZeroOnPass(): void {
		$this->selfTest->method('run')->willReturn($this->artifact(SecuritySelfTestService::RESULT_PASS));

		$tester = $this->runCommand(['--output' => 'json']);

		$this->assertSame(self::EXIT_PASS, $tester->getStatusCode());
		$this->assertSame(
			SecuritySelfTestService::RESULT_PASS,
			$this->decodeStdout($tester)['result'],
		);
		$this->assertSame('', $tester->getErrorOutput());
	}

	/**
	 * The case the evidence exists for: an exit 1 must still carry the whole
	 * artifact, because a FAIL is precisely what the report has to capture.
	 */
	public function testWritesTheCompleteArtifactAndExitsOneOnFail(): void {
		$this->selfTest->method('run')->willReturn($this->artifact(SecuritySelfTestService::RESULT_FAIL));

		$tester = $this->runCommand(['--output' => 'json']);

		$this->assertSame(self::EXIT_FAIL, $tester->getStatusCode());
		$artifact = $this->decodeStdout($tester);
		$this->assertSame(SecuritySelfTestService::RESULT_FAIL, $artifact['result']);
		$this->assertSame(
			['schema_version', 'timestamp', 'result', 'instance', 'password_hashing', 'security_config'],
			array_keys($artifact),
			'A FAIL must not short-circuit the artifact',
		);
	}

	/**
	 * Unguarded, the throwable would reach Symfony, which exits with
	 * $e->getCode() clamped to 255 rather than a documented code, writes
	 * nothing to the log, and leaves the wrapper with empty stdout it cannot
	 * tell apart from a crash.
	 */
	public function testExitsThreeAndLogsWhenTheSelfTestThrows(): void {
		$this->selfTest->method('run')
			->willThrowException(new \RuntimeException('An exception occurred while executing a query'));

		$this->logger->expects($this->once())
			->method('error')
			->with(
				'ncw_tools security selftest: could not collect the evidence artifact',
				$this->callback(fn (array $context): bool => $context['exceptionClass'] === \RuntimeException::class
					&& $context['exceptionMessage'] === 'An exception occurred while executing a query'
					// Deep frames can carry the round-trip probe password in
					// their stack-trace arguments.
					&& !array_key_exists('exception', $context)),
			);

		$tester = $this->runCommand(['--output' => 'json']);

		$this->assertSame(self::EXIT_INTERNAL_ERROR, $tester->getStatusCode());
		$this->assertStringContainsString(
			'An exception occurred while executing a query',
			$tester->getErrorOutput(),
		);
	}

	/**
	 * An internal error is not a verdict, so nothing may reach the wrapper's
	 * `jq` and nothing may be logged as evidence.
	 */
	public function testWritesNoArtifactWhenTheSelfTestThrows(): void {
		$this->selfTest->method('run')->willThrowException(new \RuntimeException('the database has gone away'));

		$this->logger->expects($this->never())->method('info');

		$tester = $this->runCommand(['--output' => 'json']);

		$this->assertSame('', $tester->getDisplay(), 'stdout must stay empty on an internal error');
	}

	/**
	 * A driver exception message can contain angle brackets -- a failing query
	 * with a comparison, for instance -- which the console formatter would
	 * otherwise read as markup.
	 */
	public function testEscapesConsoleMarkupInTheDiagnostic(): void {
		$this->selfTest->method('run')
			->willThrowException(new \RuntimeException('WHERE mtime <comment> 0 failed'));

		$tester = $this->runCommand(['--output' => 'json']);

		$this->assertStringContainsString('WHERE mtime <comment> 0 failed', $tester->getErrorOutput());
	}

	public function testExitsThreeWhenTheArtifactCannotBeEncoded(): void {
		$artifact = $this->artifact(SecuritySelfTestService::RESULT_PASS);
		// json_encode() cannot represent this, and unlike invalid UTF-8 it is
		// not covered by JSON_INVALID_UTF8_SUBSTITUTE.
		$artifact['instance']['name'] = NAN;
		$this->selfTest->method('run')->willReturn($artifact);

		$tester = $this->runCommand(['--output' => 'json']);

		$this->assertSame(
			self::EXIT_INTERNAL_ERROR,
			$tester->getStatusCode(),
			'An encode failure is not a control FAIL, and exiting 1 would break the promise that an exit 1 carries the artifact',
		);
		$this->assertSame('', $tester->getDisplay());
	}

	public function testRejectsAnUnknownOutputFormat(): void {
		$this->selfTest->expects($this->never())->method('run');

		$tester = $this->runCommand(['--output' => 'yaml']);

		$this->assertSame(self::EXIT_USAGE, $tester->getStatusCode());
		$this->assertSame('', $tester->getDisplay());
	}

	/**
	 * @param array<string, string|bool> $input
	 */
	private function runCommand(array $input): CommandTester {
		$command = new SecuritySelfTest($this->selfTest, $this->logger);
		$tester = new CommandTester($command);
		$tester->execute($input, ['capture_stderr_separately' => true]);

		return $tester;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decodeStdout(CommandTester $tester): array {
		$decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray($decoded);

		return $decoded;
	}

	/**
	 * A complete artifact in the shape the service returns, so what the command
	 * is asked to write is what it would really receive.
	 *
	 * @return array<string, mixed>
	 */
	private function artifact(string $result): array {
		return [
			'schema_version' => SecuritySelfTestService::SCHEMA_VERSION,
			'timestamp' => '2026-09-01T12:00:00Z',
			'result' => $result,
			'instance' => [
				'id' => 'oc1234567890',
				'url' => 'https://cloud.example.com',
				'name' => 'selftest',
				'namespace' => 'ncw',
				'environment' => 'development',
			],
			'password_hashing' => [
				'result' => $result,
				'configured_algorithm' => 'argon2id',
				'round_trip' => ['result' => 'SKIPPED', 'stored_algorithm' => null, 'cleaned_up' => null],
				'stored_distribution' => ['argon2id' => 1, 'bcrypt' => 0, 'empty' => 0, 'unknown' => 0],
			],
			'security_config' => [
				'result' => $result,
				'checks' => [[
					'key' => 'hashing_default_password',
					'expected' => false,
					'actual' => false,
					'result' => 'PASS',
				]],
				'parameters' => (object)['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1],
			],
		];
	}
}
