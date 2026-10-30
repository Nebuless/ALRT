<?php

namespace App\Http\Controllers;

use App\Actions\ActionException;
use App\Actions\AuthorizeReboot;
use App\Actions\CancelReboot;
use App\Actions\QueueReboot;
use App\Actions\RebootExchange;
use App\Models\Machine;
use App\ReadModels\RebootActions;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RebootController extends Controller
{
    public function show(Machine $machine, RebootActions $read): JsonResponse
    {
        return $this->respond(fn () => $read->host($machine));
    }

    public function queue(Request $request, Machine $machine, QueueReboot $queue): JsonResponse
    {
        $input = $request->validate(['proof' => ['required', 'string', 'size:64']]);

        return $this->respond(fn () => ['action' => $queue->queue($request, $machine, $input['proof'])], 201);
    }

    public function cancel(Machine $machine, string $action, CancelReboot $cancel): JsonResponse
    {
        return $this->respond(fn () => ['action' => $cancel->cancel($machine, $action)]);
    }

    public function pickup(Request $request, RebootExchange $exchange): JsonResponse
    {
        $input = $request->validate(['boot_id' => ['required', 'uuid'], 'available' => ['required', 'boolean'], 'reason' => ['nullable', 'in:helper_unavailable,credential_missing,protected_state_unavailable']]);

        return $this->respond(fn () => ['action' => $exchange->pickup($request->attributes->get('collector'), $input['boot_id'], $input['available'], $input['reason'] ?? null)]);
    }

    public function acknowledge(Request $request, string $machine, string $action, RebootExchange $exchange): JsonResponse
    {
        $input = $request->validate(['boot_id' => ['required', 'uuid']]);

        return $this->respond(fn () => ['action' => $exchange->acknowledge($request->attributes->get('collector'), $action, $input['boot_id'])]);
    }

    public function permit(Request $request, string $machine, string $action, AuthorizeReboot $authorize): JsonResponse
    {
        $input = $request->validate(['boot_id' => ['required', 'uuid'], 'nonce' => ['required', 'regex:/^[a-f0-9]{64}$/D']]);

        return $this->respond(fn () => $authorize->permit($request->attributes->get('collector'), $action, $input['boot_id'], $input['nonce']));
    }

    public function result(Request $request, string $machine, string $action, RebootExchange $exchange): JsonResponse
    {
        $input = $request->validate(['boot_id' => ['required', 'uuid'], 'status' => ['required', 'in:attempted,unknown']]);

        return $this->respond(function () use ($exchange, $request, $input, $action): array {
            $exchange->result($request->attributes->get('collector'), $action, $input['boot_id'], $input['status']);

            return ['accepted' => true];
        });
    }

    private function respond(callable $operation, int $status = 200): JsonResponse
    {
        try {
            return response()->json($operation(), $status)->header('Cache-Control', 'no-store, private');
        } catch (ActionException $error) {
            return response()->json(['error' => $error->errorCode], $error->status)->header('Cache-Control', 'no-store, private');
        } catch (QueryException) {
            return response()->json(['error' => 'action_unavailable'], 503)->header('Cache-Control', 'no-store, private');
        }
    }
}
