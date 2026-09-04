<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Tests\Unit\Security;

use OCA\NcwTools\Security\HashAlgorithm;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

class HashAlgorithmTest extends TestCase {
	/**
	 * A real hash as written by OC\Security\Hasher::hash() on PHP 8.3 — note the
	 * `3|` version prefix in front of the `$argon2id$` marker. Matching a stored
	 * value against the bare `$argon2id$` prefix can never succeed.
	 */
	private const REAL_STORED_ARGON2ID = '3|$argon2id$v=19$m=65536,t=4,p=1$QWg3b3ptdUY2bTMyLm1VSA$shWk3Zo2H45opssZuQLI/XERpr+n4BOC53D4i24CTRE';

	/** A real hash as written by hasher version 2 (argon2i). */
	private const REAL_STORED_ARGON2I = '2|$argon2i$v=19$m=65536,t=4,p=1$dFcyWjAyNWI3QTJUS2VVWg$neelG9YBu6q+p8TKXAzqAQjQXraqCRisUflPMxjeHLQ';

	/** A real hash as written by hasher version 1 (bcrypt). */
	private const REAL_STORED_BCRYPT = '1|$2y$10$nv5Wx10BmWJnGFj7mhFGPeUaW8wNnFRqP0g09H93X/9yDhxNBjv/m';

