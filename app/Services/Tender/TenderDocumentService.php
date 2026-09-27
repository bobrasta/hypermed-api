<?php

namespace App\Services\Tender;

use App\Models\DeviceRegistration;
use App\Models\Tender;
use App\Models\TenderDocument;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use ZipArchive;

/**
 * Section 19.3/19.4 — fills the company's real tender documents
 * (resources/templates/tender/*.docx, built by prepare_templates.py from
 * the executed originals with signatures/seals/stamps removed) and builds
 * the two documents that had no .docx source (Reason for Importation,
 * Annex V checklist) with PhpWord.
 *
 * Every draft is a .docx for printing, wet signing and sealing; the signed
 * scan is uploaded separately and never overwrites the draft.
 */
class TenderDocumentService
{
    private const DIR = 'templates/tender';

    // ------------------------------------------------------------- tenders

    /** @return array{0: string, 1: string} [docx bytes, filename] */
    public function generate(Tender $tender, string $type, User $user, ?string $date = null): array
    {
        $tender->loadMissing(['procuringEntity', 'boardResolution']);
        $values = $this->values($tender, $type, Carbon::parse($date ?? now()));
        $bytes = $this->fill(resource_path(self::DIR . "/{$type}.docx"), $values);

        $filename = Str::slug(TenderDocument::LABELS[$type]) . '-' . Str::slug($tender->tender_number) . '-draft.docx';
        $path = "tenders/{$tender->id}/drafts/" . Str::random(24) . '-' . $filename;
        Storage::disk('public')->put($path, $bytes);

        $doc = TenderDocument::firstOrNew(['tender_id' => $tender->id, 'type' => $type]);
        if ($doc->draft_path && $doc->draft_path !== $path) {
            Storage::disk('public')->delete($doc->draft_path); // only the latest draft is kept; executed copy untouched
        }
        $doc->fill(['draft_path' => $path, 'draft_generated_at' => now(), 'draft_generated_by' => $user->id])->save();

        activity()->performedOn($tender)->causedBy($user)
            ->log('Generated ' . TenderDocument::LABELS[$type] . ' draft');

        return [$bytes, $filename];
    }

    /** Placeholder values for one document; throws listing anything missing. */
    public function values(Tender $t, string $type, Carbon $date): array
    {
        $c = CompanyProfile::get();
        $e = $t->procuringEntity;
        $missing = [];
        $need = function ($value, string $label) use (&$missing) {
            if (blank($value)) {
                $missing[] = $label;
            }

            return (string) $value;
        };

        $v = [
            'company_name'             => $c['name'],
            'company_name_upper'       => mb_strtoupper($c['legal_name']),
            'company_short_upper'      => mb_strtoupper($c['short_name']),
            'company_physical_address' => $c['physical_address'],
            'company_po_box'           => $c['po_box'],
            'company_city'             => $c['city'],
            'signatory_name'           => $t->signatory_name ?: $c['md_name'],
            'signatory_position'       => $t->signatory_position ?: $c['md_title'],
            'date_short'               => self::ordinal($date) . ' ' . $date->format('F Y'),
            'date_dayof'               => self::ordinal($date) . ' day of ' . $date->format('F Y'),
            'date_dayof_comma'         => self::ordinal($date) . ' day of ' . $date->format('F, Y'),
            'date_of'                  => self::ordinal($date) . ' of ' . $date->format('F Y'),
            'entity_address'           => implode("\n", $e?->addressLines() ?? []),
            'tender_title_upper'       => mb_strtoupper($t->title),
        ];
        if (! $e?->addressLines()) {
            $missing[] = 'procuring entity address';
        }

        switch ($type) {
            case 'power_of_attorney':
                $r = $t->boardResolution;
                $need($r, 'board resolution');
                $v['resolution_no'] = (string) $r?->number;
                $v['resolution_date_dayof'] = $r ? self::ordinal($r->resolution_date) . ' day of ' . $r->resolution_date->format('F Y') : '';
                $v['attorney_name_upper'] = mb_strtoupper($t->attorney_name ?: $c['md_name']);
                $v['attorney_address'] = $t->attorney_address ?: $c['md_address'];
                $v['tender_number'] = $need($t->tender_number, 'tender number');
                break;
            case 'bid_securing_declaration':
                $v['tender_number'] = $need($t->tender_number, 'tender number');
                break;
            case 'performance_securing_declaration':
                $v['contract_number'] = $need($t->contract_number, 'contract number');
                $need($t->letter_of_acceptance_date, 'Letter of Acceptance date');
                break;
            case 'acceptance_letter':
                $v['tender_number'] = $need($t->tender_number, 'tender number');
                $v['our_ref'] = $t->our_ref ?: $this->proposeOurRef($t, $date);
                $v['entity_ref'] = $need($t->entity_ref, "the buyer's award letter reference");
                $v['entity_ref_date'] = $t->entity_ref_date?->format('d-m-Y') ?? $need(null, "the award letter's date");
                $value = $t->contract_value ?? $need(null, 'contract value');
                $v['currency'] = $t->currency;
                $v['value'] = number_format((int) $value, 2);
                $v['value_words'] = self::words((int) $value);
                $v['vat_basis'] = $t->vat_inclusive ? 'Inclusive' : 'Exclusive';
                break;
            default:
                abort(422, 'Unknown document type.');
        }

        if ($missing) {
            throw ValidationException::withMessages(['document' => 'Fill in first: ' . implode(', ', array_unique($missing)) . '.']);
        }

        return $v;
    }

