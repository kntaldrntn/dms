<?php

namespace App\Http\Requests;

use App\Models\Document;
use App\Services\DashboardReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The dashboard's month/year/category filters. Unknown values fall back to a default instead of failing,
 * so an old or edited link still opens a report.
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

    public function category(string $key, ?string $default = null): ?string
    {
        $category = $this->string($key)->toString();

        return in_array($category, DashboardReportService::TRANSACTION_CATEGORIES, true) ? $category : $default;
    }
}
