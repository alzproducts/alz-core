<?php

declare(strict_types=1);

/**
 * Technical Debt Reminders
 *
 * This file contains TODO comments that trigger PHPStan errors via staabm/phpstan-todo-by
 * when their conditions are met (package upgrades, dates, PHP versions).
 *
 * These are intentional reminders, not forgotten code.
 *
 * @see https://github.com/staabm/phpstan-todo-by
 */
// TODO: googleads/google-ads-php:>31.1.0 Check if PHP 8.4 implicit nullable deprecation is fixed (GitHub Issue
// #1056). If fixed, re-enable tests/ArchitectureTest.php security preset (arch()->preset()->security())
