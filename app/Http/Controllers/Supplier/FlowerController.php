<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeactivateFlowerRequest;
use App\Http\Requests\StoreFlowerRequest;
use App\Http\Requests\UpdateFlowerRequest;
use App\Models\Flower;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class FlowerController extends Controller
{
    /**
     * Display the approved Flower Management screen.
     */
    public function index(): View
    {
        return view('supplier.flowers');
    }

    /**
     * Return persisted catalog records for the existing DataTable.
     */
    public function data(): JsonResponse
    {
        return response()->json([
            'data' => Flower::query()->orderBy('id')->get()
                ->map(fn (Flower $flower) => $this->serializeFlower($flower))->values(),
        ]);
    }

    /**
     * Create a catalog record and its final server-generated code atomically.
     */
    public function store(StoreFlowerRequest $request): JsonResponse
    {
        $values = $request->validated();
        unset($values['image']);
        $newPath = $this->storeImage($request->file('image'));

        try {
            $flower = DB::transaction(function () use ($values, $newPath): Flower {
                $flower = Flower::create([
                    ...$values,
                    'code' => 'TMP-'.Str::ulid(),
                    'image_path' => $newPath,
                ]);
                $flower->code = 'FLW-'.str_pad((string) $flower->id, 3, '0', STR_PAD_LEFT);
                $flower->save();

                return $flower;
            });
        } catch (Throwable $exception) {
            $this->deleteImage($newPath);
            throw $exception;
        }

        return response()->json([
            'message' => 'Flower created successfully.',
            'data' => $this->serializeFlower($flower),
        ], 201);
    }

    /**
     * Update a locked catalog record without changing its item type or identity.
     */
    public function update(UpdateFlowerRequest $request, Flower $flower): JsonResponse
    {
        $values = $request->validated();
        unset($values['image'], $values['remove_image']);
        $newPath = $this->storeImage($request->file('image'));
        $oldPath = null;

        try {
            $updatedFlower = DB::transaction(function () use ($request, $flower, $values, $newPath, &$oldPath): Flower {
                $lockedFlower = Flower::query()->lockForUpdate()->findOrFail($flower->id);

                if ($values['product_type'] !== $lockedFlower->product_type) {
                    throw ValidationException::withMessages([
                        'product_type' => 'Item type cannot be changed after creation.',
                    ]);
                }

                if ($newPath !== null || $request->boolean('remove_image')) {
                    $oldPath = $lockedFlower->image_path;
                    $lockedFlower->image_path = $newPath;
                }

                $lockedFlower->fill($values);
                $lockedFlower->save();

                return $lockedFlower;
            });
        } catch (Throwable $exception) {
            $this->deleteImage($newPath);
            throw $exception;
        }

        // A cleanup failure must not turn a committed update into a failed response.
        if ($oldPath !== null && $oldPath !== $newPath) {
            $this->deleteImage($oldPath);
        }

        return response()->json([
            'message' => 'Flower updated successfully.',
            'data' => $this->serializeFlower($updatedFlower),
        ]);
    }

    /**
     * Idempotently deactivate a record while preserving its history and stock.
     */
    public function deactivate(DeactivateFlowerRequest $request, Flower $flower): JsonResponse
    {
        $updatedFlower = DB::transaction(function () use ($flower): Flower {
            $lockedFlower = Flower::query()->lockForUpdate()->findOrFail($flower->id);
            if ($lockedFlower->status !== 'Inactive') {
                $lockedFlower->status = 'Inactive';
                $lockedFlower->save();
            }

            return $lockedFlower;
        });

        return response()->json([
            'message' => 'Flower is inactive.',
            'data' => $this->serializeFlower($updatedFlower),
        ]);
    }

    /**
     * Expose the catalog response contract without internal storage paths.
     */
    private function serializeFlower(Flower $flower): array
    {
        return [
            'id' => $flower->id,
            'code' => $flower->code,
            'name' => $flower->name,
            'product_type' => $flower->product_type,
            'category' => $flower->category,
            'minimum_stock' => (int) $flower->minimum_stock,
            'description' => $flower->description,
            'status' => $flower->status,
            'image_url' => $flower->image_path
                ? Storage::disk('public')->url($flower->image_path)
                : null,
        ];
    }

    /**
     * Store an already validated upload on the public disk.
     */
    private function storeImage(?UploadedFile $image): ?string
    {
        if ($image === null) {
            return null;
        }

        $path = $image->store('flowers', 'public');
        if ($path === false) {
            throw new RuntimeException('Unable to store the flower image.');
        }

        return $path;
    }

    /**
     * Attempt file cleanup without undoing a successful database operation.
     */
    private function deleteImage(?string $path): void
    {
        if ($path === null) {
            return;
        }

        try {
            if (! Storage::disk('public')->delete($path)) {
                Log::warning('Flower image cleanup failed.', ['path' => $path]);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
