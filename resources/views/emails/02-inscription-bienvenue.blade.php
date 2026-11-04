{{-- BRIEF-B3 : gabarit porté de templates/02-inscription-bienvenue (worker D) — mêmes variables. --}}
@extends('emails.layout')

@section('titre_page')
Bienvenue chez FEDPAWA, {{ $client_nom }} — votre compte est actif
@endsection

@section('preheader')
Votre e-mail est vérifié : découvrez votre espace client et complétez votre dossier KYC.
@endsection

@section('content')

<div class="em-kicker" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:11px;font-weight:700;letter-spacing:0.18em;text-transform:uppercase;color:#15294D;margin-bottom:14px;">Compte activé</div>
<h1 class="em-h1" style="margin:0 0 20px;font-family:'Playfair Display',Georgia,'Times New Roman',serif;font-size:26px;font-weight:700;line-height:1.25;color:#0A1424;">Bienvenue chez FEDPAWA, {{ $client_nom }} !</h1>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 24px;"><tr><td class="em-banner-ok" style="background-color:#EAF5EF;border-left:4px solid #1E7D4F;border-radius:8px;padding:14px 18px;"><div class="em-banner-ok-t" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;font-weight:700;color:#1E7D4F;margin-bottom:4px;">Adresse e-mail vérifiée</div><div class="em-banner-ok-p" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:14px;line-height:1.6;color:#1E293B;">Votre compte est maintenant actif. Merci de nous avoir confirmé votre adresse {{ $client_email }}.</div></td></tr></table>
<p class="em-p" style="margin:0 0 16px;font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:15px;line-height:1.7;color:#1E293B;">Prochaines étapes :<br>1. Complétez votre dossier <strong>KYC</strong> (pièce d'identité + justificatif de domicile) ;<br>2. Choisissez votre <strong>pack de domiciliation</strong> ;<br>3. Signez votre contrat en ligne.</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:8px 0 24px;"><tr><td align="center" bgcolor="#15294D" class="em-cta-td" style="background-color:#15294D;"><!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $lien_espace_client }}" style="height:50px;v-text-anchor:middle;width:280px;" arcsize="0%" fillcolor="#15294D" stroke="f"><v:textbox inset="0,0,0,0"><center style="color:#FFFFFF;font-family:Arial,sans-serif;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">Ouvrir mon espace client</center></v:textbox></v:roundrect><![endif]--><!--[if !mso]><!--><a href="{{ $lien_espace_client }}" class="em-btn" style="display:inline-block;background-color:#15294D;color:#FFFFFF;font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;text-decoration:none;padding:16px 32px;">Ouvrir mon espace client</a><!--<![endif]--></td></tr></table>
<p class="em-muted" style="margin:0 0 16px;font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;line-height:1.65;color:#5A6478;">Besoin d'aide ? Écrivez-nous à contact@fedpawa.com ou appelez le +237 691 70 48 02 (Douala · Logpom).</p>
@endsection
