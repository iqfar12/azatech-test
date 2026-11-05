<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Orders are timestamped and numbered in the business time zone; keep tests deterministic.
        config(['shop.business_timezone' => 'Asia/Jakarta']);
    }
}
