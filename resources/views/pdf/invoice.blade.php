<html>
<head>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; color: #333; line-height: 1.5; margin: 40px; }
        h1 { color: #7A1C1C; font-size: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; font-size: 12px; }
        th { background-color: #f8f9fa; color: #333; }
        .total { text-align: right; font-size: 14px; font-weight: bold; margin-top: 20px; }
    </style>
</head>
<body>
    <div style="border-bottom: 2px solid #15294D; padding-bottom: 10px; margin-bottom: 18px;">
        <div style="font-size: 15px; font-weight: bold; color: #15294D; letter-spacing: 0.06em;">FEDPAWA CORPORATE SOLUTIONS SARL</div>
        <div style="font-size: 11px; color: #555; line-height: 1.6;">
            NIU : M09261890362B<br>
            RCCM : CM-DLA-01-2026-B13-00767<br>
            Siège Social : Douala - Logpom (Immeuble Pharmacie de Logpom), Cameroun<br>
            contact@fedpawa.com
        </div>
    </div>
    <h1>FACTURE / REÇU DE PAIEMENT</h1>
    <p><strong>Facture N° :</strong> {{ $invoice_number ?? 'FAC-2026-001' }}</p>
    <p><strong>Date :</strong> {{ now()->format('d/m/Y') }}</p>
    <p><strong>Client :</strong> {{ $client_name }}</p>
    <table>
        <thead>
            <tr><th>Désignation</th><th>Montant (FCFA)</th></tr>
        </thead>
        <tbody>
            <tr><td>Prestation & Services FEDPAWA Corporate Solutions</td><td>{{ number_format($amount ?? 50000, 0, ',', ' ') }}</td></tr>
        </tbody>
    </table>
    <div class="total">Total Payé : {{ number_format($amount ?? 50000, 0, ',', ' ') }} FCFA</div>
</body>
</html>
