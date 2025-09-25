import mimetypes
from core.env import *
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from email.mime.application import MIMEApplication
from email import encoders
from io import BytesIO
from html import escape
import smtplib
import string
import ssl


def send_normal_update_email(
    email_title: str, title: str, email: str, details: str
) -> None:
    with smtplib.SMTP_SSL(
        smtp_server, 465, context=ssl.create_default_context()
    ) as server:
        server.login(smtp_email, smtp_password)
        message = MIMEMultipart("alternative")
        message["Subject"] = f"[HFI-UC] {email_title}"
        message["From"] = f"HFI-UC <{smtp_email}>"
        message["To"] = email
        template = """
<!--
* This email was built using Tabular.
* For more information, visit https://tabular.email
--><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd"><html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" lang="en"><head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><title></title>


<!--[if !mso]>-->
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<!--<![endif]-->
<meta name="x-apple-disable-message-reformatting" content="">
<meta content="target-densitydpi=device-dpi" name="viewport">
<meta content="true" name="HandheldFriendly">
<meta content="width=device-width" name="viewport">
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
<style type="text/css">
table {
border-collapse: separate;
table-layout: fixed;
mso-table-lspace: 0pt;
mso-table-rspace: 0pt
}
table td {
border-collapse: collapse
}
.ExternalClass {
width: 100%
}
.ExternalClass,
.ExternalClass p,
.ExternalClass span,
.ExternalClass font,
.ExternalClass td,
.ExternalClass div {
line-height: 100%
}
body, a, li, p, h1, h2, h3 {
-ms-text-size-adjust: 100%;
-webkit-text-size-adjust: 100%;
}
html {
-webkit-text-size-adjust: none !important
}
body {
min-width: 100%;
Margin: 0px;
padding: 0px;
}
body, #innerTable {
-webkit-font-smoothing: antialiased;
-moz-osx-font-smoothing: grayscale
}
#innerTable img+div {
display: none;
display: none !important
}
img {
Margin: 0;
padding: 0;
-ms-interpolation-mode: bicubic
}
h1, h2, h3, p, a {
line-height: inherit;
overflow-wrap: normal;
white-space: normal;
word-break: break-word
}
a {
text-decoration: none
}
h1, h2, h3, p {
min-width: 100%!important;
width: 100%!important;
max-width: 100%!important;
display: inline-block!important;
border: 0;
padding: 0;
margin: 0
}
a[x-apple-data-detectors] {
color: inherit !important;
text-decoration: none !important;
font-size: inherit !important;
font-family: inherit !important;
font-weight: inherit !important;
line-height: inherit !important
}
u + #body a {
color: inherit;
text-decoration: none;
font-size: inherit;
font-family: inherit;
font-weight: inherit;
line-height: inherit;
}
a[href^="mailto"],
a[href^="tel"],
a[href^="sms"] {
color: inherit;
text-decoration: none
}
</style>
<style type="text/css">
@media (min-width: 481px) {
.hd { display: none!important }
}
</style>
<style type="text/css">
@media (max-width: 480px) {
.hm { display: none!important }
}
</style>
<style type="text/css">
@media (max-width: 480px) {
.t23,.t28{mso-line-height-alt:0px!important;line-height:0!important;display:none!important}.t24{padding:40px!important;border-radius:0!important}.t18{mso-line-height-alt:46px!important;line-height:46px!important}
}
</style>
<!--[if !mso]>-->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&amp;display=swap" rel="stylesheet" type="text/css">
<!--<![endif]-->
<!--[if mso]>
<xml>
<o:OfficeDocumentSettings>
<o:AllowPNG/>
<o:PixelsPerInch>96</o:PixelsPerInch>
</o:OfficeDocumentSettings>
</xml>
<![endif]-->
</head>
<body id="body" class="t31" style="min-width:100%;Margin:0px;padding:0px;background-color:#FFFFFF;"><div class="t30" style="background-color:#FFFFFF;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td class="t29" style="font-size:0;line-height:0;mso-line-height-rule:exactly;background-color:#FFFFFF;" valign="top" align="center">
<!--[if mso]>
<v:background xmlns:v="urn:schemas-microsoft-com:vml" fill="true" stroke="false">
<v:fill color="#FFFFFF"/>
</v:background>
<![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" align="center" id="innerTable"><tr><td><div class="t23" style="mso-line-height-rule:exactly;mso-line-height-alt:50px;line-height:50px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t27" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="600" class="t26" style="width:600px;">
<table class="t25" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t24" style="border:1px solid #EBEBEB;overflow:hidden;background-color:#FFFFFF;padding:44px 42px 32px 42px;border-radius:25px 25px 25px 25px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100% !important;"><tr><td align="left">
<table class="t4" role="presentation" cellpadding="0" cellspacing="0" style="Margin-right:auto;"><tr><td width="45" class="t3" style="width:45px;">
<table class="t2" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t1"><div style="font-size:0px;"><img class="t0" style="display:block;border:0;height:auto;width:100%;Margin:0;max-width:100%;" width="45" height="45" alt="" src="https://s21.ax1x.com/2025/09/25/pV5T6mt.png"></div></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t5" style="mso-line-height-rule:exactly;mso-line-height-alt:42px;line-height:42px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t10" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="514" class="t9" style="width:665px;">
<table class="t8" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t7" style="border-bottom:1px solid #EFF1F4;padding:0 0 18px 0;"><h1 class="t6" style="margin:0;Margin:0;font-family:Inter,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:28px;font-weight:700;font-style:normal;font-size:24px;text-decoration:none;text-transform:none;letter-spacing:-1px;direction:ltr;color:#141414;text-align:left;mso-line-height-rule:exactly;mso-text-raise:1px;">${title}</h1></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t11" style="mso-line-height-rule:exactly;mso-line-height-alt:18px;line-height:18px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t16" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="514" class="t15" style="width:600px;">
<table class="t14" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t13"><p class="t12" style="margin:0;Margin:0;font-family:Inter,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:25px;font-weight:400;font-style:normal;font-size:15px;text-decoration:none;text-transform:none;letter-spacing:-0.1px;direction:ltr;color:#141414;text-align:left;mso-line-height-rule:exactly;mso-text-raise:3px;">${details}</p></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t18" style="mso-line-height-rule:exactly;mso-line-height-alt:25px;line-height:25px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t22" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="514" class="t21" style="width:600px;">
<table class="t20" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t19" style="overflow:hidden;background-color:#F97316;border-radius:12px 12px 12px 12px;"><p class="t17" style="margin:0;Margin:0;font-family:Inter,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:70px;font-weight:400;font-style:normal;font-size:16px;text-decoration:none;text-transform:none;direction:ltr;color:#FFFFFF;text-align:center;mso-line-height-rule:exactly;mso-text-raise:16px;">Copyright © 2025 MAKERs'.</p></td></tr></table>
</td></tr></table>
</td></tr></table></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t28" style="mso-line-height-rule:exactly;mso-line-height-alt:50px;line-height:50px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr></table></td></tr></table></div><div class="gmail-fix" style="display: none; white-space: nowrap; font: 15px courier; line-height: 0;">&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;</div><img src="https://ea.pstmrk.it/open?m=v3_1.Q-wBCg89KvUnfri_FH_JXw.PCxttqC_dMJG1C4iNGXD1-GdhR3yJjDI0OWjDkBc0VV3xHkpWGGFY_xZKR7LlFAy1DmUF3SDpxrlCt5lceB3qHllEYybxfJD_PGGDRcd_1GUOyqs4XyMifyyps3NRxfnAAoHSOINMvTCOj9nJJzRxgmkkC1KqyjmnKUsHzpgHWRm49gwspsjvpiI-oi3U12cZwrdpOnItgdu3AVVtGjIg1t8dZ_3enjHRrjuofPIG4g7o3cbcoBMpfDdR15GFhzrMeByOjs912Z8RAhdEKi-99Efgse-TLXX3N-IPoRPYQDS1w2byVEu2NwGlUuqdlI_itVmfDPdiSPRSRaKH4_buRGyLOglDbp2pOhuVzlp3owwghHBnbkma_Vy5Xvl8Dit2gazUjrC78M8sH1FjOgmQYHrBpffw98dWACK5AU9TreNkCb1xOsJ35tLreHtEeMvnOIkZhurgdOx4roRplaUUrg0SNZt-Vogg9ZAtp-5yk3hbYdg2eHsEUvxvv_G4xQhNKs6nT3WD1BLZ5bKUCJGCQ" width="1" height="1" border="0" alt=""></body>
</html>
"""
        mail_body = string.Template(template).safe_substitute(
            {"title": title, "details": details}
        )
        part = MIMEText(mail_body, "html", "utf-8")
        message.attach(part)
        server.sendmail(smtp_email, email, message.as_string())


