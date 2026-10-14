<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MenuRequest;
use App\Http\Resources\MenuResource;
use App\Models\Menu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class MenuController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $menus = Menu::with('category')
            ->when($request->category_id, fn ($q, $v) => $q->where('category_id', $v))
            ->when($request->search, fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->when($request->has('is_available'), fn ($q) => $q->where('is_available', $request->boolean('is_available')))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return MenuResource::collection($menus);
    }

    public function store(MenuRequest $request): JsonResponse
    {
        $data = $request->safe()->except('image');

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('menus', 'public');
        }

        $menu = Menu::create($data);

        return (new MenuResource($menu->load('category')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Menu $menu): MenuResource
    {
        return new MenuResource($menu->load('category'));
    }

    public function update(MenuRequest $request, Menu $menu): MenuResource
    {
        $data = $request->safe()->except('image');

        if ($request->hasFile('image')) {
            if ($menu->image) {
                Storage::disk('public')->delete($menu->image);
            }
            $data['image'] = $request->file('image')->store('menus', 'public');
        }

        $menu->update($data);

        return new MenuResource($menu->load('category'));
    }

    public function destroy(Menu $menu): JsonResponse
    {
        if ($menu->orderItems()->exists()) {
            return response()->json([
                'message' => 'Menu sudah pernah dipesan dan tidak bisa dihapus. Nonaktifkan saja dengan is_available = false.',
            ], 409);
        }

        if ($menu->image) {
            Storage::disk('public')->delete($menu->image);
        }

        $menu->delete();

        return response()->json(['message' => 'Menu berhasil dihapus.']);
    }
}
