<?php

namespace App\Http\Controllers;

use App\Support\Access;
use App\Support\OpenApi;
use Illuminate\Http\Request;

class ApiReferenceController extends Controller
{
    public function index(Request $request)
    {
        Access::check($request->user(), 'read');

        return response()->view('api.reference', ['document' => OpenApi::document()])->header('Cache-Control', 'private, no-store');
    }

    public function schema(Request $request)
    {
        Access::check($request->user(), 'read');

        return response()->json(OpenApi::document(), 200, ['Cache-Control' => 'private, no-store'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
