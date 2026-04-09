@include('emails.auth._header')

<h1 style="font-size:20px;font-weight:600;color:#0f172a;margin:0 0 16px;">Код подтверждения</h1>

<p style="font-size:15px;line-height:1.6;color:#444d56;margin:0 0 16px;">Здравствуйте!</p>

<p style="font-size:15px;line-height:1.6;color:#444d56;margin:0 0 24px;">
  Для подтверждения вашей личности используйте следующий код.
  Код действителен <strong>10 минут</strong>.
</p>

<table cellpadding="0" cellspacing="0" style="margin:0 auto 28px;">
  <tr>
    <td style="background-color:#f0f4ff;border:2px solid #2563eb;border-radius:8px;padding:16px 40px;text-align:center;">
      <span style="font-size:36px;font-weight:700;letter-spacing:12px;color:#1e40af;font-family:'Courier New',Courier,monospace;">{{ $code }}</span>
    </td>
  </tr>
</table>

<p style="font-size:14px;line-height:1.6;color:#6e7781;margin:0 0 16px;">
  Введите этот код в приложении для подтверждения действия.
</p>

<hr style="border:none;border-top:1px solid #eaecef;margin:0 0 20px;">

<p style="font-size:13px;color:#6e7781;margin:0;">
  Если вы не запрашивали этот код — проигнорируйте это письмо.
  Ваш аккаунт в безопасности.
</p>

@include('emails.auth._footer')
