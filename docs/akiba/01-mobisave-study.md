# MobiSave study (kept private)

The full study of MobiSave, the owner's existing SACCO system, lives in the private portal repo at
`budget-pro-new/docs/akiba/01-mobisave-study.md`. It describes security weaknesses of a system that is still
running, so it is not published in this public repo.

What Akiba Ledger takes from it is already in [03-proposal.md](03-proposal.md), [04-architecture.md](04-architecture.md)
and [decisions.md](decisions.md): the rules worth porting (cycles, loan schemes snapshotted onto each loan, contribution
programmes, the share-out formula, the social fund, attendance, leader positions) and the weaknesses to avoid (no
double entry, money stored as text, editable history, loans that never close, missing authorisation checks).
