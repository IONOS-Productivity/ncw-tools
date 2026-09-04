<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Tests\Integration;

use OCA\NcwTools\AppInfo\Application;
use OCA\NcwTools\Security\HashAlgorithm;
use OCA\NcwTools\Security\SecuritySelfTest;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use OCP\User\Events\UserCreatedEvent;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Test\TestCase;

/**
 * Exercises the self-test against the real database: the stored hash survey and
 * the full round trip through IUserManager.
 *
 * @group DB
 */
class SecuritySelfTestIntegrationTest extends TestCase {
	private SecuritySelfTest $selfTest;
	private IDBConnection $db;
	private IUserManager $userManager;

	/** @var list<string> */
	private array $observedProbeUids = [];

	/** @var array<string, string|null> */
	private array $observedProbeEmails = [];

	protected function setUp(): void {
		parent::setUp();

		$this->db = Server::get(IDBConnection::class);
		$this->userManager = Server::get(IUserManager::class);
		// Resolved through the app container, so the DI wiring is covered too.
		$this->selfTest = (new Application())->getContainer()->get(SecuritySelfTest::class);

		// The probe user only exists between createUser() and delete(), so the
		// creation event is the only place its email address can be inspected.
		Server::get(IEventDispatcher::class)->addListener(
			UserCreatedEvent::class,
			function (UserCreatedEvent $event): void {
				$uid = $event->getUser()->getUID();
				if (!str_starts_with($uid, 'ncw-selftest-')) {
					return;
				}
				$this->observedProbeUids[] = $uid;
				$this->observedProbeEmails[$uid] = $event->getUser()->getEMailAddress();
			},
		);
	}

	protected function tearDown(): void {
		// Safety net: never leave a probe account behind, whatever failed.
		foreach ($this->observedProbeUids as $uid) {
			$this->userManager->get($uid)?->delete();
		}
		$this->observedProbeUids = [];
		$this->observedProbeEmails = [];

		parent::tearDown();
	}

	public function testSurveyCountsEveryStoredHashInTheUsersTable(): void {
		$report = $this->selfTest->run();

		$distribution = $report['password_hashing']['stored_distribution'];
		foreach ([HashAlgorithm::ARGON2ID, HashAlgorithm::BCRYPT, HashAlgorithm::EMPTY, HashAlgorithm::UNKNOWN] as $bucket) {
			$this->assertArrayHasKey($bucket, $distribution);
			$this->assertIsInt($distribution[$bucket]);
		}

		$this->assertSame($this->countUsers(), array_sum($distribution));
	}

	public function testSurveyRespectsTheSampleSize(): void {
		$this->assertGreaterThan(0, $this->countUsers(), 'The test instance needs at least one user');

		$report = $this->selfTest->run(false, 1);

		$this->assertSame(1, array_sum($report['password_hashing']['stored_distribution']));
	}

	public function testTheArtifactThisInstanceProducesMatchesThePublishedSchema(): void {
		// The unit suite validates mocked shapes; this validates what a real
		// instance actually emits, through the real hasher, config and database.
		$schema = json_decode(
			(string)file_get_contents(__DIR__ . '/../../docs/security-selftest.schema.json'),
			false,
			512,
			JSON_THROW_ON_ERROR,
		);
		$artifact = json_decode(
			(string)json_encode($this->selfTest->run(true), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
			false,
			512,
			JSON_THROW_ON_ERROR,
		);

		$result = (new Validator())->validate($artifact, $schema);

		$this->assertTrue(
			$result->isValid(),
			$result->hasError()
				? 'Artifact does not match docs/security-selftest.schema.json: '
					. (string)json_encode((new ErrorFormatter())->format($result->error()), JSON_PRETTY_PRINT)
				: '',
		);
	}

	public function testConfiguredAlgorithmIsArgon2idOnThisInstance(): void {
		$report = $this->selfTest->run();

		$this->assertSame(HashAlgorithm::ARGON2ID, $report['password_hashing']['configured_algorithm']);
		$this->assertSame(
			['memory_cost', 'time_cost', 'threads'],
			array_keys(get_object_vars($report['security_config']['parameters'])),
		);
	}

	public function testRoundTripStoresArgon2idAndRemovesTheProbeUser(): void {
		$report = $this->selfTest->run(true);

		$roundTrip = $report['password_hashing']['round_trip'];
		$this->assertSame(SecuritySelfTest::RESULT_PASS, $roundTrip['result']);
		$this->assertSame(HashAlgorithm::ARGON2ID, $roundTrip['stored_algorithm']);
		$this->assertTrue($roundTrip['cleaned_up']);

		$this->assertCount(1, $this->observedProbeUids, 'Exactly one probe user should have been created');
		$uid = $this->observedProbeUids[0];

		// Never set an email address on a disposable probe account.
		$this->assertEmpty($this->observedProbeEmails[$uid], 'The probe user must have no email address');

		// Gone from the user manager and from the database.
		$this->assertNull($this->userManager->get($uid));
		$this->assertNull($this->readStoredHash($uid));
	}

	public function testArtifactCarriesNoHashMaterialFromTheDatabase(): void {
		$storedHashes = $this->readAllStoredHashes();
		$this->assertNotEmpty($storedHashes, 'The test instance needs at least one stored hash');

		$report = $this->selfTest->run(true);
		$encoded = json_encode($report);
		$this->assertIsString($encoded);

		foreach ($storedHashes as $stored) {
			if ($stored === '') {
				continue;
			}
			$this->assertStringNotContainsString($stored, $encoded);
			$this->assertStringNotContainsString(explode('|', $stored, 2)[1] ?? $stored, $encoded);
		}

		$config = Server::get(IConfig::class);
		foreach (['passwordsalt', 'secret'] as $key) {
			$value = $config->getSystemValueString($key, '');
			if ($value !== '') {
				$this->assertStringNotContainsString($value, $encoded);
			}
		}
	}

	private function countUsers(): int {
		$query = $this->db->getQueryBuilder();
		$query->select($query->func()->count('*', 'total'))->from('users');
		$result = $query->executeQuery();
		try {
			return (int)$result->fetchOne();
		} finally {
			$result->closeCursor();
		}
	}

	private function readStoredHash(string $uid): ?string {
		$query = $this->db->getQueryBuilder();
		$query->select('password')
			->from('users')
			->where($query->expr()->eq('uid', $query->createNamedParameter($uid)));
		$result = $query->executeQuery();
		try {
			$stored = $result->fetchOne();
			return $stored === false ? null : (string)$stored;
		} finally {
			$result->closeCursor();
		}
	}

	/**
	 * @return list<string>
	 */
	private function readAllStoredHashes(): array {
		$query = $this->db->getQueryBuilder();
		$query->select('password')->from('users');
		$result = $query->executeQuery();
		try {
			$hashes = [];
			while (($row = $result->fetch()) !== false) {
				$hashes[] = (string)($row['password'] ?? '');
			}
			return $hashes;
		} finally {
			$result->closeCursor();
		}
	}
}
