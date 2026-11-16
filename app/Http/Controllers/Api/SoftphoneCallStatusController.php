<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Call;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The browser softphone reports how its call ended.
 *
 * AT's WebRTC client calls do not reliably produce provider-side callbacks for
 * the app's own Call row (the number-level Events URL covers telephony legs,
 * not the client session), so without this the row created by /calls/initiate
 * stays "initiated" forever and the call never appears completed in Call Logs.
 * The adapter posts the real outcome here when AT emits its hangup event.
 *
 * Only the call's owner may close it, and only while it is still in flight —
 * a status already written by a provider callback is never overwritten.
 */
class SoftphoneCallStatusController extends Controller
{
    public function completed(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'call_id' => ['required', 'integer'],
            'status' => ['nullable', 'string', 'in:completed,failed,canceled'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'hangup_reason' => ['nullable', 'string', 'max:191'],
            'hangup_code' => ['nullable', 'string', 'max:50'],
        ]);

        $call = Call::query()->whereKey((int) $validated['call_id'])->first();

        if ($call === null || (int) $call->user_id !== (int) $request->user()->id) {
            return response()->json(['success' => false, 'error' => 'Call not found.'], 404);
        }

        $inFlight = ['initiated', 'queued', 'ringing', 'in-progress'];

        if (! in_array($call->status, $inFlight, true)) {
            // Already settled by a provider callback (or a previous report).
            return response()->json([
                'success' => true,
                'unchanged' => true,
                'call' => ['id' => $call->id, 'status' => $call->status, 'duration' => $call->duration_seconds],
            ]);
        }

        $status = $validated['status'] ?? 'completed';
        $reason = $validated['hangup_reason'] ?? null;

        $call->forceFill([
            'status' => $status,
            // The calls table stores duration in seconds under duration_seconds.
            'duration_seconds' => $validated['duration_seconds'] ?? $call->duration_seconds,
            'ended_at' => now(),
            'error_message' => $status === 'failed'
                ? ($reason !== null ? mb_substr($reason, 0, 191) : 'Softphone call failed')
                : null,
        ])->save();

        \Illuminate\Support\Facades\Log::info('Softphone completion report applied', [
            'call_id' => $call->id,
            'user_id' => $call->user_id,
            'status' => $status,
            'duration_seconds' => $call->duration_seconds,
            'hangup_code' => $validated['hangup_code'] ?? null,
            'hangup_reason' => $reason,
        ]);

        return response()->json([
            'success' => true,
            'call' => ['id' => $call->id, 'status' => $call->status, 'duration' => $call->duration_seconds],
        ]);
    }
}
