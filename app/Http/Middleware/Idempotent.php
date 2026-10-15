<?php

namespace App\Http\Middleware;

use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class Idempotent
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->routeIs('licenses.reveal', 'api.licenses.reveal', 'labels.pdf', 'api.labels.pdf', 'labels.test', 'api.labels.test', 'labels.preview', 'api.labels.preview')) {
            return $next($request);
        }
        if (! $request->isMethod('POST') && ! $request->isMethod('PATCH') && ! $request->isMethod('PUT') && ! $request->isMethod('DELETE')) {
            return $next($request);
        }
        if ($request->is('api/*')) {
            Access::check($request->user(), 'write');
        }
        $key = $request->header('Idempotency-Key') ?? $request->input('idempotency_key');
        if (! $key && ! $request->is('api/*')) {
            return $next($request);
        }
        abort_unless(is_string($key) && preg_match('/^[a-zA-Z0-9_.:-]{16,100}$/D', $key), 422, 'Для команды требуется Idempotency-Key длиной 16–100 символов.');
        $input = $request->except(['_token', 'idempotency_key']);
        ksort($input);
        foreach ($request->allFiles() as $field => $file) {
            if ($file instanceof UploadedFile) {
                $input[$field] = hash_file('sha256', $file->getRealPath());
            }
        }
        $hash = hash('sha256', $request->method().' '.$request->path().' '.json_encode($input));

        return DB::transaction(function () use ($request, $key, $hash, $next) {
            DB::table('idempotency_keys')->insertOrIgnore(['user_id' => $request->user()->id, 'key' => $key, 'request_hash' => $hash, 'status' => 'pending', 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('idempotency_keys')->where('user_id', $request->user()->id)->where('key', $key)->lockForUpdate()->first();
            abort_unless(hash_equals($row->request_hash, $hash), 409, 'Ключ команды уже использован для другого запроса.');
            if ($row->status === 'complete') {
                return new Response($row->response_body, $row->response_status, json_decode($row->response_headers ?? '{}', true));
            }
            $response = $next($request);
            if ($response->getStatusCode() < 400 && strlen($response->getContent()) < 500000) {
                $headers = [];
                foreach (['Content-Type', 'Location', 'Cache-Control'] as $h) {
                    if ($response->headers->has($h)) {
                        $headers[$h] = $response->headers->get($h);
                    }
                }
                DB::table('idempotency_keys')->where('id', $row->id)->update(['status' => 'complete', 'response_status' => $response->getStatusCode(), 'response_body' => $response->getContent(), 'response_headers' => json_encode($headers), 'updated_at' => now()]);
            } else {
                DB::table('idempotency_keys')->where('id', $row->id)->delete();
            }

            return $response;
        }, 3);
    }
}
