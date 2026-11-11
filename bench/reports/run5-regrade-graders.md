# Run 5 replayed through the run 6 graders

A correction note for run 5, not a re-grade: run 5's reports, `run5-summary.md` and every S1 to S6 verdict stay as the graders gave them. This lists the grades that run 6's grader fixes give differently, so run 6 can be read beside run 5. Written by `node test/replay-run5.ts` from the run 5 reports (`fixture-edit-safety-2-2026-10-06-run5.json`, `fixture-2026-10-06-run5-regression.json`, `fixture-classic-2026-10-06-run5-regression.json`, `fixture-edit-safety-2026-10-06-run5-regression.json`). Run 5's other held-out set, on a snapshot of a real site, is private bench data, so its rows are not here.

## Code graders (exact and set)

Exact and set graders read only the ANSWER line, which every report row keeps, so each answer is graded again with no transcript and gets the grade the run's reply would. 1060 answers were replayed. Left out: 372 judge and contains_all rows, which read the whole reply; 2 exact and set rows that reached the turn cap; and 6 whose reply had no ANSWER line, so the grader read the whole reply.

### Controls

- **Run 5's grader reproduces run 5.** `src/grade.ts` at `826cce2a66d2`, the commit the reports record, gives 0 grades different from the recorded ones over the same 1060 answers. The replay shows each answer what the run showed it.

- **A mutation is found.** Today's grader with `names()` refusing every value gives 8 grades different from today's grader: the 8 answers it passes for naming the value with a label or qualifier, and no others. So a replay that shows a grade unchanged could have shown it changed.

### Grades that change (1)

| Report | Question | Condition | Model | Rep | Run 5 | Run 6 grader | Run 6 reason |
|---|---|---|---|---|---|---|---|
| fixture-edit-safety-2-2026-10-06-run5 | lk-22 | manual-preloaded | sonnet-5 | 1 | wrong | correct | answers yes first |

89 more answers keep their grade with a new reason: "answers yes first", "answers no first".

## Not replayed

- **Judge questions.** The judge prompt changed for every judge question. How the new prompt grades run 5's judge rows is unknown in either direction, and judging them again is the check to make before run 6 reads its judge grades.

- **contains_all.** Its grader did not change.
