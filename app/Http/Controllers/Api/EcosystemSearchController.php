<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\File;
use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\SignedRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * InfoDot's header search asks, signed, which of a company's files match what was typed. Only
 * a member of that company gets an answer (checked here, whatever InfoDot says), only files that
 * already have an InfoDot pointer are returned, and only their names and pointers leave: no
 * content, size, path or owner. Anything else answers an empty list, so the caller learns
 * nothing about who or what exists here.
 */
class EcosystemSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! SignedRequest::valid($request)) {
            return response()->json(['error' => 'invalid_request'], 401);
        }

        $input = Validator::make((array) json_decode($request->getContent(), true), [
            'team_uuid' => ['required', 'uuid'],
            'email' => ['required', 'email'],
            'q' => ['required', 'string', 'min:3', 'max:100'],
        ]);

        if ($input->fails()) {
            return response()->json(['error' => 'invalid_request'], 422);
        }

        ['team_uuid' => $uuid, 'email' => $email, 'q' => $query] = $input->validated();

        $team = Team::where('infodot_team_uuid', $uuid)->first();
        $user = User::whereRaw('lower(email) = ?', [strtolower($email)])->first();

        if (! $team || ! $user || ! $user->belongsToTeam($team)) {
            return response()->json(['results' => []]);
        }

        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query)).'%';

        $results = File::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereNotNull('infodot_resource_uuid')
            ->whereRaw("lower(name) like ? escape '!'", [$like])
            ->orderBy('name')
            ->limit(5)
            ->get(['name', 'infodot_resource_uuid'])
            ->map(fn (File $file) => ['resource' => (string) $file->infodot_resource_uuid, 'title' => (string) $file->name, 'kind' => 'file'])
            ->all();

        return response()->json(['results' => $results]);
    }
}
