<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetComponent;
use App\Models\AssetMovement;
use App\Models\Category;
use App\Models\Location;
use App\Models\PhysicalPlacement;
use App\Models\User;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Codes;
use App\Support\Version;
use Illuminate\Support\Facades\DB;

class AssetService
{
    public function save(User $actor, array $data, ?Asset $asset = null): Asset
    {
        Access::check($actor, 'write');

        return DB::transaction(function () use ($data, $asset) {
            Codes::lockPhysicalGraph();
            if ($asset) {
                $asset = Asset::lockForUpdate()->findOrFail($asset->id);
                Version::check($asset, $data['expected_version'] ?? null);
            } else {
                $asset = new Asset(['code' => Codes::next('SKX-A')]);
                if (! empty($data['location_id'])) {
                    // Recheck inside the graph transaction: a room may have
                    // been archived after the HTTP request was validated.
                    Location::whereKey($data['location_id'])->whereNull('archived_at')->lockForUpdate()->firstOrFail();
                }
            }
            if ($asset->exists && isset($data['category_id']) && (int) $data['category_id'] !== $asset->category_id && PhysicalPlacement::where('parent_asset_id', $asset->id)->lockForUpdate()->get()->isNotEmpty()) {
                abort_unless(Category::findOrFail($data['category_id'])->kind === $asset->category->kind, 422, 'Сначала снимите установленное оборудование перед изменением типа категории.');
            }
            $allowed = ['name', 'category_id', 'responsible_name', 'inventory_no', 'serial_no', 'manufacturer', 'model', 'condition', 'maintenance_policy', 'specifications', 'private_notes', 'purchased_on', 'warranty_until'];
            foreach ($allowed as $field) {
                if (array_key_exists($field, $data)) {
                    $asset->$field = $data[$field];
                }
            }
            if (! $asset->exists) {
                $asset->location_id = $data['location_id'] ?? null;
                $asset->status = $data['status'] ?? 'stock';
            } else {
                abort_if($asset->archived_at, 409, 'Архивная запись недоступна для изменения.');
                $asset->version++;
            }
            $asset->save();
            Audit::record('asset.saved', $asset, ['version' => $asset->version]);

            return $asset->refresh();
        }, 3);
    }

    public function move(User $actor, Asset $asset, array $data): Asset
    {
        Access::check($actor, 'write');

        return DB::transaction(function () use ($actor, $asset, $data) {
            Codes::lockPhysicalGraph();
            $asset = Asset::lockForUpdate()->findOrFail($asset->id);
            Version::check($asset, $data['expected_version'] ?? null);
            abort_if($asset->archived_at, 409, 'Архивный предмет нельзя переместить.');
            abort_if(PhysicalPlacement::where('asset_id', $asset->id)->lockForUpdate()->get()->isNotEmpty(), 422, 'Сначала снимите предмет с его физического родителя.');
            $ids = [$asset->id];
            $frontier = [$asset->id];
            while ($frontier) {
                $frontier = PhysicalPlacement::whereIn('parent_asset_id', $frontier)->lockForUpdate()->get()->pluck('asset_id')->all();
                foreach ($frontier as $id) {
                    abort_if(in_array($id, $ids), 409, 'Обнаружен цикл размещения.');
                    $ids[] = $id;
                }
            }
            abort_if(count($ids) > 1 && empty($data['include_children']), 422, 'Подтвердите перемещение всего установленного оборудования либо сначала отсоедините оставшиеся предметы.');
            $items = Asset::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $actual = $items->where('id', '!=', $asset->id)->mapWithKeys(fn ($item) => [$item->uuid => $item->version])->all();
            if ($actual) {
                $expected = $data['expected_contents'] ?? [];
                ksort($actual);
                ksort($expected);
                abort_unless($actual === array_map('intval', $expected), 409, 'Состав или версии установленного оборудования изменились. Откройте карточку заново и проверьте весь список.');
            }
            $location = $data['location_id'] ?? null;
            if ($location) {
                Location::whereKey($location)->whereNull('archived_at')->firstOrFail();
            }
            foreach ($items as $item) {
                $from = $item->location_id;
                $before = $item->status;
                $item->location_id = $location;
                if ($item->id === $asset->id && isset($data['status'])) {
                    $item->status = $data['status'];
                }
                $item->version++;
                $item->save();
                AssetMovement::create(['asset_id' => $item->id, 'from_location_id' => $from, 'to_location_id' => $location, 'from_status' => $before, 'to_status' => $item->status, 'reason' => $data['reason'], 'actor_id' => $actor->id, 'asset_version' => $item->version, 'occurred_at' => now()]);
                Audit::record('asset.moved', $item, ['from_location_id' => $from, 'to_location_id' => $location, 'version' => $item->version]);
            }

            return $asset->refresh();
        }, 3);
    }

