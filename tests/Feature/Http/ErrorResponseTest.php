<?php

namespace Tests\Feature\Http;

use App\Http\ApiError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * src/error.rs and the catchers of src/main.rs.
 */
class ErrorResponseTest extends TestCase
{
    public function test_api_errors_use_vaultwardens_error_model(): void
    {
        Route::get('/api/test-error', fn () => throw ApiError::message('Invalid / "quoted" message'));

        $this->get('/api/test-error')
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/json')
            ->assertContent('{"message":"Invalid / \"quoted\" message","validationErrors":{"":["Invalid / \"quoted\" message"]},'
                .'"errorModel":{"message":"Invalid / \"quoted\" message","object":"error"},"error":"","error_description":"",'
                .'"exceptionMessage":null,"exceptionStackTrace":null,"innerExceptionMessage":null,"object":"error"}');
    }

    public function test_other_error_kinds(): void
    {
        Route::get('/api/compact', fn () => throw ApiError::compact('Gone', 404));
        Route::get('/api/empty', fn () => throw ApiError::empty(401));
        Route::get('/api/json', fn () => throw ApiError::json(['TwoFactorProviders' => [0]], 'Two factor required'));

        $this->get('/api/compact')->assertStatus(404)->assertContent(
            '{"message":"Gone","validationErrors":null,"exceptionMessage":null,"exceptionStackTrace":null,"innerExceptionMessage":null,"object":"error"}'
        );
        $this->get('/api/empty')->assertStatus(401)->assertContent('{}');
        $this->get('/api/json')->assertStatus(400)->assertContent('{"TwoFactorProviders":[0]}');
    }

    public function test_database_errors_are_reported_as_db(): void
    {
        Route::get('/api/db-error', fn () => DB::select('SELECT * FROM no_such_table'));

        $this->get('/api/db-error')->assertStatus(400)->assertJsonPath('message', 'Db');
    }

    public function test_unknown_api_paths_get_the_api_catcher(): void
    {
        $this->get('/api/no-such-thing', ['Accept' => 'text/html'])
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/json')
            ->assertContent('{"error":{"code":404,"description":"The requested resource could not be found.","reason":"Not Found"}}');
    }

    public function test_unknown_paths_get_the_404_page(): void
    {
        $response = $this->get('/no-such-page')
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'text/html; charset=utf-8');

        $this->assertStringContainsString('<title>Page not found!</title>', (string) $response->getContent());
    }

    public function test_unmatched_methods_are_not_found(): void
    {
        $this->post('/api/now')->assertStatus(404)->assertJsonPath('error.code', 404);
    }

    public function test_panics_get_rockets_default_catcher(): void
    {
        Route::get('/identity/panic', fn () => throw new \LogicException('boom'));

        $this->get('/identity/panic', ['Accept' => 'application/json'])
            ->assertStatus(500)
            ->assertHeader('Content-Type', 'application/json')
            ->assertContent("{\n  \"error\": {\n    \"code\": 500,\n    \"reason\": \"Internal Server Error\",\n"
                ."    \"description\": \"The server encountered an internal error while processing this request.\"\n  }\n}");

        $html = $this->get('/identity/panic', ['Accept' => 'text/html,application/json;q=0.9'])
            ->assertStatus(500)
            ->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $this->assertStringContainsString('<h1>500: Internal Server Error</h1>', (string) $html->getContent());
    }
}
