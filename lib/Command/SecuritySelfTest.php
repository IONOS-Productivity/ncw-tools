<?php

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\NcwTools\Command;

use OC\Core\Command\Base;
use OCA\NcwTools\AppInfo\Application;
use OCA\NcwTools\Security\SecuritySelfTest as SecuritySelfTestService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Verifies that password hashing is argon2id and emits a structured evidence
 * artifact for C5 control PSS-07.
 *
 * Exit codes: 0 = PASS, 1 = FAIL, 2 = usage error, 3 = internal error.
 *
 * stdout carries nothing but the artifact, because the deployment wrapper pipes
 * it straight into `jq`. Diagnostics go to stderr, the evidence itself also goes
 * to the log. A FAIL still writes the complete artifact before exiting 1, so an
 * exit 1 always carries one; a usage error and an internal error write no
 * artifact at all.
 *
 * An internal error is deliberately not reported as a FAIL: a FAIL is a verdict
 * about the instance, and a self-test that could not run has not reached one.
 * Exit 3 lets the wrapper tell an unreachable database from a real finding
 * instead of forwarding invented evidence.
 */
class SecuritySelfTest extends Base {
	private const LOG_MESSAGE = 'ncw_tools security selftest';

	/**
	 * The self-test itself broke down, so there is no verdict and no artifact.
	 * Distinct from 1 (FAIL), which always carries the complete artifact.
	 */
	private const EXIT_INTERNAL_ERROR = 3;

	private const OUTPUT_FORMATS = [
		self::OUTPUT_FORMAT_PLAIN,
		self::OUTPUT_FORMAT_JSON,
		self::OUTPUT_FORMAT_JSON_PRETTY,
	];

