<?php

namespace App\Services\Shop;

use App\Console\Commands\ApplyOldWriteoffs;
use App\Exceptions\BusinessRuleException;
use App\Models\StockRecord;
use Illuminate\Support\Facades\DB;

/**
 * Which document a stock movement belongs to, and whether it may be undone on its own (plan E60).
 *
 * A movement made by a sale, a delivery, a count, a transfer or a return to a supplier is corrected
 * on that document (so the money and the paperwork are corrected too); only a stand-alone movement
 * (damage, loss, a manual stock in…) is reversed by itself. One rule for the classic admin, the
 * mobile API and the new web interface. It returns keys and ids, never URLs: each screen maps the
 * key to its own page.
 */
class MovementDocuments
{
    /** Document keys and the words used for them. */
    public const LABELS = [
        'sale' => 'sale',
        'sale_return' => 'sale',
        'goods_receipt' => 'delivery',
        'purchase_return' => 'return to supplier',
        'stock_transfer' => 'transfers page',
        'stock_take' => 'stock count',
        'old_writeoff' => 'original write-off',
    ];

    /**
     * @return array{key: string, id: int, label: string, advice: string}|null null for a stand-alone movement
     *                                                                         (`id` is the sale for sale/sale_return)
     */
    public static function document(StockRecord $record): ?array
    {
        $type = (string) $record->reference_type;
        $refId = (int) $record->reference_id;
        if ($record->sale_record_id || $type === 'sale' || $type === 'sale_return') {
            $saleId = (int) ($record->sale_record_id ?: ($type === 'sale' ? $refId : DB::table('sale_returns')->where('id', $refId)->value('sale_record_id')));

            return $type === 'sale_return'
                ? self::make('sale_return', $saleId, 'This stock came back with a return on a sale, which also refunded money. It cannot be undone; record a new movement if the goods left again.')
                : self::make('sale', $saleId, 'This movement is part of a sale. To undo it, void the sale or record a return on the sale page, so the money is corrected too.');
        }

        return match ($type) {
            'goods_receipt' => self::make('goods_receipt', $refId, 'This stock came in with a delivery. To send goods back, record a return to the supplier so what you owe is corrected too.'),
            'purchase_return' => self::make('purchase_return', $refId, 'This stock went back to a supplier. It cannot be undone here; receive the goods again if they came back.'),
            'stock_transfer' => self::make('stock_transfer', $refId, 'This stock moved between your locations. Make a transfer back instead.'),
            'stock_take' => self::make('stock_take', $refId, 'This figure was set by a stock count. Count the product again to correct it.'),
            ApplyOldWriteoffs::REFERENCE => self::make('old_writeoff', $refId, 'This is an automatic correction that applied an old write-off to the stock figure. Record a new movement if the figure is wrong.'),
            default => null,
        };
    }

    /** Whether Undo applies: a stand-alone movement that is not itself an undo and not undone yet. */
    public static function reversible(StockRecord $record): bool
    {
        if ($record->is_reversal || self::document($record) !== null) {
            return false;
        }

        return $record->relationLoaded('reversal')
            ? $record->reversal === null
            : ! StockRecord::withoutGlobalScopes()->where('reverses_id', $record->id)->exists();
    }

    /**
     * Undo a stand-alone movement. A movement that belongs to a document is refused with the advice
     * for that document (code `movement_belongs_to_document`, context carries the key and id).
     */
    public function reverse(StockRecord $record, ?string $reason = null, ?int $userId = null): StockRecord
    {
        if ($doc = self::document($record)) {
            throw BusinessRuleException::make('movement_belongs_to_document', $doc['advice'], ['document' => $doc['label'], 'document_key' => $doc['key'], 'document_id' => $doc['id']]);
        }

        return (new StockService())->reverse($record, $reason, $userId);
    }

    /** @return array{key: string, id: int, label: string, advice: string} */
    private static function make(string $key, int $id, string $advice): array
    {
        return ['key' => $key, 'id' => $id, 'label' => self::LABELS[$key], 'advice' => $advice];
    }
}