def send_reservation_approval_email(
    email_title: str,
    title: str,
    email: str,
    details: str,
    user: str,
    room: str,
    class_name: str,
    student_id: str,
    reason: str,
    time: str,
) -> None:
    with smtplib.SMTP_SSL(
        smtp_server, 465, context=ssl.create_default_context()
    ) as server:
        server.login(smtp_email, smtp_password)
        message = MIMEMultipart("alternative")
        message["Subject"] = f"[HFI-UC] {email_title}"
        message["From"] = f"HFI-UC <{smtp_email}>"
        message["To"] = email
        template = """
<!--
* This email was built using Tabular.
* For more information, visit https://tabular.email
--><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd"><html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" lang="en"><head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><title></title>


<!--[if !mso]>-->
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<!--<![endif]-->
<meta name="x-apple-disable-message-reformatting" content="">
<meta content="target-densitydpi=device-dpi" name="viewport">
<meta content="true" name="HandheldFriendly">
<meta content="width=device-width" name="viewport">
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
<style type="text/css">
table {
border-collapse: separate;
table-layout: fixed;
mso-table-lspace: 0pt;
mso-table-rspace: 0pt
}
table td {
border-collapse: collapse
}
.ExternalClass {
width: 100%
}
.ExternalClass,
.ExternalClass p,
.ExternalClass span,
.ExternalClass font,
.ExternalClass td,
.ExternalClass div {
line-height: 100%
}
body, a, li, p, h1, h2, h3 {
-ms-text-size-adjust: 100%;
-webkit-text-size-adjust: 100%;
}
html {
-webkit-text-size-adjust: none !important
}
body {
min-width: 100%;
Margin: 0px;
padding: 0px;
}
body, #innerTable {
-webkit-font-smoothing: antialiased;
-moz-osx-font-smoothing: grayscale
}
#innerTable img+div {
display: none;
display: none !important
}
img {
Margin: 0;
padding: 0;
-ms-interpolation-mode: bicubic
}
h1, h2, h3, p, a {
line-height: inherit;
overflow-wrap: normal;
white-space: normal;
word-break: break-word
}
a {
text-decoration: none
}
h1, h2, h3, p {
min-width: 100%!important;
width: 100%!important;
max-width: 100%!important;
display: inline-block!important;
border: 0;
padding: 0;
margin: 0
}
a[x-apple-data-detectors] {
color: inherit !important;
text-decoration: none !important;
font-size: inherit !important;
font-family: inherit !important;
font-weight: inherit !important;
line-height: inherit !important
}
u + #body a {
color: inherit;
text-decoration: none;
font-size: inherit;
font-family: inherit;
font-weight: inherit;
line-height: inherit;
}
a[href^="mailto"],
a[href^="tel"],
a[href^="sms"] {
color: inherit;
text-decoration: none
}
</style>
<style type="text/css">
@media (min-width: 481px) {
.hd { display: none!important }
}
</style>
<style type="text/css">
@media (max-width: 480px) {
.hm { display: none!important }
}
</style>
<style type="text/css">
@media (max-width: 480px) {
.t134,.t139{mso-line-height-alt:0px!important;line-height:0!important;display:none!important}.t118,.t119{display:block!important}.t135{padding:40px!important;border-radius:0!important}.t129{mso-line-height-alt:33px!important;line-height:33px!important}.t118{text-align:left!important}.t117,.t67{vertical-align:top!important;display:inline-block!important;width:100%!important;max-width:800px!important}.t65{padding-bottom:15px!important;padding-right:0!important}.t115{padding-left:0!important}
}
</style>
<!--[if !mso]>-->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&amp;family=Inter+Tight:wght@500;600&amp;display=swap" rel="stylesheet" type="text/css">
<!--<![endif]-->
<!--[if mso]>
<xml>
<o:OfficeDocumentSettings>
<o:AllowPNG/>
<o:PixelsPerInch>96</o:PixelsPerInch>
</o:OfficeDocumentSettings>
</xml>
<![endif]-->
</head>
<body id="body" class="t142" style="min-width:100%;Margin:0px;padding:0px;background-color:#FFFFFF;"><div class="t141" style="background-color:#FFFFFF;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td class="t140" style="font-size:0;line-height:0;mso-line-height-rule:exactly;background-color:#FFFFFF;" valign="top" align="center">
<!--[if mso]>
<v:background xmlns:v="urn:schemas-microsoft-com:vml" fill="true" stroke="false">
<v:fill color="#FFFFFF"/>
</v:background>
<![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" align="center" id="innerTable"><tr><td><div class="t134" style="mso-line-height-rule:exactly;mso-line-height-alt:50px;line-height:50px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t138" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="600" class="t137" style="width:600px;">
<table class="t136" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t135" style="border:1px solid #EBEBEB;overflow:hidden;background-color:#FFFFFF;padding:44px 42px 32px 42px;border-radius:25px 25px 25px 25px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100% !important;"><tr><td align="left">
<table class="t4" role="presentation" cellpadding="0" cellspacing="0" style="Margin-right:auto;"><tr><td width="45" class="t3" style="width:45px;">
<table class="t2" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t1"><div style="font-size:0px;"><img class="t0" style="display:block;border:0;height:auto;width:100%;Margin:0;max-width:100%;" width="45" height="45" alt="" src="https://s21.ax1x.com/2025/09/25/pV5T6mt.png"></div></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t5" style="mso-line-height-rule:exactly;mso-line-height-alt:42px;line-height:42px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t10" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="514" class="t9" style="width:665px;">
<table class="t8" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t7" style="border-bottom:1px solid #EFF1F4;padding:0 0 18px 0;"><h1 class="t6" style="margin:0;Margin:0;font-family:Inter,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:28px;font-weight:700;font-style:normal;font-size:24px;text-decoration:none;text-transform:none;letter-spacing:-1px;direction:ltr;color:#141414;text-align:left;mso-line-height-rule:exactly;mso-text-raise:1px;">${title}</h1></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t11" style="mso-line-height-rule:exactly;mso-line-height-alt:18px;line-height:18px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t16" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="514" class="t15" style="width:600px;">
<table class="t14" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t13"><p class="t12" style="margin:0;Margin:0;font-family:Inter,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:25px;font-weight:400;font-style:normal;font-size:15px;text-decoration:none;text-transform:none;letter-spacing:-0.1px;direction:ltr;color:#141414;text-align:left;mso-line-height-rule:exactly;mso-text-raise:3px;">${details}</p></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t17" style="mso-line-height-rule:exactly;mso-line-height-alt:18px;line-height:18px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td><div class="t122" style="mso-line-height-rule:exactly;mso-line-height-alt:5px;line-height:5px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t126" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="514" class="t125" style="width:600px;">
<table class="t124" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t123" style="overflow:hidden;background-color:#F6F6F6;padding:30px 40px 30px 40px;border-radius:12px 12px 12px 12px;"><div class="t121" style="width:100%;text-align:left;"><div class="t120" style="display:inline-block;"><table class="t119" role="presentation" cellpadding="0" cellspacing="0" align="left" valign="top">
<tr class="t118"><td></td><td class="t67" width="217" valign="top">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="t66" style="width:100%;"><tr><td class="t65" style="padding:0 5px 0 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100% !important;"><tr><td align="center">
<table class="t32" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="212" class="t31" style="width:800px;">
<table class="t30" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t29"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100% !important;"><tr><td align="center">
<table class="t22" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="212" class="t21" style="width:600px;">
<table class="t20" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t19"><p class="t18" style="margin:0;Margin:0;font-family:Inter Tight,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:22px;font-weight:600;font-style:normal;font-size:16px;text-decoration:none;text-transform:none;direction:ltr;color:#333333;text-align:left;mso-line-height-rule:exactly;mso-text-raise:2px;">Student Name</p></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t24" style="mso-line-height-rule:exactly;mso-line-height-alt:5px;line-height:5px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t28" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="212" class="t27" style="width:600px;">
<table class="t26" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t25"><p class="t23" style="margin:0;Margin:0;font-family:Inter Tight,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:22px;font-weight:500;font-style:normal;font-size:16px;text-decoration:none;text-transform:none;direction:ltr;color:#787878;text-align:left;mso-line-height-rule:exactly;mso-text-raise:2px;">${user}</p></td></tr></table>
</td></tr></table>
</td></tr></table></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t44" style="mso-line-height-rule:exactly;mso-line-height-alt:15px;line-height:15px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t48" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="212" class="t47" style="width:800px;">
<table class="t46" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t45"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100% !important;"><tr><td align="center">
<table class="t37" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="212" class="t36" style="width:600px;">
<table class="t35" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t34"><p class="t33" style="margin:0;Margin:0;font-family:Inter Tight,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:22px;font-weight:600;font-style:normal;font-size:16px;text-decoration:none;text-transform:none;direction:ltr;color:#333333;text-align:left;mso-line-height-rule:exactly;mso-text-raise:2px;">Room</p></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t39" style="mso-line-height-rule:exactly;mso-line-height-alt:5px;line-height:5px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t43" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="212" class="t42" style="width:600px;">
<table class="t41" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t40"><p class="t38" style="margin:0;Margin:0;font-family:Inter Tight,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:22px;font-weight:500;font-style:normal;font-size:16px;text-decoration:none;text-transform:none;direction:ltr;color:#787878;text-align:left;mso-line-height-rule:exactly;mso-text-raise:2px;">${room}</p></td></tr></table>
</td></tr></table>
</td></tr></table></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t60" style="mso-line-height-rule:exactly;mso-line-height-alt:15px;line-height:15px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t64" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="212" class="t63" style="width:1099px;">
<table class="t62" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t61"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100% !important;"><tr><td align="center">
<table class="t53" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="212" class="t52" style="width:600px;">
<table class="t51" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t50"><p class="t49" style="margin:0;Margin:0;font-family:Inter Tight,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:22px;font-weight:600;font-style:normal;font-size:16px;text-decoration:none;text-transform:none;direction:ltr;color:#333333;text-align:left;mso-line-height-rule:exactly;mso-text-raise:2px;">Reason</p></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t55" style="mso-line-height-rule:exactly;mso-line-height-alt:5px;line-height:5px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t59" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="212" class="t58" style="width:600px;">
<table class="t57" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t56"><p class="t54" style="margin:0;Margin:0;font-family:Inter Tight,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:22px;font-weight:500;font-style:normal;font-size:16px;text-decoration:none;text-transform:none;direction:ltr;color:#787878;text-align:left;mso-line-height-rule:exactly;mso-text-raise:2px;">${reason}</p></td></tr></table>
</td></tr></table>
</td></tr></table></td></tr></table>
</td></tr></table>
</td></tr></table></td></tr></table>
</td><td class="t117" width="217" valign="top">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="t116" style="width:100%;"><tr><td class="t115" style="padding:0 0 0 5px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100% !important;"><tr><td align="center">
<table class="t82" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="212" class="t81" style="width:800px;">
<table class="t80" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t79"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100% !important;"><tr><td align="center">
<table class="t72" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="212" class="t71" style="width:600px;">
<table class="t70" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t69"><p class="t68" style="margin:0;Margin:0;font-family:Inter Tight,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:22px;font-weight:600;font-style:normal;font-size:16px;text-decoration:none;text-transform:none;direction:ltr;color:#333333;text-align:left;mso-line-height-rule:exactly;mso-text-raise:2px;">Student Class</p></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t74" style="mso-line-height-rule:exactly;mso-line-height-alt:5px;line-height:5px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t78" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="212" class="t77" style="width:600px;">
<table class="t76" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t75"><p class="t73" style="margin:0;Margin:0;font-family:Inter Tight,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:22px;font-weight:500;font-style:normal;font-size:16px;text-decoration:none;text-transform:none;direction:ltr;color:#787878;text-align:left;mso-line-height-rule:exactly;mso-text-raise:2px;">${class}</p></td></tr></table>
</td></tr></table>
</td></tr></table></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t94" style="mso-line-height-rule:exactly;mso-line-height-alt:15px;line-height:15px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t98" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="212" class="t97" style="width:800px;">
<table class="t96" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t95"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100% !important;"><tr><td align="center">
<table class="t87" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="212" class="t86" style="width:600px;">
<table class="t85" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t84"><p class="t83" style="margin:0;Margin:0;font-family:Inter Tight,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:22px;font-weight:600;font-style:normal;font-size:16px;text-decoration:none;text-transform:none;direction:ltr;color:#333333;text-align:left;mso-line-height-rule:exactly;mso-text-raise:2px;">Student ID</p></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t89" style="mso-line-height-rule:exactly;mso-line-height-alt:5px;line-height:5px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t93" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="212" class="t92" style="width:600px;">
<table class="t91" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t90"><p class="t88" style="margin:0;Margin:0;font-family:Inter Tight,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:22px;font-weight:500;font-style:normal;font-size:16px;text-decoration:none;text-transform:none;direction:ltr;color:#787878;text-align:left;mso-line-height-rule:exactly;mso-text-raise:2px;">${student_id}</p></td></tr></table>
</td></tr></table>
</td></tr></table></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t110" style="mso-line-height-rule:exactly;mso-line-height-alt:15px;line-height:15px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t114" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="212" class="t113" style="width:800px;">
<table class="t112" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t111"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100% !important;"><tr><td align="center">
<table class="t103" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="212" class="t102" style="width:600px;">
<table class="t101" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t100"><p class="t99" style="margin:0;Margin:0;font-family:Inter Tight,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:22px;font-weight:600;font-style:normal;font-size:16px;text-decoration:none;text-transform:none;direction:ltr;color:#333333;text-align:left;mso-line-height-rule:exactly;mso-text-raise:2px;">Time Period</p></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t105" style="mso-line-height-rule:exactly;mso-line-height-alt:5px;line-height:5px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t109" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="212" class="t108" style="width:600px;">
<table class="t107" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t106"><p class="t104" style="margin:0;Margin:0;font-family:Inter Tight,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:22px;font-weight:500;font-style:normal;font-size:16px;text-decoration:none;text-transform:none;direction:ltr;color:#787878;text-align:left;mso-line-height-rule:exactly;mso-text-raise:2px;">${time}</p></td></tr></table>
</td></tr></table>
</td></tr></table></td></tr></table>
</td></tr></table>
</td></tr></table></td></tr></table>
</td>
<td></td></tr>
</table></div></div></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t127" style="mso-line-height-rule:exactly;mso-line-height-alt:5px;line-height:5px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td><div class="t129" style="mso-line-height-rule:exactly;mso-line-height-alt:25px;line-height:25px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t133" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="514" class="t132" style="width:600px;">
<table class="t131" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t130" style="overflow:hidden;background-color:#F97316;border-radius:12px 12px 12px 12px;"><p class="t128" style="margin:0;Margin:0;font-family:Inter,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:70px;font-weight:400;font-style:normal;font-size:16px;text-decoration:none;text-transform:none;direction:ltr;color:#FFFFFF;text-align:center;mso-line-height-rule:exactly;mso-text-raise:16px;">Copyright © 2025 MAKERs'.</p></td></tr></table>
</td></tr></table>
</td></tr></table></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t139" style="mso-line-height-rule:exactly;mso-line-height-alt:50px;line-height:50px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr></table></td></tr></table></div><div class="gmail-fix" style="display: none; white-space: nowrap; font: 15px courier; line-height: 0;">&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;</div><img src="https://ea.pstmrk.it/open?m=v3_1.Ka96G_HojF3aPY1pFw7bRg.2FtJXMfWOi_rzEZwTO0-eHqZaz0nIJ6C3BaXAaTEnDfPkGZWTaHlNZN6SF5-a4cNf0dOeQYnn_esp_dvkKVm--Ppd0syaGLNE3hyjD5LzQ3QbzsT9YB1IcmhGuuwnq5-fX68H-8GaWA2g5E7VvRFle-uROWmdnGuWJegEkHRALk9JS63kV7-TnhFTohIcqa9_8AvDMLXOgAU8cM9Ugcj2NCaJKY_AVsje8VawW8SgzAC5F_x0fTPC_ls7pmQHwbcvobGaAy1DNnX3-Y_To0etJx11JKSsIJt6F5Y56jyu3eacvm1svrGojQra3uvTGEnFu6-7xlMuJ7rP4YwTWVC4S_QzGbT2g1GDZ53Ks8By3RcbwrWy9cT73UAUjNNPoyhbEZL3NsnCe95EBQQtTMBatN_xqakf_d4iSmMveHvyFF6ek77VMduORwcUESNPYarLRCpkd08p0jT5xRddtDbgWsoE_d2xvWhnqtm-1oILa8QPLOZIMUinpbXTqTQbtK7M4f6DATjjjRCQ3f5mkmdGrGgtTr_3aOjcy4hmAPGM9o" width="1" height="1" border="0" alt=""></body>
</html>
"""
        mail_body = string.Template(template).safe_substitute(
            {
                "title": title,
                "user": escape(user),
                "details": details,
                "room": room,
                "class": class_name,
                "student_id": escape(student_id),
                "reason": escape(reason),
                "time": time,
            }
        )
        part = MIMEText(mail_body, "html", "utf-8")
        message.attach(part)
        server.sendmail(smtp_email, email, message.as_string())


