@include('emails.auth._header')

<h1 style="font-size:20px;font-weight:600;color:#0f172a;margin:0 0 16px;">Новый вход в аккаунт</h1>

<p style="font-size:15px;line-height:1.6;color:#444d56;margin:0 0 16px;">Здравствуйте!</p>

<p style="font-size:15px;line-height:1.6;color:#444d56;margin:0 0 20px;">
  Зафиксирован вход в ваш аккаунт PrismCore.
</p>

<table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;font-size:14px;border-collapse:collapse;">
  <tr style="border-bottom:1px solid #eaecef;">
    <td style="padding:10px 0;color:#6e7781;width:130px;vertical-align:top;">Время</td>
    <td style="padding:10px 0;color:#24292f;">{{ $time }}</td>
  </tr>
  <tr style="border-bottom:1px solid #eaecef;">
    <td style="padding:10px 0;color:#6e7781;vertical-align:top;">IP-адрес</td>
    <td style="padding:10px 0;color:#24292f;">{{ $ip }}</td>
  </tr>
  <tr>
    <td style="padding:10px 0;color:#6e7781;vertical-align:top;">Браузер</td>
    <td style="padding:10px 0;color:#24292f;">{{ $device }}</td>
  </tr>
</table>

<p style="font-size:15px;line-height:1.6;color:#444d56;margin:0 0 24px;">
  Если это были вы — никаких действий не требуется.<br>
  Если нет — немедленно смените пароль.
</p>

<table cellpadding="0" cellspacing="0" style="margin:0 0 16px;">
  <tr>
    <td style="background-color:#dc2626;border-radius:6px;">
      <a href="{{ $resetUrl }}"
         style="display:inline-block;padding:12px 28px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;">
        Сменить пароль
      </a>
    </td>
  </tr>
</table>

@include('emails.auth._footer')