	public static function provideStoredHashes(): array {
		$cases = [
			// Captured fixtures — the version prefix is the whole point of this class.
			'version 3 prefix, real argon2id hash' => [self::REAL_STORED_ARGON2ID, HashAlgorithm::ARGON2ID],
			'version 2 prefix, real argon2i hash' => [self::REAL_STORED_ARGON2I, HashAlgorithm::ARGON2I],
			'version 1 prefix, real bcrypt hash' => [self::REAL_STORED_BCRYPT, HashAlgorithm::BCRYPT],

			// Legacy, unprefixed hashes.
			'bare bcrypt hash (60 chars, no version prefix)' => [
				'$2y$10$nv5Wx10BmWJnGFj7mhFGPeUaW8wNnFRqP0g09H93X/9yDhxNBjv/m',
				HashAlgorithm::LEGACY_BCRYPT,
			],
			'bare bcrypt $2a variant' => [
				'$2a$10$nv5Wx10BmWJnGFj7mhFGPeUaW8wNnFRqP0g09H93X/9yDhxNBjv/m',
				HashAlgorithm::LEGACY_BCRYPT,
			],
			'sha1 hex digest' => [sha1('correct horse battery staple'), HashAlgorithm::LEGACY_SHA1],
			'sha1 hex digest, upper case' => [strtoupper(sha1('correct horse battery staple')), HashAlgorithm::LEGACY_SHA1],

			// Nothing stored.
			'empty string' => ['', HashAlgorithm::EMPTY],

			// Garbage and edge cases.
			'garbage' => ['not-a-hash-at-all', HashAlgorithm::UNKNOWN],
			'version prefix with empty hash' => ['3|', HashAlgorithm::UNKNOWN],
			'version prefix with garbage hash' => ['3|nonsense', HashAlgorithm::UNKNOWN],
			'non-numeric prefix is not a version' => ['foo|$argon2id$v=19$m=65536,t=4,p=1$c2FsdA$aGFzaA', HashAlgorithm::UNKNOWN],
			'zero prefix is not a version' => ['0|$argon2id$v=19$m=65536,t=4,p=1$c2FsdA$aGFzaA', HashAlgorithm::UNKNOWN],
			'40 non-hex characters' => [str_repeat('z', 40), HashAlgorithm::UNKNOWN],
			'60 characters that are not bcrypt' => [str_repeat('z', 60), HashAlgorithm::UNKNOWN],
			'md5 digest' => [md5('correct horse battery staple'), HashAlgorithm::UNKNOWN],

			// An unknown future hasher version still classifies by the inner hash.
			'unknown future version prefix' => ['9|' . self::stripPrefix(self::REAL_STORED_ARGON2ID), HashAlgorithm::ARGON2ID],

			// A trailing-garbage version prefix is accepted, deliberately: upstream
			// splits on `(int)$parts[0] > 0`, so Nextcloud reads these as version 3
			// and verifies the remainder as argon2id. Reporting `unknown` here would
			// invent an anomaly the instance does not actually have. See
			// HashAlgorithm::stripVersionPrefix().
			'version prefix with trailing garbage' => [
				'3foo|' . self::stripPrefix(self::REAL_STORED_ARGON2ID),
				HashAlgorithm::ARGON2ID,
			],
			'version prefix with leading whitespace' => [
				' 3|' . self::stripPrefix(self::REAL_STORED_ARGON2ID),
				HashAlgorithm::ARGON2ID,
			],
			// ...but a garbage prefix does not rescue a garbage hash.
			'trailing-garbage prefix with garbage hash' => ['3foo|nonsense', HashAlgorithm::UNKNOWN],
			'negative prefix is not a version' => [
				'-1|' . self::stripPrefix(self::REAL_STORED_ARGON2ID),
				HashAlgorithm::UNKNOWN,
			],

			// The argon2 marker alone decides, exactly as upstream's own argon2
			// handler does — it accepts any `$argon2id$…` string. Anything that
			// carries the marker was written by argon2id, and that is what the
			// evidence has to say.
			'argon2id marker with an unparseable body' => ['3|$argon2id$garbage', HashAlgorithm::ARGON2ID],

			// bcrypt needs its length as well as its marker, the same rule the
			// unprefixed branch applies. The `$2a`/`$2b` revisions classify as
			// bcrypt even though upstream's handler only accepts `$2y`.
			'version 1 prefix, bcrypt $2a revision' => [
				'1|$2a$10$nv5Wx10BmWJnGFj7mhFGPeUaW8wNnFRqP0g09H93X/9yDhxNBjv/m',
				HashAlgorithm::BCRYPT,
			],
			'version 1 prefix, bcrypt $2b revision' => [
				'1|$2b$10$nv5Wx10BmWJnGFj7mhFGPeUaW8wNnFRqP0g09H93X/9yDhxNBjv/m',
				HashAlgorithm::BCRYPT,
			],
			'version 1 prefix, bcrypt marker but too short' => ['1|$2y$10$tooshort', HashAlgorithm::UNKNOWN],
		];

		// Freshly generated hashes, so the classifier is exercised against what
		// the current PHP build actually produces rather than only fixtures.
		// The argon2 pair is conditional: PHP defines PASSWORD_ARGON2ID only
		// when it was built with argon2 support, and referencing it otherwise
		// is a fatal Error. The captured fixtures above keep argon2
		// classification covered on such a build — which is the point of
		// classifying by marker rather than by password_get_info().
		if (self::hasArgon2Support()) {
			$cases['freshly hashed argon2id with version 3 prefix'] = [
				'3|' . password_hash('probe', PASSWORD_ARGON2ID),
				HashAlgorithm::ARGON2ID,
			];
			$cases['freshly hashed argon2i with version 2 prefix'] = [
				'2|' . password_hash('probe', PASSWORD_ARGON2I),
				HashAlgorithm::ARGON2I,
			];
		}

		$cases['freshly hashed bcrypt with version 1 prefix'] = [
			'1|' . password_hash('probe', PASSWORD_BCRYPT),
			HashAlgorithm::BCRYPT,
		];
		$cases['freshly hashed bcrypt without prefix'] = [
			password_hash('probe', PASSWORD_BCRYPT),
			HashAlgorithm::LEGACY_BCRYPT,
		];

		return $cases;
	}

	#[DataProvider('provideStoredHashes')]
	public function testFromStoredHash(string $stored, string $expected): void {
		$this->assertSame($expected, HashAlgorithm::fromStoredHash($stored));
	}

