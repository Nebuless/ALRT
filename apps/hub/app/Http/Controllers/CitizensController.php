<?php

namespace App\Http\Controllers;

use App\Citizens\CitizensState;
use App\Citizens\ValidateCitizens;
use App\Models\Machine;
use App\Telemetry\TelemetryException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use stdClass;

class CitizensController extends Controller
{
    public function index(CitizensState $state): JsonResponse
    {
        return $this->respond(fn () => $state->roster());
    }

    public function challenge(Request $request, CitizensState $state): JsonResponse
    {
        return $this->respond(function () use ($request, $state): array {
            if (get_object_vars($this->body($request)) !== []) {
                throw new TelemetryException('invalid_citizens');
            }

            return $state->challenge($request->attributes->get('collector'));
        }, 201);
    }

    public function exchange(Request $request, CitizensState $state, ValidateCitizens $validator): JsonResponse
    {
        return $this->respond(fn () => $state->exchange($request->attributes->get('collector'), $validator->exchange($this->body($request))));
    }

    public function terminate(Request $request, Machine $machine, string $citizen, CitizensState $state, ValidateCitizens $validator): JsonResponse
    {
        return $this->respond(function () use ($request, $machine, $citizen, $state, $validator): array {
            $input = $validator->termination($this->body($request));

            return ['action' => $state->terminate($machine, $citizen, $input['revision'])];
        }, 201);
    }

    private function body(Request $request): stdClass
    {
        if (strlen($request->getContent()) > 262144) {
            throw new TelemetryException('citizens_too_large', 413);
        }
        try {
            $body = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new TelemetryException('invalid_citizens');
        }
        if (! $body instanceof stdClass) {
            throw new TelemetryException('invalid_citizens');
        }

        return $body;
    }

    private function respond(callable $operation, int $status = 200): JsonResponse
    {
        try {
            return response()->json($operation(), $status)->header('Cache-Control', 'no-store, private');
        } catch (TelemetryException $error) {
            return response()->json(['error' => $error->errorCode], $error->status)->header('Cache-Control', 'no-store, private');
        } catch (QueryException) {
            return response()->json(['error' => 'citizens_unavailable'], 503)->header('Cache-Control', 'no-store, private');
        }
    }
}
