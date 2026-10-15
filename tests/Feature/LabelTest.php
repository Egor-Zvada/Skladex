<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\LabelProfile;
use App\Models\Location;
use App\Models\User;
use App\Services\LabelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LabelTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Label persistence requires MariaDB.');
        }
        if (! preg_match('/(?:^|_)(?:tests?|testing)(?:_|$)/i', DB::connection()->getDatabaseName())) {
            throw new RuntimeException('LabelTest requires a dedicated *_test or *_testing database.');
        }
    }

    public function test_profile_update_checks_version_and_keeps_valid_geometry(): void
    {
        $service = new LabelService;
        $actor = $this->actor();
        $profile = $service->saveProfile($actor, $service->defaults() + ['name' => 'Принтер ИТ']);
        $this->assertSame(1, $profile->version);
        $updated = $service->saveProfile($actor, array_replace($service->defaults(), ['name' => 'Принтер ИТ', 'profile_id' => $profile->id, 'expected_version' => 1, 'offset_x_mm' => -0.5]));
        $this->assertSame(2, $updated->version);
        $this->assertSame('-0.50', $updated->offset_x_mm);
        try {
            $service->saveProfile($actor, $service->defaults() + ['name' => 'Stale', 'profile_id' => $profile->id, 'expected_version' => 1]);
            $this->fail('Stale profile was overwritten.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame('Принтер ИТ', $profile->fresh()->name);
    }

    public function test_uuid_selection_is_typed_and_preserves_requested_order(): void
    {
        $actor = $this->actor();
        $location = Location::create(['code' => 'SKX-R-TEST', 'name' => 'Кабинет ИТ']);
        $category = Category::create(['name' => 'Аудио', 'slug' => 'audio-label-test', 'domain' => 'audio', 'kind' => 'standard']);
        $first = Asset::create(['code' => 'SKX-A-TEST1', 'name' => 'Усилитель', 'category_id' => $category->id]);
        $second = Asset::create(['code' => 'SKX-A-TEST2', 'name' => 'Микрофон', 'category_id' => $category->id]);
        $service = new LabelService;
        $targets = $service->targets($actor, 'asset', [$second->uuid, $first->uuid]);
        $this->assertSame([$second->uuid, $first->uuid], array_column($targets, 'uuid'));
        $this->assertStringEndsWith('/p/a/'.$second->uuid, $targets[0]['qr_url']);
        $rooms = $service->targets($actor, 'location', [$location->uuid]);
        $this->assertStringEndsWith('/p/r/'.$location->uuid, $rooms[0]['qr_url']);
        $this->expectException(ValidationException::class);
        $service->targets($actor, 'asset', [$location->uuid]);
    }

    public function test_archived_targets_are_rejected_and_viewers_cannot_save_profiles(): void
    {
        $actor = $this->actor('viewer');
        $service = new LabelService;
        try {
            $service->saveProfile($actor, $service->defaults() + ['name' => 'Forbidden']);
            $this->fail('Viewer saved a profile.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(0, LabelProfile::count());
        $room = Location::create(['code' => 'SKX-R-ARCHIVED', 'name' => 'Архив', 'archived_at' => now()]);
        $this->expectException(ValidationException::class);
        $service->targets($actor, 'location', [$room->uuid]);
    }

    private function actor(string $role = 'operator'): User
    {
        return User::create(['name' => 'Label QA', 'email' => Str::uuid().'@example.invalid', 'password' => 'test-password', 'role' => $role, 'is_active' => true]);
    }
}
