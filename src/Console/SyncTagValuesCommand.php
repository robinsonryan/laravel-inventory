<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Console;

use Illuminate\Console\Command;
use RobinsonRyan\Inventory\Support\TagValueInstaller;

/**
 * Reloads the package's declared tag values.
 *
 * A migration installs them, so a fresh database needs no extra step. This
 * command exists for the case the migration cannot cover: values added to a
 * definition after that migration has already run.
 */
final class SyncTagValuesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'inventory:sync-tag-values';

    /**
     * @var string
     */
    protected $description = 'Create any missing tag values declared by the inventory tag definitions';

    public function handle(): int
    {
        $total = 0;

        foreach (TagValueInstaller::install() as $definition => $created) {
            $total += $created;

            $this->line(sprintf(
                '%s: %d value(s) created, %d already present',
                $definition::$slug,
                $created,
                count(TagValueInstaller::declaredValues($definition)) - $created,
            ));
        }

        $this->info(sprintf('Tag values synced. %d created in total.', $total));

        return self::SUCCESS;
    }
}
