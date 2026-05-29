# Field Trial 292 — QuizAttempt

**Date**: 2026-05-29
**Branch**: `feat/ft292-quiz-attempt`
**Baseline**: post FT291 merge

## Goal

Add `Nene\Kit\QuizAttempt` — record and score quiz / assessment attempts per user, with best-score, has-passed, history, and per-quiz pass rate. Distinct from `SurveyResponse` (FT215, un-scored answers) and `VotePoll` (FT239, voting).

## What was built

### `Nene\Kit\QuizAttempt` (`class/kit/QuizAttempt.php`)

| Method | Description |
| --- | --- |
| `record(quiz, userId, score, maxScore, passMark): int` | Append an attempt; pass = `score >= passMark`. |
| `bestScore(quiz, userId): ?int` | Highest score (null if none). |
| `hasPassed(quiz, userId)` | Any passing attempt? |
| `attemptCount / attempts (quiz, userId)` | Count / history (newest first). |
| `passRate(quiz): float` | Passed attempts / total (0.0 when none). |

Key design points:

- **Append-only attempts**; pass/fail computed and stored at record time (`score >= passMark`, boundary inclusive).
- Validation: `0 <= score <= maxScore`, `maxScore > 0`, `0 <= passMark <= maxScore`.
- `passRate` is attempt-based (passed/total), rounded to 4 dp.

### Tests (`tests/Unit/Kit/QuizAttemptTest.php`)

13 unit tests (20 assertions): record/count, pass-mark boundary (at/below), hasPassed if any passed, bestScore, null/zero when none, attempts newest-first, passRate 0.5, passRate 0 when none, quiz/user separation, validation (score>max, zero max, passMark>max, empty quiz).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Kit` helper. 13 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
