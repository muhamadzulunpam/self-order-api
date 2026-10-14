<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicCategoryResource;
use App\Models\Category;
use App\Models\DiningTable;
use Illuminate\Http\JsonResponse;

class MenuController extends Controller
{
    public function index(string $qrToken): JsonResponse
    {
        $table = DiningTable::findActiveByToken($qrToken);

        abort_if(! $table, 404, 'Meja tidak ditemukan atau tidak aktif.');

        $categories = Category::query()
            ->whereHas('menus', fn ($q) => $q->where('is_available', true))
            ->with(['menus' => fn ($q) => $q->where('is_available', true)->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => [
                'table' => ['number' => $table->number],
                'categories' => PublicCategoryResource::collection($categories)->resolve(),
            ],
        ]);
    }
}
