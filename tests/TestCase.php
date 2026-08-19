<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
}
