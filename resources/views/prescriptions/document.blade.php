{{-- Rendered by PrescriptionPdfService from PrescriptionDocumentResource. Keep the layout in step with
     MediCon-main/src/components/medical/PrescriptionDocument.tsx (the app view and the image download). --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Prescription #{{ $doc['id'] }}</title>
<style>
    @page { margin: 32px 36px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1f2937; }
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; }
    .muted { color: #6b7280; }
    .doctor-name { font-size: 18px; font-weight: bold; color: #0f766e; }
    .header td { padding-bottom: 10px; }
    .rule { border-top: 1.5px solid #0f766e; margin: 4px 0 10px; }
    .patient td { padding: 6px 8px; background: #f3f4f6; }
    .label { font-size: 9px; text-transform: uppercase; color: #6b7280; }
    .value { font-size: 11px; font-weight: bold; }
    .body td.tests { width: 34%; padding-right: 14px; border-right: 1px solid #e5e7eb; }
    .body td.rx { padding-left: 14px; }
    .section-title { font-weight: bold; margin: 0 0 6px; }
    .rx-symbol { font-size: 26px; font-weight: bold; margin: 0 0 6px; }
    ul { margin: 0; padding-left: 14px; }
    li { margin-bottom: 4px; }
    .med { margin-bottom: 8px; }
    .med-name { font-weight: bold; }
    .footer { margin-top: 18px; }
    .signature { width: 200px; margin-left: auto; margin-top: 46px; text-align: center; }
    .signature-line { border-top: 1px solid #1f2937; padding-top: 4px; }
</style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <div class="doctor-name">{{ $doc['doctor']['name'] ?? 'Doctor' }}</div>
                @if ($doc['doctor']['qualification'])<div>{{ $doc['doctor']['qualification'] }}</div>@endif
                @if ($doc['doctor']['specialty'])<div class="muted">{{ $doc['doctor']['specialty'] }}</div>@endif
            </td>
            <td style="text-align: right;">
                @if ($doc['doctor']['hospitalName'])<div class="value">{{ $doc['doctor']['hospitalName'] }}</div>@endif
                <div>BMDC Reg. No - {{ $doc['doctor']['bmdcRegistrationNo'] ?? '—' }}</div>
                <div class="muted">Date: {{ $doc['issuedDate'] }}</div>
            </td>
        </tr>
    </table>
    <div class="rule"></div>

    <table class="patient">
        <tr>
            <td><div class="label">Name</div><div class="value">{{ $doc['patient']['name'] ?? '—' }}</div></td>
            <td><div class="label">Gender</div><div class="value">{{ $doc['patient']['gender'] ? ucfirst($doc['patient']['gender']) : '—' }}</div></td>
            <td><div class="label">Age</div><div class="value">{{ $doc['patient']['age'] ?? '—' }}</div></td>
            <td><div class="label">Weight</div><div class="value">{{ $doc['patient']['weightKg'] !== null ? $doc['patient']['weightKg'].' kg' : '—' }}</div></td>
        </tr>
    </table>

    <table class="body" style="margin-top: 14px;">
        <tr>
            <td class="tests">
                <p class="section-title">Diagnostic Tests:</p>
                @if (count($doc['tests']) > 0)
                    <ul>
                        @foreach ($doc['tests'] as $test)
                            <li>{{ $test['name'] }}@if ($test['instructions']) <span class="muted">({{ $test['instructions'] }})</span>@endif</li>
                        @endforeach
                    </ul>
                @else
                    <div class="muted">None</div>
                @endif
            </td>
            <td class="rx">
                <p class="rx-symbol">Rx</p>
                @foreach ($doc['medicines'] as $i => $med)
                    <div class="med">
                        <div class="med-name">{{ $i + 1 }}. {{ $med['name'] }} {{ $med['dosage'] }}</div>
                        <div>
                            {{ $med['pattern'] ?? 'As directed' }}
                            &nbsp;·&nbsp; {{ $med['durationDays'] }} {{ $med['durationDays'] === 1 ? 'day' : 'days' }}
                            @if ($med['instructions']) &nbsp;·&nbsp; {{ $med['instructions'] }}@endif
                        </div>
                    </div>
                @endforeach
            </td>
        </tr>
    </table>

    <div class="footer">
        @if ($doc['followUpDate'])
            <p><strong>Follow-up:</strong> {{ \Illuminate\Support\Carbon::parse($doc['followUpDate'])->format('M j, Y') }}</p>
        @endif
        @if ($doc['advice'])
            <p><strong>Advice:</strong> {{ $doc['advice'] }}</p>
        @endif
    </div>

    <div class="signature">
        <div class="signature-line">
            <div class="value">{{ $doc['doctor']['name'] ?? 'Doctor' }}</div>
            <div class="muted">Signature</div>
        </div>
    </div>
</body>
</html>
