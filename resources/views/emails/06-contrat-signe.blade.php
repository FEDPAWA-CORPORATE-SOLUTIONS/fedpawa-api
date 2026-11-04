{{-- BRIEF-B3 : gabarit porté de templates/06-contrat-signe (worker D) — mêmes variables. --}}
@extends('emails.layout')

@section('titre_page')
Votre contrat est signé — dossier #{{ $dossier_id }} — FEDPAWA
@endsection

@section('preheader')
Le contrat du dossier #{{ $dossier_id }} a été signé électroniquement le {{ $date_signature }}.
@endsection

@section('content')

<div class="em-kicker" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:11px;font-weight:700;letter-spacing:0.18em;text-transform:uppercase;color:#15294D;margin-bottom:14px;">Contrat</div>
<h1 class="em-h1" style="margin:0 0 20px;font-family:'Playfair Display',Georgia,'Times New Roman',serif;font-size:26px;font-weight:700;line-height:1.25;color:#0A1424;">Votre contrat est signé</h1>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 24px;"><tr><td class="em-banner-ok" style="background-color:#EAF5EF;border-left:4px solid #1E7D4F;border-radius:8px;padding:14px 18px;"><div class="em-banner-ok-t" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;font-weight:700;color:#1E7D4F;margin-bottom:4px;">Signature électronique enregistrée</div><div class="em-banner-ok-p" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:14px;line-height:1.6;color:#1E293B;">Le contrat du dossier <strong>#{{ $dossier_id }}</strong> vient d'être signé électroniquement le {{ $date_signature }}.</div></td></tr></table>
<p class="em-p" style="margin:0 0 16px;font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:15px;line-height:1.7;color:#1E293B;">Le contrat signé est disponible dans votre espace client. Il sera <strong>scellé</strong> (signature PAdES et horodatage) à l'issue du processus.</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:8px 0 24px;"><tr><td align="center" bgcolor="#15294D" class="em-cta-td" style="background-color:#15294D;"><!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $lien_contrat }}" style="height:50px;v-text-anchor:middle;width:280px;" arcsize="0%" fillcolor="#15294D" stroke="f"><v:textbox inset="0,0,0,0"><center style="color:#FFFFFF;font-family:Arial,sans-serif;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">Voir le contrat signé</center></v:textbox></v:roundrect><![endif]--><!--[if !mso]><!--><a href="{{ $lien_contrat }}" class="em-btn" style="display:inline-block;background-color:#15294D;color:#FFFFFF;font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;text-decoration:none;padding:16px 32px;">Voir le contrat signé</a><!--<![endif]--></td></tr></table>
@endsection
