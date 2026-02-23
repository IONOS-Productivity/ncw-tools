<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Tests\Unit;

use OCA\NcwTools\Capabilities;
use Test\TestCase;

class CapabilitiesTest extends TestCase {
	private Capabilities $capabilities;

	protected function setUp(): void {
		parent::setUp();
		$this->capabilities = new Capabilities();
	}

	public function testGetCapabilities(): void {
		$expected = [
			'support' => [
				'hasValidSubscription' => true,
				'desktopEnterpriseChannel' => 'stable',
			],
		];

		$this->assertSame($expected, $this->capabilities->getCapabilities());
	}

	public function testImplementsICapabilityInterface(): void {
		$this->assertInstanceOf(\OCP\Capabilities\ICapability::class, $this->capabilities);
	}
}
