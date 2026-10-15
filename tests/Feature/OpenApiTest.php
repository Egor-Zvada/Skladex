<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\OpenApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class OpenApiTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Application feature suite requires MariaDB.');
        }
        if (! preg_match('/(?:^|_)(?:tests?|testing)(?:_|$)/i', DB::connection()->getDatabaseName())) {
            throw new RuntimeException('OpenApiTest requires a dedicated *_test or *_testing database.');
        }
    }

    public function test_reference_and_schema_require_authentication(): void
    {
        $this->getJson(route('api.reference'))->assertUnauthorized();
        $this->getJson(route('api.schema'))->assertUnauthorized();
    }

    public function test_active_viewer_can_read_reference_and_machine_readable_schema(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $this->actingAs($viewer);
        $this->get(route('api.reference'))->assertOk()->assertSee('Idempotency-Key')->assertSee('expected_version')->assertSee(route('api.schema'), false)->assertDontSee('swagger-ui')->assertDontSee('cdn.jsdelivr');
        $response = $this->getJson(route('api.schema'))->assertOk()->assertHeader('Content-Type', 'application/json')->assertJsonPath('openapi', '3.1.1');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertIsArray(json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)['paths']);
    }

    public function test_inactive_user_cannot_read_internal_contract(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => false]));
        $this->getJson(route('api.schema'))->assertForbidden();
    }

    public function test_every_actual_api_method_has_exactly_one_explicit_operation_contract(): void
    {
        $document = OpenApi::document();
        $actual = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $actual[$route->getName()] = strtolower($method).' /'.$route->uri();
            }
        }
        $described = [];
        foreach ($document['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $this->assertArrayNotHasKey($operation['operationId'], $described, 'operationId must be unique');
                $described[$operation['operationId']] = $method.' '.$path;
                $this->assertNotEmpty($operation['summary']);
                $this->assertArrayHasKey('responses', $operation);
                $this->assertSame([['BearerToken' => []]], $operation['security']);
            }
        }
        ksort($actual);
        ksort($described);
        $this->assertSame($actual, $described);
        $this->assertGreaterThanOrEqual(50, count($actual));
    }

    public function test_new_unmapped_api_route_fails_instead_of_silently_publishing_generic_body(): void
    {
        Route::post('/api/v1/unmapped-contract-test', fn () => [])->name('api.unmapped-contract-test');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Missing explicit OpenAPI contract');
        OpenApi::document();
    }

    public function test_all_command_bodies_have_explicit_nonempty_properties_and_resolvable_references(): void
    {
        $document = OpenApi::document();
        $this->assertReferencesResolve($document, $document);
        foreach ($document['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if ($method === 'get') {
                    $this->assertArrayNotHasKey('requestBody', $operation);

                    continue;
                }
                $body = $operation['requestBody']['content']['application/json']['schema'];
                $name = basename($body['$ref']);
                $schema = $document['components']['schemas'][$name];
                $this->assertSame('object', $schema['type'], $path);
                $this->assertNotEmpty($schema['properties'], $path.' must not use an empty generic command');
                foreach ($schema['required'] ?? [] as $field) {
                    $this->assertArrayHasKey($field, $schema['properties'], $name.' requires an undefined field');
                }
            }
        }
    }

    public function test_path_parameters_distinguish_uuid_resources_from_numeric_child_rows(): void
    {
        foreach (OpenApi::document()['paths'] as $path => $methods) {
            preg_match_all('/\{([^}]+)\}/', $path, $matches);
            foreach ($methods as $operation) {
                $parameters = array_filter($operation['parameters'], fn ($parameter) => ($parameter['in'] ?? null) === 'path');
                $this->assertCount(count($matches[1]), $parameters);
                foreach ($parameters as $parameter) {
                    $this->assertTrue($parameter['required']);
                    if (in_array($parameter['name'], ['component', 'installation', 'assignment'], true)) {
                        $this->assertSame('integer', $parameter['schema']['type']);
                    } else {
                        $this->assertSame('uuid', $parameter['schema']['format']);
                    }
                }
            }
        }
    }

    public function test_idempotency_and_ability_exceptions_match_actual_middleware_contract(): void
    {
        foreach (OpenApi::document()['paths'] as $methods) {
            foreach ($methods as $method => $operation) {
                $name = $operation['operationId'];
                $readPosts = ['api.labels.preview', 'api.labels.pdf', 'api.labels.test'];
                $expected = $method !== 'get' && ! in_array($name, [...$readPosts, 'api.licenses.reveal'], true);
                $this->assertSame($expected, $operation['x-idempotency-required'], $name);
                $hasKey = collect($operation['parameters'])->contains(fn ($parameter) => ($parameter['$ref'] ?? null) === '#/components/parameters/IdempotencyKey');
                $this->assertSame($expected, $hasKey, $name);
                $this->assertContains('read', $operation['x-token-abilities']);
                if (in_array($name, $readPosts, true)) {
                    $this->assertSame(['read'], $operation['x-token-abilities']);
                }
                if (in_array($name, ['api.maintenance.work.accept', 'api.maintenance.work.correct'], true)) {
                    $this->assertSame(['read', 'write', 'accept-work'], $operation['x-token-abilities']);
                }
            }
        }
        $reveal = OpenApi::document()['paths']['/api/v1/licenses/{license}/reveal']['post'];
        $this->assertSame(['read', 'reveal-license'], $reveal['x-token-abilities']);
        $this->assertArrayHasKey('Cache-Control', $reveal['responses']['200']['headers']);
    }

    public function test_sensitive_license_fields_are_write_only_or_separate_reveal_response(): void
    {
        $schemas = OpenApi::document()['components']['schemas'];
        $this->assertTrue($schemas['LicenseKeyCommand']['properties']['license_key']['writeOnly']);
        $this->assertTrue($schemas['LicenseCreate']['properties']['license_key']['writeOnly']);
        foreach (['LicenseInstance', 'LicenseResponse', 'LicenseAssignment'] as $name) {
            foreach (['license_key', 'secret', 'ciphertext', 'key_id'] as $field) {
                $this->assertArrayNotHasKey($field, $schemas[$name]['properties']);
            }
        }
        $this->assertSame(['license_uuid', 'license_key'], $schemas['LicenseRevealResponse']['required']);
        $this->assertSame(['device', 'user', 'instance', 'unknown'], $schemas['LicenseInstance']['properties']['metric']['enum']);
        $this->assertArrayHasKey('remaining', $schemas['LicenseUsage']['properties']);
    }

    public function test_domain_commands_document_conditional_rules_and_safe_movement(): void
    {
        $schemas = OpenApi::document()['components']['schemas'];
        $this->assertContains('location_id', $schemas['AssetMove']['required']);
        $this->assertArrayNotHasKey('location_id', $schemas['AssetUpdate']['properties']);
        $this->assertArrayNotHasKey('status', $schemas['AssetUpdate']['properties']);
        $this->assertArrayHasKey('not', $schemas['AssetUpdate']);
        $this->assertSame('uuid', $schemas['AssetMove']['properties']['expected_contents']['anyOf'][0]['propertyNames']['format']);
        $this->assertArrayHasKey('expected_child_version', $schemas['ComponentCreate']['properties']);
        $this->assertContains('expected_rack_version', $schemas['RackPlacement']['required']);
        $this->assertArrayHasKey('AssetDetailResponse', $schemas);
        $this->assertArrayHasKey('CategoryList', $schemas);
        $this->assertCount(2, $schemas['WorkCreate']['oneOf']);
        $this->assertCount(2, $schemas['InstallationCommand']['anyOf']);
        $this->assertArrayHasKey('allOf', $schemas['ResolutionCreate']);
        $this->assertStringContainsString('ПЛАНА', $schemas['OccurrenceDecision']['properties']['expected_version']['description']);
        $this->assertSame(['check', 'inspection', 'number', 'text'], $schemas['ChecklistStep']['properties']['type']['enum']);
        $this->assertSame(500, $schemas['LabelBatch']['properties']['target_ids']['maxItems']);
        $this->assertTrue($schemas['LabelBatch']['properties']['target_ids']['uniqueItems']);
        $this->assertSame(210, $schemas['LabelGeometry']['properties']['paper_width_mm']['const']);
        $this->assertSame('integer', $schemas['Asset']['properties']['id']['type']);
        $this->assertSame('uuid', $schemas['Asset']['properties']['uuid']['format']);
    }

    private function assertReferencesResolve(mixed $node, array $document): void
    {
        if (! is_array($node)) {
            return;
        }
        if (isset($node['$ref'])) {
            $this->assertStringStartsWith('#/', $node['$ref']);
            $target = $document;
            foreach (explode('/', substr($node['$ref'], 2)) as $part) {
                $part = str_replace(['~1', '~0'], ['/', '~'], $part);
                $this->assertArrayHasKey($part, $target, 'Dangling reference: '.$node['$ref']);
                $target = $target[$part];
            }
        }
        foreach ($node as $child) {
            $this->assertReferencesResolve($child, $document);
        }
    }
}
