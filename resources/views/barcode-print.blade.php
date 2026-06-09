<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak Barcode — MSG POS</title>
    <style>
        @page {
            size: A4;
            margin: 0;
        }
        body {
            margin: 0;
            padding: 10mm;
            font-family: 'DM Sans', 'Helvetica Neue', Arial, sans-serif;
            background-color: #ffffff;
            -webkit-print-color-adjust: exact;
        }
        .grid-container {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            grid-auto-rows: 29.7mm;
            column-gap: 5mm;
            row-gap: 0;
            width: 190mm; /* A4 width 210mm - 20mm margins */
        }
        .label-card {
            width: 60mm;
            height: 27.7mm;
            box-sizing: border-box;
            border: 1px dashed #e2e8f0;
            padding: 2mm 3mm;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            overflow: hidden;
            page-break-inside: avoid;
        }
        @media print {
            .label-card {
                border: none; /* Hide dashed borders when printing */
            }
        }
        .brand-text {
            font-size: 7px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0 0 1px 0;
        }
        .product-name {
            font-size: 9px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 2px 0;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            line-height: 1.1;
        }
        .barcode-container {
            margin: 1px 0;
            display: flex;
            justify-content: center;
            align-items: center;
            max-height: 10mm;
        }
        .barcode-container svg {
            max-width: 100%;
            height: auto;
            max-height: 10mm;
        }
        .barcode-text {
            font-size: 7px;
            font-family: 'Courier New', Courier, monospace;
            font-weight: 700;
            color: #475569;
            margin: 1px 0 0 0;
        }
        .price-text {
            font-size: 9px;
            font-weight: 800;
            color: #000000;
            margin: 2px 0 0 0;
        }
    </style>
</head>
<body>
    <div class="grid-container">
        @foreach($labels as $label)
            <div class="label-card">
                <p class="brand-text">{{ $label['brand'] ?: 'MSG POS' }}</p>
                <p class="product-name">{{ $label['nama'] }}</p>
                <div class="barcode-container">
                    {!! $label['svg'] !!}
                </div>
                <p class="barcode-text">{{ $label['barcode'] }}</p>
                <p class="price-text">Rp {{ number_format($label['harga'], 0, ',', '.') }}</p>
            </div>
        @endforeach
    </div>
    <script>
        // Trigger browser print menu
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>
