<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Tests\Unit\Command;

use OCA\NcwTools\Command\SecuritySelfTest;
use OCA\NcwTools\Security\SecuritySelfTest as SecuritySelfTestService;
use PHPUnit\Framework\Attributes\DataProvider;
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

	/**
	 * Whatever defeated json_encode() is in the report, so the info() line
	 * carries it too and the log writer serialises that context the same way.
	 * This scalar-only error line is the only one Kibana can be relied on to
	 * receive, and the documented exit-3 contract promises it.
	 */
	public function testLogsTheReasonTheArtifactCouldNotBeEncoded(): void {
		$artifact = $this->artifact(SecuritySelfTestService::RESULT_PASS);
		$artifact['instance']['name'] = NAN;
		$this->selfTest->method('run')->willReturn($artifact);

		$this->logger->expects($this->once())
			->method('error')
			->with(
				'ncw_tools security selftest: could not encode the evidence artifact',
				$this->callback(fn (array $context): bool => $context === ['jsonError' => 'Inf and NaN cannot be JSON encoded']),
			);

		$this->runCommand(['--output' => 'json']);
	}

	public function testRejectsAnUnknownOutputFormat(): void {
		$this->selfTest->expects($this->never())->method('run');

		$tester = $this->runCommand(['--output' => 'yaml']);

		$this->assertSame(self::EXIT_USAGE, $tester->getStatusCode());
		$this->assertSame('', $tester->getDisplay());
	}

	/**
	 * `99999999999999999999` is the one that matters: ctype_digit() accepts it,
	 * and an (int) cast saturates it to PHP_INT_MAX, so the survey would run
	 * unbounded over the users table on a value the validator rejects.
	 */
	#[DataProvider('invalidSampleSizes')]
	public function testRejectsASampleSizeThatIsNotANonNegativeInteger(string $sampleSize): void {
		$this->selfTest->expects($this->never())->method('run');

		$tester = $this->runCommand(['--output' => 'json', '--sample-size' => $sampleSize]);

		$this->assertSame(self::EXIT_USAGE, $tester->getStatusCode());
		$this->assertSame('', $tester->getDisplay());
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function invalidSampleSizes(): array {
		return [
			'more digits than an int can hold' => ['99999999999999999999'],
			'exactly one digit too many' => ['92233720368547758070'],
			'negative' => ['-1'],
			'not a number' => ['abc'],
			'empty' => [''],
			'fractional' => ['1.5'],
			'scientific notation' => ['1e3'],
			'leading whitespace' => [' 10'],
			'signed' => ['+10'],
		];
	}

	#[DataProvider('validSampleSizes')]
	public function testAcceptsANonNegativeIntegerSampleSize(string $sampleSize, int $expected): void {
		$this->selfTest->expects($this->once())
			->method('run')
			->with(false, $expected)
			->willReturn($this->artifact(SecuritySelfTestService::RESULT_PASS));

		$tester = $this->runCommand(['--output' => 'json', '--sample-size' => $sampleSize]);

		$this->assertSame(self::EXIT_PASS, $tester->getStatusCode());
	}

	/**
	 * @return array<string, array{string, int}>
	 */
	public static function validSampleSizes(): array {
		return [
			'zero surveys all rows' => ['0', 0],
			'the default' => ['1000', 1000],
			'the largest representable int' => [(string)PHP_INT_MAX, PHP_INT_MAX],
		];
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
				// All six checks, because the published schema requires every
				// one of them: a fixture carrying a single check could not be
				// produced by the real command.
				'checks' => [
					$this->check('hashing_default_password', false),
					$this->check('auth.bruteforce.protection.enabled', true),
					$this->check('ratelimit.protection.enabled', true),
					$this->check('overwriteprotocol', 'https'),
					$this->check('passwordsalt_present', true),
					$this->check('secret_present', true),
				],
				'parameters' => (object)['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1],
			],
		];
	}

	/**
	 * @return array{key: string, expected: bool|string, actual: bool|string, result: string}
	 */
	private function check(string $key, bool|string $expected): array {
		return [
			'key' => $key,
			'expected' => $expected,
			'actual' => $expected,
			'result' => SecuritySelfTestService::RESULT_PASS,
		];
	}
}
