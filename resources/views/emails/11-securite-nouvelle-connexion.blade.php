{{-- BRIEF-B3 : gabarit porté de templates/11-securite-nouvelle-connexion (worker D) — mêmes variables. --}}
@extends('emails.layout')

@section('titre_page')
Nouvelle connexion à votre compte FEDPAWA — vérifiez qu'il s'agit bien de vous
@endsection

@section('preheader')
Connexion détectée le {{ $date_connexion }} depuis l'IP {{ $ip_connexion }} ({{ $appareil }}).
@endsection

@section('content')

<div class="em-kicker" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:11px;font-weight:700;letter-spacing:0.18em;text-transform:uppercase;color:#15294D;margin-bottom:14px;">Sécurité du compte</div>
<h1 class="em-h1" style="margin:0 0 20px;font-family:'Playfair Display',Georgia,'Times New Roman',serif;font-size:26px;font-weight:700;line-height:1.25;color:#0A1424;">Nouvelle connexion détectée</h1>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 24px;"><tr><td class="em-banner-warn" style="background-color:#F7F0DC;border-left:4px solid #8A6D1D;border-radius:8px;padding:14px 18px;"><div class="em-banner-warn-t" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;font-weight:700;color:#8A6D1D;margin-bottom:4px;">Nouvel appareil ou nouvelle adresse IP</div><div class="em-banner-warn-p" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:14px;line-height:1.6;color:#1E293B;">Une connexion à votre compte a été détectée le <strong>{{ $date_connexion }}</strong> depuis l'adresse IP <strong>{{ $ip_connexion }}</strong> ({{ $appareil }}).</div></td></tr></table>
<p class="em-p" style="margin:0 0 16px;font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:15px;line-height:1.7;color:#1E293B;">Si c'est bien vous, aucune action n'est nécessaire. <strong>Si ce n'est pas vous, changez immédiatement votre mot de passe</strong> et contactez-nous.</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:8px 0 24px;"><tr><td align="center" bgcolor="#15294D" class="em-cta-td" style="background-color:#15294D;"><!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $lien_action }}" style="height:50px;v-text-anchor:middle;width:280px;" arcsize="0%" fillcolor="#15294D" stroke="f"><v:textbox inset="0,0,0,0"><center style="color:#FFFFFF;font-family:Arial,sans-serif;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">Sécuriser mon compte</center></v:textbox></v:roundrect><![endif]--><!--[if !mso]><!--><a href="{{ $lien_action }}" class="em-btn" style="display:inline-block;background-color:#15294D;color:#FFFFFF;font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;text-decoration:none;padding:16px 32px;">Sécuriser mon compte</a><!--<![endif]--></td></tr></table>
<p class="em-muted" style="margin:0 0 16px;font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;line-height:1.65;color:#5A6478;">Ne communiquez jamais votre mot de passe ni vos codes OTP — FEDPAWA ne vous les demandera jamais par e-mail ou par téléphone.</p>
@endsection
