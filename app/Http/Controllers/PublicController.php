<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Location;
use App\Services\QrResolver;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Request;

class PublicController extends Controller
{
    public function home()
    {
        return view('public.home');
    }

    public function resolve(Request $r, QrResolver $resolver)
    {
        $data = $r->validate(['code' => 'required|string|max:2048']);
        $target = $resolver->resolve($data['code']);
        if (! $target) {
            return back()->withErrors(['code' => 'Код пока не найден. Проверьте ввод; старые метки заработают после сопоставления данных при переносе.'])->withInput();
        }

        return redirect()->route($target instanceof Asset ? 'public.asset' : 'public.location', $target);
    }

    public function asset(Request $r, Asset $asset)
    {
        $this->assertPublic($asset);
        $data = $this->assetData($asset);

        return $r->expectsJson() ? response()->json(['data' => $data]) : view('public.asset', ['item' => $data]);
    }

    public function location(Request $r, Location $location)
    {
        abort_unless($location->is_public && ! $location->archived_at, 404);
        $assets = $location->assets()->whereNull('archived_at')->with('category', 'location')->orderBy('name')->paginate(50);
        $data = ['id' => $location->uuid, 'code' => $location->code, 'name' => $location->name];
        if ($r->expectsJson()) {
            return response()->json(['data' => $data, 'assets' => $assets->getCollection()->map(fn ($a) => $this->assetData($a)), 'pagination' => ['page' => $assets->currentPage(), 'last_page' => $assets->lastPage(), 'total' => $assets->total()]]);
        }

        return view('public.location', ['item' => $data, 'assets' => $assets]);
    }

    public function legacyAsset(Request $r, QrResolver $resolver, string $code = '')
    {
        $input = $code !== '' ? '/eq/'.rawurlencode($code) : '/equipment_card.php?'.http_build_query($r->only(['id', 'key', 'code']));
        $target = $resolver->resolve($input);
        abort_unless($target instanceof Asset, 404, 'Метка ещё не сопоставлена с перенесённым предметом.');
        $this->assertPublic($target);

        return redirect()->route('public.asset', $target, 302);
    }

    public function legacyLocation(Request $r, QrResolver $resolver, string $code = '')
    {
        $input = $code !== '' ? '/cabinet/'.rawurlencode($code) : '/cabinet.php?'.http_build_query($r->only('id'));
        $target = $resolver->resolve($input);
        abort_unless($target instanceof Location && $target->is_public && ! $target->archived_at, 404, 'Метка ещё не сопоставлена с перенесённым кабинетом.');

        return redirect()->route('public.location', $target, 302);
    }

    public function qrAsset(Asset $asset, QrResolver $resolver)
    {
        $this->assertPublic($asset);

        return $this->png($resolver->url($asset));
    }

    public function qrLocation(Location $location, QrResolver $resolver)
    {
        abort_unless($location->is_public && ! $location->archived_at, 404);

        return $this->png($resolver->url($location));
    }

    private function png(string $value)
    {
        $qr = new QrCode(data: $value, size: 360, margin: 12);
        $png = (new PngWriter)->write($qr)->getString();

        return response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public,max-age=3600']);
    }

    private function assertPublic(Asset $a): void
    {
        abort_if($a->archived_at || ($a->location && (! $a->location->is_public || $a->location->archived_at)), 404);
    }

    private function assetData(Asset $a): array
    {
        return ['id' => $a->uuid, 'code' => $a->code, 'name' => $a->name, 'category' => $a->category?->name, 'location' => $a->location ? ['id' => $a->location->uuid, 'code' => $a->location->code, 'name' => $a->location->name] : null, 'status' => $a->status, 'condition' => $a->condition];
    }
}
