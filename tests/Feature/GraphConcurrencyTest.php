<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\PhysicalPlacement;
use App\Models\User;
use App\Services\AssetService;
use App\Support\Codes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GraphConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_a_second_connection_cannot_mutate_the_graph_while_another_command_owns_it(): void
    {
        $actor = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $rackCategory = Category::create(['name' => 'Rack', 'slug' => 'rack', 'domain' => 'it', 'kind' => 'rack']);
        $pcCategory = Category::create(['name' => 'PC', 'slug' => 'pc', 'domain' => 'it', 'kind' => 'computer']);
        $rack = Asset::create(['code' => 'SKX-A-GUARD-R', 'name' => 'Rack', 'category_id' => $rackCategory->id]);
        $pc = Asset::create(['code' => 'SKX-A-GUARD-PC', 'name' => 'PC', 'category_id' => $pcCategory->id]);
        DB::transaction(fn () => Codes::lockPhysicalGraph());
        config(['database.connections.graph_guard' => config('database.connections.'.config('database.default'))]);
        $guard = DB::connection('graph_guard');
        $guard->beginTransaction();
        $guard->table('code_counters')->where('prefix', 'LOCK-PHYSICAL-GRAPH')->lockForUpdate()->first();
        DB::statement('SET SESSION innodb_lock_wait_timeout=1');
        $blocked = false;
        try {
            app(AssetService::class)->rack($actor, $pc, $rack, 1, null, 1);
        } catch (QueryException $e) {
            $this->assertSame(1205, (int) $e->errorInfo[1]);
            $blocked = true;
        } finally {
            $guard->rollBack();
            DB::purge('graph_guard');
            DB::statement('SET SESSION innodb_lock_wait_timeout=50');
        }
        $this->assertTrue($blocked, 'A concurrent graph mutation bypassed the mutex.');
        $this->assertSame(0, PhysicalPlacement::count());
        app(AssetService::class)->rack($actor, $pc->fresh(), $rack->fresh(), 1, null, 1);
        $this->assertSame($rack->id, $pc->fresh()->physicalPlacement->parent_asset_id);
    }
}
