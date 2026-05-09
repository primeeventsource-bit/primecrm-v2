<?php

declare(strict_types=1);

namespace App\Modules\Lead\Http\Controllers;

use App\Modules\Lead\Application\Jobs\ImportLeadsBatchJob;
use App\Modules\Lead\Domain\Models\LeadImport;
use App\Modules\Lead\Http\Requests\ImportLeadsRequest;
use App\Modules\Lead\Http\Resources\LeadImportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

/**
 * Bulk lead import via CSV.
 *
 * Flow:
 *   1. POST /api/leads/import — multipart upload, returns batch metadata
 *   2. ImportLeadsBatchJob runs on the lead-import queue
 *   3. GET /api/leads/imports/{id} — poll status while batch processes
 *
 * The file is stored on the configured filesystem (S3 in production) so
 * the worker can read it without depending on the request lifecycle.
 */
final class LeadImportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = LeadImport::query()
            ->orderByDesc('created_at')
            ->paginate(25);

        return LeadImportResource::collection($page)->response();
    }

    public function store(ImportLeadsRequest $request): JsonResponse
    {
        $user = $request->user();
        $file = $request->file('file');
        $columnMapping = $request->validated('column_mapping');
        $source = $request->validated('source');

        $disk = config('filesystems.default');
        $path = "imports/leads/{$user->tenant_id}/".now()->format('Y/m/d').'/'.uniqid('import-', true).'.csv';

        Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()));

        $batch = LeadImport::query()->create([
            'imported_by_id' => $user->id,
            'source' => LeadImport::SOURCE_CSV,
            'original_filename' => $file->getClientOriginalName(),
            's3_path' => $path,
            'status' => LeadImport::STATUS_PENDING,
            'column_mapping' => $columnMapping,
        ]);

        ImportLeadsBatchJob::dispatch($batch->id, $columnMapping);

        return (new LeadImportResource($batch))
            ->response()
            ->setStatusCode(202);
    }

    public function show(string $id): LeadImportResource
    {
        $batch = LeadImport::query()->findOrFail($id);

        return new LeadImportResource($batch);
    }
}
