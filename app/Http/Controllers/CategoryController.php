<?php

namespace App\Http\Controllers;

use App\Models\Category;

class CategoryController extends Controller
{
    public function index()
    {
        return response()->json(['data' => Category::orderBy('name')->get(['id', 'name', 'slug', 'domain', 'kind', 'maintenance_policy'])]);
    }
}
