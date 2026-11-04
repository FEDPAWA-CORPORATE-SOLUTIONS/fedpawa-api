{{-- BRIEF-B3 : gabarit porté de templates/10-courrier-recu (worker D) — mêmes variables. --}}
@extends('emails.layout')

@section('titre_page')
Nouveau courrier reçu — FEDPAWA
@endsection

@section('preheader')
Un courrier est arrivé à votre adresse de domiciliation le {{ $date_reception }}. Consultez le scan.
@endsection

@section('content')

<div class="em-kicker" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:11px;font-weight:700;letter-spacing:0.18em;text-transform:uppercase;color:#15294D;margin-bottom:14px;">Domiciliation</div>
<h1 class="em-h1" style="margin:0 0 20px;font-family:'Playfair Display',Georgia,'Times New Roman',serif;font-size:26px;font-weight:700;line-height:1.25;color:#0A1424;">Un nouveau courrier vous attend</h1>
<p class="em-p" style="margin:0 0 16px;font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:15px;line-height:1.7;color:#1E293B;">Bonjour <strong>{{ $client_nom }}</strong>,<br><br>Un courrier a été déposé à votre adresse de domiciliation FEDPAWA et numérisé.</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="em-box" style="background-color:#FAF9F6;border:1px solid #E2DDD5;border-radius:8px;padding:18px 20px;margin:0 0 24px;"><tr><td><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:10px;"><tr><td class="em-box-label" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;color:#5A6478;">Reçu le</td><td align="right" class="em-box-val" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:14px;font-weight:600;color:#1E293B;text-align:right;">{{ $date_reception }}</td></tr></table><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:10px;"><tr><td class="em-box-label" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;color:#5A6478;">Statut</td><td align="right" class="em-box-val" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:14px;font-weight:600;color:#1E293B;text-align:right;">Non lu</td></tr></table></td></tr></table>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:8px 0 24px;"><tr><td align="center" bgcolor="#15294D" class="em-cta-td" style="background-color:#15294D;"><!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $lien_action }}" style="height:50px;v-text-anchor:middle;width:280px;" arcsize="0%" fillcolor="#15294D" stroke="f"><v:textbox inset="0,0,0,0"><center style="color:#FFFFFF;font-family:Arial,sans-serif;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">Lire le courrier (scan)</center></v:textbox></v:roundrect><![endif]--><!--[if !mso]><!--><a href="{{ $lien_action }}" class="em-btn" style="display:inline-block;background-color:#15294D;color:#FFFFFF;font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;text-decoration:none;padding:16px 32px;">Lire le courrier (scan)</a><!--<![endif]--></td></tr></table>
<p class="em-muted" style="margin:0 0 16px;font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;line-height:1.65;color:#5A6478;">Vous pouvez aussi nous demander la réexpédition ou la conservation du courrier depuis votre espace client.</p>
@endsection
