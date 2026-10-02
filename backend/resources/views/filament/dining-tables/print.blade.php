{{-- Every table's QR code on one sheet; the Print button hides everything else. --}}
<x-filament-panels::page>
    <style>
        .table-qr-sheet { display: grid; grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr)); gap: 1rem; }
        .table-qr-card { display: grid; gap: 0.25rem; justify-items: center; padding: 1rem; border: 1px solid #d4d4d8; border-radius: 0.75rem; background: #fff; color: #000; break-inside: avoid; text-align: center; }
        .table-qr-card svg { width: 100%; height: auto; }
        .table-qr-card .table-qr-label { font-size: 1.75rem; font-weight: 700; line-height: 1.2; }
        .table-qr-card .table-qr-hint { font-size: 0.875rem; }
        @media print {
            .fi-sidebar, .fi-topbar, .fi-header, .table-qr-note { display: none !important; }
            .fi-main, .fi-page { padding: 0 !important; margin: 0 !important; max-width: none !important; }
            .table-qr-sheet { grid-template-columns: repeat(3, 1fr); gap: 0.5cm; }
            .table-qr-card { border-color: #000; }
        }
    </style>

    @if (! $dineInEnabled)
        <p class="table-qr-note">Dine-in ordering is off, so these codes won’t take orders yet. Turn on “Offer dine in” in Settings.</p>
    @endif

    @if (count($codes) === 0)
        <p>No tables are taking orders. Add tables, or turn them on, to print their codes.</p>
    @else
        <div class="table-qr-sheet">
            @foreach ($codes as $code)
                <div class="table-qr-card">
                    {!! $code['svg'] !!}
                    <p class="table-qr-label">Table {{ $code['label'] }}</p>
                    <p class="table-qr-hint">Scan to order from {{ $restaurant }}</p>
                </div>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
