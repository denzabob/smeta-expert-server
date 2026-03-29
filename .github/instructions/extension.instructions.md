\---

applyTo: "chrome-extension/\*\*, extension/\*\*"

\---



\## Chrome extension instructions

\- Manifest V3

\- Preserve existing capture and extraction flows unless explicitly replacing them

\- Prefer additive endpoints and payload fields

\- For screenshot capture, define clear mode semantics: viewport, full\_page, element, annotated

\- Keep popup logic thin and isolate API calls

\- Avoid coupling extension-only logic to backend internals more than necessary

