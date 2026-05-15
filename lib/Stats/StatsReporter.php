<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Stats;

interface StatsReporter {
	public function reportUserCount(int $count, \DateTimeInterface $at): void;
}
