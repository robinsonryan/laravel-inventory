<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Matching\Enums\MatchStatus;
use RobinsonRyan\Inventory\Support\TableResolver;
use RobinsonRyan\Vendor\Purchasing\Enums\DocumentKind;
use RobinsonRyan\Vendor\Purchasing\Enums\LineKind;

return new class extends Migration
{
    /**
     * One row per stock line. `item_id` comes from the confirmed match only, and
     * one vendor item has at most one of those, so the left join cannot repeat a
     * line. A rejected match leaves the line in place with no part, so it drops
     * off the part's history and stays on the vendor item's. Credit memos are
     * refunds, not prices.
     *
     * Dropped first rather than `create or replace`, which cannot change a
     * view's columns.
     */
    public function up(): void
    {
        $view = TableResolver::resolve('part_price_history', 'part_price_history');
        $matches = TableResolver::resolve('part_matches', 'part_matches');
        $lines = TableResolver::vendor('purchase_lines', 'purchase_lines');
        $documents = TableResolver::vendor('vendor_documents', 'vendor_documents');

        $stockKinds = implode(', ', array_map(
            static fn (LineKind $kind): string => "'{$kind->value}'",
            array_filter(LineKind::cases(), static fn (LineKind $kind): bool => $kind->isStock()),
        ));
        $creditMemo = DocumentKind::CreditMemo->value;
        $confirmed = MatchStatus::Confirmed->value;

        DB::statement("drop view if exists {$view}");

        DB::statement(<<<SQL
            create view {$view} as
            select
                line.id as purchase_line_id,
                line.vendor_document_id,
                document.vendor_id,
                line.vendor_item_id,
                confirmed_match.item_id,
                document.document_date,
                line.quantity,
                line.unit_of_measure,
                line.unit_price,
                line.extended,
                document.number as document_number
            from {$lines} as line
            inner join {$documents} as document
                on document.id = line.vendor_document_id
            left join {$matches} as confirmed_match
                on confirmed_match.vendor_item_id = line.vendor_item_id
                and confirmed_match.status = '{$confirmed}'
            where line.line_kind in ({$stockKinds})
                and document.kind <> '{$creditMemo}'
            SQL);
    }

    public function down(): void
    {
        $view = TableResolver::resolve('part_price_history', 'part_price_history');

        DB::statement("drop view if exists {$view}");
    }
};
