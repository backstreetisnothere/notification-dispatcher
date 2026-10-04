<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Notification\CreateNotificationService;
use App\Application\Notification\DomainValidationException;
use JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CreateNotificationController
{
    public function __construct(
        private CreateNotificationService $service,
    ) {
    }

    #[Route(
        '/api/v1/notifications',
        name: 'api_v1_notifications_create',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = json_decode(
                $request->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return new JsonResponse(
                ['error' => 'Request body must contain valid JSON.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (!is_array($payload) || array_is_list($payload)) {
            return new JsonResponse(
                ['error' => 'Request body must be a JSON object.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $allowedKeys = ['destinations', 'template', 'variables'];
        $unknownKeys = array_diff(array_keys($payload), $allowedKeys);

        if ($unknownKeys !== []) {
            return new JsonResponse(
                ['error' => 'Request contains unsupported fields.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $destinations = $payload['destinations'] ?? null;
        $template = $payload['template'] ?? null;
        $variables = $payload['variables'] ?? [];

        if (
            !is_array($destinations)
            || !is_string($template)
            || !is_array($variables)
        ) {
            return new JsonResponse(
                ['error' => 'destinations, template and variables have invalid types.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $notificationId = $this->service->create(
                destinations: $destinations,
                template: $template,
                variables: $variables,
            );
        } catch (DomainValidationException $exception) {
            return new JsonResponse(
                ['error' => $exception->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return new JsonResponse(
            [
                'id' => $notificationId,
                'status' => 'queued',
            ],
            Response::HTTP_CREATED,
            [
                'Location' => '/api/v1/notifications/'.$notificationId,
            ],
        );
    }
}
