\# Large change skill



Use this skill when a task affects multiple layers:

\- database

\- backend services

\- API

\- frontend

\- PDF

\- browser extension



Checklist:

1\. Inspect current architecture

2\. Identify domain entities and lifecycle

3\. List required schema changes

4\. Define API contracts

5\. Define UI impact

6\. Define rollout / backward compatibility

7\. Implement only one bounded block at a time

8\. Run targeted checks

9\. Summarize risks and follow-ups

## Planning gate for large tasks

For any task that affects more than one layer (database, backend, frontend, PDF, browser extension, external API), do not start implementing immediately.



First return:

1\. goal summary

2\. current architecture involved

3\. affected files and directories

4\. proposed changes by layer

5\. risks and backward compatibility concerns

6\. implementation split into blocks



Only start coding after the requested block is explicitly selected.



\## Edit boundaries

When implementing:

\- change only files required for the selected block

\- avoid broad refactors

\- do not rewrite large legacy files if a new service, adapter, or component can be added instead

\- preserve old endpoints and flows during migration

\- prefer additive migrations and adapters over replacement



\## Response discipline

For implementation tasks, always respond in this order:

1\. plan

2\. files to change

3\. code changes

4\. checks to run

5\. known limitations

