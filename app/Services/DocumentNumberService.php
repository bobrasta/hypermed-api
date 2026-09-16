<?php

namespace App\Services;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the per-controller nextXNumber() helpers (PO, PR, QT, SO, INV,
 * BILL, BPAY, PAY) that each hand-rolled their own "count rows this year" or
 * "parse the last number" logic. Two real problems with those: the
 * count-based ones (PO/PR/QT/SO) double-issue a number whenever a row is
 * deleted, and every one of them races under concurrent requests — two
 * simultaneous creates can both read the same "last number" before either
 * commits, producing a duplicate document number. lockForUpdate() below
 * serializes concurrent callers on the same sequence row, so this can't
 * happen; a plain transaction alone would not have fixed the race.
 */
class DocumentNumberService
{
    public function next(string $documentType): string
    {
        return DB::transaction(function () use ($documentType) {
            $seq = DocumentSequence::where('document_type', $documentType)->lockForUpdate()->first();
            abort_if(! $seq, 500, "No document numbering sequence configured for '{$documentType}'.");

            $currentYear = (int) now()->format('Y');
            if ($seq->reset_yearly && $seq->year !== $currentYear) {
                $seq->next_number = 1;
                $seq->year = $currentYear;
            }

            $number = $seq->next_number;
            $seq->next_number = $number + 1;
            $seq->save();

            $formatted = str_pad((string) $number, $seq->digits, '0', STR_PAD_LEFT);

            return $seq->reset_yearly
                ? "{$seq->prefix}-{$currentYear}-{$formatted}"
                : "{$seq->prefix}-{$formatted}";
        });
    }
}
