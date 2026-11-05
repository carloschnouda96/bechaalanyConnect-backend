<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * DatabaseTransactions, NOT RefreshDatabase.
     *
     * RefreshDatabase runs migrate:fresh, which would drop the ~15 tables the
     * hellotree CMS generated and that have no migration — leaving a schema the
     * application cannot run against. The test database is built once from
     * database/dumps/schema.sql (see `php artisan test:prepare-db`) and each test
     * simply rolls back.
     *
     * Caveat worth knowing: DDL is not transactional in MySQL, so a test that
     * issues ALTER TABLE (e.g. the schema-guard tests) must restore the schema
     * itself in tearDown.
     */
    use DatabaseTransactions;

    /**
     * The local .env holds live supplier credentials, and a supplier purchase is an
     * ordinary HTTP call. An unfaked request must fail the test, never leave the machine.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }
}
