<?php

namespace Goldnead\PrivateMedia\Console\Commands;

use Goldnead\PrivateMedia\Models\AccessLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Deletes audit rows past their retention. Schedule it daily:
 *
 *     Schedule::command('private-media:prune')->daily();
 */
class Prune extends Command
{
    protected $signature = 'private-media:prune
        {--days= : Keep this many days instead of private-media.audit.retention_days}';

    protected $description = 'Delete private media audit rows older than the retention period';

    public function handle(): int
    {
        $option = $this->option('days');

        if ($option !== null && (! is_scalar($option) || preg_match('/^\d+$/', (string) $option) !== 1)) {
            $this->components->error('--days must be a whole number, got "'.(is_scalar($option) ? (string) $option : gettype($option)).'".');

            return self::FAILURE;
        }

        $days = $option !== null ? (int) $option : (int) config('private-media.audit.retention_days', 90);

        if ($days < 1) {
            $this->components->error('Retention must be at least one day.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days);
        $deleted = 0;

        // In chunks, so a table that was never pruned does not lock for minutes.
        do {
            $ids = AccessLog::query()->where('created_at', '<', $cutoff)->limit(1000)->pluck('id');
            $removed = $ids->isEmpty() ? 0 : AccessLog::query()->whereKey($ids->all())->delete();
            $deleted += $removed;
        } while ($removed > 0);

        if ($ids->isNotEmpty()) {
            $this->components->error("{$ids->count()} audit rows older than {$days} days could not be deleted ({$deleted} deleted). Check the database user's DELETE permission and any triggers on the table.");

            return self::FAILURE;
        }

        $this->components->info("Deleted {$deleted} audit rows older than {$days} days.");

        return self::SUCCESS;
    }
}
