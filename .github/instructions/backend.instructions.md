\---

applyTo: "server/\*\*, app/\*\*, routes/\*\*, database/\*\*, tests/\*\*"

\---



\## Backend instructions

\- Stack is Laravel / PHP

\- Prefer service classes for new domain logic

\- Keep controllers thin

\- For schema changes, update migrations, models, relations, validation, and tests together

\- Preserve backward compatibility for existing endpoints unless task explicitly requires replacement

\- When adding new domain concepts, define enums/statuses clearly

\- If task affects revision/evidence flow, document lifecycle and state transitions in code comments or PR notes

