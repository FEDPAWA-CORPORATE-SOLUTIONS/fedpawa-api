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
        .badge {
            display: inline-block;
            width: 30px;
            height: 30px;
            background: transparent;
            color: #d4af37;
            border: 2px solid #d4af37;
            border-radius: 50%;
            text-align: center;
            line-height: 26px;
            font-weight: 800;
            font-size: 13px;
            margin-bottom: 8px;
        }
        h1.doc-title {
            font-size: 20px;
            font-weight: 700;
            color: #ffffff;
            margin: 0 0 5px 0;
            letter-spacing: 0.03em;
        }
        p.doc-subtitle {
            font-size: 12px;
            color: #94a3b8;
            margin: 0 0 22px 0;
        }
        .card {
            background: #0f172a;
            border: none;
            border-radius: 10px;
            padding: 24px 28px;
            box-sizing: border-box;
        }
        .emetteur-nom {
            font-size: 17px;
            font-weight: 700;
            color: #ffffff;
            margin: 0 0 7px 0;
        }
        .emetteur-infos {
            font-size: 11px;
            color: #94a3b8;
            line-height: 1.6;
            margin: 0;
        }
        .emetteur-infos span { display: block; }
        .sep-gold {
            height: 1.5px;
            background: linear-gradient(to right, transparent, #d4af37, transparent);
            border: none;
            margin: 22px 0;
            opacity: 0.7;
        }
        .meta-title {
            font-size: 14px;
            font-weight: 700;
            color: #ffffff;
            margin: 0 0 9px 0;
        }
        .meta-item {
            font-size: 12px;
            color: #cbd5e1;
            margin: 0 0 5px 0;
        }
        .meta-item strong { color: #ffffff; }
        .two-col {
            display: table;
            width: 100%;
            margin-top: 5px;
        }
        .two-col > div {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            padding-right: 22px;
        }
        .two-col > div:last-child { padding-right: 0; padding-left: 22px; border-left: 1px solid #1e293b; }
        .col-label {
            font-size: 12px;
            font-weight: 700;
            color: #94a3b8;
            margin: 0 0 7px 0;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .col-content {
            font-size: 12px;
            color: #e2e8f0;
            line-height: 1.7;
            margin: 0;
        }
        .col-content span { display: block; }
        table.lines {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
        }
        table.lines th {
            font-size: 11px;
            font-weight: 700;
            color: #94a3b8;
            text-align: left;
            padding: 8px 0;
            border-bottom: 1px solid #1e293b;
            text-transform: uppercase;
        }
        table.lines td {
            font-size: 12px;
            color: #e2e8f0;
            padding: 11px 0;
            border-bottom: 1px solid #1e293b;
        }
        table.lines td.montant, table.lines th.montant { text-align: right; }
        table.lines tr:last-child td { border-bottom: none; }
        .total-row {
            margin-top: 5px;
            display: table;
            width: 100%;
        }
        .total-label {
            font-size: 14px;
            font-weight: 800;
            color: #ffffff;
            display: table-cell;
            text-align: left;
        }
        .total-value {
            font-size: 15px;
            font-weight: 800;
            color: #d4af37;
            display: table-cell;
            text-align: right;
        }
        .note {
            font-size: 10px;
            color: #94a3b8;
            line-height: 1.65;
            margin-top: 22px;
            padding-top: 12px;
            border-top: 1px solid #1e293b;
        }
        .note strong { color: #e2e8f0; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="badge">7</div>
        <h1 class="doc-title">Facture Proforma</h1>
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

        <p class="meta-title">FACTURE PROFORMA</p>
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
            <strong>Arrêtée la présente facture à la somme de {{ $amount_en_lettres }} francs CFA.</strong><br>
            Règlement par Mobile Money (Orange / MTN), carte bancaire ou virement, via la plateforme sécurisée FEDPAWA.
        </p>
    </div>
</body>
</html>
