<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Forecasting\Commands;

use Illuminate\Console\Command;
use RobinsonRyan\Inventory\Forecasting\Actions\GenerateRecommendations;

final class GenerateReorderRecommendationsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'inventory:generate-recommendations {--tenant= : The tenant ID to generate recommendations for}';

    /**
     * @var string
     */
    protected $description = 'Generate reorder recommendations for items below reorder point';

    public function __construct(
        private readonly GenerateRecommendations $generateRecommendations,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = $this->option('tenant');

        // TODO(QUEUE): --tenant is accepted and echoed but never applied; the action
        // takes item IDs, not a tenant. See QUEUE.md "Honour --tenant".
        if (is_string($tenantId) && $tenantId !== '') {
            $this->info("Generating recommendations for tenant: {$tenantId}");
        } else {
            $this->info('Generating recommendations for all items...');
        }

        $recommendations = $this->generateRecommendations->execute();

        $count = $recommendations->count();

        if ($count === 0) {
            $this->info('No new recommendations generated. All items are above reorder point or already have pending recommendations.');

            return self::SUCCESS;
        }

        $this->info("Generated {$count} reorder recommendation(s):");

        foreach ($recommendations as $recommendation) {
            $this->line(sprintf(
                '  - Item %s: recommend ordering %d units (available: %s, reorder point: %s)',
                $recommendation->item_id,
                $recommendation->recommended_quantity,
                number_format((float) $recommendation->available_quantity, 2),
                $recommendation->min_stock_level ?? 'calculated',
            ));
        }

        return self::SUCCESS;
    }
}
