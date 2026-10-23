<?php

namespace App\Services;

use App\Models\DeliveryMethod;
use App\Models\Department;
use App\Models\DocumentClassification;
use App\Models\DocumentType;
use App\Models\Person;
use App\Models\TransactionType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;
use UnexpectedValueException;

/**
 * Turns the OCR text of a scanned document into intake form values.
 *
 * Text only (no page image): on gpt-4o-mini even a low-detail image costs about as many tokens as
 * a full page of OCR text, and in tests it did not improve any field.
 */
class DocumentIntakeAnalyzer
{
    private const PRIORITIES = ['Primary', 'Secondary', 'FYI'];

    /** @return array<string, mixed> form values with the same keys the intake form reads */
    public function analyze(string $ocrText): array
    {
        $offices = Department::where('state', 1)->orderBy('code')->get(['id', 'code', 'name']);
        $types = DocumentType::where('state', 1)->with('classifications:id', 'defaultTransactionType:id,name')
            ->get(['id', 'document_type', 'default_transaction_type_id']);
        $classifications = DocumentClassification::where('state', 1)->pluck('name', 'id');
        $categories = TransactionType::where('state', 1)->pluck('name', 'id');
        $deliveryMethods = DeliveryMethod::where('state', 1)->pluck('delivery_method_name', 'id');
        $administratorId = $offices->firstWhere('code', 'ADM')?->id;
        $mayorId = $offices->firstWhere('code', 'OCM')?->id;
        $text = $this->clean($ocrText);

        $response = OpenAI::chat()->create([
            ...$this->modelParameters(),
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'intake', 'strict' => true, 'schema' => $this->schema($offices, $types, $classifications, $categories, $deliveryMethods)],
            ],
            'messages' => [
                ['role' => 'system', 'content' => $this->instructions($administratorId, $mayorId)],
                ['role' => 'user', 'content' => $this->options($offices, $types, $classifications, $categories, $deliveryMethods)
                    ."\n\nOCR TEXT:\n".$text],
            ],
        ]);

        $result = json_decode((string) $response->choices[0]->message->content, true);
        if (! is_array($result) || $result === []) {
            throw new UnexpectedValueException('Invalid analysis response.');
        }

        $result['source_name'] = $this->sourceName($result['source_name'] ?? null, $text);
        // The directory only refines the sender; if it cannot be read, the rest of the autofill still applies.
        try {
            $person = $this->directoryMatch($result['source_name']);
        } catch (QueryException $exception) {
            Log::warning('People directory lookup failed during intake analysis.', ['message' => $exception->getMessage()]);
            $person = null;
        }
        if ($person) {
            $result['source_name'] = $person->full_name;
            if ($offices->contains('id', $person->department_id)) {
                $result['source_type'] = 'Internal';
                $result['source_location'] = $person->department_id;
            }
        }
        $result = $this->validated($result, $offices, $types, $administratorId);
        if (isset($response->usage)) {
            $result['meta'] = [
                'total_tokens' => $response->usage->totalTokens,
                'prompt_tokens' => $response->usage->promptTokens,
                'completion_tokens' => $response->usage->completionTokens,
            ];
        }

        return $result;
    }

    /** Reasoning models (gpt-5 and later, o-series) reject `temperature` and take a reasoning effort instead. */
    private function modelParameters(): array
    {
        $model = (string) config('services.intake_ai.model');
        if (! preg_match('/^(gpt-[5-9]|o\d)/', $model)) {
            return ['model' => $model, 'temperature' => 0];
        }

        return array_filter(['model' => $model, 'reasoning_effort' => config('services.intake_ai.reasoning_effort')]);
    }

    private function instructions(?int $administratorId, ?int $mayorId): string
    {
        return <<<PROMPT
        You fill the intake form of the Records Section, City Government of San Fernando, La Union, from OCR text of a scanned document. The text may have OCR mistakes and "--- Page N ---" markers.

        An attached Referral/Action/Routing Slip page is reduced to its From/Subject lines. It is NOT the document and its signer (the City Administrator) is never the source; its "From:" line names the document's sender, so use it to confirm or repair the sender's name.

        Fields:
        - barcode: the stamped tracking number, one letter then digits (e.g. C003192314). Fix obvious OCR mix-ups (O->0, I->1). Null if not found.
        - source_name: the AUTHOR of the main document: the name printed right under the closing ("Respectfully yours", "Very truly yours", "Sincerely") or the main signature block, usually on the last page above the position title, or a "From:" line. Keep printed titles (Atty., Dr., Engr., Hon.). Never: the addressee (To/Thru/Attn/Dear); a person only mentioned in the body (e.g. "upon the instruction of the City Mayor"); an endorser after "Noted:" or "Approved:". OCR often garbles signature names (e.g. "JUNALYNF. P. AYAN" next to a slip line "From: JUNALYN PANGAYAN" is "Junalyn F. Pangayan"): repair it from the same person's name elsewhere in the text, drop stray punctuation, keep degree suffixes out; never invent letters that appear nowhere. Null if there is no sender evidence.
        - source_type: "Internal" if the author signs for an office of this city government, else "External" (schools, agencies, companies, citizens).
        - source_location: the id of the author's city office when Internal, else null.
        - subject_matter: when the text has "MEMORANDUM NO." write "memorandum no. <number> <recipient> re: <summary>", otherwise "<recipient> re: <summary>". Summary: specific, at most 25 words, naming the project, event or request (what, for whom, when). When the request concerns someone other than the addressee (an "Attn:" person, "you and/or Mr. X", a nominee or employee), name that person: e.g. "Hon. Juan Cruz re: Invitation for Mr. Pedro Reyes to serve as panelist for the X capstone defense on October 15, 2026".
        - document_type_id: from the list. Letters, memoranda, invitations and requests are usually Communications.
        - classification_id: must be one of the classifications listed under the chosen document type. Overtime requests or approvals = Request for OT; invitations to attend or serve = Invitation; payments, vouchers, budgets = Financial; other requests = Request.
        - transaction_type_id: Simple, Complex or Highly Technical per RA 11032; use the document type's default when it has one.
        - department_id: decided by the addressee (To:, the address block or the salutation) and the approval requested, never by a mention in the body. {$mayorId} if addressed to the City Mayor or asking the Mayor to approve; {$administratorId} if addressed to the City Administrator or asking the City Administrator to approve; null if the City Administrator's or Mayor's office is the author, or it goes straight to a department.
        - routing_suggestions: 1 to 3 offices that must act, chosen by the office NAME. Exactly one "Primary": the office whose mandate fits the work (match the subject to the office names; an "Attn:" line counts only when it names the office itself, never guess a person's office); others "Secondary" or "FYI". Never ADM, never the author's office, and never OCM when department_id is {$mayorId}.
        - gender, contact_no, email: the sender's only. Gender from title or first name; null if unsure.
        - delivery_method_id: how it arrived; Hand Carry if not stated.
        - confidence_score: 1 to 100.
        PROMPT;
    }

    private function options(Collection $offices, Collection $types, Collection $classifications, Collection $categories, Collection $deliveryMethods): string
    {
        $officeLines = $offices->map(fn ($office) => "{$office->id} {$office->code} {$office->name}")->implode("\n");
        $typeLines = $types->map(function ($type) use ($classifications) {
            $allowed = $type->classifications->pluck('id')->map(fn ($id) => "{$id} {$classifications[$id]}")->implode(', ');
            $category = $type->defaultTransactionType?->name ?? 'varies';

            return "{$type->id} {$type->document_type} | category: {$category} | classifications: ".($allowed ?: 'any');
        })->implode("\n");
        $list = fn (Collection $items) => $items->map(fn ($name, $id) => "{$id} {$name}")->implode(', ');

        return "OFFICES (id code name):\n{$officeLines}\n\nDOCUMENT TYPES:\n{$typeLines}\n\nCLASSIFICATIONS: ".$list($classifications)
            ."\nCATEGORIES: ".$list($categories)."\nDELIVERY METHODS: ".$list($deliveryMethods);
    }

    /** Strict JSON schema: the model can only answer with ids that exist. */
    private function schema(Collection $offices, Collection $types, Collection $classifications, Collection $categories, Collection $deliveryMethods): array
    {
        $idOrNull = fn (Collection $ids) => ['type' => ['integer', 'null'], 'enum' => [...$ids->map(fn ($id) => (int) $id)->values()->all(), null]];
        $text = ['type' => ['string', 'null']];
        $officeIds = $offices->pluck('id');

        $properties = [
            'barcode' => $text,
            'source_type' => ['type' => 'string', 'enum' => ['Internal', 'External']],
            'source_location' => $idOrNull($officeIds),
            'source_name' => $text,
            'gender' => ['type' => ['string', 'null'], 'enum' => ['Male', 'Female', null]],
            'contact_no' => $text,
            'email' => $text,
            'delivery_method_id' => $idOrNull($deliveryMethods->keys()),
            'subject_matter' => ['type' => 'string'],
            'document_type_id' => $idOrNull($types->pluck('id')),
            'classification_id' => $idOrNull($classifications->keys()),
            'transaction_type_id' => $idOrNull($categories->keys()),
            // Only the two executive offices can be the intake target; everything else goes through routing.
            'department_id' => $idOrNull($offices->whereIn('code', ['ADM', 'OCM'])->pluck('id')),
            'approval_reason' => $text,
            // "CODE Name", not ids: gpt-4o-mini picked the lowest bare ids, and read bare codes by their letters
            // (CTO, the Treasurer, as a technology office).
            'routing_suggestions' => ['type' => 'array', 'items' => [
                'type' => 'object',
                // The reason comes first so the model states the work before it picks the office.
                'properties' => [
                    'reason' => ['type' => 'string'],
                    'office' => ['type' => 'string', 'enum' => $offices->map(fn ($office) => "{$office->code} {$office->name}")->values()->all()],
                    'priority' => ['type' => 'string', 'enum' => self::PRIORITIES],
                ],
                'required' => ['reason', 'office', 'priority'],
                'additionalProperties' => false,
            ]],
            'confidence_score' => ['type' => 'integer'],
        ];

        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    /** Re-check the model's answer against the registries before the form trusts it. */
    private function validated(array $result, Collection $offices, Collection $types, ?int $administratorId): array
    {
        $result['barcode'] = $this->barcode($result['barcode'] ?? null);

        $type = $types->firstWhere('id', $result['document_type_id'] ?? null);
        $allowed = $type?->classifications->pluck('id')->all() ?? [];
        if ($type && $allowed !== [] && ! in_array($result['classification_id'] ?? null, $allowed, true)) {
            $result['classification_id'] = null;
        }
        if ($type?->default_transaction_type_id) {
            $result['transaction_type_id'] = $type->default_transaction_type_id;
        }
        if (($result['source_type'] ?? null) !== 'Internal') {
            $result['source_location'] = null;
        }

        $officeIdsByCode = $offices->pluck('id', 'code');
        $mayorId = $officeIdsByCode['OCM'] ?? null;
        if (! in_array($result['department_id'] ?? null, array_filter([$administratorId, $mayorId]), true)) {
            $result['department_id'] = null;
        }
        $suggestions = collect(is_array($result['routing_suggestions'] ?? null) ? $result['routing_suggestions'] : [])
            ->map(fn ($suggestion) => is_array($suggestion)
                ? [...$suggestion, 'department_id' => $officeIdsByCode[Str::before((string) ($suggestion['office'] ?? ''), ' ')] ?? null]
                : $suggestion)
            ->all();
        $result['routing_suggestions'] = $this->routingSuggestions(
            $suggestions,
            $offices->pluck('id')->all(),
            // Records never routes to itself, and OCM is reached only through Direct to (or after ADM approval).
            array_filter([
                $administratorId, $officeIdsByCode['REC'] ?? null, $result['source_location'] ?? null,
                $administratorId !== null && $result['department_id'] === $administratorId ? null : $mayorId,
            ]),
        );

        return $result;
    }

    /** Barcodes are one letter and nine digits; the model sometimes drops the zero padding. */
    private function barcode(mixed $barcode): ?string
    {
        if (! is_string($barcode)) {
            return null;
        }
        $clean = preg_replace('/[-\s]/', '', $barcode);
        if (! preg_match('/^([A-Z])([0-9]{1,9})$/i', $clean, $matches)) {
            return null;
        }

        return strtoupper($matches[1]).str_pad($matches[2], 9, '0', STR_PAD_LEFT);
    }

    private function routingSuggestions(mixed $suggestions, array $activeOfficeIds, array $excludedOfficeIds): array
    {
        if (! is_array($suggestions)) {
            return [];
        }

        $kept = collect($suggestions)
            ->filter(fn ($suggestion) => is_array($suggestion) && is_numeric($suggestion['department_id'] ?? null))
            ->map(fn ($suggestion) => [
                'department_id' => (int) $suggestion['department_id'],
                'priority' => in_array($suggestion['priority'] ?? null, self::PRIORITIES, true) ? $suggestion['priority'] : 'FYI',
                'reason' => is_string($suggestion['reason'] ?? null) ? Str::limit(trim($suggestion['reason']), 500, '') : '',
            ])
            ->filter(fn ($suggestion) => in_array($suggestion['department_id'], $activeOfficeIds, true)
                && ! in_array($suggestion['department_id'], $excludedOfficeIds, true))
            ->unique('department_id')->take(4)->values();

        // Routing needs exactly one Primary office: keep the first one the model named, or promote its first pick.
        $primaryIndex = $kept->search(fn ($suggestion) => $suggestion['priority'] === 'Primary');

        return $kept->map(function ($suggestion, $index) use ($primaryIndex) {
            $isPrimary = $primaryIndex === false ? $index === 0 : $index === $primaryIndex;
            if ($isPrimary) {
                $suggestion['priority'] = 'Primary';
            } elseif ($suggestion['priority'] === 'Primary') {
                $suggestion['priority'] = 'Secondary';
            }

            return $suggestion;
        })->all();
    }

    private function clean(string $ocrText): string
    {
        // Collapse runs of spaces but keep line breaks: signature blocks are recognised by their lines.
        $text = preg_replace('/[^\S\n]+/', ' ', trim($ocrText));
        $text = preg_replace('/\n\s*\n+/', "\n", $text);

        return mb_substr($this->withoutReferralSlips($text), 0, (int) config('services.intake_ai.max_text_characters'));
    }

    /**
     * The City Administrator's referral slip is often scanned with the letter. Its signature block made the
     * model name the City Administrator as the sender, so only the slip's From/Subject lines reach the model.
     */
    private function withoutReferralSlips(string $text): string
    {
        $pages = preg_split('/^(?=--- Page \d+ ---$)/m', $text);

        return collect($pages)->map(function (string $page) {
            if (! preg_match('/\b(referral|action|routing)\s*(\/\s*action\s*)?slip\b/i', $page)) {
                return $page;
            }
            preg_match('/^--- Page (\d+) ---$/m', $page, $marker);
            preg_match_all('/^\s*(from|subject|re)\s*[:;.].*$/im', $page, $lines);

            return '--- Page '.($marker[1] ?? '?')." (attached referral slip, not the document) ---\n"
                .implode("\n", array_map('trim', $lines[0]))."\n";
        })->implode('');
    }

    /**
     * OCR often splits a surname under a signature ("P. AYAN" for "PANGAYAN"). Take the longer spelling from
     * another line that also has the sender's first name (usually the slip's "From:" line), and drop a name
     * whose surname appears nowhere in the text the model saw.
     */
    private function sourceName(mixed $name, string $text): ?string
    {
        if (! is_string($name) || trim($name) === '') {
            return null;
        }
        $name = trim($name);
        // Degree suffixes ("…, MIT") stay as they are; titles, initials and Jr./III are not first names or surnames.
        $base = trim(Str::before($name, ','));
        $suffix = str_contains($name, ',') ? ','.Str::after($name, ',') : '';
        $named = array_values(array_filter(preg_split('/\s+/', $base),
            fn ($word) => ! preg_match('/^(atty|dr|engr|hon|mr|mrs|ms|prof|arch|jr|sr|ii|iii|iv)\.?$|^\p{L}\.$/iu', $word)));
        if (count($named) < 2) {
            return $name;
        }
        $first = preg_quote(trim($named[0], '.'), '/');
        $surname = trim(end($named), '.');
        $quoted = preg_quote($surname, '/');

        foreach (explode("\n", $text) as $line) {
            if (preg_match("/\\b{$first}\\b/iu", $line) && preg_match("/\\b(\\p{L}+{$quoted})\\b/iu", $line, $longer)) {
                return preg_replace("/{$quoted}\\.?$/u", mb_convert_case($longer[1], MB_CASE_TITLE), $base, 1).$suffix;
            }
        }

        return preg_match("/{$quoted}/iu", $text) ? $name : null;
    }

    /**
     * Match the sender against the People Directory by surname. A handwritten signature often hides the printed
     * first name, so OCR leaves stray letters ("Atty. R. L. Caloza"): short tokens only count as initials, while a
     * readable word (3+ letters) must be one of the person's first names. Ambiguous matches are left as read.
     */
    private function directoryMatch(?string $name): ?Person
    {
        if ($name === null) {
            return null;
        }
        $base = mb_strtolower(trim(Str::before($name, ',')));
        $surname = Str::afterLast($base, ' ');
        if (mb_strlen($surname) < 2 || $surname === $base) {
            return null;
        }

        $scored = Person::where('state', 1)->where('last_name', 'like', '%'.$surname)->get()
            ->filter(fn (Person $person) => preg_match('/(^|\s)'.preg_quote(mb_strtolower($person->last_name), '/').'$/u', $base))
            ->map(function (Person $person) use ($base) {
                $given = trim(mb_substr($base, 0, mb_strlen($base) - mb_strlen($person->last_name)));
                $tokens = array_filter(preg_split('/\s+/', $given), fn ($token) => $token !== ''
                    && ! preg_match('/^(atty|dr|engr|hon|mr|mrs|ms|prof|arch)\.?$/u', $token));
                $firstNames = preg_split('/\s+/', mb_strtolower($person->first_name));
                $initials = [...array_map(fn ($word) => mb_substr($word, 0, 1), $firstNames), mb_strtolower(mb_substr((string) $person->middle_initial, 0, 1))];
                $score = 0;
                foreach ($tokens as $token) {
                    $word = rtrim($token, '.');
                    if (mb_strlen($word) >= 3 && ! str_ends_with($token, '.')) {
                        if (! in_array($word, $firstNames, true)) {
                            return null;
                        }
                        $score += 2;
                    } elseif (in_array(mb_substr($word, 0, 1), $initials, true)) {
                        $score++;
                    }
                }

                // Initials that all disagree ("M. Santos" vs Jose Santos) point to a different person, not OCR noise.
                return $tokens !== [] && $score === 0 ? null : ['person' => $person, 'score' => $score];
            })
            ->filter();

        $best = $scored->max('score');
        $top = $scored->where('score', $best);

        return $top->count() === 1 ? $top->first()['person'] : null;
    }
}
