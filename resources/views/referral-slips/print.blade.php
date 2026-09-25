<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Referral Action Slip</title>
    <style>
        @page { size: A4; margin: 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #222; font-family: Arial, Helvetica, sans-serif; font-size: 12px; }
        .toolbar { max-width: 190mm; margin: 16px auto; display: flex; justify-content: flex-end; gap: 8px; }
        .toolbar button { border: 0; border-radius: 6px; padding: 9px 14px; background: #1e4e79; color: white; font-weight: 700; cursor: pointer; }
        .slip { width: 100%; padding: 10px 14px 18px; }
        .header { position: relative; display: flex; align-items: center; justify-content: center; min-height: 72px; border-bottom: 3px solid #222; padding: 4px 76px 8px; text-align: center; }
        .logo { position: absolute; left: 8px; top: 4px; z-index: 0; width: 63px; height: 63px; object-fit: contain; }
        .header > div { position: relative; z-index: 1; }
        .government { font-size: 12px; font-weight: 700; line-height: 1.35; }
        .office { font-size: 17px; font-weight: 800; margin-top: 3px; }
        .title { margin: 8px 0; border-top: 1px solid #444; border-bottom: 1px solid #444; padding: 5px; text-align: center; font-weight: 800; font-size: 14px; }
        .summary { border-bottom: 1px solid #444; padding: 7px 0; }
        .summary h2 { margin: 0 0 6px; font-size: 12px; text-align: center; }
        .line { display: flex; gap: 6px; margin: 3px 0; }
        .label { width: 62px; font-weight: 700; }
        .value { flex: 1; border-bottom: 1px solid #777; min-height: 15px; }
        .grid { display: grid; grid-template-columns: 1.1fr .9fr; gap: 14px; margin-top: 12px; }
        h3 { margin: 0 0 6px; font-size: 12px; }
        .checks { display: grid; grid-template-columns: repeat(2, 1fr); gap: 5px 10px; }
        .department-checks { display: grid; grid-template-columns: repeat(2, 1fr); gap: 4px 8px; }
        .check { display: flex; align-items: flex-start; gap: 5px; min-height: 16px; }
        .box { display: inline-block; width: 12px; height: 12px; border: 1px solid #222; flex: 0 0 12px; text-align: center; line-height: 10px; font-size: 12px; font-weight: 800; }
        .checked { background: #222; color: white; }
        .personnel, .remarks { margin-top: 10px; border-bottom: 1px solid #777; min-height: 24px; }
        .signature { margin: 28px auto 0; width: 250px; text-align: center; border-top: 1px solid #222; padding-top: 4px; font-weight: 700; }
        .signature small { display: block; font-weight: normal; }
        @media print { .toolbar { display: none; } }
    </style>
</head>
<body>
    @php
        $actions = [
            'for_approval' => 'For Approval', 'for_signature' => 'For Signature',
            'for_information_reference' => 'For Information / Reference', 'for_appropriate_action' => 'For Appropriate Action',
            'for_review_evaluation' => 'For Review / Evaluation', 'lets_discuss_see_me' => "Let's Discuss / See Me",
            'please_attend' => 'Please Attend', 'please_handle' => 'Please Handle',
            'for_dissemination' => 'For Dissemination', 'return_documents_to_me' => 'Return Document/s to Me',
            'for_comment_recommendation' => 'For Comment / Recommendation', 'other' => 'Others',
        ];
        $selected = $trail->required_actions ?? [];
    @endphp
    <div class="toolbar"><button onclick="window.print()">Print / Save as PDF</button></div>
    <main class="slip">
        <header class="header">
            @if (file_exists(public_path('images/san_fernando_logo.png')))
                <img class="logo" src="{{ url('images/san_fernando_logo.png') }}" alt="City of San Fernando logo">
            @endif
            <div>
                <div class="government">Republic of the Philippines<br>Province of La Union<br>City of San Fernando</div>
                <div class="office">OFFICE OF THE CITY ADMINISTRATOR</div>
            </div>
        </header>
        <div class="title">REFERRAL / ACTION SLIP</div>
        <section class="summary">
            <h2>BACKGROUND SUMMARY</h2>
            <div class="line"><span class="label">From:</span><span class="value">{{ $document->source_name }}</span></div>
            <div class="line"><span class="label">Date:</span><span class="value">{{ $document->created_at?->timezone('Asia/Manila')->format('F j, Y - g:i A') }}</span></div>
            <div class="line"><span class="label">Subject:</span><span class="value">{{ $document->subject_matter }}</span></div>
        </section>
        <section class="grid">
            <div>
                <h3>CONCERNED DEPARTMENTS</h3>
                <div class="department-checks">
                    @foreach ($departments as $department)
                        @php($isSelected = in_array($department->id, $document->approved_routing_slip ?? [], true))
                        <div class="check"><span class="box {{ $isSelected ? 'checked' : '' }}">{!! $isSelected ? '&#10003;' : '' !!}</span><span>{{ $department->code ?: $department->name }}</span></div>
                    @endforeach
                </div>
                <div class="personnel"><strong>Concerned Personnel:</strong> {{ $trail->concerned_personnel ?: '' }}</div>
            </div>
            <div>
                <h3>REQUIRED ACTION</h3>
                <div class="checks">
                    @foreach ($actions as $key => $label)
                        <div class="check"><span class="box {{ in_array($key, $selected, true) ? 'checked' : '' }}">{!! in_array($key, $selected, true) ? '&#10003;' : '' !!}</span><span>{{ $label }}@if ($key === 'other' && $trail->other_action): {{ $trail->other_action }}@endif</span></div>
                    @endforeach
                </div>
            </div>
        </section>
        <div class="remarks"><strong>Remarks:</strong> {{ $trail->remarks ?: '' }}</div>
        <div class="signature">ATTY. GILBERT L. CALOZA<small>City Administrator</small></div>
    </main>
    @if (request()->boolean('autoprint'))
        <script>window.addEventListener('load', () => window.print());</script>
    @endif
</body>
</html>
