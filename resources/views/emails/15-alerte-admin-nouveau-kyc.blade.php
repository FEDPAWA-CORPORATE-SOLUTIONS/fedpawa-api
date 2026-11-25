{{-- Alerte admin : nouveau dossier KYC soumis — BRIEF-B3 / catalogue événements. --}}
@extends('emails.layout')

@section('titre_page')
Nouveau dossier KYC soumis — FEDPAWA
@endsection

@section('preheader')
Un client vient de déposer ses documents KYC (dossier #{{ $kyc_id ?? 'non défini' }}). À examiner dans l'espace administrateur.
@endsection

@section('content')

<div class="em-kicker" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:11px;font-weight:700;letter-spacing:0.18em;text-transform:uppercase;color:#15294D;margin-bottom:14px;">Conformité KYC</div>
<h1 class="em-h1" style="margin:0 0 20px;font-family:'Playfair Display',Georgia,'Times New Roman',serif;font-size:26px;font-weight:700;line-height:1.25;color:#0A1424;">Nouveau dossier KYC soumis</h1>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 24px;">
  <tr>
    <td class="em-banner-warn" style="background-color:#FBF3E2;border-left:4px solid #D4AF37;border-radius:8px;padding:14px 18px;">
      <div class="em-banner-warn-t" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;font-weight:700;color:#8A6D1A;margin-bottom:4px;">Dossier en attente de validation</div>
      <div class="em-banner-warn-p" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:14px;line-height:1.6;color:#1E293B;">Un client vient de soumettre ses pièces d'identité et justificatif de domicile. Le dossier est en statut <strong>en_cours</strong> et attend votre décision depuis l'onglet « Validation KYC » du dashboard administrateur.</div>
    </td>
  </tr>
</table>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="em-box" style="background-color:#FAF9F6;border:1px solid #E2DDD5;border-radius:8px;padding:18px 20px;margin:0 0 24px;">
  <tr>
    <td>
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:10px;">
        <tr>
          <td class="em-box-label" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;color:#5A6478;">Dossier KYC</td>
          <td align="right" class="em-box-val" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:14px;font-weight:600;color:#1E293B;text-align:right;">#{{ $kyc_id ?? 'non défini' }}</td>
        </tr>
      </table>
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:10px;">
        <tr>
          <td class="em-box-label" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;color:#5A6478;">Client</td>
          <td align="right" class="em-box-val" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:14px;font-weight:600;color:#1E293B;text-align:right;">{{ $client_nom ?? 'non spécifié' }}</td>
        </tr>
      </table>
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:10px;">
        <tr>
          <td class="em-box-label" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;color:#5A6478;">E-mail client</td>
          <td align="right" class="em-box-val" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:14px;font-weight:600;color:#1E293B;text-align:right;">{{ $client_email ?? 'non renseignée' }}</td>
        </tr>
      </table>
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:10px;">
        <tr>
          <td class="em-box-label" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;color:#5A6478;">Statut</td>
          <td align="right" class="em-box-val" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:14px;font-weight:600;color:#1E293B;text-align:right;">En cours</td>
        </tr>
      </table>
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:10px;">
        <tr>
          <td class="em-box-label" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;color:#5A6478;">Date de dépôt</td>
          <td align="right" class="em-box-val" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:14px;font-weight:600;color:#1E293B;text-align:right;">{{ $date_maj }}</td>
        </tr>
      </table>
    </td>
  </tr>
</table>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:8px 0 24px;">
  <tr>
    <td align="center" bgcolor="#0A1424" class="em-cta-td" style="background-color:#0A1424;">
      <!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $lien_action ?? '#' }}" style="height:50px;v-text-anchor:middle;width:280px;" arcsize="0%" fillcolor="#0A1424" stroke="f"><v:textbox inset="0,0,0,0"><center style="color:#FFFFFF;font-family:Arial,sans-serif;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">Examiner le dossier KYC</center></v:textbox></v:roundrect><![endif]-->
      <!--[if !mso]><!--><a href="{{ $lien_action ?? '#' }}" class="em-btn" style="display:inline-block;background-color:#0A1424;color:#FFFFFF;font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;text-decoration:none;padding:16px 32px;">Examiner le dossier KYC</a><!--<![endif]-->
    </td>
  </tr>
</table>
<p class="em-p" style="margin:0;font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:14px;line-height:1.7;color:#1E293B;">Ce message est automatique. Vous le recevez car une notification est associée à chaque nouvel événement KYC.</p>
@endsection
