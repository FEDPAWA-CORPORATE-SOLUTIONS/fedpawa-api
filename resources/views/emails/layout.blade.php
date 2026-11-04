<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>@yield('titre_page')</title>
<style>
/* Progressive enhancement UNIQUEMENT : un client qui strippe <style> garde le CSS inline clair. */
@@media (max-width:620px){
  .em-card{width:100% !important;}
  .em-pad{padding:30px 22px !important;}
  .em-head{padding:22px !important;}
  .em-foot{padding:28px 22px !important;}
  .em-h1{font-size:22px !important;line-height:1.3 !important;}
  .em-cta-td{width:100% !important;}
  .em-btn{display:block !important;width:100% !important;box-sizing:border-box !important;text-align:center !important;}
}
@@media (prefers-color-scheme:dark){
  .em-body{background-color:#0A1424 !important;}
  .em-card{background-color:#111C2E !important;border-color:#1E293B !important;}
  .em-head{background-color:#111C2E !important;border-bottom-color:#1E293B !important;}
  .em-word{color:#F1F5F9 !important;}
  .em-sub{color:#94A3B8 !important;}
  .em-h1{color:#F1F5F9 !important;}
  .em-kicker{color:#D4AF37 !important;}
  .em-p{color:#E2E8F0 !important;}
  .em-muted{color:#94A3B8 !important;}
  .em-box{background-color:#0A1424 !important;border-color:#1E293B !important;}
  .em-box-label{color:#94A3B8 !important;}
  .em-box-val{color:#E2E8F0 !important;}
  .em-total{border-top-color:#F1F5F9 !important;}
  .em-total-val{color:#D4AF37 !important;}
  .em-div{border-color:#1E293B !important;}
  .em-cta-td{background-color:#D4AF37 !important;}
  .em-btn{background-color:#D4AF37 !important;color:#0A1424 !important;}
  .em-link{color:#D4AF37 !important;border-bottom-color:#D4AF37 !important;}
  .em-foot{background-color:#020306 !important;}
  .em-foot-bar{background-color:#D4AF37 !important;}
  .em-foot-txt{color:#64748B !important;}
  .em-foot-link{color:#94A3B8 !important;}
  .em-banner-ok{background-color:#12261E !important;border-left-color:#7FBF9E !important;}
  .em-banner-ok-t{color:#7FBF9E !important;}
  .em-banner-ok-p{color:#E2E8F0 !important;}
  .em-banner-ko{background-color:#2A1712 !important;border-left-color:#E08573 !important;}
  .em-banner-ko-t{color:#E08573 !important;}
  .em-banner-ko-p{color:#E2E8F0 !important;}
  .em-banner-warn{background-color:#2A2410 !important;border-left-color:#D4B96A !important;}
  .em-banner-warn-t{color:#D4B96A !important;}
  .em-banner-warn-p{color:#E2E8F0 !important;}
}
</style>
</head>
<body class="em-body" style="margin:0;padding:0;background-color:#FAF9F6;">
<span style="display:none;max-height:0;overflow:hidden;mso-hide:all;">@yield('preheader')</span>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="em-body" style="background-color:#FAF9F6;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" class="em-card" style="width:100%;max-width:600px;background-color:#FFFFFF;border:1px solid #E2DDD5;">
<tr><td style="background-color:#D4AF37;height:3px;font-size:0;line-height:0;" class="em-foot-bar">&nbsp;</td></tr>
<tr><td class="em-head" style="padding:26px 40px;border-bottom:1px solid #E2DDD5;background-color:#FFFFFF;">
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr>
    <td width="48" valign="middle" style="width:48px;">
      <img src="https://app.fedpawa.com/icons/icon-512x512.png" width="48" height="48" alt="FEDPAWA" style="display:block;width:48px;height:48px;border:1px solid #D4AF37;border-radius:6px;">
    </td>
    <td valign="middle" style="padding-left:14px;">
      <div class="em-word" style="font-family:'Playfair Display',Georgia,'Times New Roman',serif;font-size:22px;font-weight:800;letter-spacing:0.04em;color:#0A1424;line-height:1.1;">FEDPAWA</div>
      <div class="em-sub" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:10px;font-weight:600;letter-spacing:0.32em;text-transform:uppercase;color:#5A6478;margin-top:4px;">Corporate Solutions</div>
    </td>
  </tr></table>
</td></tr>
<tr><td class="em-pad" style="padding:40px;">
@yield('content')
</td></tr>
<tr><td class="em-div" style="border-top:1px solid #E2DDD5;"></td></tr>
<tr><td class="em-foot" style="padding:30px 40px;background-color:#05080F;">
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr>
    <td width="32" valign="top" style="width:32px;">
      <img src="https://app.fedpawa.com/icons/icon-512x512.png" width="32" height="32" alt="" style="display:block;width:32px;height:32px;border:1px solid #D4AF37;border-radius:6px;">
    </td>
    <td valign="top" style="padding-left:12px;">
      <div class="em-foot-txt" style="font-family:'Playfair Display',Georgia,serif;font-size:14px;font-weight:800;letter-spacing:0.04em;color:#FFFFFF;">FEDPAWA CORPORATE SOLUTIONS</div>
      <div class="em-foot-txt" style="font-family:'Open Sans','Helvetica Neue',Arial,sans-serif;font-size:12px;line-height:1.7;color:#94A3B8;margin-top:10px;">
        <a href="mailto:contact@fedpawa.com" class="em-foot-link" style="color:#D8D4CE;text-decoration:none;">contact@fedpawa.com</a>
        &nbsp;·&nbsp; +237 691 70 48 02 &nbsp;·&nbsp; Douala · Logpom · Cameroun<br>
        <span class="em-foot-txt" style="color:#64748B;">Vous recevez cet e-mail transactionnel à l'adresse <strong>{{ $client_email }}</strong> parce que vous avez un compte FEDPAWA CORPORATE SOLUTIONS. Les e-mails transactionnels (vérification, sécurité, documents, paiements) ne comportent pas de lien de désabonnement.</span><br>
        <span class="em-foot-txt" style="color:#64748B;">© {{ $annee }} FEDPAWA CORPORATE SOLUTIONS — </span>
        <a href="https://app.fedpawa.com/cgu" class="em-foot-link" style="color:#D8D4CE;text-decoration:none;">CGU</a>
        <span class="em-foot-txt" style="color:#64748B;"> · </span>
        <a href="https://app.fedpawa.com/politique-confidentialite" class="em-foot-link" style="color:#D8D4CE;text-decoration:none;">Politique de confidentialité</a>
        <span class="em-foot-txt" style="color:#64748B;"> · Ne répondez pas à ce message : écrivez à contact@fedpawa.com.</span>
      </div>
    </td>
  </tr></table>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
