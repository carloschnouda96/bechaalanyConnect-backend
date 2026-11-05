<?php

namespace Tests\Feature;

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The local .env holds live supplier credentials with every supplier enabled. A test
 * that forgets Http::fake() must fail loudly, never reach a real supplier API.
 */
class TestHarnessIsolationTest extends TestCase
{
    public function test_an_unfaked_http_call_throws_instead_of_leaving_the_machine(): void
    {
        $this->expectException(StrayRequestException::class);

        Http::get('https://example.invalid/never-sent');
    }

    public function test_supplier_credentials_from_the_env_file_do_not_reach_the_suite(): void
    {
        foreach (['yassen.token', 'allosharejle.token', 'swift.key', '1xpanel.key', 'usharez.token', 'umanage.key', 'umanage.secret', 'bycel.key'] as $key) {
            $this->assertEmpty(config('services.' . $key), "services.{$key} must be blank under test");
        }
    }

    public function test_suppliers_are_switched_off_by_default(): void
    {
        foreach (['yassen', 'allosharejle', 'swift', '1xpanel', 'usharez', 'umanage', 'bycel'] as $key) {
            $this->assertFalse(filter_var(config("services.{$key}.enabled"), FILTER_VALIDATE_BOOLEAN), "{$key} must be disabled under test");
        }
    }
}
