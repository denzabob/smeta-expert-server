# Russian Trusted CA

Эти публичные сертификаты НУЦ Минцифры устанавливаются в Debian runtime image
для построения TLS-цепочки сайтов, использующих `Russian Trusted Root CA` и
`Russian Trusted Sub CA`.

## Provenance

Первичный официальный источник: [Госуслуги — сертификаты НУЦ](https://www.gosuslugi.ru/crt).
Фактические PEM-файлы получены с официального delivery-домена НУЦ:

- [Russian Trusted Root CA](https://gu-st.ru/content/lending/russian_trusted_root_ca_pem.crt)
- [Russian Trusted Sub CA](https://gu-st.ru/content/lending/russian_trusted_sub_ca_pem.crt)

Дата получения и проверки: `2026-09-04`.

## Expected certificate identity

SHA-256 ниже рассчитан по DER-кодировке сертификата, а не по текстовому PEM-файлу.

| Файл | Subject | Issuer | Serial | Validity | DER SHA-256 |
|---|---|---|---|---|---|
| `russian-trusted-root-ca.crt` | `C=RU, O=The Ministry of Digital Development and Communications, CN=Russian Trusted Root CA` | self-signed | `1000` | `2022-03-01` — `2032-02-27` | `D26D2D0231B7C39F92CC738512BA54103519E4405D68B5BD703E9788CA8ECF31` |
| `russian-trusted-sub-ca.crt` | `C=RU, O=The Ministry of Digital Development and Communications, CN=Russian Trusted Sub CA` | `Russian Trusted Root CA` | `1002` | `2022-03-02` — `2027-03-06` | `BBBDE2103E790B999EC62BD03CF625A5A2E7C316E10AFE6A490EEDEAD8B3FD9B` |

Проверка отпечатка:

```sh
openssl x509 -in russian-trusted-root-ca.crt -outform DER | sha256sum
openssl x509 -in russian-trusted-sub-ca.crt -outform DER | sha256sum
```

Docker build копирует оба `.crt` в `/usr/local/share/ca-certificates/` и
выполняет `update-ca-certificates`. `verify => true` в application HTTP
transport сохраняется; отключение TLS-проверки не используется.
