<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\File;
use App\Models\Obj;
use App\Models\Team;
use App\Services\Ecosystem\PlatformRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The platform file API (R-25 Phase 3): other Dot platforms store and fetch business files
 * here, server to server. Contract: docs/programme/PLATFORM_FILE_API.md.
 *
 * Authorisation is structural, not polite: every query filters explicitly on the company
 * resolved from `team_uuid` AND on `origin_platform` = the verified caller, because no session
 * means the team scope is not in play here. A platform reaches only what it itself filed, for
 * exactly one company per call; everything else — other tenants, other platforms' files,
 * people's own uploads, trashed rows — answers the same `not_found`.
 */
class PlatformFileController extends Controller
{
    /** Same formats the workspace upload accepts. */
    private const EXTENSIONS = ['pdf', 'csv', 'txt', 'text', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'zip', 'tar', 'gz', 'mp4', 'mp3', 'mov', 'avi'];

    private const MAX_BYTES = 25 * 1024 * 1024;

    public function store(Request $request): JsonResponse
    {
        [$platform, $team, $input, $error] = $this->open($request, [
            'name' => ['required', 'string', 'max:255'],
            'content_b64' => ['required', 'string', 'max:'.(int) ceil(self::MAX_BYTES * 1.4)],
            'mime' => ['nullable', 'string', 'max:191'],
            'folder' => ['nullable', 'string', 'max:255'],
        ]);

        if ($error) {
            return $error;
        }

        $name = $this->cleanName($input['name']);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($name === '' || ! in_array($extension, self::EXTENSIONS, true)) {
            return response()->json(['error' => 'unsupported_format'], 422);
        }

        $binary = base64_decode($input['content_b64'], true);

        if ($binary === false || $binary === '') {
            return response()->json(['error' => 'invalid_content'], 422);
        }

        if (strlen($binary) > self::MAX_BYTES) {
            return response()->json(['error' => 'too_large'], 413);
        }

        // The stored mime is what the bytes actually are, not what the caller claims.
        $sniffed = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary);
        $mime = $sniffed !== '' ? $sniffed : ($input['mime'] ?? null);

        Storage::disk('local')->put($path = 'files/'.Str::random(40), $binary);

        $file = $team->files()->create([
            'name' => $name,
            'size' => strlen($binary),
            'mime' => $mime,
            'path' => $path,
        ]);
        $file->forceFill(['origin_platform' => $platform])->saveQuietly();

        $obj = $team->objects()->make(['parent_id' => $this->platformFolder($team, $platform, $input['folder'] ?? null)->id]);
        $obj->objectable()->associate($file);
        $obj->save();

        $this->record($platform, $team, (string) $file->uuid, 'stored');