    public function component(User $actor, Asset $parent, array $data): AssetComponent
    {
        Access::check($actor, 'write');

        return DB::transaction(function () use ($actor, $parent, $data) {
            Codes::lockPhysicalGraph();
            $parent = Asset::lockForUpdate()->findOrFail($parent->id);
            Version::check($parent, $data['expected_version'] ?? null);
            abort_if($parent->archived_at, 409, 'Архивный компьютер недоступен для изменения.');
            abort_unless($parent->category->kind === 'computer', 422, 'Состав доступен у компьютерного оборудования.');
            $child = null;
            if (! empty($data['child_asset_id'])) {
                $child = Asset::lockForUpdate()->findOrFail($data['child_asset_id']);
                Version::check($child, $data['expected_child_version'] ?? null);
                $this->assertPlacement($child, $parent);
                PhysicalPlacement::create(['asset_id' => $child->id, 'parent_asset_id' => $parent->id, 'kind' => 'component']);
                $this->placeInRoom($actor, $child, $parent, 'Установка комплектующего');
            }
            $row = AssetComponent::create(['parent_asset_id' => $parent->id, 'child_asset_id' => $child?->id, 'component_type' => $data['component_type'], 'description' => $child?->name ?? $data['description'], 'quantity' => $child ? 1 : ($data['quantity'] ?? 1), 'serial_no' => $child ? null : ($data['serial_no'] ?? null), 'inventory_no' => $child ? null : ($data['inventory_no'] ?? null), 'installed_at' => now()]);
            $parent->increment('version');
            Audit::record('component.added', $parent, ['component_id' => $row->id, 'child_asset_id' => $child?->id]);

            return $row;
        }, 3);
    }

    public function removeComponent(User $actor, Asset $parent, AssetComponent $row, int $expected): void
    {
        Access::check($actor, 'write');
        DB::transaction(function () use ($parent, $row, $expected) {
            Codes::lockPhysicalGraph();
            $parent = Asset::lockForUpdate()->findOrFail($parent->id);
            Version::check($parent, $expected);
            abort_if($parent->archived_at, 409);
            $row = AssetComponent::lockForUpdate()->findOrFail($row->id);
            abort_unless($row->parent_asset_id === $parent->id && ! $row->removed_at, 409);
            if ($row->child_asset_id) {
                PhysicalPlacement::where('asset_id', $row->child_asset_id)->where('parent_asset_id', $parent->id)->delete();
                Asset::whereKey($row->child_asset_id)->increment('version');
            }
            $row->removed_at = now();
            $row->save();
            $parent->increment('version');
            Audit::record('component.removed', $parent, ['component_id' => $row->id]);
        }, 3);
    }

