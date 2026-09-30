<?php

namespace App\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Base class for the project's API functional tests:
 * - ResetDatabase recreates the test database from migrations before the first test of each class.
 * - Factories enables Zenstruck Foundry factories (UserFactory, etc.).
 * - ApiTestCase provides a JSON-aware test client (see ApiPlatform docs).
 */
abstract class AbstractApiTestCase extends ApiTestCase
{
    use Factories;
    use ResetDatabase;
}
