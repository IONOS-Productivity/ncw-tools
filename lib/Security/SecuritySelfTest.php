<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Security;

use OCP\DB\IResult;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Evidence collector for C5 control PSS-07: password hashing must be argon2id.
 *
 * The service produces the evidence artifact as a plain array and writes no
 * output of its own, so the occ command and the tests share a single code path.
 *
 * Security invariant: no value in the returned artifact may carry hash material,
 * a salt or a secret. Only algorithm names, counts, booleans and cost parameters
 * are reported; `passwordsalt` and `secret` are reported as presence only.
 */
class SecuritySelfTest {
	public const SCHEMA_VERSION = '3';

	public const RESULT_PASS = 'PASS';
	public const RESULT_FAIL = 'FAIL';
	public const RESULT_SKIPPED = 'SKIPPED';

	/** Stored hashes surveyed unless a different `--sample-size` is given; 0 surveys all. */
	public const DEFAULT_SAMPLE_SIZE = 1000;

	/** The algorithm PSS-07 requires. */
	private const EXPECTED_ALGORITHM = HashAlgorithm::ARGON2ID;

	private const ROUND_TRIP_UID_PREFIX = 'ncw-selftest-';

	/**
	 * Buckets always present in `stored_distribution`, in artifact order. Other
	 * algorithms are reported additionally, but only when actually observed.
	 */
	private const REPORTED_BUCKETS = [
		HashAlgorithm::ARGON2ID,
		HashAlgorithm::BCRYPT,
		HashAlgorithm::EMPTY,
		HashAlgorithm::UNKNOWN,
	];

	/**
	 * Rows with no local password at all (SSO-only accounts) are not a hashing
	 * downgrade, so they do not fail the survey.
	 */
	private const TOLERATED_BUCKETS = [
		HashAlgorithm::ARGON2ID,
		HashAlgorithm::EMPTY,
	];