        return response()->json($this->shape($file->refresh()), 201);
    }

    public function fetch(Request $request): JsonResponse
    {
        [$platform, $team, $input, $error] = $this->open($request, ['uuid' => ['required', 'uuid']]);

        if ($error) {
            return $error;
        }

        $file = $this->ownFile($platform, $team, $input['uuid']);

        if (! $file) {
            return response()->json(['error' => 'not_found'], 404);
        }

        if ($file->size > self::MAX_BYTES) {
            return response()->json(['error' => 'too_large'], 413);
        }

        if (! Storage::disk('local')->exists($file->path)) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $this->record($platform, $team, (string) $file->uuid, 'fetched');

        return response()->json($this->shape($file) + [
            'content_b64' => base64_encode(Storage::disk('local')->get($file->path)),
        ]);
    }

    public function meta(Request $request): JsonResponse
    {
        [$platform, $team, $input, $error] = $this->open($request, ['uuid' => ['required', 'uuid']]);

        if ($error) {
            return $error;
        }

        $file = $this->ownFile($platform, $team, $input['uuid']);

        return $file
            ? response()->json($this->shape($file))
            : response()->json(['error' => 'not_found'], 404);
    }

    public function list(Request $request): JsonResponse
    {
        [$platform, $team, $input, $error] = $this->open($request, ['after_id' => ['nullable', 'integer', 'min:0']]);

        if ($error) {
            return $error;
        }

        $files = File::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('origin_platform', $platform)
            ->where('id', '>', (int) ($input['after_id'] ?? 0))
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->filter(fn (File $f) => ! $f->isTrashed())
            ->values();

        return response()->json([
            'files' => $files->map(fn (File $f) => $this->shape($f))->all(),
            'next_after_id' => $files->last()?->id,
        ]);
    }

    public function trash(Request $request): JsonResponse
    {
        [$platform, $team, $input, $error] = $this->open($request, ['uuid' => ['required', 'uuid']]);

        if ($error) {
            return $error;
        }

        $file = $this->ownFile($platform, $team, $input['uuid']);
        $obj = $file?->obj()->withoutGlobalScope('team')->first();

        if (! $file || ! $obj) {
            return response()->json(['error' => 'not_found'], 404);
        }

        // Into the company's trash — the 30-day hold and restore belong to its people.
        // Platforms never delete permanently.
        $obj->trash(null);
        $this->record($platform, $team, (string) $file->uuid, 'trashed');

        return response()->json(['uuid' => (string) $file->uuid, 'status' => 'trashed']);
    }

    /**
     * Verify the signature, resolve the company, validate the body.
     *
     * @return array{0: string, 1: ?Team, 2: array, 3: ?JsonResponse}
     */
    private function open(Request $request, array $rules): array
    {
        $platform = PlatformRequest::platform($request);

        if ($platform === null) {
            return ['', null, [], response()->json(['error' => 'invalid_request'], 401)];
        }

        $input = Validator::make((array) json_decode($request->getContent(), true),
            $rules + ['team_uuid' => ['required', 'uuid']]);

        if ($input->fails()) {
            return [$platform, null, [], response()->json(['error' => 'invalid_request'], 422)];
        }

        $data = $input->validated();
        $team = Team::where('infodot_team_uuid', $data['team_uuid'])->first();

        if (! $team) {
            // Indistinguishable from a missing file: the caller learns nothing about tenants.
            return [$platform, null, [], response()->json(['error' => 'not_found'], 404)];
        }

        return [$platform, $team, $data, null];
    }

    /** The caller's own, untrashed file in this company — or null, with no reason given. */
    private function ownFile(string $platform, Team $team, string $uuid): ?File
    {
        $file = File::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('origin_platform', $platform)
            ->where('uuid', $uuid)
            ->first();

        return $file === null || $file->isTrashed() ? null : $file;
    }

    /** The folder this platform files into, created at the company's root on first use. */
    private function platformFolder(Team $team, string $platform, ?string $folderName): Obj
    {
        $name = $this->cleanName($folderName ?? '') ?: (string) (config("ecosystem.platforms.{$platform}.name") ?: ucfirst($platform));
        $root = $team->objects()->withoutGlobalScope('team')->whereNull('parent_id')->firstOrFail();

        $existing = Obj::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('parent_id', $root->id)
            ->where('objectable_type', 'folder')
            ->whereNull('trashed_at')
            ->with('objectable')
            ->get()
            ->first(fn (Obj $o) => $o->objectable?->name === $name);

        if ($existing) {
            return $existing;
        }

        $obj = $team->objects()->make(['parent_id' => $root->id]);
        $obj->objectable()->associate($team->folders()->create(['name' => $name]));
        $obj->save();

        return $obj;
    }

    private function shape(File $file): array
    {
        return [
            'uuid' => (string) $file->uuid,
            'name' => $file->name,
            'size' => (int) $file->size,
            'mime' => $file->mime,
            'reference' => $file->infodot_reference,
            'open_url' => url('/o/'.$file->uuid),
            'filed_at' => $file->created_at?->toIso8601String(),
        ];
    }

    private function record(string $platform, Team $team, string $fileUuid, string $event): void
    {
        DB::table('platform_file_events')->insert([
            'platform' => $platform,
            'team_id' => $team->id,
            'file_uuid' => $fileUuid,
            'event' => $event,
            'created_at' => now(),
        ]);
    }

    private function cleanName(string $name): string
    {
        $name = basename(trim(str_replace(["\0", '\\'], '', $name)));

        return mb_substr($name, 0, 255);
    }
}
