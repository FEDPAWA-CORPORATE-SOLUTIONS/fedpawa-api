{{-- BRIEF-B3 : gabarit porté de templates/12-securite-mot-de-passe (worker D) — mêmes variables. --}}
@extends('emails.layout')

@section('titre_page')
Votre mot de passe FEDPAWA a été modifié
@endsection

@section('preheader')
Le mot de passe de votre compte a été modifié le {{ $date_modification }}. Si ce n'est pas vous, agissez maintenant.
@endsection

@section('content')

<div class="em-kicker" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:11px;font-weight:700;letter-spacing:0.18em;text-transform:uppercase;color:#15294D;margin-bottom:14px;">Sécurité du compte</div>
<h1 class="em-h1" style="margin:0 0 20px;font-family:'Playfair Display',Georgia,'Times New Roman',serif;font-size:26px;font-weight:700;line-height:1.25;color:#0A1424;">Votre mot de passe a été modifié</h1>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 24px;"><tr><td class="em-banner-ko" style="background-color:#FBEAE7;border-left:4px solid #C0392B;border-radius:8px;padding:14px 18px;"><div class="em-banner-ko-t" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;font-weight:700;color:#C0392B;margin-bottom:4px;">Modification de mot de passe</div><div class="em-banner-ko-p" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:14px;line-height:1.6;color:#1E293B;">Le mot de passe de votre compte FEDPAWA vient d'être modifié le <strong>{{ $date_modification }}</strong>.</div></td></tr></table>
<p class="em-p" style="margin:0 0 16px;font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:15px;line-height:1.7;color:#1E293B;"><strong>Si ce n'est pas vous,</strong> réinitialisez immédiatement votre mot de passe et contactez notre support à contact@fedpawa.com.</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:8px 0 24px;"><tr><td align="center" bgcolor="#15294D" class="em-cta-td" style="background-color:#15294D;"><!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $lien_action }}" style="height:50px;v-text-anchor:middle;width:280px;" arcsize="0%" fillcolor="#15294D" stroke="f"><v:textbox inset="0,0,0,0"><center style="color:#FFFFFF;font-family:Arial,sans-serif;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">Réinitialiser mon mot de passe</center></v:textbox></v:roundrect><![endif]--><!--[if !mso]><!--><a href="{{ $lien_action }}" class="em-btn" style="display:inline-block;background-color:#15294D;color:#FFFFFF;font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;text-decoration:none;padding:16px 32px;">Réinitialiser mon mot de passe</a><!--<![endif]--></td></tr></table>
<p class="em-muted" style="margin:0 0 16px;font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;line-height:1.65;color:#5A6478;">Cet e-mail est envoyé systématiquement à chaque changement de mot de passe, quel que soit le statut de votre dossier.</p>
@endsection
