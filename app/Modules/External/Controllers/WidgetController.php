<?php

declare(strict_types=1);

namespace App\Modules\External\Controllers;

use App\Models\ExternalSource;
use App\Models\Message;
use App\Modules\External\DTOs\ExternalMessageDto;
use App\Modules\External\Services\ExternalTrafficService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WidgetController — serves JS widget client requests.
 *
 * All routes are protected by WidgetGate middleware which:
 *  - authenticates via X-Widget-Key → ExternalSource.public_key
 *  - enforces domain/IP allowlist
 *  - applies rate limits
 *  - sets CORS headers
 *  - stores the resolved ExternalSource in request attributes as 'widget_source'
 *
 * No auth-related logic belongs here.
 *
 * @OA\Tag(name="Widget", description="JS widget gateway — authentication via X-Widget-Key header")
 */
class WidgetController
{
    public function __construct(private ExternalTrafficService $externalTrafficService)
    {
    }

    /**
     * OPTIONS preflight handler — CORS headers are already set by WidgetGate.
     *
     * @OA\Options(
     *     path="/api/widget/{external_id}/{any}",
     *     summary="CORS preflight",
     *     tags={"Widget"},
     *
     *     @OA\Parameter(name="external_id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="any", in="path", required=true, @OA\Schema(type="string")),
     *
     *     @OA\Response(response=204, description="CORS preflight accepted")
     * )
     *
     * @param Request $request
     *
     * @return Response
     */
    public function preflight(Request $request): Response
    {
        return response('', 204);
    }

    /**
     * Send a text message from a widget user.
     *
     * @OA\Post(
     *     path="/api/widget/{external_id}/messages",
     *     summary="Send a text message from a widget user",
     *     tags={"Widget"},
     *     security={{"widgetKey":{}}},
     *
     *     @OA\Parameter(name="external_id", in="path", required=true, @OA\Schema(type="string")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"text"},
     *
     *             @OA\Property(property="text", type="string", maxLength=4000)
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Message accepted", @OA\JsonContent(@OA\Property(property="success", type="boolean"))),
     *     @OA\Response(response=401, description="Unauthorized"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=429, description="Too many requests")
     * )
     *
     * @param Request $request
     * @param string  $external_id
     *
     * @return JsonResponse
     */
    public function sendMessage(Request $request, string $external_id): JsonResponse
    {
        $request->validate([
            'text' => ['required', 'string', 'max:4000'],
        ]);

        /** @var ExternalSource $source */
        $source = $request->attributes->get('widget_source');

        $request->merge([
            'source' => $source->name,
            'external_id' => $external_id,
        ]);

        $dto = ExternalMessageDto::fromRequest($request);

        if ($dto === null) {
            return response()->json(['message' => 'Invalid request data.'], 422);
        }

        $this->externalTrafficService->store($dto);

        return response()->json(['success' => true]);
    }

    /**
     * Upload a file from a widget user.
     *
     * @OA\Post(
     *     path="/api/widget/{external_id}/files",
     *     summary="Upload a file from a widget user",
     *     tags={"Widget"},
     *     security={{"widgetKey":{}}},
     *
     *     @OA\Parameter(name="external_id", in="path", required=true, @OA\Schema(type="string")),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *
     *             @OA\Schema(
     *                 required={"uploaded_file"},
     *
     *                 @OA\Property(property="uploaded_file", type="string", format="binary")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="File accepted", @OA\JsonContent(@OA\Property(property="success", type="boolean"))),
     *     @OA\Response(response=401, description="Unauthorized"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=429, description="Too many requests")
     * )
     *
     * @param Request $request
     * @param string  $external_id
     *
     * @return JsonResponse
     */
    public function sendFile(Request $request, string $external_id): JsonResponse
    {
        $request->validate([
            'uploaded_file' => ['required', 'file', 'max:20480'],
        ]);

        /** @var ExternalSource $source */
        $source = $request->attributes->get('widget_source');

        $request->merge([
            'source' => $source->name,
            'external_id' => $external_id,
        ]);

        $dto = ExternalMessageDto::fromRequest($request);

        if ($dto === null) {
            return response()->json(['message' => 'Invalid request data.'], 422);
        }

        $this->externalTrafficService->sendFile($dto);

        return response()->json(['success' => true]);
    }

    /**
     * Get messages for a widget conversation.
     *
     * @OA\Get(
     *     path="/api/widget/{external_id}/messages",
     *     summary="Get messages for a widget conversation",
     *     tags={"Widget"},
     *     security={{"widgetKey":{}}},
     *
     *     @OA\Parameter(name="external_id", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="after", in="query", required=false, description="Return only messages with id > after", @OA\Schema(type="integer")),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Message list",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="messages", type="array", @OA\Items(
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="text", type="string", nullable=true),
     *                 @OA\Property(property="direction", type="string", enum={"in","out"}),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="attachments", type="array", @OA\Items(type="string"))
     *             ))
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthorized"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=429, description="Too many requests")
     * )
     *
     * @param Request $request
     * @param string  $external_id
     *
     * @return JsonResponse
     */
    public function getMessages(Request $request, string $external_id): JsonResponse
    {
        $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        /** @var ExternalSource $source */
        $source = $request->attributes->get('widget_source');

        $after = $request->integer('after', 0);

        /** @var \Illuminate\Database\Eloquent\Builder<Message> $query */
        $query = Message::query()
            ->whereHas('botUser', function (\Illuminate\Database\Eloquent\Builder $q) use ($source, $external_id): void {
                $q->whereHas('externalUser', function (\Illuminate\Database\Eloquent\Builder $eq) use ($source, $external_id): void {
                    $eq->where('source', $source->name)
                        ->where('external_id', $external_id);
                });
            })
            ->with(['externalMessage', 'attachments']);

        if ($after > 0) {
            $query->where('id', '>', $after);
        }

        $messages = $query->orderBy('id', 'asc')->limit(100)->get();

        $result = $messages->map(function (Message $message): array {
            $attachments = $message->attachments->map(fn ($a) => $a->file_id)->values()->all();

            return [
                'id' => $message->id,
                'text' => $message->externalMessage?->text,
                'direction' => $message->message_type === 'incoming' ? 'in' : 'out',
                'created_at' => $message->created_at?->toIso8601String(),
                'attachments' => $attachments,
            ];
        });

        return response()->json(['messages' => $result]);
    }
}