    /**
     * HH/{buyer code}-HQ/{month}/{year}/{sequence}, from the one sample
     * (HH/BMH-HQ/11/2025/013). Inferred, so it's only a proposal — saved on
     * the tender where it stays editable.
     */
    public function proposeOurRef(Tender $t, Carbon $date): string
    {
        $code = strtoupper($t->procuringEntity?->short_code ?: $t->procuringEntity?->hospital?->short_code
            ?: collect(preg_split('/\s+/', (string) $t->procuringEntity?->name))->filter()->map(fn ($w) => $w[0])->implode(''));
        $seq = Tender::whereNotNull('our_ref')->where('our_ref', 'like', '%/' . $date->year)->count() + 1;
        $ref = sprintf('HH/%s-HQ/%s/%d/%03d', $code, $date->format('m'), $date->year, $seq);
        $t->forceFill(['our_ref' => $ref])->save();

        return $ref;
    }

    /** Replace ${key} in the document, headers and footers; "\n" becomes a line break. */
    public function fill(string $template, array $values): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tdoc');
        copy($template, $tmp);
        $zip = new ZipArchive();
        $zip->open($tmp);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (! preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $name)) {
                continue;
            }
            $xml = $zip->getFromIndex($i);
            foreach ($values as $k => $val) {
                $esc = htmlspecialchars((string) $val, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $esc = str_replace("\n", '</w:t><w:br/><w:t xml:space="preserve">', $esc);
                $xml = str_replace('${' . $k . '}', $esc, $xml);
            }
            if (preg_match('/\$\{\w+\}/', $xml, $m)) {
                $zip->close();
                @unlink($tmp);
                throw new \RuntimeException("Template placeholder {$m[0]} has no value.");
            }
            $zip->addFromString($name, $xml);
        }
        $zip->close();
        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    // ------------------------------------------------------ device letters

    /**
     * Reason for Importation letter to TMDA, rebuilt from the company's
     * executed letter (PDF only) — same letterhead, wording and numbering.
     * Refuses to generate without a live registration number.
     */
    public function reasonForImportation(DeviceRegistration $d, array $input): array
    {
        if (! $d->allowsImport()) {
            throw ValidationException::withMessages(['registration' => 'This device has no active TMDA registration number on file, so a Reason for Importation letter can\'t be generated.']);
        }
        $c = CompanyProfile::get();
        $office = $c['tmda_offices'][(int) ($input['office'] ?? 0)] ?? $c['tmda_offices'][0] ?? null;
        if (! $office) {
            throw ValidationException::withMessages(['office' => 'Add a TMDA office address in Settings first.']);
        }
        $date = Carbon::parse($input['date'] ?? now());
        $device = mb_strtoupper($d->brand_name);
        $purpose = trim($input['purpose'] ?? '') ?: 'These goods are aimed to be sold to our private clinics in Tanzania.';

        $w = $this->word();
        $s = $w->addSection(['marginTop' => 700, 'marginLeft' => 1300, 'marginRight' => 1300]);
        $s->addImage(resource_path(self::DIR . '/letterhead.png'), ['width' => 430, 'alignment' => Jc::CENTER]);
        $s->addText(self::ordinal($date) . ' ' . $date->format('F, Y'), [], ['alignment' => Jc::END]);
        foreach (preg_split('/\r?\n/', $office['address']) as $line) {
            $s->addText($line, [], ['spaceAfter' => 120]);
        }
        $s->addTextBreak();
        $s->addText("RE:  REQUEST AND REASON FOR IMPORTATION OF {$device}", ['bold' => true], ['indentation' => ['left' => 400]]);
        $s->addTextBreak();
        $list = ['listType' => \PhpOffice\PhpWord\Style\ListItem::TYPE_NUMBER];
        $s->addListItem('The heading above is mainly concerned', 0, null, $list);
        $s->addListItem("I would sincerely request for importation of {$device}. This machine is registered under the registration Number {$d->registration_number}.", 0, null, $list);
        $s->addText("The certificates are attached with this application. {$purpose}", [], ['indentation' => ['left' => 720]]);
        $s->addListItem('We have full authority and training from the manufacturer and all quality proved documents are attached in the registration file', 0, null, $list);
        $s->addListItem('The certificate of registration is attached with this application.', 0, null, $list);
        $s->addTextBreak();
        $s->addText('It’s our hope that this request will be highly considered');
        $s->addTextBreak(2);
        $s->addText('Sincerely', [], ['indentation' => ['left' => 300]]);
        $s->addTextBreak(2);
        $s->addText('………………….', [], ['indentation' => ['left' => 300]]);
        $s->addText($input['signatory_name'] ?? self::shortName($c['md_name']), [], ['indentation' => ['left' => 300]]);
        $s->addTextBreak();
        $s->addText("{$c['md_title']} –{$c['name']} ({$c['md_phone']})");

        activity()->performedOn($d)->log('Generated Reason for Importation letter');

        return [$this->save($w), 'reason-for-importation-' . Str::slug($d->brand_name) . '.docx'];
    }

    /** TMDA Annex V essential requirements checklist, from the stored rows. */
    public function checklist(DeviceRegistration $d): array
    {
        $d->loadMissing('requirements');
        $w = $this->word();
        $s = $w->addSection(['orientation' => 'landscape', 'marginLeft' => 900, 'marginRight' => 900, 'marginTop' => 900]);
        $s->addText('ESSENTIAL REQUIREMENTS CHECKLIST (ANNEX V)', ['bold' => true, 'size' => 13], ['alignment' => Jc::CENTER]);
        $s->addTextBreak();
        foreach ([
            'Brand name' => $d->brand_name, 'Common name' => $d->common_name, 'Model' => $d->model,
            'Manufacturer' => $d->manufacturer, 'Risk class' => $d->risk_class,
            'Registration number' => $d->registration_number ?: 'Pending',
        ] as $k => $val) {
            $run = $s->addTextRun();
            $run->addText("{$k}: ", ['bold' => true]);
            $run->addText((string) $val);
        }
        $s->addTextBreak();

        $cell = ['borderSize' => 6, 'borderColor' => '000000', 'valign' => 'center'];
        $head = $cell + ['bgColor' => 'D9D9D9'];
        $table = $s->addTable(['borderSize' => 6, 'borderColor' => '000000', 'cellMargin' => 60]);
        $table->addRow(400, ['tblHeader' => true]);
        foreach ([[700, 'No.'], [5200, 'Essential principle'], [1400, 'Applicable (Yes/No)'], [3000, 'Method of conformity'], [3600, 'Identity of specific document']] as [$wd, $label]) {
            $table->addCell($wd, $head)->addText($label, ['bold' => true]);
        }
        foreach ($d->requirements as $r) {
            $table->addRow();
            $table->addCell(700, $cell)->addText((string) $r->principle_no);
            $table->addCell(5200, $cell)->addText($r->principle);
            $table->addCell(1400, $cell)->addText($r->applicable === null ? '' : ($r->applicable ? 'Yes' : 'No'));
            $table->addCell(3000, $cell)->addText((string) $r->method);
            $table->addCell(3600, $cell)->addText((string) $r->supporting_document);
        }

        activity()->performedOn($d)->log('Generated Annex V checklist');

        return [$this->save($w), 'annex-v-checklist-' . Str::slug($d->brand_name) . '.docx'];
    }

    private function word(): PhpWord
    {
        $w = new PhpWord();
        $w->setDefaultFontName('Times New Roman');
        $w->setDefaultFontSize(12);

        return $w;
    }

    private function save(PhpWord $w): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pw');
        \PhpOffice\PhpWord\IOFactory::createWriter($w, 'Word2007')->save($tmp);
        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    // ---------------------------------------------------------- formatting

    /** "Moses Deogratias Kiduduye" → "Moses Kiduduye" (as signed on letters). */
    private static function shortName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));

        return count($parts) > 2 ? $parts[0] . ' ' . end($parts) : $name;
    }

    public static function ordinal(Carbon $d): string
    {
        return $d->format('jS');
    }

    /** 7752000 → "Seven Million Seven Hundred Fifty-Two Thousand" */
    public static function words(int $n): string
    {
        if ($n === 0) {
            return 'Zero';
        }
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
            'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $chunk = function (int $x) use ($ones, $tens): string {
            $out = [];
            if ($x >= 100) {
                $out[] = $ones[intdiv($x, 100)] . ' Hundred';
                $x %= 100;
            }
            if ($x >= 20) {
                $out[] = $tens[intdiv($x, 10)] . ($x % 10 ? '-' . $ones[$x % 10] : '');
            } elseif ($x > 0) {
                $out[] = $ones[$x];
            }

            return implode(' ', $out);
        };
        $parts = [];
        foreach ([1_000_000_000_000 => 'Trillion', 1_000_000_000 => 'Billion', 1_000_000 => 'Million', 1000 => 'Thousand', 1 => ''] as $size => $name) {
            if ($n >= $size) {
                $parts[] = trim($chunk(intdiv($n, $size)) . ' ' . $name);
                $n %= $size;
            }
        }

        return ($n < 0 ? 'Minus ' : '') . implode(' ', $parts);
    }
}
