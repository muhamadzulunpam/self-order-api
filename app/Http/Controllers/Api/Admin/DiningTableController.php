<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DiningTableRequest;
use App\Http\Resources\DiningTableResource;
use App\Models\DiningTable;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class DiningTableController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return DiningTableResource::collection(
            DiningTable::withCount('orders')->orderBy('number')->get()
        );
    }

    public function store(DiningTableRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active', true);

        $table = DiningTable::create($data);

        return (new DiningTableResource($table))
            ->response()
            ->setStatusCode(201);
    }

    public function show(DiningTable $diningTable): DiningTableResource
    {
        return new DiningTableResource($diningTable->loadCount('orders'));
    }

    public function update(DiningTableRequest $request, DiningTable $diningTable): DiningTableResource
    {
        $diningTable->update($request->validated());

        return new DiningTableResource($diningTable);
    }

    public function destroy(DiningTable $diningTable): JsonResponse
    {
        if ($diningTable->orders()->exists()) {
            return response()->json([
                'message' => 'Meja sudah memiliki riwayat pesanan dan tidak bisa dihapus. Nonaktifkan saja dengan is_active = false.',
            ], 409);
        }

        $diningTable->delete();

        return response()->json(['message' => 'Meja berhasil dihapus.']);
    }

    public function regenerateToken(DiningTable $diningTable): DiningTableResource
    {
        $diningTable->regenerateToken();

        return new DiningTableResource($diningTable);
    }

    public function qr(Request $request, DiningTable $diningTable): Response
    {
        $result = (new Builder(
            writer: new SvgWriter(),
            data: $diningTable->orderUrl(),
            size: 400,
            margin: 16,
        ))->build();

        $headers = ['Content-Type' => $result->getMimeType()];

        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="meja-'.$diningTable->number.'.svg"';
        }

        return response($result->getString(), 200, $headers);
    }
}