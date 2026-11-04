{{-- BRIEF-B3 : gabarit porté de templates/01-verification-email (worker D) — mêmes variables. --}}
@extends('emails.layout')

@section('titre_page')
Vérifiez votre adresse e-mail — FEDPAWA
@endsection

@section('preheader')
Confirmez votre adresse e-mail pour activer votre compte FEDPAWA. Lien valable 72 heures.
@endsection

@section('content')

<div class="em-kicker" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:11px;font-weight:700;letter-spacing:0.18em;text-transform:uppercase;color:#15294D;margin-bottom:14px;">Sécurité du compte</div>
<h1 class="em-h1" style="margin:0 0 20px;font-family:'Playfair Display',Georgia,'Times New Roman',serif;font-size:26px;font-weight:700;line-height:1.25;color:#0A1424;">Confirmez votre adresse e-mail</h1>
<p class="em-p" style="margin:0 0 16px;font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:15px;line-height:1.7;color:#1E293B;">Bonjour <strong>{{ $client_nom }}</strong>,<br><br>Merci de confirmer votre adresse e-mail <strong>{{ $client_email }}</strong> en cliquant sur le bouton ci-dessous. Ce lien est valable <strong>{{ $duree_validite }}</strong> (72 heures).</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:8px 0 24px;"><tr><td align="center" bgcolor="#15294D" class="em-cta-td" style="background-color:#15294D;"><!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $lien_verification }}" style="height:50px;v-text-anchor:middle;width:280px;" arcsize="0%" fillcolor="#15294D" stroke="f"><v:textbox inset="0,0,0,0"><center style="color:#FFFFFF;font-family:Arial,sans-serif;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">Vérifier mon adresse e-mail</center></v:textbox></v:roundrect><![endif]--><!--[if !mso]><!--><a href="{{ $lien_verification }}" class="em-btn" style="display:inline-block;background-color:#15294D;color:#FFFFFF;font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;text-decoration:none;padding:16px 32px;">Vérifier mon adresse e-mail</a><!--<![endif]--></td></tr></table>
<p class="em-muted" style="margin:0 0 16px;font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;line-height:1.65;color:#5A6478;">Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :<br><span style="overflow-wrap:anywhere;word-break:break-all;">{{ $lien_verification }}</span></p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 24px;"><tr><td class="em-div" style="border-top:1px solid #E2DDD5;"></td></tr></table>
<p class="em-muted" style="margin:0 0 16px;font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;line-height:1.65;color:#5A6478;">Si vous n'êtes pas à l'origine de cette inscription, ignorez cet e-mail : aucun compte ne sera activé sans cette confirmation.</p>
@endsection
