# Russian Trusted CA

Эти публичные сертификаты НУЦ Минцифры устанавливаются в Debian runtime image
для построения TLS-цепочки сайтов, использующих `Russian Trusted Root CA` и
`Russian Trusted Sub CA`.

## Provenance

Первичный официальный источник: [Госуслуги — сертификаты НУЦ](https://www.gosuslugi.ru/crt).
Root CA оставлен без изменений. Актуальный intermediate получен по AIA,
указанному в leaf-сертификате `rosstat.gov.ru`:

- `http://nuc-cdp.voskhod.ru/cdp/subca_ssl_rsa2024.crt`
- `http://nuc-cdp.digital.gov.ru/cdp/subca_ssl_rsa2024.crt`

Оба AIA-ответа совпали побайтно. После загрузки сертификат был нормализован
в PEM и проверен подписью существующего Root CA. Дата получения и проверки:
`2026-09-04`.

## Certificate identities

SHA-256 fingerprint — это SHA-256 отпечаток DER-кодировки сертификата.
`PEM file SHA-256` приведён дополнительно для контроля сохранённого файла.

| Файл | Subject | Issuer | Serial | Validity | SHA-256 fingerprint | DER SHA-256 | PEM file SHA-256 |
|---|---|---|---|---|---|---|---|
| `russian-trusted-root-ca.crt` | `C=RU, O=The Ministry of Digital Development and Communications, CN=Russian Trusted Root CA` | self-signed | `1000` | `2022-03-01` — `2032-02-27` | `D2:6D:2D:02:31:B7:C3:9F:92:CC:73:85:12:BA:54:10:35:19:E4:40:5D:68:B5:BD:70:3E:97:88:CA:8E:CF:31` | `D26D2D0231B7C39F92CC738512BA54103519E4405D68B5BD703E9788CA8ECF31` | `AA800EF345422D6158C6FAFE1C06C429DBDA21C3DF4BB1CCB45A920EC1111399` |
| `russian-trusted-sub-ca.crt` | `C=RU, O=The Ministry of Digital Development and Communications, CN=Russian Trusted Sub CA` | `Russian Trusted Root CA` | `1005` | `2024-07-15` — `2029-07-19` | `21:55:78:50:36:C9:00:DB:B5:F1:BB:2A:15:69:C8:0C:55:59:5B:D6:BF:94:86:7A:29:BB:DD:BC:7D:88:A3:F2` | `2155785036C900DBB5F1BB2A1569C80C55595BD6BF94867A29BBDDBC7D88A3F2` | `6F9D829C8E6712444FCE3624658D8788672849C5D5B7B53FD9CF7E83EAC4193E` |

### Rosstat chain selection

Предыдущий `Russian Trusted Sub CA` 2022 (serial `1002`, SKI
`D1:E1:71:0D:0B:2D:81:4E:6E:8A:4A:8F:4C:23:B3:4C:5E:AB:69:0B`) исторически
валиден и подписан тем же Root CA, но не подписывает текущий TLS leaf
`rosstat.gov.ru`. У текущего RSA 2024 intermediate:

`SKI = 77:3D:D9:39:AF:42:BD:DC:5B:CA:76:EA:EE:FD:CE:3E:61:29:30:5F`

Это значение совпадает с `Authority Key Identifier` leaf-сертификата
`rosstat.gov.ru`.

Проверка цепочки, выполненная для production leaf:

```sh
openssl verify \
  -CAfile russian-trusted-root-ca.crt \
  russian-trusted-sub-ca.crt
# russian-trusted-sub-ca.crt: OK

openssl verify \
  -CAfile russian-trusted-root-ca.crt \
  -untrusted russian-trusted-sub-ca.crt \
  rosstat-leaf.pem
# rosstat-leaf.pem: OK
```

Docker build копирует оба `.crt` в `/usr/local/share/ca-certificates/` и
выполняет `update-ca-certificates`. `verify => true` в application HTTP
transport сохраняется; отключение TLS-проверки не используется.
