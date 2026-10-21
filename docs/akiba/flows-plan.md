# Akiba Ledger: the simple flows (plan, 2026-10-21)

The owner: "a simple SACCO system, not too many rules". Five flows, built on what exists (PostingService, ReversalService,
Amount, ShareService, SaccoTransactions, LoanTerms/Schedule, Repayments, LoanJobs, LoanNotices, Notifier, MemberLogin).
Appraisal, committee votes, collateral, contracts, cooling-off and check-off stay in the code, out of the default flow and
menus; only a licensed SACCO (`SaccoRules::strict`) sees them. Money is integer minor units (`*_minor`) everywhere.
Staff API = `/api/v1/sacco/...` (staff token). Member API = `/api/v1/sacco/me/...` (member token).

| Flow | Tables | Kernel service | Web (portal) | API |
|---|---|---|---|---|
| 0. Member password sign-in | (sacco_member_logins) | MemberLogin::attempt | /member/login (exists) | `POST sacco/member-auth/login {login, password}` → `{token, member}` for one membership, else `{memberships[], ticket}` then `POST sacco/member-auth/token {company_id, member_id, ticket}` |
| 1. Shares | sacco_share_accounts, sacco_transactions (exist) | ShareService::purchase (shares_count × `share_par_minor`, channel cash/bank, value_date) | /sacco/shares: register (member, shares, value, SACCO total) + "Buy shares" form + receipt link | `GET sacco/shares` (register + totals + share price), `POST sacco/shares/purchase {member_id, shares_count, channel, value_date}`, `GET sacco/transactions/{id}/receipt`; member `GET sacco/me/shares` |
| 2. SACCO account | **sacco_account_categories**, **sacco_account_entries** | Sacco\Simple\SaccoAccount: `record`, `reverse` (a reversing entry, never an edit), `balance`, `statement`, `categories`, `saveCategory` | /sacco/account: balance, money in / money out form, entries, statement with running balance, categories | `GET sacco/account` (balance per method + total), `GET sacco/account/statement?from&to`, `GET/POST sacco/account/entries {direction in/out, category_id, amount_minor, value_date, method cash/bank/mobile_money, notes}`, `POST sacco/account/entries/{id}/reverse {reason}`, `GET/POST sacco/account/categories`, `PATCH sacco/account/categories/{id}` |
| 3. Share-out | **sacco_shareouts**, **sacco_shareout_lines** | Sacco\Simple\ShareOut: `preview(?amount)`, `run(uuid, share_on, amount, method)` (idempotent on uuid, one per date, locked), `slipPdf` | /sacco/share-out: live preview with the formula, reconciliation to the balance, "Run share-out" (date, amount, confirm), past runs, slips | `GET sacco/share-out/preview?amount_minor`, `GET sacco/share-outs`, `POST sacco/share-outs {uuid, share_on, amount_minor, method}`, `GET sacco/share-outs/{id}`, `GET sacco/share-outs/{id}/slips/{member_id}` (PDF); member `GET sacco/me/share-out` |
| 4. Loan request + approval | sacco_loans (status widened), sacco_loan_guarantors (exist), **sacco_loan_steps**, **sacco_member_notices** | Sacco\Simple\LoanFlow: `quote`, `request`, `guarantorDecide`, `chairDecide`, `payOut`, `notPaidOut`, `steps` | member /member/loans (apply with schedule, pick a guarantor, guarantee requests); staff /sacco/loans (tabs: waiting for chair, approved to pay out, running, closed, declined) and /sacco/loans/{id} | member: `POST sacco/me/loan-quote {amount_minor, months}`, `GET sacco/me/guarantor-options`, `POST sacco/me/loan-requests {amount_minor, months, purpose, guarantor_member_id, uuid}`, `GET sacco/me/loan-requests`, `GET sacco/me/loan-requests/{id}`, `GET sacco/me/guarantor-requests`, `POST sacco/me/guarantor-requests/{loan_id} {decision approve/decline, note}`, `GET sacco/me/notices`; staff: `GET sacco/loan-requests?status=`, `GET sacco/loan-requests/{id}`, `POST sacco/loan-requests/{id}/chair {decision, note}`, `POST sacco/loan-requests/{id}/pay-out {paid_on, method cash/bank/mobile_money, notes}`, `POST sacco/loan-requests/{id}/not-paid-out {notes}` |
| 5. Repayments + interest | sacco_loan_schedule_lines, sacco_loan_repayments (exist) | the SACCO's loan product holds rate/period/method (default 1 % a month, reducing balance; flat optional); LoanJobs (interest on each due date) + Repayments::record (penalty → fees → interest → principal; closes at zero) | Settings › Loan interest; /sacco/loans/{id}: schedule (principal, interest, balance), paid, due, days overdue, outstanding, "Record repayment" | `GET/PUT sacco/loan-settings {rate_bp, rate_period month/year, method reducing/flat}`, `POST sacco/loan-requests/{id}/repayments {uuid, amount_minor, paid_on, method}`, `GET sacco/loan-requests/{id}` (schedule + position); member `GET sacco/me/loan-requests/{id}` |

**Loan statuses** (API `status`): `pending_guarantor → pending_chair → approved → disbursed → closed`, plus
`declined_by_guarantor`, `declined_by_chair`, `not_disbursed`. (`disbursed` is stored as the books' `active`.) Each
step adds a `steps[]` row `{step, by, at, note}` and notifies the next person: the guarantor and the borrower as members
(sacco_member_notices + SMS/WhatsApp via LoanNotices), the chairperson and the treasurer as staff (app notification +
SMS/WhatsApp via Notifier/Messenger). Who acts: guarantor = the member picked (active, not the borrower); chair = role
`sacco_chair`, the seated chairperson, or the owner; treasurer = role `sacco_treasurer`, the seated treasurer, or the owner.

**Share-out maths.** Profit = Σ income − Σ expenses + retained/current-year surplus (what earlier share-outs took is
gone). Pool = Σ members' savings + profit. Amount A ≤ min(pool, money in the chosen account). If A ≥ savings: each member
gets their savings + (their shares ÷ total shares) × (A − savings); else savings are returned pro rata and no profit.
Every split uses `Amount::allocate`, so Σ lines = A exactly. Ledger: Dr each savings account, Dr 3410 profit, Cr cash/bank.

**Books.** Money in: Dr cash/bank/1150, Cr the category's income account. Money out: Dr the category's expense
account, Cr cash/bank/1150. Payout of a loan: Dr 1300, Cr cash/bank/1150. Postings only through PostingService.

**Menu (unlicensed):** Home, Members, Shares, Savings, Loans, SACCO account, Share-out, Reports, Settings, Team.
Receive money / Withdrawals stay reachable from Members and Savings. Approvals, Books, Periods, Journal, Chart, Officers,
Audit and loan products only when strict (the auditor keeps Audit).

**Tests (kernel, tests/Feature/Sacco/Simple):** SharesFlowTest; SaccoAccountTest (posting, reversal, balance,
statement); ShareOutTest (preview reconciles, run posts, Σ = A, idempotent replay, one per date, locked) +
ShareOutAllocationPropertyTest (random members/amounts); LoanFlowTest (every transition and refusal, idempotent pay-out,
notices) + ReducingScheduleTest (property: principal sums to the loan, balance ends at 0, interest = balance × rate);
RepaymentFlowTest (allocation, closes); MemberPasswordLoginTest; SimpleFlowsApiTest. **Portal:** each role signs in
and does its part (member applies, guarantor approves, chair approves, treasurer pays out and records a repayment;
officer runs a share-out and records money in/out).
