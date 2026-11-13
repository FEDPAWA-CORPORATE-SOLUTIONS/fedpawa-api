{{-- E-mail sortant admin : nouveau courrier --}}
@extends('emails.layout')

@section('titre_page')
{{ $sujet }}
@endsection

@section('preheader')
Nouveau message de FEDPAWA — {{ $sujet }}
@endsection

@section('content')
<div class="em-kicker" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:11px;font-weight:700;letter-spacing:0.18em;text-transform:uppercase;color:#15294D;margin-bottom:14px;">Courrier sortant — Administration</div>
<h1 class="em-h1" style="margin:0 0 20px;font-family:'Playfair Display',Georgia,'Times New Roman',serif;font-size:26px;font-weight:700;line-height:1.25;color:#0A1424;">{{ $sujet }}</h1>
<p class="em-p" style="margin:0 0 16px;font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:15px;line-height:1.7;color:#1E293B;">Bonjour,<br><br>{{ $corps_texte }}</p>
{!! $corps_html !!}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 24px;"><tr><td class="em-div" style="border-top:1px solid #E2DDD5;"></td></tr></table>
<p class="em-muted" style="margin:0 0 16px;font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:13px;line-height:1.65;color:#5A6478;">Ce message vous a été envoyé par l'administration FEDPAWA le {{ $date_envoi }}.<br>FEDPAWA Corporate Solutions — Immeuble Pharmacie de Logpom, Douala.</p>
@endsection