    public function rack(User $actor, Asset $child, Asset $rack, int $expected, ?string $position, ?int $expectedRack = null): void
    {
        Access::check($actor, 'write');
        DB::transaction(function () use ($actor, $child, $rack, $expected, $position, $expectedRack) {
            Codes::lockPhysicalGraph();
            $locked = Asset::whereIn('id', [$child->id, $rack->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $child = $locked[$child->id];
            $rack = $locked[$rack->id];
            Version::check($child, $expected);
            Version::check($rack, $expectedRack);
            abort_unless($rack->category->kind === 'rack', 422, 'Выбранный родитель не является серверным/коммутационным шкафом.');
            $this->assertPlacement($child, $rack);
            PhysicalPlacement::create(['asset_id' => $child->id, 'parent_asset_id' => $rack->id, 'kind' => 'rack', 'position' => $position]);
            $this->placeInRoom($actor, $child, $rack, 'Установка в шкаф');
            $rack->increment('version');
            Audit::record('rack.installed', $child, ['rack_id' => $rack->id, 'position' => $position]);
        }, 3);
    }

    public function detach(User $actor, Asset $child, int $expected): void
    {
        Access::check($actor, 'write');
        DB::transaction(function () use ($child, $expected) {
            Codes::lockPhysicalGraph();
            $child = Asset::lockForUpdate()->findOrFail($child->id);
            Version::check($child, $expected);
            $placement = PhysicalPlacement::where('asset_id', $child->id)->lockForUpdate()->firstOrFail();
            abort_unless($placement->kind === 'rack', 422, 'Компонент снимайте из состава ПК.');
            $parent = $placement->parent_asset_id;
            $placement->delete();
            $child->increment('version');
            Asset::whereKey($parent)->increment('version');
            Audit::record('rack.detached', $child, ['rack_id' => $parent]);
        }, 3);
    }

    public function archive(User $actor, Asset $asset, int $expected): void
    {
        Access::check($actor, 'write');
        DB::transaction(function () use ($asset, $expected) {
            Codes::lockPhysicalGraph();
            $asset = Asset::lockForUpdate()->findOrFail($asset->id);
            Version::check($asset, $expected);
            abort_if(PhysicalPlacement::where('asset_id', $asset->id)->orWhere('parent_asset_id', $asset->id)->lockForUpdate()->get()->isNotEmpty(), 422, 'Сначала снимите физические связи и установленное оборудование.');
            $asset->archived_at = now();
            $asset->version++;
            $asset->save();
            Audit::record('asset.archived', $asset);
        }, 3);
    }

    private function placeInRoom(User $actor, Asset $child, Asset $parent, string $reason): void
    {
        $from = $child->location_id;
        $child->location_id = $parent->location_id;
        $child->version++;
        $child->save();
        if ($from !== $child->location_id) {
            AssetMovement::create(['asset_id' => $child->id, 'from_location_id' => $from, 'to_location_id' => $child->location_id, 'from_status' => $child->status, 'to_status' => $child->status, 'reason' => $reason.' · '.$parent->code, 'actor_id' => $actor->id, 'asset_version' => $child->version, 'occurred_at' => now()]);
        }
    }

    private function assertPlacement(Asset $child, Asset $parent): void
    {
        abort_if($child->location_id !== $parent->location_id && PhysicalPlacement::where('parent_asset_id', $child->id)->lockForUpdate()->get()->isNotEmpty(), 422, 'Сначала переместите предмет вместе с его содержимым в кабинет родителя.');
        abort_if($child->id === $parent->id || $child->archived_at || $parent->archived_at, 422, 'Недопустимое размещение.');
        abort_if(PhysicalPlacement::where('asset_id', $child->id)->lockForUpdate()->get()->isNotEmpty(), 409, 'Предмет уже установлен. Сначала снимите его.');
        $ancestor = $parent->id;
        $visited = [];
        while ($ancestor) {
            abort_if($ancestor === $child->id || in_array($ancestor, $visited), 422, 'Размещение создаёт цикл.');
            $visited[] = $ancestor;
            $ancestor = PhysicalPlacement::where('asset_id', $ancestor)->lockForUpdate()->first()?->parent_asset_id;
        }
    }
}
