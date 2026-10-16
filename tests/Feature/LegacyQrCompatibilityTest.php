<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use App\Models\QrAlias;
use App\Services\QrResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyQrCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_exact_verified_historical_url_can_bypass_host_allowlist(): void
    {
        $asset = $this->asset();
        $url = 'http://skaladex.vve.local/admin/equipment.php?code=EQ-000001';
        QrAlias::create(['alias' => 'url:'.$url, 'target_type' => 'asset', 'target_id' => $asset->id]);
        $resolver = app(QrResolver::class);
        $this->assertSame($asset->uuid, $resolver->resolve($url)?->uuid);
        $this->assertNull($resolver->resolve($url.'&other=1'));
        $this->assertNull($resolver->resolve('https://untrusted.invalid/eq/EQ-000001'));
        $asset->update(['archived_at' => now()]);
        $this->assertNull($resolver->resolve($url));
    }

    public function test_php_card_and_numeric_keys_use_independent_explicit_aliases(): void
    {
        $byCode = $this->asset();
        $byId = $this->asset('SKX-A-OTHER');
        QrAlias::create(['alias' => 'asset-code:EQ-000001', 'target_type' => 'asset', 'target_id' => $byCode->id]);
        QrAlias::create(['alias' => 'asset-id:29', 'target_type' => 'asset', 'target_id' => $byId->id]);
        $this->get('/admin/equipment.php?code=EQ-000001')->assertRedirect(route('public.asset', $byCode));
        foreach (['/admin/equipment.php?id=00029', '/equipment_card.php?key=%2000029%20', '/eq/00029'] as $path) {
            $this->get($path)->assertRedirect(route('public.asset', $byId));
        }
        $this->assertSame($byId->uuid, app(QrResolver::class)->resolve('00029')?->uuid);
        $this->get('/eq/EQ-000029')->assertNotFound();
        $this->get('/admin/equipment.php?code[]=EQ-000001')->assertNotFound();
    }

    public function test_room_identifiers_remain_exact_unicode_strings_with_leading_zeroes(): void
    {
        $room = Location::create(['code' => 'Сe-00029', 'name' => 'Fixture room']);
        QrAlias::create(['alias' => 'location-id:Сe-00029', 'target_type' => 'location', 'target_id' => $room->id]);
        QrAlias::create(['alias' => 'location-id:00029', 'target_type' => 'location', 'target_id' => $room->id]);
        $this->get('/cabinet/'.rawurlencode('Сe-00029'))->assertRedirect(route('public.location', $room));
        $this->get('/cabinet.php?id=00029')->assertRedirect(route('public.location', $room));
        $this->get('/cabinet.php?id=29')->assertNotFound();
        $this->get('/cabinet/'.rawurlencode('Ce-00029'))->assertNotFound();
    }

    private function asset(string $code = 'SKX-A-COMPAT'): Asset
    {
        $category = Category::firstOrCreate(['slug' => 'qr-compatibility'], ['name' => 'Fixture category', 'domain' => 'it', 'kind' => 'standard']);

        return Asset::create(['code' => $code, 'name' => 'Fixture item', 'category_id' => $category->id]);
    }
}
