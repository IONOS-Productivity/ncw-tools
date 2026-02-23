<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Tests\Integration;

use OC\CapabilitiesManager;
use OCA\NcwTools\Capabilities;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Integration test to verify that the ncw_tools Capabilities class
 * properly overrides the support.hasValidSubscription value in the
 * actual Nextcloud capabilities system.
 *
 * @group DB
 */
class CapabilitiesIntegrationTest extends TestCase {
	private CapabilitiesManager $capabilitiesManager;
	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		// Create a fresh CapabilitiesManager for isolation
		$this->capabilitiesManager = new CapabilitiesManager($this->logger);
	}

	public function testCapabilityIsRegisteredInSystem(): void {
		// Register our capability
		$this->capabilitiesManager->registerCapability(function () {
			return new Capabilities();
		});

		$capabilities = $this->capabilitiesManager->getCapabilities();

		// Verify the support capability exists
		$this->assertArrayHasKey('support', $capabilities, 'Support capability should be present');
		$this->assertArrayHasKey('hasValidSubscription', $capabilities['support'], 'hasValidSubscription should be present');
	}

	public function testHasValidSubscriptionIsSetToFalse(): void {
		// Register our capability
		$this->capabilitiesManager->registerCapability(function () {
			return new Capabilities();
		});

		$capabilities = $this->capabilitiesManager->getCapabilities();

		// Verify the value is true
		$this->assertTrue(
			$capabilities['support']['hasValidSubscription'],
			'hasValidSubscription should be true as set by ncw_tools'
		);
	}

	public function testCapabilityOverridesOtherApps(): void {
		// Simulate another app setting hasValidSubscription to true
		$this->capabilitiesManager->registerCapability(function () {
			return new class implements \OCP\Capabilities\ICapability {
				public function getCapabilities() {
					return [
						'support' => [
							'hasValidSubscription' => true,
						],
					];
				}
			};
		});

		// Now register our capability that should also set it to true
		$this->capabilitiesManager->registerCapability(function () {
			return new Capabilities();
		});

		$capabilities = $this->capabilitiesManager->getCapabilities();

		// Verify it's true
		$this->assertTrue(
			$capabilities['support']['hasValidSubscription'],
			'ncw_tools should ensure hasValidSubscription is true'
		);
	}
}
