<?php

namespace Tests\Feature\PriceIndices;

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PriceIndicesCapabilitiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('price_indices.enabled', true);
        Config::set('price_indices.admin_only', true);
    }

    public function test_guest_receives_standard_unauthorized_response(): void
    {
        $this->getJson('/api/indices/capabilities')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_user_role_is_forbidden_when_module_is_enabled(): void
    {
        $this->authenticateAs('user');

        $this->getJson('/api/indices/capabilities')->assertForbidden();
    }

    public function test_arbitrary_role_is_forbidden_when_module_is_enabled(): void
    {
        $this->authenticateAs('auditor');

        $this->getJson('/api/indices/capabilities')->assertForbidden();
    }

    public function test_admin_role_with_different_case_is_forbidden(): void
    {
        $this->authenticateAs('Admin');

        $this->getJson('/api/indices/capabilities')->assertForbidden();
    }

    public function test_legacy_owner_id_does_not_bypass_role_check(): void
    {
        $this->authenticateAs('user', 1);

        $this->getJson('/api/indices/capabilities')->assertForbidden();
    }

    public function test_admin_receives_not_found_when_module_is_disabled(): void
    {
        Config::set('price_indices.enabled', false);
        $this->authenticateAs('admin');

        $this->getJson('/api/indices/capabilities')->assertNotFound();
    }

    public function test_superadmin_receives_not_found_when_module_is_disabled(): void
    {
        Config::set('price_indices.enabled', false);
        $this->authenticateAs('superadmin');

        $this->getJson('/api/indices/capabilities')->assertNotFound();
    }

    public function test_admin_receives_capabilities_when_module_is_enabled(): void
    {
        $this->authenticateAs('admin');

        $this->getJson('/api/indices/capabilities')
            ->assertOk()
            ->assertExactJson($this->expectedCapabilities());
    }

    public function test_superadmin_receives_capabilities_when_module_is_enabled(): void
    {
        $this->authenticateAs('superadmin');

        $this->getJson('/api/indices/capabilities')
            ->assertOk()
            ->assertExactJson($this->expectedCapabilities());
    }

    public function test_admin_only_false_does_not_grant_access_to_regular_user(): void
    {
        Config::set('price_indices.admin_only', false);
        $this->authenticateAs('user');

        $this->getJson('/api/indices/capabilities')->assertForbidden();
    }

    public function test_admin_only_value_is_returned_from_configuration(): void
    {
        Config::set('price_indices.admin_only', false);
        $this->authenticateAs('admin');

        $this->getJson('/api/indices/capabilities')
            ->assertOk()
            ->assertExactJson($this->expectedCapabilities(adminOnly: false));
    }

    public function test_capabilities_endpoint_executes_no_sql_queries(): void
    {
        $queries = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->authenticateAs('admin');

        $this->getJson('/api/indices/capabilities')->assertOk();

        $this->assertSame([], $queries);
    }

    public function test_projects_index_route_remains_registered(): void
    {
        $route = Route::getRoutes()->getByName('projects.index');

        $this->assertNotNull($route);
        $this->assertSame('api/projects', $route->uri());
        $this->assertContains('GET', $route->methods());
    }

    private function authenticateAs(string $role, int $id = 100): void
    {
        $user = new User();
        $user->forceFill([
            'id' => $id,
            'role' => $role,
        ]);

        Sanctum::actingAs($user);
    }

    /**
     * @return array{data: array{application: string, enabled: bool, access: bool, admin_only: bool, stage: string}}
     */
    private function expectedCapabilities(bool $adminOnly = true): array
    {
        return [
            'data' => [
                'application' => 'price_indices',
                'enabled' => true,
                'access' => true,
                'admin_only' => $adminOnly,
                'stage' => 'skeleton',
            ],
        ];
    }
}
