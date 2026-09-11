<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Stub based on Nextcloud server stable33
 * https://github.com/nextcloud/server/blob/stable33/core/Command/Base.php
 *
 * OC\Core\Command\Base is a private server class and therefore not part of the
 * nextcloud/ocp package this app depends on. At runtime the real class is used;
 * this stub only exists so static analysis can resolve it.
 */

declare(strict_types=1);

namespace OC\Core\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Base {
	public const OUTPUT_FORMAT_PLAIN = 'plain';
	public const OUTPUT_FORMAT_JSON = 'json';
	public const OUTPUT_FORMAT_JSON_PRETTY = 'json_pretty';

	protected string $defaultOutputFormat = self::OUTPUT_FORMAT_PLAIN;

	public function __construct(?string $name = null) {
	}

	protected function configure() {
	}

	/**
	 * @return $this
	 */
	public function setName(string $name) {
	}

	/**
	 * @return $this
	 */
	public function setDescription(string $description) {
	}

	/**
	 * @return $this
	 */
	public function setHelp(string $help) {
	}

	/**
	 * @return $this
	 */
	public function addOption(string $name, $shortcut = null, ?int $mode = null, string $description = '', $default = null) {
	}

	/**
	 * @return $this
	 */
	public function addArgument(string $name, ?int $mode = null, string $description = '', $default = null) {
	}

	protected function writeArrayInOutputFormat(InputInterface $input, OutputInterface $output, iterable $items, string $prefix = '  - '): void {
	}

	protected function writeTableInOutputFormat(InputInterface $input, OutputInterface $output, array $items): void {
	}

	protected function writeMixedInOutputFormat(InputInterface $input, OutputInterface $output, $item) {
	}

	protected function valueToString($value, bool $returnNull = true): ?string {
	}

	protected function abortIfInterrupted() {
	}

	protected function cancelOperation() {
	}

	public function run(InputInterface $input, OutputInterface $output) {
	}
}
