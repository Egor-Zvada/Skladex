<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\LicenseAssignment;
use App\Models\LicenseInstance;
use App\Models\SoftwareProduct;
use App\Services\LicenseService;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LicenseController extends Controller
{
    public function __construct(private LicenseService $licenses) {}

    public function index(Request $request)
    {
        Access::check($request->user(), 'read');
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 200);
        $licenses = LicenseInstance::with('product')->withCount('activeAssignments')->withExists('secret')
            ->whereNull('archived_at')->when($q !== '', fn ($query) => $query->where(function ($query) use ($q) {
                $query->where('code', 'like', '%'.$q.'%')->orWhere('name', 'like', '%'.$q.'%')->orWhereHas('product', fn ($product) => $product->where('name', 'like', '%'.$q.'%'));
            }))->latest('id')->paginate(30)->withQueryString();
        if ($this->json($request)) {
            $licenses->getCollection()->each(fn ($license) => $license->setAttribute('usage', $this->licenses->usage($license)));

            return response()->json($licenses);
        }

        return view('licenses.index', ['licenses' => $licenses, 'q' => $q, 'products' => SoftwareProduct::whereNull('archived_at')->orderBy('name')->get(), 'assets' => $this->assets()]);
    }

    public function show(Request $request, LicenseInstance $license)
    {
        Access::check($request->user(), 'read');
        $license->load('product', 'assignments.asset', 'assignments.actor')->loadCount('activeAssignments')->loadExists('secret');
        $warnings = $this->licenses->warnings($license);
        if ($this->json($request)) {
            return response()->json(['license' => $license, 'warnings' => $warnings, 'usage' => $this->licenses->usage($license)]);
        }

        return view('licenses.show', ['license' => $license, 'warnings' => $warnings, 'assets' => $this->assets()]);
    }

    public function store(Request $request)
    {
        return $this->command($request, fn () => $this->licenses->create($request->user(), $request->all()), 'Лицензия сохранена.', 201);
    }

    public function update(Request $request, LicenseInstance $license)
    {
        return $this->command($request, fn () => $this->licenses->update($request->user(), $license, $request->all()), 'Сведения о лицензии сохранены.');
    }

    public function keyStore(Request $request, LicenseInstance $license)
    {
        return $this->command($request, fn () => $this->licenses->storeKey($request->user(), $license, $request->all()), 'Защищённый ключ сохранён.');
    }

    public function assign(Request $request, LicenseInstance $license)
    {
        return $this->command($request, fn () => $this->licenses->assign($request->user(), $license, $request->all()), 'Место привязано к оборудованию.');
    }

    public function release(Request $request, LicenseInstance $license, LicenseAssignment $assignment)
    {
        return $this->command($request, fn () => $this->licenses->release($request->user(), $license, $assignment, $request->all()), 'Место освобождено. История сохранена.');
    }

    public function reveal(Request $request, LicenseInstance $license)
    {
        $key = $this->licenses->reveal($request->user(), $license, $request->all());
        $response = $this->json($request)
            ? response()->json(['license_uuid' => $license->uuid, 'license_key' => $key])
            : response()->view('licenses.reveal', ['license' => $license, 'licenseKey' => $key]);

        return $response->header('Cache-Control', 'private, no-store, max-age=0')->header('Pragma', 'no-cache')->header('Expires', '0');
    }

    private function command(Request $request, callable $callback, string $message, int $status = 200)
    {
        try {
            $license = $callback();
        } catch (ValidationException $exception) {
            if ($this->json($request)) {
                throw $exception;
            }

            // Laravel's default validation redirect flashes all inputs except passwords.
            // The activation key must never enter the session, even on invalid forms.
            return back()->withErrors($exception->errors())->withInput($request->except(['license_key', 'key', 'secret', 'ciphertext', '_token', 'idempotency_key']));
        }
        $warnings = $this->licenses->warnings($license);
        if ($this->json($request)) {
            return response()->json(['license' => $license, 'warnings' => $warnings, 'usage' => $this->licenses->usage($license)], $status);
        }
        $response = redirect()->route('licenses.show', $license)->with('status', $message);

        return $warnings ? $response->with('warning', implode(' ', $warnings)) : $response;
    }

    private function json(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    private function assets()
    {
        return Asset::whereNull('archived_at')->where('status', '!=', 'written_off')->orderBy('name')->get(['id', 'uuid', 'name', 'code']);
    }
}
