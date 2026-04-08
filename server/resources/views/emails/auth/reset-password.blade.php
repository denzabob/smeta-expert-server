@include('emails.auth._header')

<h1 style="font-size:20px;font-weight:600;color:#0f172a;margin:0 0 16px;">Сброс пароля</h1>

<p style="font-size:15px;line-height:1.6;color:#444d56;margin:0 0 16px;">Здравствуйте!</p>

<p style="font-size:15px;line-height:1.6;color:#444d56;margin:0 0 24px;">
  Мы получили запрос на сброс пароля для вашего аккаунта PrismCore.
  Нажмите кнопку ниже, чтобы создать новый пароль.
  Ссылка действительна <strong>60 минут</strong>.
</p>

<table cellpadding="0" cellspacing="0" style="margin:0 0 28px;">
  <tr>
    <td style="background-color:#2563eb;border-radius:6px;">
      <a href="{{ $url }}"
         style="display:inline-block;padding:12px 28px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;">
        Создать новый пароль
      </a>
    </td>
  </tr>
</table>

<hr style="border:none;border-top:1px solid #eaecef;margin:0 0 20px;">

<p style="font-size:12px;color:#6e7781;margin:0 0 8px;line-height:1.5;">
  Если кнопка не работает — скопируйте и вставьте ссылку в браузер:
</p>
<p style="font-size:12px;color:#2563eb;word-break:break-all;margin:0 0 16px;">{{ $url }}</p>

<p style="font-size:13px;color:#6e7781;margin:0;">
  Если вы не запрашивали сброс пароля — проигнорируйте это письмо. Ваш текущий пароль останется прежним.
</p>

@include('emails.auth._footer')
