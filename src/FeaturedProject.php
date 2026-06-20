<?php

namespace Convoro\Ext\Projects;

use App\Models\User;
use Convoro\Ext\Projects\Models\Project;

/**
 * Maintains the denormalised "featured projects" snapshot stored on the user
 * (users.projects_featured) — a JSON array of the user's manually featured
 * project ids. Recomputed whenever a project's featured state changes so the
 * profile showcase can order featured-first with zero extra queries.
 */
class FeaturedProject
{
    /** Toggle a project's featured state for its author and return the new set. */
    public static function toggle(Project $project): array
    {
        $ids = self::ids($project->user_id);
        $pid = (int) $project->id;

        if (in_array($pid, $ids, true)) {
            $ids = array_values(array_filter($ids, fn ($i) => $i !== $pid));
        } else {
            $ids[] = $pid;
        }

        self::store($project->user_id, $ids);

        return $ids;
    }

    /** Drop a project id from its author's featured set (e.g. on delete/unpublish). */
    public static function remove(?int $userId, int $projectId): void
    {
        if (! $userId) {
            return;
        }
        $ids = array_values(array_filter(self::ids($userId), fn ($i) => $i !== (int) $projectId));
        self::store($userId, $ids);
    }

    /** Featured project ids for a user (only those still published). */
    public static function ids(?int $userId): array
    {
        if (! $userId) {
            return [];
        }
        $user = User::query()->find($userId);
        $raw = $user?->projects_featured;
        $ids = is_string($raw) ? (array) json_decode($raw, true) : (array) $raw;

        return array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
    }

    private static function store(?int $userId, array $ids): void
    {
        if (! $userId) {
            return;
        }
        $user = User::query()->find($userId);
        if (! $user) {
            return;
        }
        $snapshot = $ids ? json_encode(array_values($ids)) : null;
        if ($user->projects_featured !== $snapshot) {
            $user->projects_featured = $snapshot;
            $user->save();
        }
    }
}
