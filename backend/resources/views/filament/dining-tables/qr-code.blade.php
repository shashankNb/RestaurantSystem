{{-- One table's QR code, in the "QR code" dialog. --}}
<div style="display: grid; gap: 0.75rem; justify-items: center;">
    <div style="width: 16rem; background: #fff; color: #000; border-radius: 0.5rem; padding: 0.5rem;">
        {!! $svg !!}
    </div>
    <p style="font-size: 0.875rem; word-break: break-all; text-align: center;">{{ $url }}</p>
    <a
        href="data:image/svg+xml;base64,{{ base64_encode($svg) }}"
        download="{{ $filename }}"
        style="font-weight: 600; text-decoration: underline;"
    >Download as SVG</a>
</div>
