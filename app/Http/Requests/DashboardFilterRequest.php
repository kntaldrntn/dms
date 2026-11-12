<?php

namespace App\Http\Requests;

use App\Models\Department;
use App\Models\Document;
use App\Services\DashboardReportService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The dashboard's period (From/To month) and category filters. Unknown values fall back to a default instead of
 * failing, so an old or edited link still opens a report. Older links with a single month + year still work.
 */
class DashboardFilterRequest extends FormRequest
{
    public const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [];
    }

    public function monthName(): string
    {
        $month = $this->string('month')->toString();

        return in_array($month, self::MONTHS, true) ? $month : Carbon::now('Asia/Manila')->format('F');
    }

    public function monthNumber(): int
    {
        return array_search($this->monthName(), self::MONTHS, true) + 1;
    }

    /** Years from the first registered document to this year, newest first. */
    public function years(): array
    {
        $currentYear = Carbon::now('Asia/Manila')->year;
        $firstDocumentAt = Document::min('created_at');

        return range($currentYear, min($firstDocumentAt ? Carbon::parse($firstDocumentAt)->year : $currentYear, $currentYear));
    }

    public function year(): int
    {
        $year = $this->integer('year', Carbon::now('Asia/Manila')->year);

        return in_array($year, $this->years(), true) ? $year : Carbon::now('Asia/Manila')->year;
    }

    /**
     * The reporting period in Manila time: the start of the From month to the end of the To month (`from`/`to` as
     * YYYY-MM). Without them, the single `month` + `year` (default: this month). A reversed range is swapped.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function period(): array
    {
        $single = CarbonImmutable::create($this->year(), $this->monthNumber(), 1, 0, 0, 0, 'Asia/Manila');
        $from = $this->monthInput('from') ?? $single;
        $to = $this->monthInput('to') ?? ($this->filled('from') ? $from : $single);
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->startOfMonth(), $to->endOfMonth()];
    }

    /** A YYYY-MM input inside the selectable years, as the first moment of that month in Manila; null otherwise. */
    private function monthInput(string $key): ?CarbonImmutable
    {
        if (! preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $this->string($key)->toString(), $parts)
            || ! in_array((int) $parts[1], $this->years(), true)) {
            return null;
        }

        return CarbonImmutable::create((int) $parts[1], (int) $parts[2], 1, 0, 0, 0, 'Asia/Manila');
    }

    public function category(string $key, ?string $default = null): ?string
    {
        $category = $this->string($key)->toString();

        return in_array($category, DashboardReportService::TRANSACTION_CATEGORIES, true) ? $category : $default;
    }

    /** An office picked in the dashboard's by-office table; an unknown id means no office filter. */
    public function office(): ?Department
    {
        $id = $this->integer('office');

        return $id > 0 ? Department::find($id, ['id', 'code', 'name']) : null;
    }

    /** "xlsx" downloads the report as an Excel file instead of showing the printable page. */
    public function wantsSpreadsheet(): bool
    {
        return $this->string('format')->toString() === 'xlsx';
    }
}
