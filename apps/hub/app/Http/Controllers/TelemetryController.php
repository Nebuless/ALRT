<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Telemetry\IngestReport;
use App\Telemetry\RecordContact;
use App\Telemetry\TelemetryException;
use App\Telemetry\ValidateDelivery;
use App\Telemetry\ValidateReport;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use stdClass;

class TelemetryController extends Controller
{
    public function reports(Request $request, IngestReport $ingest, ValidateReport $validator): JsonResponse
    {
        return $this->respond(function () use ($request, $ingest, $validator): array {
            $body = $this->body($request);
            if (! property_exists($body, 'reports') || ! is_array($body->reports)) {
                throw new TelemetryException('invalid_report');
            }
            if (count($body->reports) > 128) {
                throw new TelemetryException('batch_too_large', 413);
            }
            if (count(get_object_vars($body)) !== 1 || $body->reports === []) {
                throw new TelemetryException('invalid_report');
            }
            $machine = $this->machine($request);
            $reports = [];
            foreach ($body->reports as $report) {
                $reports[] = $validator->validate($machine, $report);
            }

            return ['accepted' => $ingest->batch($machine, $reports)];
        });
    }

    public function challenge(Request $request, RecordContact $contacts): JsonResponse
    {
        return $this->respond(fn () => $contacts->challenge($this->machine($request)), 201);
    }

    public function contact(Request $request, RecordContact $contacts, ValidateReport $validator, ValidateDelivery $deliveryValidator): JsonResponse
    {
        return $this->respond(function () use ($request, $contacts, $validator, $deliveryValidator): array {
            $body = $this->body($request);
            if (array_diff(array_keys(get_object_vars($body)), ['nonce', 'report', 'delivery']) !== [] || ! isset($body->nonce, $body->report) || ! is_string($body->nonce) || strlen($body->nonce) !== 64) {
                throw new TelemetryException('invalid_report');
            }
            $machine = $this->machine($request);

            return $contacts->record($machine, $body->nonce, $validator->validate($machine, $body->report), property_exists($body, 'delivery') ? $deliveryValidator->validate($body->delivery) : null);
        });
    }

    private function body(Request $request): stdClass
    {
        if (strlen($request->getContent()) > 262144) {
            throw new TelemetryException('batch_too_large', 413);
        }
        try {
            $body = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new TelemetryException('invalid_report');
        }
        if (! $body instanceof stdClass) {
            throw new TelemetryException('invalid_report');
        }

        return $body;
    }

    private function machine(Request $request): Machine
    {
        return $request->attributes->get('collector');
    }

    private function respond(callable $operation, int $status = 200): JsonResponse
    {
        try {
            return response()->json($operation(), $status)->header('Cache-Control', 'no-store, private');
        } catch (TelemetryException $error) {
            return response()->json(['error' => $error->errorCode], $error->status);
        } catch (QueryException) {
            return response()->json(['error' => 'ingest_unavailable'], 503);
        }
    }
}