def send_normal_update_with_external_link_email(
    email_title: str, title: str, email: str, details: str, button_text: str, link: str
) -> None:
    with smtplib.SMTP_SSL(
        smtp_server, 465, context=ssl.create_default_context()
    ) as server:
        server.login(smtp_email, smtp_password)
        message = MIMEMultipart("alternative")
        message["Subject"] = f"[HFI-UC] {email_title}"
        message["From"] = f"HFI-UC <{smtp_email}>"
        message["To"] = email
        template = """
<!--
* This email was built using Tabular.
* For more information, visit https://tabular.email
--><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd"><html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" lang="en"><head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><title></title>


<!--[if !mso]>-->
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<!--<![endif]-->
<meta name="x-apple-disable-message-reformatting" content="">
<meta content="target-densitydpi=device-dpi" name="viewport">
<meta content="true" name="HandheldFriendly">
<meta content="width=device-width" name="viewport">
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
<style type="text/css">
table {
border-collapse: separate;
table-layout: fixed;
mso-table-lspace: 0pt;
mso-table-rspace: 0pt
}
table td {
border-collapse: collapse
}
.ExternalClass {
width: 100%
}
.ExternalClass,
.ExternalClass p,
.ExternalClass span,
.ExternalClass font,
.ExternalClass td,
.ExternalClass div {
line-height: 100%
}
body, a, li, p, h1, h2, h3 {
-ms-text-size-adjust: 100%;
-webkit-text-size-adjust: 100%;
}
html {
-webkit-text-size-adjust: none !important
}
body {
min-width: 100%;
Margin: 0px;
padding: 0px;
}
body, #innerTable {
-webkit-font-smoothing: antialiased;
-moz-osx-font-smoothing: grayscale
}
#innerTable img+div {
display: none;
display: none !important
}
img {
Margin: 0;
padding: 0;
-ms-interpolation-mode: bicubic
}
h1, h2, h3, p, a {
line-height: inherit;
overflow-wrap: normal;
white-space: normal;
word-break: break-word
}
a {
text-decoration: none
}
h1, h2, h3, p {
min-width: 100%!important;
width: 100%!important;
max-width: 100%!important;
display: inline-block!important;
border: 0;
padding: 0;
margin: 0
}
a[x-apple-data-detectors] {
color: inherit !important;
text-decoration: none !important;
font-size: inherit !important;
font-family: inherit !important;
font-weight: inherit !important;
line-height: inherit !important
}
u + #body a {
color: inherit;
text-decoration: none;
font-size: inherit;
font-family: inherit;
font-weight: inherit;
line-height: inherit;
}
a[href^="mailto"],
a[href^="tel"],
a[href^="sms"] {
color: inherit;
text-decoration: none
}
</style>
<style type="text/css">
@media (min-width: 481px) {
.hd { display: none!important }
}
</style>
<style type="text/css">
@media (max-width: 480px) {
.hm { display: none!important }
}
</style>
<style type="text/css">
@media (max-width: 480px) {
.t29,.t34{mso-line-height-alt:0px!important;line-height:0!important;display:none!important}.t30{padding:40px!important;border-radius:0!important}.t24{mso-line-height-alt:46px!important;line-height:46px!important}
}
</style>
<!--[if !mso]>-->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&amp;family=Inter+Tight:wght@700&amp;display=swap" rel="stylesheet" type="text/css">
<!--<![endif]-->
<!--[if mso]>
<xml>
<o:OfficeDocumentSettings>
<o:AllowPNG/>
<o:PixelsPerInch>96</o:PixelsPerInch>
</o:OfficeDocumentSettings>
</xml>
<![endif]-->
</head>
<body id="body" class="t37" style="min-width:100%;Margin:0px;padding:0px;background-color:#FFFFFF;"><div class="t36" style="background-color:#FFFFFF;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td class="t35" style="font-size:0;line-height:0;mso-line-height-rule:exactly;background-color:#FFFFFF;" valign="top" align="center">
<!--[if mso]>
<v:background xmlns:v="urn:schemas-microsoft-com:vml" fill="true" stroke="false">
<v:fill color="#FFFFFF"/>
</v:background>
<![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" align="center" id="innerTable"><tr><td><div class="t29" style="mso-line-height-rule:exactly;mso-line-height-alt:50px;line-height:50px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t33" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="600" class="t32" style="width:600px;">
<table class="t31" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t30" style="border:1px solid #EBEBEB;overflow:hidden;background-color:#FFFFFF;padding:44px 42px 32px 42px;border-radius:25px 25px 25px 25px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100% !important;"><tr><td align="left">
<table class="t4" role="presentation" cellpadding="0" cellspacing="0" style="Margin-right:auto;"><tr><td width="45" class="t3" style="width:45px;">
<table class="t2" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t1"><div style="font-size:0px;"><img class="t0" style="display:block;border:0;height:auto;width:100%;Margin:0;max-width:100%;" width="45" height="45" alt="" src="https://s21.ax1x.com/2025/09/25/pV5T6mt.png"></div></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t5" style="mso-line-height-rule:exactly;mso-line-height-alt:42px;line-height:42px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t10" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="514" class="t9" style="width:665px;">
<table class="t8" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t7" style="border-bottom:1px solid #EFF1F4;padding:0 0 18px 0;"><h1 class="t6" style="margin:0;Margin:0;font-family:Inter,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:28px;font-weight:700;font-style:normal;font-size:24px;text-decoration:none;text-transform:none;letter-spacing:-1px;direction:ltr;color:#141414;text-align:left;mso-line-height-rule:exactly;mso-text-raise:1px;">${title}</h1></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t11" style="mso-line-height-rule:exactly;mso-line-height-alt:18px;line-height:18px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t16" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="514" class="t15" style="width:600px;">
<table class="t14" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t13"><p class="t12" style="margin:0;Margin:0;font-family:Inter,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:25px;font-weight:400;font-style:normal;font-size:15px;text-decoration:none;text-transform:none;letter-spacing:-0.1px;direction:ltr;color:#141414;text-align:left;mso-line-height-rule:exactly;mso-text-raise:3px;">${details}</p></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t18" style="mso-line-height-rule:exactly;mso-line-height-alt:20px;line-height:20px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="left">
<table class="t22" role="presentation" cellpadding="0" cellspacing="0" style="Margin-right:auto;"><tr><td width="161" class="t21" style="width:161px;">
<table class="t20" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t19" style="overflow:hidden;background-color:#F97316;text-align:center;line-height:24px;mso-line-height-rule:exactly;mso-text-raise:3px;padding:10px 10px 10px 10px;border-radius:15px 15px 15px 15px;"><a class="t17" href="${link}" style="display:block;margin:0;Margin:0;font-family:Inter Tight,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:24px;font-weight:700;font-style:normal;font-size:15px;text-decoration:none;direction:ltr;color:#FFFFFF;text-align:center;mso-line-height-rule:exactly;mso-text-raise:3px;" target="_blank">${button_text}</a></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t24" style="mso-line-height-rule:exactly;mso-line-height-alt:25px;line-height:25px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t28" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="514" class="t27" style="width:600px;">
<table class="t26" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t25" style="overflow:hidden;background-color:#F97316;border-radius:12px 12px 12px 12px;"><p class="t23" style="margin:0;Margin:0;font-family:Inter,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:70px;font-weight:400;font-style:normal;font-size:16px;text-decoration:none;text-transform:none;direction:ltr;color:#FFFFFF;text-align:center;mso-line-height-rule:exactly;mso-text-raise:16px;">Copyright © 2025 MAKERs'.</p></td></tr></table>
</td></tr></table>
</td></tr></table></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t34" style="mso-line-height-rule:exactly;mso-line-height-alt:50px;line-height:50px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr></table></td></tr></table></div><div class="gmail-fix" style="display: none; white-space: nowrap; font: 15px courier; line-height: 0;">&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;</div><img src="https://ea.pstmrk.it/open?m=v3_1.5jiKMGfBDsAfl2KySg6fSg.zk1dRiIkNfKPcnxNLiW8aNZsaMcCGW1o7z5svKHi_U1dJD_wYrVAFcQFh1faxCglSPtwdwTRr1OtTRJXCVE9c2UziRpRQuRxoBZPIDNsyBo8cpAtANvTG4nQA1jeLQriluJeAcRn29KapjlkHbZ8MGDZxVHTXYEHojDCLUBf2FMe4KAdkBP1kbvQqJhSHUDUhJDJnx59cTTB9cusuy6qXMUBiICHyBILNQ1MKxAXFLl_Km5mRcrdc7R6q_hefPKWlvAFwYB8c84Q-203fuMTCd3kPV5kODFcirDVjek0BdTwm_h2hWWyDcVXdd_Ofnx29fQZZDPHb1RInfXfPgNRfHvYD7EmfLDotZFy37vFyqyvsPWb9XLsaVscG_flWa0NG4gAVQb5MqJPHA6_OmolGX-_53Ei13HXDCHZjltw3fUcXoGs1j5sNIPklm4CgGM6yby3KC9qzGCqiGRqQDHSc10Xvr9eLQXJirV1t9cIJRqGVS3laaMwbMprKQoGPkvlrNmJLwqYpim18mp7zZnU3A" width="1" height="1" border="0" alt=""></body>
</html>
"""
        mail_body = string.Template(template).safe_substitute(
            {
                "title": title,
                "details": details,
                "button_text": button_text,
                "link": link,
            }
        )
        part = MIMEText(mail_body, "html", "utf-8")
        message.attach(part)
        server.sendmail(smtp_email, email, message.as_string())


