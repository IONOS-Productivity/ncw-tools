<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Security;

/**
 * Classifies a stored Nextcloud password hash by the algorithm that produced it.
 *
 * Nextcloud does **not** store a bare `password_hash()` string. `OC\Security\Hasher::hash()`
 * prepends a hasher version and a pipe, so a stored value looks like:
 *
 *     3|$argon2id$v=19$m=65536,t=4,p=1$<salt>$<hash>
 *
 * Version 3 is argon2id, version 2 is argon2i, version 1 is bcrypt. Comparing a
 * stored value against the literal prefix `$argon2id$` therefore never matches —
 * the version prefix has to be split off first, exactly as the private
 * `Hasher::splitHash()` does.
 *
 * Hashes written before the version prefix existed are stored unprefixed: a
 * 60 character bcrypt string or a 40 character sha1 hex digest.
 *
 * Classification is by algorithm marker rather than `password_get_info()`, so
 * that it does not depend on how the running PHP was built — see
 * {@see self::fromHashMarker()}.
 *
 * No method of this class ever returns hash material, a salt or a secret —
 * only algorithm names and cost parameters.
 */
final class HashAlgorithm {
	public const ARGON2ID = 'argon2id';
	public const ARGON2I = 'argon2i';
	public const BCRYPT = 'bcrypt';
	public const LEGACY_BCRYPT = 'legacy-bcrypt';
	public const LEGACY_SHA1 = 'legacy-sha1';
	public const EMPTY = 'empty';
	public const UNKNOWN = 'unknown';

	/** Length of a bcrypt hash, prefixed or not — the constant is what both branches check. */
	private const LEGACY_BCRYPT_LENGTH = 60;

	/** Length of an unprefixed legacy sha1 hex digest. */
	private const LEGACY_SHA1_LENGTH = 40;

	/**
	 * @param string $stored The raw value of the `oc_users.password` column
	 * @return self::ARGON2ID|self::ARGON2I|self::BCRYPT|self::LEGACY_BCRYPT|self::LEGACY_SHA1|self::EMPTY|self::UNKNOWN
	 */
	public static function fromStoredHash(string $stored): string {
		if ($stored === '') {
			return self::EMPTY;
		}

		$hash = self::stripVersionPrefix($stored);
		if ($hash !== null) {
			return self::fromHashMarker($hash);
		}

		if (strlen($stored) === self::LEGACY_BCRYPT_LENGTH && str_starts_with($stored, '$2')) {
			return self::LEGACY_BCRYPT;
		}

		if (strlen($stored) === self::LEGACY_SHA1_LENGTH && ctype_xdigit($stored)) {
			return self::LEGACY_SHA1;
		}

		return self::UNKNOWN;
	}

	/**
	 * The cost parameters the given hash was produced with, as reported by
	 * `password_get_info()` — `memory_cost`/`time_cost`/`threads` for argon2,
	 * `cost` for bcrypt. These are the *effective* values, which is stronger
	 * evidence than the `hashing*` config keys because the hasher clamps those
	 * to the algorithm minimums.
	 *
	 * Returns an empty array for legacy, empty or unrecognised hashes — and,
	 * unlike {@see self::fromStoredHash()}, for an argon2 hash on a PHP build
	 * without argon2 support, because only the registered handler can read the
	 * cost fields. The artifact reports these parameters as evidence and never
	 * asserts on them, so degrading to "not reported" is the right failure: the
	 * algorithm itself is still classified, and the configured-algorithm check
	 * is what turns such a build into a FAIL.
	 *
	 * @return array<string, int>
	 */
	public static function parametersFromStoredHash(string $stored): array {
		$hash = self::stripVersionPrefix($stored) ?? $stored;

		// password_get_info() reports argon2's memory_cost/time_cost/threads and
		// bcrypt's cost as integers, and an empty array for anything it does not
		// recognise. It never reports hash material.
		/** @var array<string, int> $options */
		$options = password_get_info($hash)['options'];

		return $options;
	}

	/**
	 * Splits `<version>|<hash>` and returns the hash part, mirroring the private
	 * `OC\Security\Hasher::splitHash()`. Returns null when the value carries no
	 * version prefix, i.e. when it is a legacy hash.
	 *
	 * The loose `(int)` cast is deliberate, not an oversight: upstream splits on
	 * `(int)$explodedString[0] > 0`, so Nextcloud itself accepts `3foo|<hash>`
	 * as version 3 and verifies the remainder as argon2id. This is evidence
	 * about what the instance actually does, so a stricter digits-only rule
	 * would report `unknown` for a value Nextcloud verifies happily — inventing
	 * an anomaly rather than reporting one. Malformed *hashes* are still caught:
	 * the remainder has to carry a known algorithm marker to classify as
	 * anything but `unknown`.
	 */
	private static function stripVersionPrefix(string $stored): ?string {
		$parts = explode('|', $stored, 2);
		if (count($parts) !== 2) {
			return null;
		}

		if ((int)$parts[0] <= 0) {
			return null;
		}

		return $parts[1];
	}

	/**
	 * Classifies the hash part of a version-prefixed value by its algorithm
	 * marker.
	 *
	 * Deliberately *not* `password_get_info()`. PHP registers the argon2
	 * handlers only when it was built with argon2 support — the same
	 * `HAVE_ARGON2LIB || HAVE_LIBSODIUM` condition that defines
	 * `PASSWORD_ARGON2ID` — so on a build without it `password_get_info()`
	 * reports `unknown` for a perfectly good `$argon2id$` hash. That is exactly
	 * the build this self-test exists to catch, and it is the build on which the
	 * stored distribution has to stay truthful: those rows really are argon2id,
	 * and reporting them as `unknown` would blur the finding instead of
	 * sharpening it. The configured-algorithm check reports the fallback.
	 *
	 * Marker matching is also what upstream's own handlers do: argon2 accepts
	 * any `$argon2id$…` string, and bcrypt any 60 character `$2y…` one.
	 *
	 * @return self::ARGON2ID|self::ARGON2I|self::BCRYPT|self::UNKNOWN
	 */
	private static function fromHashMarker(string $hash): string {
		if (str_starts_with($hash, '$argon2id$')) {
			return self::ARGON2ID;
		}

		if (str_starts_with($hash, '$argon2i$')) {
			return self::ARGON2I;
		}

		// The same rule the legacy branch of fromStoredHash() applies, so a
		// prefixed and an unprefixed bcrypt hash classify consistently.
		// Upstream's bcrypt handler is stricter — it rejects the `$2a`/`$2b`
		// revisions — but those are bcrypt, and the unprefixed form already
		// says so.
		if (strlen($hash) === self::LEGACY_BCRYPT_LENGTH && str_starts_with($hash, '$2')) {
			return self::BCRYPT;
		}

		return self::UNKNOWN;
	}
}
