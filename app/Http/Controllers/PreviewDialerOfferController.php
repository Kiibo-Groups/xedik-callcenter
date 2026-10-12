<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PreviewDialerOfferException;
use App\Services\PreviewDialerOfferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreviewDialerOfferController extends Controller
{
    public function __construct(private PreviewDialerOfferService $service) {}

    public function eligibility(Request $request): JsonResponse
    {
        return response()->json($this->service->eligibilityFor($request->user()));
    }

    public function current(Request $request): JsonResponse
    {
        $offer = $this->service->currentOffer($request->user());

        // Always return an explicit 200 JSON body. When there is no live current offer we
        // return { ok: true, offer: null } (instead of 204 No Content) so the frontend
        // unambiguously sees "no current offer" and continues the poll -> claim flow. A
        // terminal/finalized offer is never surfaced here (see currentOffer()).
        return response()->json([
            'ok' => true,
            'offer' => $offer ? $this->service->serializeOffer($offer) : null,
        ]);
    }

    public function claim(Request $request): JsonResponse
    {
        $result = $this->service->claim($request->user());

        if (!$result['offer']) {
            $status = in_array($result['reason'], ['no_eligible_leads', 'agent_not_ready', 'active_offer_exists', 'agent_in_wrap_up', 'blocked_by_calling_hours'], true)
                ? Response::HTTP_OK
                : Response::HTTP_FORBIDDEN;

            return response()->json([
                'ok' => false,
                'offer' => null,
                'reason' => $result['reason'],
            ], $status);
        }

        return response()->json([
            'ok' => true,
            'offer' => $this->service->serializeOffer($result['offer']),
        ], $result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        return $this->runOfferAction(fn () => $this->service->accept($request->user(), $token));
    }

    public function decline(Request $request, string $token): JsonResponse
    {
        return $this->runOfferAction(fn () => $this->service->decline($request->user(), $token));
    }

    public function expire(Request $request, string $token): JsonResponse
    {
        return $this->runOfferAction(fn () => $this->service->expire($request->user(), $token));
    }

    public function link(Request $request, string $token): JsonResponse
    {
        $request->validate(['conversation_id' => ['required', 'integer', 'min:1']]);

        return $this->runOfferAction(
            fn () => $this->service->linkConversation(
                $request->user(),
                $token,
                (int) $request->integer('conversation_id'),
            )
        );
    }

    public function recoverStaleCall(Request $request, string $token): JsonResponse
    {
        $request->validate(['conversation_id' => ['required', 'integer', 'min:1']]);

        try {
            $result = $this->service->recoverStaleCallAfterRefresh(
                $request->user(),
                $token,
                (int) $request->integer('conversation_id'),
            );

            return response()->json([
                'ok' => true,
                'recovered' => $result['recovered'],
                'reason' => $result['reason'],
                'offer' => $result['offer']
                    ? $this->service->serializeOffer($result['offer'])
                    : null,
                'voice_call' => $result['voice_call']
                    ? [
                        'id' => $result['voice_call']->id,
                        'call_uuid' => $result['voice_call']->call_uuid,
                        'direction' => $result['voice_call']->direction,
                        'call_state' => $result['voice_call']->call_state,
                        'ringing_at' => $result['voice_call']->ringing_at?->toISOString(),
                        'answered_at' => $result['voice_call']->answered_at?->toISOString(),
                        'ended_at' => $result['voice_call']->ended_at?->toISOString(),
                        'duration_seconds' => $result['voice_call']->duration_seconds,
                        'talk_seconds' => $result['voice_call']->talk_seconds,
                        'hangup_cause' => $result['voice_call']->hangup_cause,
                        'is_terminal' => $result['voice_call']->isTerminal(),
                    ]
                    : null,
                'is_voice_wrap_up' => $result['is_voice_wrap_up'],
            ]);
        } catch (PreviewDialerOfferException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->error,
            ], $e->statusCode);
        }
    }

    private function runOfferAction(\Closure $action): JsonResponse
    {
        try {
            $offer = $action();

            return response()->json([
                'ok' => true,
                'offer' => $this->service->serializeOffer($offer),
            ]);
        } catch (PreviewDialerOfferException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->error,
            ], $e->statusCode);
        }
    }
}
