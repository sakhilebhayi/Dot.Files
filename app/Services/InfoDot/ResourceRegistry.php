<?php

namespace App\Services\InfoDot;

use App\Models\File;
use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells InfoDot that a file exists, so InfoDot can open it with a stable reference.
 * Only a pointer is sent: the company's InfoDot uuid, the file's uuid and the path it
 * opens at. Never the name, the content or the size. Best effort by design: a failure is
 * logged and the upload carries on; `files:register-resources` catches up later.
 */
class ResourceRegistry
{
    public function enabled(): bool
    {
        return filled(config('ecosystem.infodot.secret')) && filled($this->url());
    }

    public function register(File $file): bool
    {
        return $this->registerPointer((string) $file->uuid, $file->team_id);
    }

    /**
     * Register by identifiers rather than by model. The model hook queues this to run after
     * the response, and a queued closure that holds a model is serialised and re-read from the
     * database first, through the team scope of whoever is signed in by then: a needless query
     * that silently drops the registration if the person's current team has changed meanwhile.
     */
    public function registerPointer(string $fileUuid, ?int $teamId): bool
    {
        $team = $teamId !== null ? Team::withoutGlobalScopes()->find($teamId) : null;

        // Personal teams are not companies: nothing to register against.
        if (! $this->enabled() || ! $team || $team->personal_team || blank($team->infodot_team_uuid)) {
            return false;
        }

        try {
            $response = Http::withToken((string) config('ecosystem.infodot.secret'))
                ->acceptJson()
                ->timeout((int) config('ecosystem.infodot.timeout', 5))
                ->post($this->url(), [
                    'platform' => (string) config('ecosystem.infodot.platform'),
                    'team_uuid' => $team->infodot_team_uuid,
                    'type' => 'file',
                    'object_uuid' => $fileUuid,
                    'path' => '/o/'.$fileUuid,
                ]);
        } catch (Throwable $e) {
            Log::warning('InfoDot resource registration failed.', ['error' => class_basename($e)]);

            return false;
        }

        if (! $response->successful() || ! is_string($response->json('reference'))) {
            Log::warning('InfoDot refused a resource registration.', ['status' => $response->status()]);

            return false;
        }

        File::withoutGlobalScopes()->where('uuid', $fileUuid)->toBase()->update([
            'infodot_reference' => $response->json('reference'),
            'infodot_resource_uuid' => $response->json('uuid'),
        ]);

        return true;
    }

    public function remove(string $fileUuid): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            Http::withToken((string) config('ecosystem.infodot.secret'))
                ->acceptJson()
                ->timeout((int) config('ecosystem.infodot.timeout', 5))
                ->delete($this->url().'/'.$fileUuid, ['platform' => (string) config('ecosystem.infodot.platform')]);
        } catch (Throwable $e) {
            Log::warning('InfoDot resource removal failed.', ['error' => class_basename($e)]);
        }
    }

    private function url(): string
    {
        return (string) (config('ecosystem.infodot.resources_url')
            ?: str_replace('/redeem', '/resources', (string) config('ecosystem.infodot.redeem_url')));
    }
}
