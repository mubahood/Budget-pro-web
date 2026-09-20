@php
    $program = $record->budgetProgram;
    $company = $record->company;
    $programName = $program->title ?: ($program->name ?? 'our fundraiser');
    $companyName = $company->name ?? '';
    $amount = number_format((float) $record->amount);
    $currency = $company->currency ?? 'UGX';
    $isFullyPaid = $record->fully_paid === 'Yes';

    // Plain text, WhatsApp's own formatting only (*bold*, _italic_, literal
    // line breaks) -- no HTML, since this is meant to be pasted straight
    // into a WhatsApp chat, not rendered as a web page there.
    $lines = [];
    $lines[] = '🙏 *Thank you, ' . $record->name . '!*';
    $lines[] = '';
    $lines[] = 'Your ' . ($isFullyPaid ? 'contribution' : 'pledge') . ' of *' . $currency . ' ' . $amount . '* towards *' . $programName . '* is deeply appreciated.';
    $lines[] = '';
    if ($isFullyPaid) {
        $lines[] = '✅ Status: _Fully paid_';
    } else {
        $paid = number_format((float) $record->paid_amount);
        $remaining = number_format((float) $record->not_paid_amount);
        $lines[] = '📌 Status: _' . $currency . ' ' . $paid . ' received, ' . $currency . ' ' . $remaining . ' remaining_';
    }
    $lines[] = '';
    $lines[] = 'With gratitude,';
    if ($companyName) {
        $lines[] = '*' . $companyName . '*';
    }
    $whatsappText = implode("\n", $lines);
@endphp
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Thank You - {{ $record->name }}</title>
    @include('css.css')
    <style>
        body {
            background: #f4f4f4;
        }

        .thanks-card {
            max-width: 520px;
            margin: 40px auto;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 32px;
            text-align: center;
        }

        .thanks-card h1 {
            font-size: 22px;
            margin-bottom: 4px;
        }

        .thanks-card .amount {
            font-size: 28px;
            font-weight: 800;
            color: #1a7a3c;
            margin: 12px 0;
        }

        .thanks-card .status {
            margin-bottom: 20px;
            color: #555;
        }

        .copy-btn {
            display: inline-block;
            padding: 10px 22px;
            background: #25D366;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            cursor: pointer;
        }

        .copy-btn.copied {
            background: #128C4A;
        }

        .print-btn {
            display: inline-block;
            padding: 10px 22px;
            background: #444;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            cursor: pointer;
            margin-left: 8px;
        }

        @media print {
            .no-print {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="thanks-card">
        <h1>🙏 Thank you, {{ $record->name }}!</h1>
        <p>for your {{ $isFullyPaid ? 'contribution' : 'pledge' }} towards</p>
        <p><strong>{{ $programName }}</strong></p>
        <div class="amount">{{ $currency }} {{ $amount }}</div>
        <div class="status">
            @if ($isFullyPaid)
                ✅ Fully paid
            @else
                📌 {{ $currency }} {{ number_format((float) $record->paid_amount) }} received,
                {{ $currency }} {{ number_format((float) $record->not_paid_amount) }} remaining
            @endif
        </div>

        <div class="no-print">
            <button class="copy-btn" id="copyBtn" type="button">📋 Copy for WhatsApp</button>
            <button class="print-btn" type="button" onclick="window.print()">🖨️ Print</button>
        </div>
    </div>

    <script>
        // Kept as a hidden, pre-formatted plain-text block rather than built
        // from the visible HTML above -- copying innerText would carry stray
        // whitespace/line-break differences per browser, and WhatsApp only
        // understands its own *bold*/_italic_ markup, not HTML tags.
        const whatsappText = {!! json_encode($whatsappText) !!};

        document.getElementById('copyBtn').addEventListener('click', async function () {
            const btn = this;
            try {
                await navigator.clipboard.writeText(whatsappText);
            } catch (e) {
                // Fallback for browsers without Clipboard API / non-secure context.
                const ta = document.createElement('textarea');
                ta.value = whatsappText;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
            }
            const original = btn.textContent;
            btn.textContent = '✅ Copied!';
            btn.classList.add('copied');
            setTimeout(function () {
                btn.textContent = original;
                btn.classList.remove('copied');
            }, 2000);
        });
    </script>
</body>

</html>
