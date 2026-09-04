<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Tests\Unit\Security;

use OCA\NcwTools\Security\HashAlgorithm;
use OCA\NcwTools\Security\SecuritySelfTest;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class SecuritySelfTestTest extends TestCase {
	private const PASSWORD_SALT = 'a-very-secret-password-salt';
	private const SECRET = 'a-very-secret-secret';

	/** The published contract other repos parse the artifact against. */
	private const ARTIFACT_SCHEMA = __DIR__ . '/../../../docs/security-selftest.schema.json';

	private IHasher&MockObject $hasher;
	private IDBConnection&MockObject $db;
	private IConfig&MockObject $config;
	private IUserManager&MockObject $userManager;
	private ISecureRandom&MockObject $random;
	private LoggerInterface&MockObject $logger;

	/** @var list<IQueryBuilder&MockObject> */
	private array $queryBuilders = [];

	protected function setUp(): void {
		parent::setUp();
		$this->hasher = $this->createMock(IHasher::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->config = $this->createMock(IConfig::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->random = $this->createMock(ISecureRandom::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->queryBuilders = [];

		// Deterministic randomness: each call returns the head of the requested
		// character class, so probe passwords stay assertable.
		$this->random->method('generate')
			->willReturnCallback(fn (int $length, string $characters = 'abc'): string => substr(str_repeat($characters, $length), 0, $length));
	}

	public function testPassWhenHashingIsArgon2idAndTheInstanceIsHardened(): void {
		$this->stubHasher(self::argon2id());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id()]);

		$report = $this->selfTest()->run();

		$this->assertSame(SecuritySelfTest::RESULT_PASS, $report['result']);
		$this->assertSame(SecuritySelfTest::RESULT_PASS, $report['password_hashing']['result']);
		$this->assertSame(SecuritySelfTest::RESULT_PASS, $report['security_config']['result']);
		$this->assertSame(HashAlgorithm::ARGON2ID, $report['password_hashing']['configured_algorithm']);
	}

	public function testArtifactMatchesTheAgreedShape(): void {
		$this->stubHasher(self::argon2id());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id()]);

		$report = $this->selfTest()->run();

		$this->assertSame(
			['schema_version', 'timestamp', 'result', 'instance', 'password_hashing', 'security_config'],
			array_keys($report),
		);
		$this->assertSame('3', $report['schema_version']);
		$this->assertSame(
			['id', 'url', 'name', 'namespace', 'environment'],
			array_keys($report['instance']),
		);
		$this->assertSame('inst-1', $report['instance']['id']);
		$this->assertSame('https://cloud.example.com', $report['instance']['url']);
		$this->assertSame(
			['result', 'configured_algorithm', 'round_trip', 'stored_distribution'],
			array_keys($report['password_hashing']),
		);
		$this->assertSame(
			['result', 'stored_algorithm', 'cleaned_up'],
			array_keys($report['password_hashing']['round_trip']),
		);
		$this->assertSame(
			[HashAlgorithm::ARGON2ID, HashAlgorithm::BCRYPT, HashAlgorithm::EMPTY, HashAlgorithm::UNKNOWN],
			array_keys($report['password_hashing']['stored_distribution']),
		);
		$this->assertSame(
			['result', 'checks', 'parameters'],
			array_keys($report['security_config']),
		);
		$this->assertSame(
			['key', 'expected', 'actual', 'result'],
			array_keys($report['security_config']['checks'][0]),
		);
		// An object, not an array: an empty map must not encode as `[]`.
		$this->assertInstanceOf(\stdClass::class, $report['security_config']['parameters']);
		$this->assertSame(
			['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1],
			get_object_vars($report['security_config']['parameters']),
		);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $report['timestamp']);
	}

	public function testInstanceMetadataComesFromTheEnvironment(): void {
		$this->stubHasher(self::argon2id());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id()]);

		putenv('INSTANCE_NAME=customer-42');
		putenv('NAMESPACE=ncw-prod');
		putenv('ENVIRONMENT=production');
		try {
			$report = $this->selfTest()->run();
		} finally {
			putenv('INSTANCE_NAME');
			putenv('NAMESPACE');
			putenv('ENVIRONMENT');
		}

		$this->assertSame('customer-42', $report['instance']['name']);
		$this->assertSame('ncw-prod', $report['instance']['namespace']);
		$this->assertSame('production', $report['instance']['environment']);
	}

	/**
	 * Environment values are arbitrary bytes. Both evidence channels encode the
	 * artifact as JSON, and `json_encode()` returns false on invalid UTF-8 — so
	 * an unencodable label must degrade its own field rather than cost the whole
	 * artifact on stdout and the context of the Kibana line.
	 */
	public function testInvalidUtf8InTheEnvironmentStillYieldsAnEncodableArtifact(): void {
		$this->stubHasher(self::argon2id());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id()]);

		// A lone 0x80 continuation byte: valid Latin-1, never valid UTF-8.
		putenv("INSTANCE_NAME=ncw-\x80-prod");
		try {
			$report = $this->selfTest()->run();
		} finally {
			putenv('INSTANCE_NAME');
		}

		$this->assertTrue(
			mb_check_encoding($report['instance']['name'], 'UTF-8'),
			'the instance label must be valid UTF-8 once sanitised',
		);
		$this->assertNotFalse(
			json_encode($report),
			'the artifact must survive json_encode() without the substitute flag',
		);
		$this->assertStringContainsString('ncw-', $report['instance']['name']);
		$this->assertStringContainsString('-prod', $report['instance']['name']);
	}

	public function testInstanceMetadataIsEmptyWithoutEnvironmentVariables(): void {
		$this->stubHasher(self::argon2id());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id()]);

		$report = $this->selfTest()->run();

		$this->assertSame('', $report['instance']['name']);
		$this->assertSame('', $report['instance']['namespace']);
		$this->assertSame('', $report['instance']['environment']);
	}

	public function testFailWhenConfiguredAlgorithmIsBcrypt(): void {
		$this->stubHasher(self::bcrypt());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id()]);

		$report = $this->selfTest()->run();

		$this->assertSame(HashAlgorithm::BCRYPT, $report['password_hashing']['configured_algorithm']);
		$this->assertSame(SecuritySelfTest::RESULT_FAIL, $report['password_hashing']['result']);
		$this->assertSame(SecuritySelfTest::RESULT_FAIL, $report['result']);
		// The hardening checks are independent of the hasher probe.
		$this->assertSame(SecuritySelfTest::RESULT_PASS, $report['security_config']['result']);
		$this->assertSame(['cost' => 10], get_object_vars($report['security_config']['parameters']));
	}

	public function testFailWhenConfiguredAlgorithmIsArgon2i(): void {
		$this->stubHasher(self::argon2i());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id()]);

		$report = $this->selfTest()->run();

		$this->assertSame(HashAlgorithm::ARGON2I, $report['password_hashing']['configured_algorithm']);
		$this->assertSame(SecuritySelfTest::RESULT_FAIL, $report['result']);
	}

	/**
	 * @return array<string, array{string, array<string, bool|string>}>
	 */
	public static function provideBrokenSecurityConfig(): array {
		return [
			'hashing_default_password enabled downgrades the hasher' => [
				'hashing_default_password',
				['hashing_default_password' => true],
			],
			'brute force protection disabled' => [
				'auth.bruteforce.protection.enabled',
				['auth.bruteforce.protection.enabled' => false],
			],
			'rate limit protection disabled' => [
				'ratelimit.protection.enabled',
				['ratelimit.protection.enabled' => false],
			],
			'plain http' => [
				'overwriteprotocol',
				['overwriteprotocol' => 'http'],
			],
			'protocol not configured' => [
				'overwriteprotocol',
				['overwriteprotocol' => ''],
			],
			'password salt missing' => [
				'passwordsalt_present',
				['passwordsalt' => ''],
			],
			'secret missing' => [
				'secret_present',
				['secret' => ''],
			],
		];
	}

	/**
	 * @param array<string, bool|string> $overrides
	 */
	#[DataProvider('provideBrokenSecurityConfig')]
	public function testFailForEachSecurityConfigAssertion(string $failingKey, array $overrides): void {
		$this->stubHasher(self::argon2id());
		$this->stubConfig($overrides);
		$this->stubDatabase([self::argon2id()]);

		$report = $this->selfTest()->run();

		$this->assertSame(SecuritySelfTest::RESULT_FAIL, $report['security_config']['result']);
		$this->assertSame(SecuritySelfTest::RESULT_FAIL, $report['result']);
		// Only the broken assertion fails; the password hashing checks are untouched.
		$this->assertSame(SecuritySelfTest::RESULT_PASS, $report['password_hashing']['result']);

		$failed = [];
		foreach ($report['security_config']['checks'] as $check) {
			if ($check['result'] === SecuritySelfTest::RESULT_FAIL) {
				$failed[] = $check['key'];
			}
		}
		$this->assertSame([$failingKey], $failed);
	}

	public function testSecurityConfigReportsSecretPresenceOnly(): void {
		$this->stubHasher(self::argon2id());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id()]);

		$report = $this->selfTest()->run();

		$presence = [];
		foreach ($report['security_config']['checks'] as $check) {
			$presence[$check['key']] = $check['actual'];
		}
		$this->assertTrue($presence['passwordsalt_present']);
		$this->assertTrue($presence['secret_present']);

		$encoded = json_encode($report);
		$this->assertIsString($encoded);
		$this->assertStringNotContainsString(self::PASSWORD_SALT, $encoded);
		$this->assertStringNotContainsString(self::SECRET, $encoded);
	}

	public function testArtifactNeverCarriesHashMaterial(): void {
		$this->stubHasher(self::argon2id());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id(), self::bcrypt()], self::argon2id());

		$report = $this->selfTest()->run(true);

		$encoded = json_encode($report);
		$this->assertIsString($encoded);
		foreach ([self::argon2id(), self::bcrypt(), self::argon2i()] as $hash) {
			$this->assertStringNotContainsString($hash, $encoded);
			// Not even the bare hash without the version prefix.
			$this->assertStringNotContainsString(explode('|', $hash, 2)[1], $encoded);
		}
	}

	public function testStoredDistributionCountsEveryAlgorithm(): void {
		$this->stubHasher(self::argon2id());
		$this->stubConfig();
		$this->stubDatabase([
			self::argon2id(),
			self::argon2id(),
			self::argon2i(),
			self::bcrypt(),
			self::legacyBcrypt(),
			self::legacySha1(),
			'',
			'garbage',
		]);

		$report = $this->selfTest()->run();

		$this->assertSame([
			HashAlgorithm::ARGON2ID => 2,
			HashAlgorithm::BCRYPT => 1,
			HashAlgorithm::EMPTY => 1,
			HashAlgorithm::UNKNOWN => 1,
			HashAlgorithm::ARGON2I => 1,
			HashAlgorithm::LEGACY_BCRYPT => 1,
			HashAlgorithm::LEGACY_SHA1 => 1,
		], $report['password_hashing']['stored_distribution']);
		$this->assertSame(SecuritySelfTest::RESULT_FAIL, $report['password_hashing']['result']);
	}

	public function testStoredHashesWithoutAPasswordDoNotFailTheSurvey(): void {
		$this->stubHasher(self::argon2id());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id(), '', '', '']);

		$report = $this->selfTest()->run();

		$this->assertSame(
			[HashAlgorithm::ARGON2ID => 1, HashAlgorithm::BCRYPT => 0, HashAlgorithm::EMPTY => 3, HashAlgorithm::UNKNOWN => 0],
			$report['password_hashing']['stored_distribution'],
		);
		$this->assertSame(SecuritySelfTest::RESULT_PASS, $report['result']);
	}

	public function testAStoredBcryptHashFailsTheSurvey(): void {
		$this->stubHasher(self::argon2id());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id(), self::bcrypt()]);

		$report = $this->selfTest()->run();

		$this->assertSame(1, $report['password_hashing']['stored_distribution'][HashAlgorithm::BCRYPT]);
		$this->assertSame(SecuritySelfTest::RESULT_FAIL, $report['password_hashing']['result']);
		$this->assertSame(SecuritySelfTest::RESULT_FAIL, $report['result']);
	}

	public function testSampleSizeLimitsTheSurvey(): void {
		$this->stubHasher(self::argon2id());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id()]);

		$this->queryBuilders[0]->expects($this->once())->method('setMaxResults')->with(250);

		$this->selfTest()->run(false, 250);
	}

	public function testSampleSizeZeroSurveysEveryRow(): void {
		$this->stubHasher(self::argon2id());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id()]);

		$this->queryBuilders[0]->expects($this->never())->method('setMaxResults');

		$this->selfTest()->run(false, 0);
	}

	public function testRoundTripIsSkippedUnlessRequested(): void {
		$this->stubHasher(self::argon2id());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id()]);

		$this->userManager->expects($this->never())->method('createUser');

		$report = $this->selfTest()->run();

		$this->assertSame([
			'result' => SecuritySelfTest::RESULT_SKIPPED,
			'stored_algorithm' => null,
			'cleaned_up' => null,
		], $report['password_hashing']['round_trip']);
		// A skipped round trip must not drag the overall result down.
		$this->assertSame(SecuritySelfTest::RESULT_PASS, $report['result']);
	}

	public function testRoundTripPassesAndRemovesTheProbeUser(): void {
		$this->stubHasher(self::argon2id());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id()], self::argon2id());

		$user = $this->createMock(IUser::class);
		$user->expects($this->once())->method('delete')->willReturn(true);
		// A disposable probe account must never get an email address.
		$user->expects($this->never())->method('setEMailAddress');

		$this->userManager->expects($this->once())
			->method('createUser')
			->with($this->stringStartsWith('ncw-selftest-'), $this->anything())
			->willReturn($user);
		$this->userManager->method('get')->willReturn(null);
		$this->logger->expects($this->never())->method('error');

		$report = $this->selfTest()->run(true);

		$this->assertSame([
			'result' => SecuritySelfTest::RESULT_PASS,
			'stored_algorithm' => HashAlgorithm::ARGON2ID,
			'cleaned_up' => true,
		], $report['password_hashing']['round_trip']);
		$this->assertSame(SecuritySelfTest::RESULT_PASS, $report['result']);
	}

	public function testRoundTripProbePasswordUsesAllCharacterClasses(): void {
		$this->stubHasher(self::argon2id());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id()], self::argon2id());

		$user = $this->createMock(IUser::class);
		$password = null;
		$this->userManager->method('createUser')
			->willReturnCallback(function (string $uid, string $given) use ($user, &$password): IUser {
				$password = $given;
				return $user;
			});
		$this->userManager->method('get')->willReturn(null);

		$this->selfTest()->run(true);

		$this->assertIsString($password);
		$this->assertGreaterThanOrEqual(12, strlen($password));
		$this->assertMatchesRegularExpression('/[a-z]/', $password);
		$this->assertMatchesRegularExpression('/[A-Z]/', $password);
		$this->assertMatchesRegularExpression('/[0-9]/', $password);
		$this->assertMatchesRegularExpression('/[^a-zA-Z0-9]/', $password);
	}

	public function testRoundTripFailsWhenTheStoredHashIsNotArgon2id(): void {
		$this->stubHasher(self::argon2id());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id()], self::bcrypt());

		$user = $this->createMock(IUser::class);
		$user->expects($this->once())->method('delete')->willReturn(true);
		$this->userManager->method('createUser')->willReturn($user);
		$this->userManager->method('get')->willReturn(null);

		$report = $this->selfTest()->run(true);

		$this->assertSame([
			'result' => SecuritySelfTest::RESULT_FAIL,
			'stored_algorithm' => HashAlgorithm::BCRYPT,
			'cleaned_up' => true,
		], $report['password_hashing']['round_trip']);
		$this->assertSame(SecuritySelfTest::RESULT_FAIL, $report['result']);
	}

	public function testRoundTripFailsWhenTheProbeUserSurvives(): void {
		$this->stubHasher(self::argon2id());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id()], self::argon2id());

		$user = $this->createMock(IUser::class);
		$this->userManager->method('createUser')->willReturn($user);
		$this->userManager->method('get')->willReturn($user);

		$this->logger->expects($this->once())
			->method('error')
			->with('SecuritySelfTest: round-trip probe user still exists', $this->anything());

		$report = $this->selfTest()->run(true);

		$this->assertSame([
			'result' => SecuritySelfTest::RESULT_FAIL,
			'stored_algorithm' => HashAlgorithm::ARGON2ID,
			'cleaned_up' => false,
		], $report['password_hashing']['round_trip']);
		$this->assertSame(SecuritySelfTest::RESULT_FAIL, $report['result']);
	}

	public function testRoundTripFailsAndLogsWhenUserCreationThrows(): void {
		$this->stubHasher(self::argon2id());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id()]);

		$this->userManager->method('createUser')
			->willThrowException(new \InvalidArgumentException('Password is among the most common ones'));
		$this->userManager->method('get')->willReturn(null);

		$this->logger->expects($this->once())
			->method('error')
			->with(
				'SecuritySelfTest: round-trip probe failed',
				$this->callback(fn (array $context): bool => $context['exceptionClass'] === \InvalidArgumentException::class
					&& $context['message'] === 'Password is among the most common ones'
					&& !array_key_exists('exception', $context)),
			);

		$report = $this->selfTest()->run(true);

		$this->assertSame([
			'result' => SecuritySelfTest::RESULT_FAIL,
			'stored_algorithm' => null,
			'cleaned_up' => true,
		], $report['password_hashing']['round_trip']);
		$this->assertSame(SecuritySelfTest::RESULT_FAIL, $report['result']);
	}

	public function testRoundTripFailsAndLogsWhenUserCreationReturnsFalse(): void {
		$this->stubHasher(self::argon2id());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id()]);

		$this->userManager->method('createUser')->willReturn(false);
		$this->userManager->method('get')->willReturn(null);

		$this->logger->expects($this->once())
			->method('error')
			->with('SecuritySelfTest: could not create the round-trip probe user', $this->anything());

		$report = $this->selfTest()->run(true);

		$this->assertSame(SecuritySelfTest::RESULT_FAIL, $report['password_hashing']['round_trip']['result']);
		$this->assertNull($report['password_hashing']['round_trip']['stored_algorithm']);
	}

	public function testThePublishedSchemaTracksTheProducersSchemaVersion(): void {
		$schema = json_decode((string)file_get_contents(self::ARTIFACT_SCHEMA), true, 512, JSON_THROW_ON_ERROR);

		$this->assertIsArray($schema);
		$this->assertSame(
			SecuritySelfTest::SCHEMA_VERSION,
			$schema['properties']['schema_version']['const'],
			'The schema pins a schema_version the producer no longer emits',
		);
	}

	public function testAPassingArtifactMatchesThePublishedSchema(): void {
		$this->stubHasher(self::argon2id());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id()]);

		$report = $this->selfTest()->run();

		$this->assertSame(SecuritySelfTest::RESULT_PASS, $report['result']);
		$this->assertMatchesArtifactSchema($report);
	}

	public function testAFailingArtifactMatchesThePublishedSchema(): void {
		// Both halves failing at once: a downgraded hasher and plain http.
		$this->stubHasher(self::bcrypt());
		$this->stubConfig(['overwriteprotocol' => 'http']);
		$this->stubDatabase([self::argon2id(), self::legacySha1(), '']);

		$report = $this->selfTest()->run();

		$this->assertSame(SecuritySelfTest::RESULT_FAIL, $report['result']);
		$this->assertMatchesArtifactSchema($report);
	}

	public function testARoundTripArtifactMatchesThePublishedSchema(): void {
		$this->stubHasher(self::argon2id());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id()], self::argon2id());

		$user = $this->createMock(IUser::class);
		$user->method('delete')->willReturn(true);
		$this->userManager->method('createUser')->willReturn($user);
		$this->userManager->method('get')->willReturn(null);

		$report = $this->selfTest()->run(true);

		$this->assertSame(SecuritySelfTest::RESULT_PASS, $report['password_hashing']['round_trip']['result']);
		$this->assertMatchesArtifactSchema($report);
	}

	public function testAnArtifactWithoutCostParametersKeepsParametersAnObject(): void {
		// A probe hash password_get_info() cannot read cost fields from, which
		// is what a PHP build without argon2 support would produce for an
		// argon2 hash. The empty map must not degrade into a JSON array.
		$this->stubHasher(self::legacySha1());
		$this->stubConfig();
		$this->stubDatabase([self::argon2id()]);

		$report = $this->selfTest()->run();

		$this->assertEquals(new \stdClass(), $report['security_config']['parameters']);
		$this->assertStringContainsString('"parameters":{}', $this->encodeArtifact($report));
		$this->assertMatchesArtifactSchema($report);
	}

	/**
	 * Encodes the artifact exactly as the command does, so what is validated is
	 * what a consumer actually receives on stdout and in the Kibana context.
	 *
	 * @param array<string, mixed> $report
	 */
	private function encodeArtifact(array $report): string {
		return (string)json_encode($report, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
	}

	/**
	 * @param array<string, mixed> $report
	 */
	private function assertMatchesArtifactSchema(array $report): void {
		$schema = json_decode((string)file_get_contents(self::ARTIFACT_SCHEMA), false, 512, JSON_THROW_ON_ERROR);
		$artifact = json_decode($this->encodeArtifact($report), false, 512, JSON_THROW_ON_ERROR);

		$result = (new Validator())->validate($artifact, $schema);

		$this->assertTrue(
			$result->isValid(),
			$result->hasError()
				? 'Artifact does not match docs/security-selftest.schema.json: '
					. (string)json_encode((new ErrorFormatter())->format($result->error()), JSON_PRETTY_PRINT)
				: '',
		);
	}

	private function selfTest(): SecuritySelfTest {
		return new SecuritySelfTest(
			$this->hasher,
			$this->db,
			$this->config,
			$this->userManager,
			$this->random,
			$this->logger,
		);
	}

	private function stubHasher(string $hash): void {
		$this->hasher->method('hash')->willReturn($hash);
	}

	/**
	 * @param array<string, bool|string> $overrides
	 */
	private function stubConfig(array $overrides = []): void {
		$values = array_merge([
			'instanceid' => 'inst-1',
			'overwrite.cli.url' => 'https://cloud.example.com',
			'overwriteprotocol' => 'https',
			'passwordsalt' => self::PASSWORD_SALT,
			'secret' => self::SECRET,
			'hashing_default_password' => false,
			'auth.bruteforce.protection.enabled' => true,
			'ratelimit.protection.enabled' => true,
		], $overrides);

		$this->config->method('getSystemValueString')
			->willReturnCallback(function (string $key, string $default = '') use ($values): string {
				$value = $values[$key] ?? $default;
				return is_string($value) ? $value : $default;
			});
		$this->config->method('getSystemValueBool')
			->willReturnCallback(function (string $key, bool $default = false) use ($values): bool {
				$value = $values[$key] ?? $default;
				return is_bool($value) ? $value : $default;
			});
	}

	/**
	 * The service asks for one query builder to survey the stored hashes and, for
	 * a round trip, a second one to read the probe user's hash back.
	 *
	 * @param list<string> $storedHashes
	 */
	private function stubDatabase(array $storedHashes, ?string $roundTripHash = null): void {
		$surveyResult = $this->createMock(IResult::class);
		$rows = array_map(static fn (string $hash): array => ['password' => $hash], $storedHashes);
		$rows[] = false;
		$surveyResult->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
		$this->queryBuilders[] = $this->stubQueryBuilder($surveyResult);

		if ($roundTripHash !== null) {
			$roundTripResult = $this->createMock(IResult::class);
			$roundTripResult->method('fetchOne')->willReturn($roundTripHash);
			$this->queryBuilders[] = $this->stubQueryBuilder($roundTripResult);
		}

		$this->db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(...$this->queryBuilders);
	}

	private function stubQueryBuilder(IResult&MockObject $result): IQueryBuilder&MockObject {
		$query = $this->createMock(IQueryBuilder::class);
		$query->method('select')->willReturnSelf();
		$query->method('from')->willReturnSelf();
		$query->method('where')->willReturnSelf();
		$query->method('setMaxResults')->willReturnSelf();
		$query->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$query->method('createNamedParameter')->willReturn(':uid');
		$query->method('executeQuery')->willReturn($result);

		return $query;
	}

	private static function argon2id(): string {
		return '3|$argon2id$v=19$m=65536,t=4,p=1$QWg3b3ptdUY2bTMyLm1VSA$shWk3Zo2H45opssZuQLI/XERpr+n4BOC53D4i24CTRE';
	}

	private static function argon2i(): string {
		return '2|$argon2i$v=19$m=65536,t=4,p=1$dFcyWjAyNWI3QTJUS2VVWg$neelG9YBu6q+p8TKXAzqAQjQXraqCRisUflPMxjeHLQ';
	}

	private static function bcrypt(): string {
		return '1|$2y$10$nv5Wx10BmWJnGFj7mhFGPeUaW8wNnFRqP0g09H93X/9yDhxNBjv/m';
	}

	private static function legacyBcrypt(): string {
		return '$2y$10$nv5Wx10BmWJnGFj7mhFGPeUaW8wNnFRqP0g09H93X/9yDhxNBjv/m';
	}

	private static function legacySha1(): string {
		return sha1('legacy');
	}
}