	public function __construct(
		private IHasher $hasher,
		private IDBConnection $db,
		private IConfig $config,
		private IUserManager $userManager,
		private ISecureRandom $random,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Collects the evidence artifact.
	 *
	 * @param bool $roundTrip Create and delete a disposable probe user to observe
	 *                        the algorithm actually written to the database
	 * @param int $sampleSize Number of stored hashes to survey; 0 surveys all
	 *
	 * @return array{
	 *     schema_version: string,
	 *     timestamp: string,
	 *     result: self::RESULT_PASS|self::RESULT_FAIL,
	 *     instance: array{id: string, url: string, name: string, namespace: string, environment: string},
	 *     password_hashing: array{
	 *         result: self::RESULT_PASS|self::RESULT_FAIL,
	 *         configured_algorithm: string,
	 *         round_trip: array{result: string, stored_algorithm: string|null, cleaned_up: bool|null},
	 *         stored_distribution: array<string, int>
	 *     },
	 *     security_config: array{
	 *         result: self::RESULT_PASS|self::RESULT_FAIL,
	 *         checks: list<array{key: string, expected: bool|string, actual: bool|string, result: string}>,
	 *         parameters: array<string, int>
	 *     }
	 * }
	 */
	public function run(bool $roundTrip = false, int $sampleSize = self::DEFAULT_SAMPLE_SIZE): array {
		// One probe hash serves both checks: it reveals the algorithm every new
		// password gets, and the cost parameters it was actually produced with.
		// The probe hash itself is never reported.
		$probeHash = $this->hasher->hash($this->probeMessage());

		$passwordHashing = $this->checkPasswordHashing($probeHash, $roundTrip, $sampleSize);
		$securityConfig = $this->checkSecurityConfig($probeHash);

		return [
			'schema_version' => self::SCHEMA_VERSION,
			'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
			'result' => $this->verdict(
				$passwordHashing['result'] === self::RESULT_PASS
				&& $securityConfig['result'] === self::RESULT_PASS,
			),
			'instance' => $this->describeInstance(),
			'password_hashing' => $passwordHashing,
			'security_config' => $securityConfig,
		];
	}

	/**
	 * @return array{
	 *     result: self::RESULT_PASS|self::RESULT_FAIL,
	 *     configured_algorithm: string,
	 *     round_trip: array{result: string, stored_algorithm: string|null, cleaned_up: bool|null},
	 *     stored_distribution: array<string, int>
	 * }
	 */
	private function checkPasswordHashing(string $probeHash, bool $roundTrip, int $sampleSize): array {
		$configuredAlgorithm = HashAlgorithm::fromStoredHash($probeHash);
		$distribution = $this->surveyStoredHashes($sampleSize);
		$roundTripReport = $roundTrip
			? $this->runRoundTrip()
			: ['result' => self::RESULT_SKIPPED, 'stored_algorithm' => null, 'cleaned_up' => null];

		$passed = $configuredAlgorithm === self::EXPECTED_ALGORITHM
			&& $this->surveyPassed($distribution)
			&& $roundTripReport['result'] !== self::RESULT_FAIL;

		return [
			'result' => $this->verdict($passed),
			'configured_algorithm' => $configuredAlgorithm,
			'round_trip' => $roundTripReport,
			'stored_distribution' => $distribution,
		];
	}

	/**
	 * Counts stored hashes per algorithm. Only counts are returned — never a hash.
	 *
	 * @return array<string, int>
	 */
	private function surveyStoredHashes(int $sampleSize): array {
		$counts = array_fill_keys(self::REPORTED_BUCKETS, 0);

		$query = $this->db->getQueryBuilder();
		// Table name without prefix on purpose: the query builder adds `oc_`.
		$query->select('password')->from('users');
		if ($sampleSize > 0) {
			$query->setMaxResults($sampleSize);
		}

		$result = $query->executeQuery();
		try {
			while (($stored = $this->nextStoredHash($result)) !== null) {
				$algorithm = HashAlgorithm::fromStoredHash($stored);
				$counts[$algorithm] = ($counts[$algorithm] ?? 0) + 1;
			}
		} finally {
			$result->closeCursor();
		}

		return $counts;
	}

	/**
	 * The next `password` value of the result, or null once it is exhausted.
	 *
	 * `IResult::fetchAssociative()` would be typed, but it only exists since
	 * Nextcloud 33 and this app supports 31.
	 */
	private function nextStoredHash(IResult $result): ?string {
		/** @var array<string, mixed>|false $row */
		$row = $result->fetch();
		if ($row === false) {
			return null;
		}

		return $this->asString($row['password'] ?? null);
	}

	/**
	 * @param array<string, int> $distribution
	 */
	private function surveyPassed(array $distribution): bool {
		foreach ($distribution as $algorithm => $count) {
			if ($count > 0 && !in_array($algorithm, self::TOLERATED_BUCKETS, true)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Hashes a throwaway password through the real user pipeline and reads back
	 * what landed in the database, then removes the probe user again.
	 *
	 * @return array{result: string, stored_algorithm: string|null, cleaned_up: bool|null}
	 */
	private function runRoundTrip(): array {
		$uid = self::ROUND_TRIP_UID_PREFIX . $this->random->generate(12, ISecureRandom::CHAR_ALPHANUMERIC);
		$user = null;
		$storedAlgorithm = null;

		try {
			// Deliberately no email address: a disposable probe account must not
			// be able to receive mail or a password reset link.
			$user = $this->userManager->createUser($uid, $this->probePassword());
			if ($user === false) {
				$this->logger->error('SecuritySelfTest: could not create the round-trip probe user', ['uid' => $uid]);
			} else {
				$storedAlgorithm = HashAlgorithm::fromStoredHash($this->readStoredHash($uid));
			}
		} catch (\Throwable $e) {
			// Message only, never the exception: deep frames can carry the
			// throwaway password in their stack-trace arguments.
			$this->logger->error('SecuritySelfTest: round-trip probe failed', [
				'uid' => $uid,
				'exceptionClass' => $e::class,
				'message' => $e->getMessage(),
			]);
		} finally {
			if ($user !== null && $user !== false) {
				try {
					$user->delete();
				} catch (\Throwable $e) {
					$this->logger->error('SecuritySelfTest: could not delete the round-trip probe user', [
						'uid' => $uid,
						'exceptionClass' => $e::class,
						'message' => $e->getMessage(),
					]);
				}
			}
		}

		$cleanedUp = $this->userManager->get($uid) === null;
		if (!$cleanedUp) {
			$this->logger->error('SecuritySelfTest: round-trip probe user still exists', ['uid' => $uid]);
		}

		return [
			'result' => $this->verdict($storedAlgorithm === self::EXPECTED_ALGORITHM && $cleanedUp),
			'stored_algorithm' => $storedAlgorithm,
			'cleaned_up' => $cleanedUp,
		];
	}

	private function readStoredHash(string $uid): string {
		$query = $this->db->getQueryBuilder();
		$query->select('password')
			->from('users')
			->where($query->expr()->eq('uid', $query->createNamedParameter($uid)));

		$result = $query->executeQuery();
		try {
			return $this->asString($result->fetchOne());
		} finally {
			$result->closeCursor();
		}
	}

	/**
	 * Reads the hardening switches through IConfig, so the *effective merged*
	 * configuration is asserted rather than a single config file.
	 *
	 * @return array{
	 *     result: self::RESULT_PASS|self::RESULT_FAIL,
	 *     checks: list<array{key: string, expected: bool|string, actual: bool|string, result: string}>,
	 *     parameters: array<string, int>
	 * }
	 */
	private function checkSecurityConfig(string $probeHash): array {
		$checks = [
			// The real downgrade switch: Hasher::getPrefferedAlgorithm() falls
			// back to PASSWORD_DEFAULT (bcrypt today) when this is true.
			$this->check('hashing_default_password', false, $this->config->getSystemValueBool('hashing_default_password', false)),
			$this->check('auth.bruteforce.protection.enabled', true, $this->config->getSystemValueBool('auth.bruteforce.protection.enabled', true)),
			$this->check('ratelimit.protection.enabled', true, $this->config->getSystemValueBool('ratelimit.protection.enabled', true)),
			$this->check('overwriteprotocol', 'https', $this->config->getSystemValueString('overwriteprotocol', '')),
			// Presence only — the values are secrets and must never be reported.
			$this->check('passwordsalt_present', true, $this->config->getSystemValueString('passwordsalt', '') !== ''),
			$this->check('secret_present', true, $this->config->getSystemValueString('secret', '') !== ''),
		];

		$passed = true;
		foreach ($checks as $check) {
			if ($check['result'] !== self::RESULT_PASS) {
				$passed = false;
			}
		}

		return [
			'result' => $this->verdict($passed),
			'checks' => $checks,
			// Evidence, not an assertion: the cost parameters new hashes get.
			'parameters' => HashAlgorithm::parametersFromStoredHash($probeHash),
		];
	}

	/**
	 * @return array{key: string, expected: bool|string, actual: bool|string, result: string}
	 */
	private function check(string $key, bool|string $expected, bool|string $actual): array {
		return [
			'key' => $key,
			'expected' => $expected,
			'actual' => $actual,
			'result' => $this->verdict($expected === $actual),
		];
	}

	/**
	 * @return array{id: string, url: string, name: string, namespace: string, environment: string}
	 */
	private function describeInstance(): array {
		return [
			'id' => $this->config->getSystemValueString('instanceid', ''),
			'url' => $this->config->getSystemValueString('overwrite.cli.url', ''),
			'name' => $this->fromEnvironment('INSTANCE_NAME'),
			'namespace' => $this->fromEnvironment('NAMESPACE'),
			'environment' => $this->fromEnvironment('ENVIRONMENT'),
		];
	}

	/**
	 * Environment values are arbitrary bytes, and both evidence channels encode
	 * the artifact as JSON: `json_encode()` returns false on invalid UTF-8, and
	 * the log writer would drop the context the same way. Substituting here
	 * rather than at the encode boundary keeps stdout and the Kibana line
	 * consistent, and keeps a malformed label from costing the whole artifact.
	 */
	private function fromEnvironment(string $name): string {
		$value = getenv($name);
		if (!is_string($value)) {
			return '';
		}

		return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
	}

	/**
	 * @return self::RESULT_PASS|self::RESULT_FAIL
	 */
	private function verdict(bool $passed): string {
		return $passed ? self::RESULT_PASS : self::RESULT_FAIL;
	}

	private function asString(mixed $value): string {
		return is_string($value) ? $value : '';
	}

	private function probeMessage(): string {
		return $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
	}

	/**
	 * A throwaway password with all four character classes present, so the
	 * always-enabled password_policy app accepts it whatever it enforces.
	 */
	private function probePassword(): string {
		return $this->random->generate(8, ISecureRandom::CHAR_LOWER)
			. $this->random->generate(8, ISecureRandom::CHAR_UPPER)
			. $this->random->generate(8, ISecureRandom::CHAR_DIGITS)
			. $this->random->generate(8, ISecureRandom::CHAR_SYMBOLS);
	}
}
