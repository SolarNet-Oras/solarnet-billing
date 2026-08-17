<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="x-apple-disable-message-reformatting">
  <title>{{ $company['name'] }} billing update</title>
  <style>
    @media only screen and (max-width: 640px) {
      .email-shell { width: 100% !important; }
      .mobile-pad { padding-left: 22px !important; padding-right: 22px !important; }
      .mobile-stack { display: block !important; width: 100% !important; box-sizing: border-box !important; }
      .mobile-center { text-align: center !important; }
      .mobile-spacer { height: 16px !important; }
      .hero-title { font-size: 28px !important; line-height: 34px !important; }
    }
  </style>
</head>
<body style="margin:0; padding:0; background:#e8efec; color:#123c3a; font-family:Arial, Helvetica, sans-serif;">
  <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:transparent; mso-hide:all;">{{ $preheader }}</div>
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; background:#e8efec; margin:0; padding:0;">
    <tr><td align="center" style="padding:28px 14px;">
      <table role="presentation" class="email-shell" width="680" cellspacing="0" cellpadding="0" border="0" style="width:680px; max-width:680px; margin:0 auto; background:#ffffff; border-radius:20px; overflow:hidden; box-shadow:0 10px 32px rgba(7,57,53,.12);">
        <tr><td class="mobile-pad" style="padding:30px 42px 26px; background:#073e3a; background:linear-gradient(135deg,#063a37 0%,#075149 58%,#0a6558 100%); color:#ffffff;">
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>
            <td class="mobile-stack mobile-center" valign="middle" style="width:67%;">
              @if($company['logo_url'])
                <img src="{{ $company['logo_url'] }}" alt="{{ $company['name'] }}" style="display:block; max-width:190px; max-height:62px; width:auto; height:auto; border:0; outline:none; text-decoration:none; margin:0 0 14px;" />
              @endif
              <div style="font-size:{{ $company['logo_url'] ? '13px' : '27px' }}; line-height:{{ $company['logo_url'] ? '19px' : '32px' }}; font-weight:700; letter-spacing:{{ $company['logo_url'] ? '1.8px' : '.1px' }}; color:#ffffff; text-transform:{{ $company['logo_url'] ? 'uppercase' : 'none' }};">{{ $company['name'] }}</div>
              <div style="padding-top:5px; font-size:12px; line-height:18px; color:#d7eee8;">High-Speed Internet &amp; Network Solutions</div>
            </td>
            <td class="mobile-stack mobile-center mobile-spacer" style="width:33%;" valign="middle" align="right">
              <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="right" style="border:1px solid rgba(255,255,255,.28); border-radius:14px; background:rgba(0,0,0,.12);"><tr><td align="center" style="padding:10px 13px;">
                <div style="font-size:10px; line-height:15px; font-weight:700; letter-spacing:1px; color:#b8f1cb;">&#9679; SECURE</div>
                <div style="font-size:10px; line-height:15px; color:#e1f4ed;">SOLARNET PORTAL</div>
              </td></tr></table>
            </td>
          </tr></table>
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:27px;">
            <tr><td style="height:1px; background:rgba(202,239,220,.34); line-height:1px; font-size:1px;">&nbsp;</td></tr>
            <tr><td style="padding-top:18px;">
              <div style="font-size:11px; line-height:16px; letter-spacing:1.5px; font-weight:700; color:#99f0bd;">{{ $notice }}</div>
              <div class="hero-title" style="padding-top:8px; font-size:32px; line-height:39px; letter-spacing:-.45px; font-weight:700; color:#ffffff;">{{ $headline }}</div>
            </td></tr>
          </table>
        </td></tr>

        <tr><td class="mobile-pad" style="padding:32px 42px 10px; background:#ffffff;">
          <div style="font-size:16px; line-height:26px; color:#335a56;">Hello {{ $customer?->full_name ?: 'SolarNet customer' }},</div>
          <div style="padding-top:10px; font-size:15px; line-height:24px; color:#4d6b68;">{{ $intro }}</div>
        </td></tr>

        <tr><td class="mobile-pad" style="padding:20px 42px 0; background:#ffffff;">
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #d9e9e3; border-radius:14px; background:#f7fbf9;"><tr><td style="padding:18px 20px 16px;">
            <div style="font-size:11px; line-height:16px; color:#28765e; font-weight:700; letter-spacing:1.2px; text-transform:uppercase;">{{ $customer_card_title }}</div>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:10px;"><tr>
              <td class="mobile-stack" style="font-size:18px; line-height:25px; font-weight:700; color:#123f3a;">{{ $customer?->full_name ?: 'SolarNet customer' }}</td>
              <td class="mobile-stack" align="right" style="font-size:13px; line-height:20px; color:#54716e;">Account no.<br><strong style="color:#123f3a;">{{ $customer?->account_number ?: '—' }}</strong></td>
            </tr></table>
          </td></tr></table>
        </td></tr>

        <tr><td class="mobile-pad" style="padding:20px 42px 0; background:#ffffff;">
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #d9e9e3; border-radius:14px; overflow:hidden;">
            <tr><td style="padding:18px 20px 8px; background:#ffffff;"><div style="font-size:11px; line-height:16px; color:#28765e; font-weight:700; letter-spacing:1.2px; text-transform:uppercase;">{{ $summary_title }}</div></td></tr>
            @foreach($summary_rows as $row)
              <tr><td style="padding:9px 20px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>
                <td style="width:47%; font-size:13px; line-height:20px; color:#65807d;">{{ $row['label'] }}</td>
                <td align="right" style="font-size:13px; line-height:20px; color:#173f3b; font-weight:700;">{{ $row['value'] }}</td>
              </tr></table></td></tr>
            @endforeach
            <tr><td style="padding:5px 20px 16px;"><div style="height:1px; line-height:1px; font-size:1px; background:#e4efeb;">&nbsp;</div></td></tr>
            @foreach($detail_rows as $row)
              <tr><td style="padding:0 20px 12px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>
                <td style="width:47%; font-size:13px; line-height:20px; color:#65807d;">{{ $row['label'] }}</td>
                <td align="right" style="font-size:13px; line-height:20px; color:#173f3b; font-weight:600;">{{ $row['value'] }}</td>
              </tr></table></td></tr>
            @endforeach
          </table>
        </td></tr>

        <tr><td class="mobile-pad" style="padding:20px 42px 0; background:#ffffff;">
          <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-radius:14px; background:#e8f8ed; border:1px solid #bbebca;"><tr><td align="center" style="padding:20px;">
            <div style="font-size:11px; line-height:16px; color:#267455; font-weight:700; letter-spacing:1.3px; text-transform:uppercase;">{{ $amount_label }}</div>
            <div style="padding-top:6px; font-size:31px; line-height:38px; font-weight:700; color:#0a6a47; letter-spacing:-.4px;">{{ $amount }}</div>
          </td></tr></table>
        </td></tr>

        <tr><td class="mobile-pad" align="center" style="padding:25px 42px 0; background:#ffffff;">
          <table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="border-radius:9px; background:#16965f;">
            <a href="{{ $cta_url }}" target="_blank" style="display:inline-block; padding:15px 28px; font-family:Arial, Helvetica, sans-serif; font-size:14px; line-height:18px; font-weight:700; letter-spacing:.5px; color:#ffffff; text-decoration:none;">{{ $cta_label }}</a>
          </td></tr></table>
          <div style="padding-top:14px; max-width:490px; font-size:12px; line-height:19px; color:#64817e;">{{ $payment_note }}</div>
        </td></tr>

        <tr><td class="mobile-pad" style="padding:24px 42px 0; background:#ffffff;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-top:1px solid #e5efec;"><tr><td style="padding:20px 0 0; font-size:13px; line-height:21px; color:#54716e;">{{ $reminder }}</td></tr></table></td></tr>

        <tr><td class="mobile-pad" style="padding:22px 42px 0; background:#ffffff;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f1f7f5; border-radius:12px;"><tr><td style="padding:17px 19px;">
          <div style="font-size:13px; line-height:20px; font-weight:700; color:#17453f;">Need help with your account?</div>
          <div style="padding-top:4px; font-size:12px; line-height:19px; color:#5b7773;">
            @if($company['contact']) Call {{ $company['contact'] }} @endif
            @if($company['contact'] && $company['email']) &nbsp;·&nbsp; @endif
            @if($company['email']) Email <a href="mailto:{{ $company['email'] }}" style="color:#0d8053; text-decoration:underline;">{{ $company['email'] }}</a> @endif
            @if(($company['contact'] || $company['email']) && $company['facebook_url']) &nbsp;·&nbsp; @endif
            @if($company['facebook_url']) <a href="{{ $company['facebook_url'] }}" target="_blank" style="color:#0d8053; text-decoration:underline;">Facebook support</a> @endif
          </div>
        </td></tr></table></td></tr>

        <tr><td class="mobile-pad" style="padding:24px 42px 0; background:#ffffff;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-top:1px solid #e5efec;"><tr>
          @foreach($features as $feature)
            <td class="mobile-stack mobile-center" align="center" style="padding:17px 6px 0; font-size:11px; line-height:17px; color:#397166;">&#10003;&nbsp; {{ $feature }}</td>
          @endforeach
        </tr></table></td></tr>

        <tr><td class="mobile-pad" style="padding:24px 42px 28px; background:#073e3a; color:#d9efea;">
          <div style="font-size:13px; line-height:19px; font-weight:700; color:#ffffff;">{{ $company['name'] }}</div>
          @if($company['address'])<div style="padding-top:4px; font-size:11px; line-height:17px;">{{ $company['address'] }}</div>@endif
          @if($company['website'])<div style="padding-top:4px; font-size:11px; line-height:17px;">{{ $company['website'] }}</div>@endif
          <div style="padding-top:13px; font-size:10px; line-height:16px; color:#a9cec4;">This is an automated account notice for your SolarNet service. &copy; {{ $year }} {{ $company['name'] }}.</div>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
