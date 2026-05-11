<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers;

use App\Modules\Compliance\Application\Services\ProhibitedPhraseScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Pre-save / pre-display compliance scan for free-form text.
 *
 *   POST /api/compliance/phrase-check    { text: "..." }
 *
 * Returns the list of matches with severity / reason / suggestion /
 * offset / length so the frontend can highlight problem spans inline
 * AND block submission when severity='block' is present.
 *
 * Use sites:
 *   - Email / SMS template editor (pre-save guard)
 *   - Agent free-text on owner communications
 *   - AI Live Coach suggestions before display (D8)
 */
final class ProhibitedPhraseController extends Controller
{
    public function __construct(private readonly ProhibitedPhraseScanner $scanner) {}

    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'text' => ['required', 'string', 'max:50000'],
        ]);

        $text = (string) $request->string('text');
        $matches = $this->scanner->scan($text);

        $blocks = array_filter($matches, fn ($m) => $m['severity'] === 'block');
        $warns = array_filter($matches, fn ($m) => $m['severity'] === 'warn');

        return response()->json([
            'is_blocked' => count($blocks) > 0,
            'matches' => array_values($matches),
            'summary' => [
                'block_count' => count($blocks),
                'warn_count' => count($warns),
            ],
        ]);
    }
}