	public function testBareArgon2idPrefixIsNotHowNextcloudStoresHashes(): void {
		// Regression guard for the defect this class replaces: a shell check that
		// compared the stored value against the literal '$argon2id$' prefix.
		// Deliberately the captured fixture rather than password_hash(), so the
		// guard runs on every PHP build, argon2 support or not.
		$stored = self::REAL_STORED_ARGON2ID;

		$this->assertStringStartsNotWith('$argon2id$', $stored);
		$this->assertStringStartsWith('3|$argon2id$', $stored);
		$this->assertSame(HashAlgorithm::ARGON2ID, HashAlgorithm::fromStoredHash($stored));
	}

	public function testArgon2idIsClassifiedWithoutDependingOnArgon2Support(): void {
		// password_get_info() knows argon2 only when PHP was built with argon2
		// support, and reports 'unknown' otherwise. The classifier must not
		// inherit that: on such a build these rows are still argon2id, and
		// calling them 'unknown' would hide the anomaly the self-test looks for.
		$this->assertSame(HashAlgorithm::ARGON2ID, HashAlgorithm::fromStoredHash(self::REAL_STORED_ARGON2ID));
		$this->assertSame(HashAlgorithm::ARGON2I, HashAlgorithm::fromStoredHash(self::REAL_STORED_ARGON2I));

		if (!self::hasArgon2Support()) {
			return;
		}

		// On a build that does support argon2, the two agree — so the marker
		// matching above is not quietly diverging from upstream.
		$this->assertSame(
			'argon2id',
			password_get_info(self::stripPrefix(self::REAL_STORED_ARGON2ID))['algoName'],
		);
	}

	public function testParametersFromArgon2idHash(): void {
		if (!self::hasArgon2Support()) {
			$this->markTestSkipped('PHP was built without argon2 support, so no argon2 hash can be produced');
		}

		// Deliberately *not* the PHP defaults (65536/4/1): password_get_info()
		// seeds its result with those defaults and only overwrites them if it
		// can parse the hash, so asserting the defaults would pass even if the
		// parsing were broken.
		$stored = '3|' . password_hash('probe', PASSWORD_ARGON2ID, [
			'memory_cost' => 32768,
			'time_cost' => 3,
			'threads' => 1,
		]);

		$this->assertSame(
			['memory_cost' => 32768, 'time_cost' => 3, 'threads' => 1],
			HashAlgorithm::parametersFromStoredHash($stored),
		);
	}

	public function testParametersFromBcryptHash(): void {
		$stored = '1|' . password_hash('probe', PASSWORD_BCRYPT, ['cost' => 11]);

		$this->assertSame(['cost' => 11], HashAlgorithm::parametersFromStoredHash($stored));
	}

	public static function provideHashesWithoutParameters(): array {
		return [
			'empty' => [''],
			'garbage' => ['not-a-hash-at-all'],
			'sha1' => [sha1('probe')],
		];
	}

	#[DataProvider('provideHashesWithoutParameters')]
	public function testParametersAreEmptyForUnrecognisedHashes(string $stored): void {
		$this->assertSame([], HashAlgorithm::parametersFromStoredHash($stored));
	}

	public function testParametersNeverCarryHashMaterial(): void {
		// Both fixtures: on a build without argon2 support the argon2 one
		// reports nothing, so bcrypt is what keeps the invariant asserted
		// against real parameters everywhere.
		$reported = [];
		foreach ([self::REAL_STORED_ARGON2ID, self::REAL_STORED_BCRYPT] as $stored) {
			foreach (HashAlgorithm::parametersFromStoredHash($stored) as $name => $value) {
				$this->assertIsString($name);
				$this->assertIsInt($value);
				$reported[] = $name;
			}
		}

		// Guards the loop above against passing vacuously.
		$this->assertContains('cost', $reported, 'bcrypt reports its cost on every PHP build');
	}

	private static function stripPrefix(string $stored): string {
		return explode('|', $stored, 2)[1];
	}

	/**
	 * PHP defines the argon2 constants and registers the matching
	 * `password_get_info()` handlers under one condition
	 * (`HAVE_ARGON2LIB || HAVE_LIBSODIUM`), so this one check covers both.
	 */
	private static function hasArgon2Support(): bool {
		return defined('PASSWORD_ARGON2ID');
	}
}
