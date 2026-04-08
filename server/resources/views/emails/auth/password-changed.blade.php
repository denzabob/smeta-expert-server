@include('emails.auth._header')

<h1 style="font-size:20px;font-weight:600;color:#0f172a;margin:0 0 16px;">Пароль изменён</h1>

<p style="font-size:15px;line-height:1.6;color:#444d56;margin:0 0 16px;">Здравствуйте!</p>

@if ($isReset)
<p style="font-size:15px;line-height:1.6;color:#444d56;margin:0 0 16px;">
  Пароль вашего аккаунта PrismCore был успешно сброшен и установлен новый.
</p>
@else
<p style="font-size:15px;line-height:1.6;color:#444d56;margin:0 0 16px;">
  Пароль вашего аккаунта PrismCore был успешно изменён.
</p>
@endif

<p style="font-size:15px;line-height:1.6;color:#444d56;margin:0 0 24px;">
  Если вы не выполняли это действие — немедленно восстановите доступ, используя функцию сброса пароля.
</p>

<table cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
  <tr>
    <td style="background-color:#dc2626;border-radius:6px;">
      <a href="{{ $resetUrl }}"
         style="display:inline-block;padding:12px 28px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;">
        Восстановить доступ
      </a>
    </td>
  </tr>
</table>

<p style="font-size:13px;color:#6e7781;margin:0;">
  Если это были вы — всё в порядке. Это письмо отправлено в целях безопасности.
</p>

@include('emails.auth._footer')