def send_normal_update_email_with_attached_files(
    email_title: str,
    title: str,
    email: str,
    details: str,
    attachments: list[tuple[str, BytesIO]] | None = None,
) -> None:
    with smtplib.SMTP_SSL(
        smtp_server, 465, context=ssl.create_default_context()
    ) as server:
        server.login(smtp_email, smtp_password)
        message = MIMEMultipart("mixed")
        message["Subject"] = f"[HFI-UC] {email_title}"
        message["From"] = f"HFI-UC <{smtp_email}>"
        message["To"] = email
        template = """
<!--
* This email was built using Tabular.
* For more information, visit https://tabular.email
--><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd"><html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" lang="en"><head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><title></title>


<!--[if !mso]>-->
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<!--<![endif]-->
<meta name="x-apple-disable-message-reformatting" content="">
<meta content="target-densitydpi=device-dpi" name="viewport">
<meta content="true" name="HandheldFriendly">
<meta content="width=device-width" name="viewport">
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
<style type="text/css">
table {
border-collapse: separate;
table-layout: fixed;
mso-table-lspace: 0pt;
mso-table-rspace: 0pt
}
table td {
border-collapse: collapse
}
.ExternalClass {
width: 100%
}
.ExternalClass,
.ExternalClass p,
.ExternalClass span,
.ExternalClass font,
.ExternalClass td,
.ExternalClass div {
line-height: 100%
}
body, a, li, p, h1, h2, h3 {
-ms-text-size-adjust: 100%;
-webkit-text-size-adjust: 100%;
}
html {
-webkit-text-size-adjust: none !important
}
body {
min-width: 100%;
Margin: 0px;
padding: 0px;
}
body, #innerTable {
-webkit-font-smoothing: antialiased;
-moz-osx-font-smoothing: grayscale
}
#innerTable img+div {
display: none;
display: none !important
}
img {
Margin: 0;
padding: 0;
-ms-interpolation-mode: bicubic
}
h1, h2, h3, p, a {
line-height: inherit;
overflow-wrap: normal;
white-space: normal;
word-break: break-word
}
a {
text-decoration: none
}
h1, h2, h3, p {
min-width: 100%!important;
width: 100%!important;
max-width: 100%!important;
display: inline-block!important;
border: 0;
padding: 0;
margin: 0
}
a[x-apple-data-detectors] {
color: inherit !important;
text-decoration: none !important;
font-size: inherit !important;
font-family: inherit !important;
font-weight: inherit !important;
line-height: inherit !important
}
u + #body a {
color: inherit;
text-decoration: none;
font-size: inherit;
font-family: inherit;
font-weight: inherit;
line-height: inherit;
}
a[href^="mailto"],
a[href^="tel"],
a[href^="sms"] {
color: inherit;
text-decoration: none
}
</style>
<style type="text/css">
@media (min-width: 481px) {
.hd { display: none!important }
}
</style>
<style type="text/css">
@media (max-width: 480px) {
.hm { display: none!important }
}
</style>
<style type="text/css">
@media (max-width: 480px) {
.t23,.t28{mso-line-height-alt:0px!important;line-height:0!important;display:none!important}.t24{padding:40px!important;border-radius:0!important}.t18{mso-line-height-alt:46px!important;line-height:46px!important}
}
</style>
<!--[if !mso]>-->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&amp;display=swap" rel="stylesheet" type="text/css">
<!--<![endif]-->
<!--[if mso]>
<xml>
<o:OfficeDocumentSettings>
<o:AllowPNG/>
<o:PixelsPerInch>96</o:PixelsPerInch>
</o:OfficeDocumentSettings>
</xml>
<![endif]-->
</head>
<body id="body" class="t31" style="min-width:100%;Margin:0px;padding:0px;background-color:#FFFFFF;"><div class="t30" style="background-color:#FFFFFF;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td class="t29" style="font-size:0;line-height:0;mso-line-height-rule:exactly;background-color:#FFFFFF;" valign="top" align="center">
<!--[if mso]>
<v:background xmlns:v="urn:schemas-microsoft-com:vml" fill="true" stroke="false">
<v:fill color="#FFFFFF"/>
</v:background>
<![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" align="center" id="innerTable"><tr><td><div class="t23" style="mso-line-height-rule:exactly;mso-line-height-alt:50px;line-height:50px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t27" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="600" class="t26" style="width:600px;">
<table class="t25" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t24" style="border:1px solid #EBEBEB;overflow:hidden;background-color:#FFFFFF;padding:44px 42px 32px 42px;border-radius:25px 25px 25px 25px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100% !important;"><tr><td align="left">
<table class="t4" role="presentation" cellpadding="0" cellspacing="0" style="Margin-right:auto;"><tr><td width="45" class="t3" style="width:45px;">
<table class="t2" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t1"><div style="font-size:0px;"><img class="t0" style="display:block;border:0;height:auto;width:100%;Margin:0;max-width:100%;" width="45" height="45" alt="" src="https://s21.ax1x.com/2025/09/25/pV5T6mt.png"></div></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t5" style="mso-line-height-rule:exactly;mso-line-height-alt:42px;line-height:42px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t10" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="514" class="t9" style="width:665px;">
<table class="t8" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t7" style="border-bottom:1px solid #EFF1F4;padding:0 0 18px 0;"><h1 class="t6" style="margin:0;Margin:0;font-family:Inter,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:28px;font-weight:700;font-style:normal;font-size:24px;text-decoration:none;text-transform:none;letter-spacing:-1px;direction:ltr;color:#141414;text-align:left;mso-line-height-rule:exactly;mso-text-raise:1px;">${title}</h1></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t11" style="mso-line-height-rule:exactly;mso-line-height-alt:18px;line-height:18px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t16" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="514" class="t15" style="width:600px;">
<table class="t14" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t13"><p class="t12" style="margin:0;Margin:0;font-family:Inter,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:25px;font-weight:400;font-style:normal;font-size:15px;text-decoration:none;text-transform:none;letter-spacing:-0.1px;direction:ltr;color:#141414;text-align:left;mso-line-height-rule:exactly;mso-text-raise:3px;">${details}</p></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t18" style="mso-line-height-rule:exactly;mso-line-height-alt:25px;line-height:25px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr><tr><td align="center">
<table class="t22" role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="514" class="t21" style="width:600px;">
<table class="t20" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t19" style="overflow:hidden;background-color:#F97316;border-radius:12px 12px 12px 12px;"><p class="t17" style="margin:0;Margin:0;font-family:Inter,BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:70px;font-weight:400;font-style:normal;font-size:16px;text-decoration:none;text-transform:none;direction:ltr;color:#FFFFFF;text-align:center;mso-line-height-rule:exactly;mso-text-raise:16px;">Copyright © 2025 MAKERs'.</p></td></tr></table>
</td></tr></table>
</td></tr></table></td></tr></table>
</td></tr></table>
</td></tr><tr><td><div class="t28" style="mso-line-height-rule:exactly;mso-line-height-alt:50px;line-height:50px;font-size:1px;display:block;">&nbsp;&nbsp;</div></td></tr></table></td></tr></table></div><div class="gmail-fix" style="display: none; white-space: nowrap; font: 15px courier; line-height: 0;">&nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;</div><img src="https://ea.pstmrk.it/open?m=v3_1.Q-wBCg89KvUnfri_FH_JXw.PCxttqC_dMJG1C4iNGXD1-GdhR3yJjDI0OWjDkBc0VV3xHkpWGGFY_xZKR7LlFAy1DmUF3SDpxrlCt5lceB3qHllEYybxfJD_PGGDRcd_1GUOyqs4XyMifyyps3NRxfnAAoHSOINMvTCOj9nJJzRxgmkkC1KqyjmnKUsHzpgHWRm49gwspsjvpiI-oi3U12cZwrdpOnItgdu3AVVtGjIg1t8dZ_3enjHRrjuofPIG4g7o3cbcoBMpfDdR15GFhzrMeByOjs912Z8RAhdEKi-99Efgse-TLXX3N-IPoRPYQDS1w2byVEu2NwGlUuqdlI_itVmfDPdiSPRSRaKH4_buRGyLOglDbp2pOhuVzlp3owwghHBnbkma_Vy5Xvl8Dit2gazUjrC78M8sH1FjOgmQYHrBpffw98dWACK5AU9TreNkCb1xOsJ35tLreHtEeMvnOIkZhurgdOx4roRplaUUrg0SNZt-Vogg9ZAtp-5yk3hbYdg2eHsEUvxvv_G4xQhNKs6nT3WD1BLZ5bKUCJGCQ" width="1" height="1" border="0" alt=""></body>
</html>
"""
        mail_body = string.Template(template).safe_substitute(
            {"title": title, "details": details}
        )
        alt = MIMEMultipart("alternative")
        part = MIMEText(mail_body, "html", "utf-8")
        alt.attach(part)
        message.attach(alt)

        if attachments:
            for filename, data_obj in attachments:
                if isinstance(data_obj, BytesIO):
                    data_obj.seek(0)
                    data = data_obj.read()
                elif isinstance(data_obj, (bytes, bytearray)):
                    data = bytes(data_obj)
                else:
                    continue
                ctype, _ = mimetypes.guess_type(filename)
                maintype, subtype = (
                    ctype.split("/", 1) if ctype else ("application", "octet-stream")
                )
                part = (
                    MIMEApplication(data, _subtype=subtype, name=filename)
                    if maintype == "application"
                    else (
                        MIMEText(
                            data.decode("utf-8"), _subtype=subtype, _charset="utf-8"
                        )
                        if maintype == "text"
                        else MIMEApplication(data, name=filename)
                    )
                )

                encoders.encode_base64(part)
                part.add_header("Content-Disposition", "attachment", filename=filename)
                message.attach(part)
        server.sendmail(smtp_email, email, message.as_string())