	public function __construct(
		private SecuritySelfTestService $selfTest,
		private LoggerInterface $logger,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		parent::configure();
		$this
			->setName(Application::APP_ID . ':security:selftest')
			->setDescription('Verify that password hashing is argon2id and emit an evidence artifact')
			->addOption(
				'round-trip',
				null,
				InputOption::VALUE_NONE,
				'Create and delete a disposable probe user to observe the algorithm actually written to the database',
			)
			->addOption(
				'sample-size',
				null,
				InputOption::VALUE_REQUIRED,
				'Number of stored password hashes to survey (0 = all)',
				(string)SecuritySelfTestService::DEFAULT_SAMPLE_SIZE,
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$errors = $this->errorOutput($output);

		$format = $this->outputFormat($input);
		if ($format === null) {
			$errors->writeln('<error>Invalid --output format, expected one of: ' . implode(', ', self::OUTPUT_FORMATS) . '</error>');
			return 2;
		}

		$sampleSize = $this->sampleSize($input);
		if ($sampleSize === null) {
			$errors->writeln('<error>Invalid --sample-size, expected a non-negative integer (0 = all)</error>');
			return 2;
		}

		try {
			$report = $this->selfTest->run($input->getOption('round-trip') === true, $sampleSize);
		} catch (\Throwable $e) {
			// Without this the throwable would reach Symfony, which exits with
			// $e->getCode() clamped to 255 -- not 1 -- and leaves stdout empty
			// and the log without a line, so the failure is invisible to both
			// the wrapper and Kibana.
			//
			// Message only, never the exception: the round-trip probe password
			// can appear in stack-trace arguments.
			$this->logger->error(self::LOG_MESSAGE . ': could not collect the evidence artifact', [
				'exceptionClass' => $e::class,
				'exceptionMessage' => $e->getMessage(),
			]);
			$errors->writeln('<error>Could not collect the evidence artifact: '
				. OutputFormatter::escape($e->getMessage()) . '</error>');

			return self::EXIT_INTERNAL_ERROR;
		}

		// The structured context is what reaches Kibana; the deployment sets
		// log_type=errorlog, so the context is serialised alongside the message.
		$this->logger->info(self::LOG_MESSAGE, $report);

		// A FAIL is the case the evidence exists for, so the complete artifact is
		// written before the non-zero exit — never a short-circuited error path.
		if ($format === self::OUTPUT_FORMAT_PLAIN) {
			$this->writePlain($output, $report);
		} else {
			// JSON_INVALID_UTF8_SUBSTITUTE backs up the sanitising the service
			// already does on environment-derived labels: an unencodable byte
			// must degrade one field, never cost the entire artifact.
			$flags = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
				| ($format === self::OUTPUT_FORMAT_JSON_PRETTY ? JSON_PRETTY_PRINT : 0);
			$json = json_encode($report, $flags);
			if ($json === false) {
				// Not a FAIL: the artifact was collected, only writing it out
				// failed, and exiting 1 here would break the promise that an
				// exit 1 carries the complete artifact.
				$errors->writeln('<error>Could not encode the evidence artifact: ' . json_last_error_msg() . '</error>');
				return self::EXIT_INTERNAL_ERROR;
			}
			$output->writeln($json);
		}

		return $report['result'] === SecuritySelfTestService::RESULT_PASS ? 0 : 1;
	}

	/**
	 * Diagnostics must not land on stdout: under `--output=json` the deployment
	 * wrapper pipes stdout straight into `jq`, and a single stray line there
	 * costs the whole evidence artifact.
	 */
	private function errorOutput(OutputInterface $output): OutputInterface {
		return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
	}

	/**
	 * @param array{
	 *     schema_version: string,
	 *     timestamp: string,
	 *     result: string,
	 *     instance: array{id: string, url: string, name: string, namespace: string, environment: string},
	 *     password_hashing: array{
	 *         result: string,
	 *         configured_algorithm: string,
	 *         round_trip: array{result: string, stored_algorithm: string|null, cleaned_up: bool|null},
	 *         stored_distribution: array<string, int>
	 *     },
	 *     security_config: array{
	 *         result: string,
	 *         checks: list<array{key: string, expected: bool|string, actual: bool|string, result: string}>,
	 *         parameters: \stdClass
	 *     }
	 * } $report
	 */
	private function writePlain(OutputInterface $output, array $report): void {
		$output->writeln('schema_version: ' . $report['schema_version']);
		$output->writeln('timestamp: ' . $report['timestamp']);
		$output->writeln('result: ' . $report['result']);
		$output->writeln('instance:');
		foreach ($report['instance'] as $key => $value) {
			$output->writeln('  ' . $key . ': ' . $value);
		}

		$hashing = $report['password_hashing'];
		$output->writeln('password_hashing: ' . $hashing['result']);
		$output->writeln('  configured_algorithm: ' . $hashing['configured_algorithm']);
		$output->writeln('  round_trip: ' . $hashing['round_trip']['result']);
		$output->writeln('    stored_algorithm: ' . ($hashing['round_trip']['stored_algorithm'] ?? '-'));
		$output->writeln('    cleaned_up: ' . $this->formatValue($hashing['round_trip']['cleaned_up'] ?? '-'));
		$output->writeln('  stored_distribution:');
		foreach ($hashing['stored_distribution'] as $algorithm => $count) {
			$output->writeln('    ' . $algorithm . ': ' . $count);
		}

		$security = $report['security_config'];
		$output->writeln('security_config: ' . $security['result']);
		foreach ($security['checks'] as $check) {
			$output->writeln(sprintf(
				'  %s: %s (expected %s, actual %s)',
				$check['key'],
				$check['result'],
				$this->formatValue($check['expected']),
				$this->formatValue($check['actual']),
			));
		}
		$output->writeln('  parameters:');
		// A map cast to an object by the service, so it cannot degrade into a
		// JSON array when empty.
		/** @var array<string, int> $parameters */
		$parameters = get_object_vars($security['parameters']);
		foreach ($parameters as $name => $value) {
			$output->writeln('    ' . $name . ': ' . $value);
		}
	}

	private function formatValue(bool|string $value): string {
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		return $value;
	}

	private function outputFormat(InputInterface $input): ?string {
		$format = $input->getOption('output');
		if (!is_string($format) || !in_array($format, self::OUTPUT_FORMATS, true)) {
			return null;
		}

		return $format;
	}

	private function sampleSize(InputInterface $input): ?int {
		$sampleSize = $input->getOption('sample-size');
		if (is_int($sampleSize)) {
			return $sampleSize >= 0 ? $sampleSize : null;
		}

		if (!is_string($sampleSize) || $sampleSize === '' || !ctype_digit($sampleSize)) {
			return null;
		}

		return (int)$sampleSize;
	}
}
