<?php

namespace App\Http\Controllers;

use App\History\Retention;
use App\Http\Resources\HostResource;
use App\Incidents\IncidentReadModel;
use App\Models\Machine;
use App\ReadModels\FleetSummary;
use App\ReadModels\ReportingPaths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class FleetController extends Controller
{
    public function fleet(FleetSummary $read): JsonResponse
    {
        return response()->json($read->fleet());
    }

    public function reportingPaths(ReportingPaths $read): JsonResponse
    {
        return response()->json(['data' => $read->all()]);
    }

    public function incidents(Machine $machine, IncidentReadModel $read): JsonResponse
    {
        return response()->json(['data' => $read->today($machine->id)]);
    }

    public function host(Machine $machine, FleetSummary $read): HostResource
    {
        return new HostResource($read->host($machine));
    }

    public function processes(Machine $machine, FleetSummary $read): JsonResponse
    {
        return response()->json(['data' => $read->processes($machine)]);
    }

    public function capabilities(Machine $machine, FleetSummary $read): JsonResponse
    {
        return response()->json(['data' => $read->capabilities($machine)]);
    }

    public function paths(Machine $machine, FleetSummary $read): JsonResponse
    {
        return response()->json(['data' => $read->paths($machine)]);
    }

    public function history(Request $request, Machine $machine, FleetSummary $read): JsonResponse
    {
        if ($request->hasAny(['from', 'to', 'resolution'])) {
            $input = $request->validate([
                'from' => ['sometimes', 'required', 'date'],
                'to' => ['sometimes', 'required', 'date'],
                'resolution' => ['sometimes', 'required', Rule::in(['raw', 'minute', 'hour'])],
            ]);
            $retention = new Retention;
            $to = isset($input['to']) ? Carbon::parse($input['to'])->utc() : $retention->now;
            $from = isset($input['from']) ? Carbon::parse($input['from'])->utc() : $to->copy()->subHours(4);
            try {
                return response()->json($read->timeline($machine, $from, $to, $input['resolution'] ?? null, $retention));
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['from' => 'Choose a retained range and compatible resolution.']);
            }
        }
        $limit = (int) $request->query('limit', 50);
        $before = $request->query('before');

        return response()->json($read->history($machine, $limit, $before === null ? null : (int) $before));
    }

    public function snapshot(Machine $machine, int $sample, FleetSummary $read): JsonResponse
    {
        $snapshot = $read->snapshot($machine, $sample);
        abort_if($snapshot === null, 404);

        return response()->json(['data' => $snapshot]);
    }
}
