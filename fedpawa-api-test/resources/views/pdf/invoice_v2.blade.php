<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facture Proforma — FEDPAWA CORPORATE SOLUTIONS</title>
    <style>
        @page { size: A4; margin: 0; }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            background: #0a1120;
            color: #ffffff;
            margin: 0;
            padding: 0;
            line-height: 1.5;
        }
        .wrapper {
            padding: 32px 40px;
            box-sizing: border-box;
        }
        .badge-logo {
            display: inline-block;
            width: 68px;
            height: 68px;
            border-radius: 0;
            text-align: center;
            line-height: 68px;
            margin-bottom: 14px;
        }
        .badge-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        h1.doc-title {
            font-size: 24px;
            font-weight: 800;
            color: #ffffff;
            margin: 0 0 6px 0;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        p.doc-subtitle {
            font-size: 13px;
            color: #94a3b8;
            margin: 0 0 26px 0;
        }
        .card {
            background: linear-gradient(180deg, #111827 0%, #0c1425 100%);
            border: 1px solid #1e293b;
            border-radius: 14px;
            padding: 30px 34px;
            box-sizing: border-box;
        }
        .emetteur-nom {
            font-size: 20px;
            font-weight: 800;
            color: #ffffff;
            margin: 0 0 10px 0;
            letter-spacing: 0.03em;
        }
        .emetteur-infos {
            font-size: 12px;
            color: #94a3b8;
            line-height: 1.75;
            margin: 0;
        }
        .emetteur-infos span { display: block; }
        .sep-gold {
            height: 2px;
            background: linear-gradient(to right, transparent 0%, #d4af37 50%, transparent 100%);
            border: none;
            margin: 26px 0;
        }
        .meta-title {
            font-size: 17px;
            font-weight: 800;
            color: #d4af37;
            margin: 0 0 12px 0;
            letter-spacing: 0.02em;
        }
        .meta-item {
            font-size: 13px;
            color: #e2e8f0;
            margin: 0 0 7px 0;
        }
        .meta-item strong { color: #ffffff; }
        .two-col {
            display: table;
            width: 100%;
            margin-top: 6px;
        }
        .two-col > div {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            padding-right: 26px;
        }
        .two-col > div:last-child { padding-right: 0; padding-left: 26px; border-left: 1px solid #1e293b; }
        .col-label {
            font-size: 13px;
            font-weight: 800;
            color: #d4af37;
            margin: 0 0 9px 0;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .col-content {
            font-size: 13px;
            color: #e2e8f0;
            line-height: 1.85;
            margin: 0;
        }
        .col-content span { display: block; }
        table.lines {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        table.lines thead {
            background: rgba(212, 175, 55, 0.08);
        }
        table.lines th {
            font-size: 12px;
            font-weight: 800;
            color: #d4af37;
            text-align: left;
            padding: 11px 0;
            border-bottom: 2px solid #1e293b;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }
        table.lines td {
            font-size: 13px;
            color: #e2e8f0;
            padding: 14px 0;
            border-bottom: 1px solid #1e293b;
        }
        table.lines td.montant, table.lines th.montant { text-align: right; }
        table.lines tr:last-child td { border-bottom: none; }
        .total-row {
            margin-top: 8px;
            display: table;
            width: 100%;
            background: rgba(212, 175, 55, 0.06);
            border: 1px solid #1e293b;
            border-radius: 8px;
            padding: 14px 20px;
            box-sizing: border-box;
        }
        .total-label {
            font-size: 15px;
            font-weight: 800;
            color: #ffffff;
            display: table-cell;
            text-align: left;
        }
        .total-value {
            font-size: 17px;
            font-weight: 900;
            color: #d4af37;
            display: table-cell;
            text-align: right;
        }
        .note {
            font-size: 11px;
            color: #94a3b8;
            line-height: 1.75;
            margin-top: 24px;
            padding: 16px 20px;
            background: #0c1425;
            border: 1px solid #1e293b;
            border-radius: 8px;
        }
        .note strong { color: #e2e8f0; }
        .note span.or { color: #d4af37; font-weight: 800; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="badge-logo">
            @if($logo_data_uri)
                <img src="{{ $logo_data_uri }}" alt="Logo FEDPAWA">
            @endif
        </div>
        <h1 class="doc-title">FACTURE</h1>
        <p class="doc-subtitle">Vérifiez votre facture en bonne et due forme avant de procéder au paiement.</p>

        <div class="card">
            <p class="emetteur-nom">FEDPAWA CORPORATE SOLUTIONS SARL</p>
            <p class="emetteur-infos">
                <span>NIU : M09261890362B</span>
                <span>RCCM : CM-DLA-01-2026-B13-00767</span>
                <span>Siège Social : Douala - Logpom (Immeuble Pharmacie de Logpom), Cameroun</span>
                <span>+237 691 70 48 02 · contact@fedpawa.com</span>
            </p>
        </div>

        <hr class="sep-gold">

        <p class="meta-title">FACTURE</p>
        <p class="meta-item"><strong>N°</strong> {{ $invoice_number ?? 'FAC-2026-001' }}</p>
        <p class="meta-item"><strong>Date :</strong> {{ now()->format('d/m/Y') }}</p>

        <div class="two-col">
            <div>
                <p class="col-label">Facturé à</p>
                <p class="col-content">
                    <span>{{ $client_name ?? 'Client' }}</span>
                    <span>{{ $client_email ?? '' }}</span>
                    <span>{{ $client_phone ?? '' }}</span>
                </p>
            </div>
            <div>
                <p class="col-label">Prestation</p>
                <p class="col-content">
                    <span>{{ $pack_label ?? 'Pack BUSINESS — Domiciliation' }}</span>
                    <span>Période : {{ $periode ?? 'Semestriel (6 mois)' }}</span>
                </p>
            </div>
        </div>

        <table class="lines">
            <thead>
                <tr>
                    <th>Désignation</th>
                    <th class="montant">Montant</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $designation ?? 'Pack BUSINESS — Domiciliation (Semestriel (6 mois))' }}</td>
                    <td class="montant">{{ number_format($amount ?? 50000, 0, ',', ' ') }} FCFA</td>
                </tr>
            </tbody>
        </table>

        <div class="total-row">
            <span class="total-label">TOTAL À RÉGLER</span>
            <span class="total-value">{{ number_format($amount ?? 50000, 0, ',', ' ') }} FCFA</span>
        </div>

        <p class="note">
            <strong>Arrêtée la présente facture à la somme de <span class="or">{{ $amount_en_lettres }}</span> francs CFA.</strong><br>
            Règlement par Mobile Money (Orange / MTN), carte bancaire ou virement, via la plateforme sécurisée FEDPAWA.
        </p>
    </div>
</body>
</html>
