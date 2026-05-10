<?php

declare(strict_types=1);

namespace App\Modules\Note\Http\Controllers;

use App\Core\Shared\Services\AuditLogService;
use App\Modules\Customer\Domain\Models\Customer;
use App\Modules\Lead\Domain\Models\Lead;
use App\Modules\Note\Domain\Models\Note;
use App\Modules\Note\Http\Requests\StoreNoteRequest;
use App\Modules\Note\Http\Resources\NoteResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

/**
 * Notes are always queried per-entity. We don't expose an unbounded list
 * endpoint — too easy to leak across entities and there's no UX for it.
 *
 * Index:  GET  /api/notes?notable_type=lead&notable_id=<uuid>
 * Store:  POST /api/notes  (notable_type, notable_id, body[, kind, metadata])
 * Delete: DELETE /api/notes/{id}  (soft-delete; only the author or a supervisor)
 */
final class NoteController extends Controller
{
    /** Map the wire alias to the FQCN we actually persist in `notable_type`. */
    private const TYPE_MAP = [
        'lead' => Lead::class,
        'customer' => Customer::class,
    ];

    public function __construct(private readonly AuditLogService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'notable_type' => ['required', 'string', 'in:lead,customer'],
            'notable_id' => ['required', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $fqcn = self::TYPE_MAP[$validated['notable_type']];

        // Verify the parent entity exists in this tenant before exposing notes.
        // The model query itself is tenant-scoped; findOrFail returns 404 if
        // the entity belongs to another tenant.
        $this->findEntity($fqcn, $validated['notable_id']);

        $notes = Note::query()
            ->with('author')
            ->where('notable_type', $fqcn)
            ->where('notable_id', $validated['notable_id'])
            ->orderByDesc('created_at')
            ->paginate(min(200, (int) $request->integer('per_page', 50)));

        return NoteResource::collection($notes)->response();
    }

    public function store(StoreNoteRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $fqcn = self::TYPE_MAP[$validated['notable_type']];

        // Reject if the entity doesn't exist in this tenant (avoids creating
        // dangling notes that point at nothing reachable).
        $this->findEntity($fqcn, $validated['notable_id']);

        $note = Note::query()->create([
            'notable_type' => $fqcn,
            'notable_id' => $validated['notable_id'],
            'user_id' => $request->user()?->id,
            'kind' => $validated['kind'] ?? Note::KIND_NOTE,
            'body' => $validated['body'],
            'metadata' => $validated['metadata'] ?? null,
        ]);

        $this->audit->record(
            action: 'note.created',
            entityType: $validated['notable_type'],
            entityId: $validated['notable_id'],
            context: ['note_id' => $note->id, 'kind' => $note->kind],
        );

        $note->load('author');

        return (new NoteResource($note))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $note = Note::query()->findOrFail($id);

        // Author can delete their own; supervisors can delete any.
        $user = $request->user();
        $isAuthor = $user !== null && $note->user_id === $user->id;
        $isSupervisor = $user?->role?->canSupervise() ?? false;

        if (! $isAuthor && ! $isSupervisor) {
            throw ValidationException::withMessages([
                'id' => ['You can only delete notes you authored.'],
            ]);
        }

        $note->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Resolve the parent entity through its tenant-scoped model so that
     * cross-tenant IDs surface as 404 rather than as silently empty lists.
     */
    private function findEntity(string $fqcn, string $id): void
    {
        $fqcn::query()->findOrFail($id);
    }
}
