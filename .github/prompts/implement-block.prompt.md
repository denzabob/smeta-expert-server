Implement only the requested block from the approved plan.



Block:

\[PASTE BLOCK HERE]



Constraints:

\- minimal localized diff

\- no unrelated refactors

\- preserve backward compatibility

\- do not rewrite large legacy files unless explicitly required

\- keep old flows operational during transition

\- run only targeted checks relevant to changed files



Before coding, return:

1\. Short plan

2\. Files to change

3\. Risks



Then implement.



After implementation, return:

1\. Files changed

2\. What was implemented

3\. Checks run

4\. Known limitations

5\. Suggested next block



Acceptance criteria section before coding

Out-of-scope confirmation

Evidence required after coding:

exact endpoints changed

exact middleware/config changes

exact tests added

exact manual reproduction steps

No silent scope expansion

If implementation reveals hidden dependency, stop and report instead of broadening block

