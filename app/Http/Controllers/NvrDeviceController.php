<?php

namespace App\Http\Controllers;

use App\Jobs\CheckNvrReachability;
use App\Models\NvrDevice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NvrDeviceController extends Controller
{
    public function index()
    {
        return view('nvr.dashboard', ['inventoryPage' => false]);
    }

    public function inventory()
    {
        return view('nvr.dashboard', ['inventoryPage' => true]);
    }

    public function dashboardData(Request $request): JsonResponse
    {
        $staleBefore = now()->subSeconds(5);

        NvrDevice::query()
            ->where('enabled', true)
            ->where(function (Builder $builder) use ($staleBefore): void {
                $builder->whereNull('last_checked_at')
                    ->orWhere('last_checked_at', '<=', $staleBefore);
            })
            ->select('id')
            ->chunkById(200, function ($devices): void {
                foreach ($devices as $device) {
                    CheckNvrReachability::dispatch($device->id);
                }
            });

        $query = NvrDevice::query();
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('ip', 'like', "%{$search}%")
                    ->orWhere('site', 'like', "%{$search}%")
                    ->orWhere('vendor', 'like', "%{$search}%");
            });
        }

        if (in_array($status, ['online', 'offline', 'unknown'], true)) {
            $query->where('status', $status);
        } elseif ($status === 'disabled') {
            $query->where('enabled', false);
        }

        if ($request->boolean('all')) {
            $devices = $query->orderBy('name')->get();
            $pagination = [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $devices->count(),
                'total' => $devices->count(),
            ];
        } else {
            $paginator = $query->orderBy('name')->paginate(8)->withQueryString();
            $devices = $paginator->items();
            $pagination = [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ];
        }

        $checkedAt = NvrDevice::query()->max('last_checked_at');

        return response()->json([
            'devices' => $devices,
            'pagination' => $pagination,
            'summary' => $this->summary(),
            'checked_at' => $checkedAt
                ? Carbon::parse($checkedAt, config('app.timezone'))->toIso8601String()
                : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $device = NvrDevice::create($request->validate($this->deviceRules($request)));
        CheckNvrReachability::dispatch($device->id);

        return response()->json(['message' => 'NVR added successfully.'], 201);
    }

    public function update(Request $request, NvrDevice $device): JsonResponse
    {
        $device->update($request->validate($this->deviceRules($request, $device->id)));

        if ($device->enabled) {
            CheckNvrReachability::dispatch($device->id);
        }

        return response()->json(['message' => 'NVR updated successfully.']);
    }

    public function destroy(NvrDevice $device): JsonResponse
    {
        $device->delete();

        return response()->json(['message' => 'NVR removed successfully.']);
    }

    public function check(NvrDevice $device): JsonResponse
    {
        if (! $device->enabled) {
            return response()->json(['message' => 'Enable monitoring before checking this NVR.'], 422);
        }

        CheckNvrReachability::dispatch($device->id);

        return response()->json(['message' => 'Reachability check queued.'], 202);
    }

    public function checkAll(): JsonResponse
    {
        $count = 0;

        NvrDevice::query()
            ->where('enabled', true)
            ->select('id')
            ->chunkById(200, function ($devices) use (&$count): void {
                foreach ($devices as $device) {
                    CheckNvrReachability::dispatch($device->id);
                    $count++;
                }
            });

        return response()->json([
            'message' => "Queued checks for {$count} enabled NVR(s).",
            'count' => $count,
        ], 202);
    }

    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);
        $handle = fopen($validated['file']->getRealPath(), 'r');

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'Could not read the uploaded CSV file.']);
        }

        try {
            $headers = fgetcsv($handle, null, ',', '"', '');

            if ($headers === false) {
                throw ValidationException::withMessages(['file' => 'The CSV file is empty.']);
            }

            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
            $headers = array_map(fn ($header) => strtolower(trim((string) $header)), $headers);
            $positions = array_flip($headers);

            foreach (['name', 'ip', 'port'] as $requiredHeader) {
                if (! array_key_exists($requiredHeader, $positions)) {
                    throw ValidationException::withMessages([
                        'file' => "Missing required CSV column: {$requiredHeader}.",
                    ]);
                }
            }

            $rows = [];
            $errors = [];
            $line = 1;

            while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $line++;

                if ($cells === [null] || count(array_filter($cells, fn ($cell) => trim((string) $cell) !== '')) === 0) {
                    continue;
                }

                if (count($rows) >= 2000) {
                    $errors[] = 'A single import is limited to 2,000 NVRs.';

                    break;
                }

                $row = [];

                foreach (['name', 'ip', 'port', 'site', 'vendor', 'channels'] as $column) {
                    $value = isset($positions[$column]) ? trim((string) ($cells[$positions[$column]] ?? '')) : '';
                    $row[$column] = $value === '' ? null : $value;
                }

                $validator = Validator::make($row, [
                    'name' => ['required', 'string', 'max:120'],
                    'ip' => ['required', 'ip'],
                    'port' => ['required', 'integer', 'between:1,65535'],
                    'site' => ['nullable', 'string', 'max:120'],
                    'vendor' => ['nullable', 'string', 'max:120'],
                    'channels' => ['nullable', 'integer', 'between:1,256'],
                ]);

                if ($validator->fails()) {
                    $errors[] = "Line {$line}: ".implode(' ', $validator->errors()->all());
                } else {
                    $rows[] = $validator->validated();
                }

                if (count($errors) >= 20) {
                    $errors[] = 'Further errors were omitted.';

                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => $errors]);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'No NVR rows were found in the CSV file.']);
        }

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                NvrDevice::updateOrCreate(
                    ['ip' => $row['ip'], 'port' => $row['port']],
                    [...$row, 'enabled' => true],
                );
            }
        });

        return response()->json([
            'message' => count($rows).' NVR(s) imported successfully.',
            'count' => count($rows),
        ], 201);
    }

    public function importTemplate()
    {
        return response(
            "name,ip,port,site,vendor,channels\r\nNVR-01,192.168.1.201,80,Main Office,Hikvision,16\r\n",
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="nvr-import-template.csv"',
            ],
        );
    }

    private function summary(): array
    {
        return [
            'total' => NvrDevice::count(),
            'online' => NvrDevice::where('enabled', true)->where('status', 'online')->count(),
            'offline' => NvrDevice::where('enabled', true)->where('status', 'offline')->count(),
            'unknown' => NvrDevice::where('enabled', true)->where('status', 'unknown')->count(),
            'disabled' => NvrDevice::where('enabled', false)->count(),
        ];
    }

    private function deviceRules(Request $request, ?int $deviceId = null): array
    {
        $uniqueIpPort = Rule::unique('nvr_devices', 'ip')
            ->where(fn ($query) => $query->where('port', $request->input('port')));

        if ($deviceId !== null) {
            $uniqueIpPort->ignore($deviceId);
        }

        return [
            'name' => ['required', 'string', 'max:120'],
            'ip' => ['required', 'ip', $uniqueIpPort],
            'port' => ['required', 'integer', 'between:1,65535'],
            'site' => ['nullable', 'string', 'max:120'],
            'vendor' => ['nullable', 'string', 'max:120'],
            'channels' => ['nullable', 'integer', 'between:1,256'],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
