# Akiba Ledger: how Ugandan SACCOs actually work (research notes)

Status: research, not legal advice. Compiled 2026-10-21. Every source is listed in
[References](#references) with its URL; all were accessed on 2026-10-21. Tags in square
brackets, such as [S3], point to that list.

Confidence markers used below:

- **(primary)**: read in the gazetted or official text itself (Act, statutory instrument or government handbook).
- **(press)**: reported by a newspaper or a government news page, not checked against a gazette.
- **(vendor)**: the vendor's own claim.
- **(UNVERIFIED)**: I could not confirm it, or sources disagree. Check before building anything on it.

A note on citations: Uganda's 2023 law revision gave Acts new chapter numbers. The Cooperative
Societies Act is now **Cap. 107** (it was Cap. 112). The Tier 4 Microfinance Institutions and Money
Lenders Act 2016 (Act 18 of 2016) is now **Cap. 61** [S1][S9]. Section numbers moved in some places.
For example, the Lending Conditions Regulations 2024 cite "section 110(2)(d)" of Cap. 61, which was
s.112 in the 2016 text. Section numbers below follow the text I actually read, and that text is
named each time.

---

## 0. The ten facts that matter most

1. **Three regulators, split by size.** The Registrar of Cooperatives (MoTIC) registers every SACCO
   and oversees its governance. UMRA licenses and supervises SACCOs that provide financial services.
   The Bank of Uganda licenses "large" SACCOs, meaning those with voluntary savings above
   **UGX 1.5 bn** and institutional capital above **UGX 500 m** [S1][S2][S12][S13].
2. **Most SACCOs are registered but not licensed.** MoTIC counted **31,832 registered SACCOs** in March
   2025 [S30]. UMRA had licensed only **25 / 125 / 151** SACCOs in 2021 / 2022 / 2023 [S11].
   Emyooga alone had **6,326** SACCOs running without a licence in 2021 (Auditor General, via [S15]).
3. **UMRA returns are prescribed forms with fixed fields** (SI 51 of 2020, Schedule 4) [S3]:
   - **Monthly, by the 15th:** capital adequacy (Form 3), liquidity statement (Form 4) and a statement of savings.
   - **Quarterly, by the 15th:** risk classification and provisioning (Form 1), investments (Form 2), financial position (Form 5), comprehensive income (Form 6) and other disclosures (Form 7). There is also a quarterly equity return.
   - **Annual, within 5 months of year-end:** audited IFRS statements.
4. **Late returns are expensive.** A late monthly return costs **25 currency points (UGX 500,000) plus
   10 CP (UGX 200,000) for each further working day**. A late quarterly or annual return costs 20 CP
   plus 10 CP a day. One currency point is UGX 20,000 [S3][S4].
5. **Flat-rate interest is effectively unlawful for Tier 4 lenders, SACCOs included, since November
   2024.** Interest "shall be computed on the monthly outstanding balance of the principal" (Lending
   Conditions Regs 2024, reg. 9(2)) [S4]. The same regulations also set these limits:
   - penalty interest is capped at **half the monthly rate**, charged on principal only;
   - all interest, fees and charges are capped at **100 % of principal per year**;
   - borrowers get a **5-working-day cooling-off period**;
   - **no early-settlement charge** is allowed;
   - every transaction needs a receipt;
   - consent is required before sharing data with a credit bureau.
6. **The UGX 2.8 % per month cap applies to money lenders, not SACCOs.** Legal Notice 21 of 2024 [S5][S6].
7. **Provisioning is fixed by regulation** [S3]:

   | Class | Provision |
   |---|---|
   | Performing | 1 % |
   | Watch (1–60 days) | 5 % |
   | Substandard (60–90 days) | 25 % |
   | Doubtful (90–180 days) | 50 % |
   | Loss (over 180 days) | 100 % |

   Interest is suspended from substandard onwards.
8. **Shares are permanent equity.** They are never refunded, cannot be pledged as collateral, and no
   member may hold more than one third of share capital. A leaving member exits by transferring
   shares to another member or through a Share Transfer Fund (at most 5 % of share capital a year)
   [S1 s.48][S3 reg.32][S8 reg.9, 43].
9. **Year-end allocations are set in law.** The financial year ends **31 December**. Out of the surplus:
   - at least **10 %** goes to the reserve fund;
   - **1 %** goes to the National Cooperative Education Fund;
   - dividends on shares are capped at **10 %**, need the Registrar's consent, and are only allowed
     while core capital is at least 10 % of assets;
   - the AGM must be held **within 3 months** of year-end [S1 s.45, s.52][S7 s.46–49][S8 reg.18, 34, 40].
10. **Financial data is "special personal data" under the Data Protection and Privacy Act 2019 (s.9).**
    It needs consent or a legal basis. Storing it outside Uganda needs adequacy or consent (s.19).
    Breaches must be reported to the PDPO "immediately" (s.23) [S10].

---

## 1. Law and regulation

### 1.1 Who regulates what (October 2026)

| Layer | Body | Law | What it means for the software |
|---|---|---|---|
| Legal existence, by-laws, AGM, audit, governance | Registrar of Cooperatives, Ministry of Trade, Industry and Cooperatives (MoTIC) | Cooperative Societies Act Cap. 107, as amended by Act 5 of 2020; Cooperative Societies Regulations 1992 | Every SACCO, from a village group upward. Annual return, audited accounts and AGM records go to the Registrar. Quarterly reports cover portfolio, operational self-sufficiency (OSS), risk and sustainability (s.55AS). |
| Licence to provide financial services (Tier 4) | Uganda Microfinance Regulatory Authority (UMRA) | Tier 4 MFI and Money Lenders Act 2016 / Cap. 61; Tier 4 (SACCO) Regulations 2020 (SI 51/2020); Lending Conditions Regs 2024 (SI 108/2024) | Prudential ratios plus the monthly, quarterly and annual returns in Schedule 4 forms. |
| Large SACCOs | Bank of Uganda | MDI Act Cap. 58; MDI (Registered Societies) Regulations 2023 (SI 54/2023) | Applies above UGX 1.5 bn voluntary savings **and** UGX 500 m institutional capital. Needs at least UGX 500 m institutional capital, core capital of at least 10 % of total assets, and a monthly statement of assets and liabilities plus Form RS100 to BoU [S12]. |
| Self-help groups (VSLAs) | District "responsible officer", reporting to UMRA | Tier 4 Act s.99–101 | Registration at the district, a governance committee, and books of account. At least one share-out a year [S1]. |

**Flux to track:**

- **UMRA may be dissolved (UNVERIFIED).** Parliament passed the *Tier 4 Microfinance Institutions and
  Money Lenders (Amendment) Bill, 2024* on 6 Nov 2024. The Bill dissolves UMRA and moves its functions
  into the Ministry of Finance, under the government rationalisation policy (RAPEX) [S14]. I found no
  confirmation of presidential assent or a commencement date. UMRA still appears as the SACCO
  regulator in February 2026 press (Executive Director Edith Tusubira quoted) [S15]. A September 2026
  trade-press article refers to a "Ministry of Finance … Microfinance Regulatory Department" for
  Tier 4 [S13]. **Design for the regulator's name and portal to change; the return content is unlikely to.**
- **The BoU deadline for large SACCOs has moved.** It was 30 Sep 2026 and is now **31 Mar 2027**
  (BoU notice of 28 Sep 2026). About 90 SACCOs qualify, and 5–7 were licensed by September 2026
  [S13][S31]. In February 2026 the President told BoU to stop forcing SACCOs to apply [S15], so this
  remains contested.
- **SACCO income-tax exemption** currently expires 30 Jun 2027. The President agreed to extend it (press) [S15].

### 1.2 Tier 4 Act 2016: SACCO provisions (primary [S1])

- **s.36:** A SACCO may only provide financial services if it is (a) registered under the Cooperative
  Societies Act **and** (b) licensed. It may serve **members only**. It may operate while on
  probationary registration or while its licence application is pending.
- **s.38(2):** A licence application must include:
  - a certified registration certificate;
  - evidence of minimum equity;
  - head office and branches;
  - proof of fee payment;
  - objectives;
  - **evidence of membership and of each member's shareholding**;
  - an economic environment statement;
  - organisation structure;
  - a business plan;
  - the **credit policy and lending procedures**.
- **s.40:** Only a licensed SACCO may use the words "SACCO" or "Savings and Credit Cooperative" in its
  name. A newly registered society has 12 months to apply for a licence.
- **s.41–42:** An annual licence fee, with annual renewal.
- **s.45:** The financial year is the 12 months ending **31 December**.
- **s.46:** Minimum liquid assets against savings. Liquid assets are cash, bank and MDI balances, money
  at call, and T-bills of 3 months or less. The definition in s.2 includes "**mobile money float**".
- **s.48:** Shares:
  - unlimited number, at a par value set in the by-laws;
  - no member may hold more than **one third** of paid-up share capital;
  - shares are equity, transferable, **not withdrawable**, and **cannot be used as loan security**.
- **s.49:** Savings. The committee sets the basis and rate of interest on savings. "Savings shall
  constitute the first call on the member's payment obligations."
- **s.50:** Borrowing is limited to the cap set at the general meeting and to UMRA's limit.
- **s.51:** A written credit policy is mandatory. It must set out repayment terms, maximum loan amount
  and acceptable security.
- **s.52:** The **Reserve Fund takes 10 % of the annual surplus**. The **Share Transfer Fund** takes up
  to **5 % of share capital** a year, out of surplus.
- **s.53:** Dormant and unclaimed accounts:
  - after 2 years with no activity, withdrawals need approval from two officers;
  - the account moves to a dormant register and the member gets written notice;
  - after 3 years on the register the SACCO must advertise it;
  - after 5 years the balance goes to UMRA, and the member can reclaim it within 1 year of transfer.
- **s.54–60:** Stabilisation Fund and Savings Protection Fund:
  - the **SACCO Stabilisation Fund** takes an annual contribution of **0.5 % of average total assets**
    (s.56), and **s.55(2): "A SACCO shall submit a quarterly financial report to the Authority."**;
  - the **Savings Protection Fund** protects savings, **not shares** (s.57(4)).
- **s.90 (2016 numbering):** Interest caps are for **money lenders** only.
- **s.86:** Compound interest is banned for money lenders. SI 108/2024 extended the ban to all Tier 4 institutions (see 1.4).

### 1.3 Tier 4 (SACCO) Regulations 2020, SI 51 of 2020 (primary [S3])

The PDF on UMRA's site is a scan, and I OCR'd it. UMRA's web page calls it "2018". The gazetted
instrument is **SI 2020 No. 51, made on 20 Jan 2020 and gazetted on 20 Mar 2020**.

**Licensing**

- Form 1A asks for:
  - SACCO type (Employee / Community / Nationwide / Other);
  - capital split into core, permanent non-withdrawable shares, redeemable shares and institutional capital;
  - details of each committee member and each senior manager;
  - bankers and auditors;
  - litigation and related-party questions;
  - a list of financial products and delivery methods.
- A fit-and-proper questionnaire (Schedule 2) is required for officers.
- Decision within 3 months.
- **Fees (Schedule 3):**

  | Item | Fee |
  |---|---|
  | Application | **UGX 50,000** |
  | Licence or renewal | **UGX 300,000 a year** |
  | Branch licence | nil |

- The licence is valid until **31 Dec** of its year of issue. Renewal must be applied for **at least 90
  days before expiry**, so by early October.
- Renewal checks whether the SACCO has held its **AGM** and met its governance and **reporting** duties (reg. 8).
- A branch or outlet needs UMRA's written approval and an AGM or special general meeting resolution (reg. 24).

**Display (reg. 12):** licence, members' rights and responsibilities, and the product list.

**UMRA's register of SACCOs (reg. 13) records:**

- committee members' **NINs**;
- **gender-disaggregated membership**;
- auditors;
- branches;
- capital, total assets and total deposits.

**Governance**

- One person may sit on only one SACCO committee (reg. 22(2)).
- The committee must adopt these policies (reg. 22(1)(c)): HR, audit, **credit**, investment,
  **savings**, liquidity, information preservation, **dividend**, and risk management.
- A person removed for misconduct is barred for 3 years.
- UMRA may remove an officer who has a **non-performing loan**.
- UMRA may take over a SACCO if institutional capital falls **below 2 % of total assets** and is declining.

**Prudential rules (reg. 27–31, 37, 50)**

| Ratio | Requirement |
|---|---|
| Core capital / total assets | at least **10 %** at all times |
| Institutional capital / total assets | at least 8 % (Form 7 line 4.7) |
| Core capital / deposit liabilities | at least 8 % (Form 7 line 4.10) |
| Liquidity (liquid assets minus liabilities due within 30 days, divided by total savings) | at least **15 %**, computed **weekly** on the last working day's balances |
| External borrowing | at most **25 % of total assets**, unless waived |
| Non-earning assets plus property and equipment | at most **10 % of total assets** (donated and foreclosed assets excluded) |
| Financial investment in non-government securities | at most **40 % of core capital** or 5 % of total deposits |
| Land and buildings / total assets | Form 7 line 6.2 shows "5 %" (UNVERIFIED; it conflicts with the 10 % rule above) |

The Cooperative Act s.55AI(3) separately requires deposits of at least **20 % of total savings and
deposits** in a BoU-supervised bank or in government or BoU securities [S7]. **The two liquidity rules
are different tests. Compute both.**

**Shares and savings (reg. 32–35)**

- The SACCO sets a minimum number of shares and a par value for membership.
- Shares cannot be pledged and are **never refunded**. A leaving member may only transfer them to another member.
- Dividends are paid only out of net surplus, under the dividend policy, and only if core capital is at least 10 %.
- **Record keeping (reg. 33):**
  - a share and savings account for each member, kept **at least 10 years**;
  - each savings record shows member name, certificate and account number, deposit date, amount,
    maturity, interest rate and dates interest was paid, and early-withdrawal penalties.
- **Savings disclosures:** minimum share value needed to hold a savings account, minimum
  interest-bearing balance, interest rate and fees, early-withdrawal penalty, and maturity.
- **Monthly statement of savings** (withdrawable and non-withdrawable) is due by the 15th.

**Credit (reg. 36–39)**

- The credit policy must cover:
  - requirements and documentation;
  - permitted purposes and acceptable collateral;
  - **concentration limits**;
  - loan types, **interest calculation method** and payment frequency;
  - maximum loan per product;
  - **loan-to-collateral %**;
  - repayment-capacity appraisal;
  - **insider-lending terms**;
  - **approval limits for each officer and committee member**;
  - **guarantor requirements**.
- These must be disclosed to members.
- Early repayment is allowed on any business day **without full-term interest**.
- Members get **30 days' written notice** of any change to loan terms.
- A borrower can ask for a statement of every transaction.
- **Interest on a delinquent loan may not exceed the amount owing when it became delinquent.**
- **Insiders (reg. 38):**
  - committee members' and employees' loans are approved by the committee;
  - the applicant leaves the room while their loan is discussed;
  - insiders get no better terms than other members.
- Foreclosed assets must be sold within 1 year. If not, provide for loss at **25 % a year**. Book them
  at the lower of loan balance and market value, and keep a collateral register (reg. 43).

**Risk classification and provisioning (reg. 40–45), as written (instalment ranges overlap in the gazette text):**

| Class | Days past due | Or instalments outstanding | Provision |
|---|---|---|---|
| Performing | 0 | 0 | 1 % |
| Watch | 1–60 | 1 | 5 % |
| Substandard | 60–90 | "2–6" (sic) | 25 % |
| Doubtful | 90–180 | 4–6 | 50 % |
| Loss | over 180 | more than 6 | 100 % |

- Interest is **suspended** from substandard onwards.
- Accrued interest may not exceed **30 days' earnings of the whole portfolio**.
- A loan can be upgraded after **6 months or 6 instalments** of good performance, at most **twice** in its life.
- Form 1 has a separate block for **rescheduled loans**, using the same classes.
- **Write-off (reg. 44):**
  - allowed when a court rules against the SACCO, security has been realised but falls short, there
    is no collateral and no recovery, or the borrower is bankrupt;
  - recoveries are booked as income in the year received;
  - the write-off procedure belongs in the credit policy.

**Savings Protection premium (reg. 46):** "zero point zero five percent (0.05 %) of the average annual
savings". Press reported 0.5 % [S29]. **(UNVERIFIED: the OCR'd text reads 0.05 %.)**

**Returns: fields an engineer must produce (Schedule 4, all in UGX, all with "Name of SACCO, CS No.,
financial year start and end", all signed by an authorising and a countersigning officer)**

| Form | Frequency, due date | Lines |
|---|---|---|
| **1. Risk classification of assets and provisioning** ("Portfolio ageing report") | Quarterly, by the 15th of the next month | For normal loans and, in a second block, **rescheduled** loans: amount and required provision for Performing 1 %, Watch 5 %, Substandard 25 %, Doubtful 50 %, Loss 100 %; subtotals; grand total |
| **2. Investment return** | Quarterly, 15th | 1.1 core capital; 1.2 total assets; 1.3 total deposits; 1.4 non-earning assets; 1.5 financial assets; 1.6 land and building; 2.x land and building to total assets against the maximum; 3.0 financial investment / core capital (1.5 / 1.1); 3.1 maximum 40 %; 3.2 excess or deficiency |
| **3. Capital adequacy** (UGX '000) | **Monthly**, 15th | Core capital: 1.1.1 share capital; 1.1.2 statutory reserves; 1.1.3 retained earnings or accumulated losses; 1.1.4 **current-year net surplus after tax, counted at 50 %**; 1.1.5 capital grants; 1.1.6 general reserves; 1.1.7 other reserves; 1.1.8 subtotal. Less deductions: 1.1.9 investments in subsidiaries and equity of other institutions; 1.1.10 other; 1.1.11 total; **1.1.12 core capital**; **1.1.13 institutional capital = core minus share capital**. On-balance-sheet assets: 2.1 cash; 2.2 government securities; 2.3 deposits at other institutions; 2.4 loans and advances; 2.5 investments; 2.6 property and equipment (net); 2.7 other; 2.8 total; 2.9 difference. 3 off-balance-sheet items. 4.1–4.3 total assets; 4.4 minimum core capital; 4.5 total deposit liabilities; **4.6 core capital / assets %**; 4.7 minimum 10 %. Attach a reconciliation for any difference. |
| **4. Liquidity statement** (UGX '000) | **Monthly**, 15th | 1.1 local notes and coins; 1.2 foreign notes and coins; 2.1 bank balances, less 2.2 time deposits over 90 days and 2.3 overdrafts or matured bank loans; 3.1 balances with other SACCOs; 3.2 other financial institutions, less 3.3 due to other societies, 3.4 due to financial institutions and 3.5 matured loans; 4.1 T-bills; 4.2 T-bonds; **5 net liquid assets**. Deposits: 6.1 from members, including interest; 6.2 from other sources; 6.3 total, less 6.4–6.6; 6.8 net deposit liabilities. Other liabilities: 7.1 matured; 7.2 maturing within 91 days. **8.3 ratio = net liquid assets / (6.3 + 7.3)**; 8.4 minimum 15 %; 8.5 excess or deficit |
| **5. Statement of financial position** (current and prior period) | Quarterly, 15th (the form footer also says "within three months after year-end") | Assets: 1.1 cash in hand; 1.2 cash at bank; 2 prepayments; 3.1 government securities; 3.2 other securities; 3.3 other investments; 4.1 gross loan portfolio; 4.2 allowance for loan loss; 5.x receivables, tax recoverable, deferred tax asset, retirement benefit asset; property and equipment; investment property; intangibles; other assets. Liabilities: savings deposits; short-term deposits; **non-withdrawable deposits**; tax payable; dividends payable; deferred tax; retirement benefits; **external borrowings**. Equity: share capital; grants and donations; retained earnings (prior and current year); statutory reserves; other reserves; revaluation reserve; **proposed dividends**; adjustments |
| **6. Statement of comprehensive income** | Quarterly ("within 15 days after the end of the month" or 3 months after year-end) | 2.1 interest on portfolio; 2.2 **fees and commission on loan portfolio**; 3.x income from government securities, bank deposits and other investments; other operating income. Expenses: 4.1 on funding liabilities; 4.2 **interest on deposits**; 4.3 cost of external borrowing; 4.4 **dividend expense**; 4.5–4.7 other. 5 net financial expense. **6.1 provision for loan losses; 6.2 value of loans recovered**. Operating expenses: 7.1 personnel; 7.2 governance; 7.3 marketing; 7.4 depreciation; 7.5 administration. 8 net operating income; 9 non-operating income and expenses; 10 net income before tax and donations; 11 tax; 13.1 donations for loan capital; 13.2 donations for operations; 14 net income |
| **7. Other disclosures** | Quarterly per reg. 53 (footer says annual) | 1.1 gross NPLs; 1.2 **interest in suspense**; 1.3 total; 1.4 allowance; 1.5 net NPLs. **2.1 insider loans to directors; 2.2 to employees; 2.3 total insider**. 3 guarantees and commitments. Capital strength: core capital; institutional capital; core / total assets (minimum 10 %); institutional / total assets (minimum 8 %); core / deposits (minimum 8 %). Liquidity ratio (minimum 15 %). Investments: land and buildings / total assets; financial investments / total assets |
| Statement of savings (deposit return) | **Monthly**, 15th | Withdrawable and non-withdrawable savings. I did not find a separate layout in the OCR. Treat it as a breakdown by product (UNVERIFIED) |
| Quarterly equity return (reg. 28) | Quarterly, 15th | Layout not printed. Form 3 covers it in practice (UNVERIFIED) |
| **Annual return** (reg. 54) | Within **5 months** of year-end (by 31 May) | IFRS audited statements: financial position, comprehensive income, committee responsibility statement, auditor's opinion, **audited statistical information**, cash flows, changes in equity, material disclosures |

The returns do not ask for membership counts. The licence register (reg. 13) and the Registrar's annual
return do ask for them, and press reports say UMRA monitoring wants gender-disaggregated member numbers.
**Build a membership statistics block anyway:** members by gender, active and dormant, new and exited,
and youth.

UMRA's site links a "License Application & Reporting" portal at `licensing.umra.go.ug` [S2]. I could not
see what it accepts, whether that is Excel templates or form entry (UNVERIFIED). The safe design is to
**export each form as a filled XLSX/PDF in the exact line order above**, plus CSV.

### 1.4 Lending Conditions Regulations 2024, SI 108 of 2024 (primary [S4])

Gazetted on 15 Nov 2024 and made on 8 Nov 2024. It applies to **all Tier 4 institutions and money
lenders except self-help groups**, which means SACCOs are covered. Institutions already licensed had
3 months to comply.

| Reg. | Rule | Product implication |
|---|---|---|
| 4(2), (7) | Interest plus all loan fees and charges may not exceed **100 % of principal a year** | Hard validation on product setup and at disbursement |
| 4(3) | Before lending: give a schedule of interest, fees and commissions; display rates at branches and on digital channels; disclose third-party fees in advance | Printable key-facts sheet for each product; public product page |
| 4(5) | **Penalty interest at most ½ the monthly rate, on principal balance only** | Penalty engine: default rate = 50 % of the contract monthly rate, on overdue principal (see §4) |
| 5 | Written or electronic contract, signed by both parties and **witnessed by a third party**. It must state: method of calculating interest, rate **as % per year**, **total cost of credit**, payment schedule, cooling-off terms, collateral, recovery procedure, disbursement date, principal, borrower duties, mode of repayment, **nature of guarantorship**, right to early repayment. **A template loan agreement must be filed with UMRA when applying for or renewing a licence** | Contract generator with all 18 fields; e-signature plus witness capture |
| 6 | No variation without the borrower's written consent. A rate increase also needs prior notice to UMRA | Versioned terms; consent record |
| 7 | On a non-performing loan, interest plus fees may not exceed the **principal owing at the date it became non-performing**, plus recovery costs | Cap accruals |
| 8 | **National ID, passport, ATM card or PIN, savings passbook, or a pre-signed transfer deed may not be held as collateral.** Collateral can be sold only **60 days after a written demand**, and the first two auctions may not go below forced-sale value | Collateral types allowed; demand-notice workflow with a 60-day timer |
| 9 | **No compound interest. Interest is computed on the monthly outstanding principal** | Reducing-balance only. Flat rate cannot be offered (see §4.3) |
| 10 | Guarantor must be told **in writing** of the amount and nature of their liability, and give **written consent** | Guarantor consent screen and SMS |
| 11 | **Cooling-off: at least 5 working days.** A cancellation fee of at most 2 % of the loan is allowed | Cancellation flow |
| 12 | **No early-settlement charge** | Product rule |
| 13 | No harassment, no messaging the borrower's contacts; recovery agents must be named to the borrower first | Collections module guardrails |
| 14 | Share positive and negative data with UMRA's credit reference mechanism, **with the borrower's consent**, using the UMRA-allocated identifier | Consent flag; credit bureau export |
| 15 | English or another official language, at least 10-point font; oral explanation countersigned by a third party; disclose "**regulated by UMRA**" | Templates; Luganda and other language strings |
| 16 | A hard-copy receipt, e-receipt or **instant message for every transaction** | Automatic SMS or WhatsApp receipt |

Offence penalty: up to 48 CP plus 10 CP a day. Collateral offences: up to 96 CP a day.

### 1.5 Cooperative Societies Act Cap. 107, as amended by Act 5 of 2020 (primary [S7]) and Regulations 1992 (primary [S8])

**Registration**

- At least **30 members** for a primary society (s.4).
- Members are 18 or older. A person aged 12–17 may be a member but not a committee member (s.13).
- The MoTIC process [S16]:
  1. Buy the savings and credit by-laws (**UGX 15,000**).
  2. Gather 30 or more members.
  3. Fill in the by-laws.
  4. Get a letter from the District Commercial Officer.
  5. Prepare an income and expenditure statement, a balance sheet and a **schedule of each member's
     shares, entrance fee, savings and loans**.
  6. Provide photos of the people who will handle the accounts.
  7. Pay the **UGX 50,000** registration fee to the Audit and Supervision Fund.
- The MoTIC page still cites "Cap 112", so it predates the revision.
- The first general meeting must be held within **1 month** of registration (reg. 19).
- Only "savings and credit cooperative" societies may use the name (s.55A).
- 2022 amendment: a **register of beneficial owners** is required (AML) [S17].

**Members and voting**

- **One member, one vote** (s.18). **No proxies** (reg. 17(2)).
- No member may hold more than one third of share capital (s.14).

**Board (s.18A)**

- An odd number, **5 to 9**, with **4-year terms** and re-election allowed.
- Candidates are vetted with a fit-and-proper test.
- Board members must be literate.
- No relatives up to the second degree on the same board.
- **No joint-account holder** may sit on a SACCO board.
- Managers need a fit-and-proper test and **two guarantors**.

**Supervisory board (s.18B)**

- **Three people elected by the AGM.** They examine the accounts, records, and the actions of officers,
  board and credit committee.
- They report to the general meeting and must inform the Registrar within 5 working days of any
  recommendation.

**Credit committee (s.55AH)**

- Appointed by the board: an odd number, **at least 3**.
- A loan needs **approval by a majority of the committee** or by an officer with delegated limits.
  Delegated approvals are reported to the committee.
- A rejected applicant can **appeal to the board**, whose decision is final.
- A committee member who defaults leaves the committee.

**Other SACCO-specific rules (Part VIIA)**

- Audit committee meets every quarter (s.55I).
- An ALM committee is required (s.55J).
- An **internal auditor** is required and **certifies returns to the Registrar** (s.55K). This may be
  impractical for small SACCOs; enforcement is UNVERIFIED.
- **Credit check on every applicant** (s.55AC).
- Savings rules (s.55AI):
  - demand and fixed-term accounts are allowed;
  - the board sets terms and rates;
  - **at least 20 % of savings and deposits** must be held in BoU-supervised banks or government securities.
- Joint accounts are "group accounts" (s.55AJ).
- **First charge on shares, savings, dividends and interest for any debt, including a guarantor's
  liability**. The SACCO may offset and may **block withdrawals while a member is in arrears** (s.55AK).
- **Dormant accounts (s.55AL):**
  - after 2 years the SACCO may charge a "reasonable maintenance fee", after 90 days' notice, but it
    may not reduce the member's interest;
  - after 5 years the funds are presumed abandoned and go to a special reserve;
  - they can be reclaimed through court within 8 years.
  - This differs from the Tier 4 s.53 rule, which forbids dormant fees other than the advertising
    cost and sends balances to UMRA after 5 years. **The two Acts conflict. Default to the stricter
    Tier 4 rule for licensed SACCOs.**
- **Loans (s.55AM–AP):**
  - **members only**;
  - written lending policy approved by the AGM;
  - loans may be secured or unsecured, and **guarantor endorsement, wage assignment and deposits count as security**;
  - **single-borrower limit (member plus related parties): at most 10 % of total assets or 25 % of institutional capital**;
  - insider loans are reported to the full board and disclosed in the financial statements;
  - corporate or group borrowers' leaders must pledge personal security;
  - rates are set by the board, subject to any limits from the Registrar;
  - reasonable fees and by-law penalties are allowed;
  - **board members, employees and supervisory committee members may not guarantee members' loans** (s.55AP(3));
  - employees may only get salary advances (s.55AP(2)). This **conflicts** with Tier 4 SACCO Regs
    reg. 38, which lets the committee approve employee loans. Make it configurable and flag it.
- **Reporting to the Registrar (s.55AS):** quarterly reports on **portfolio, operational
  self-sufficiency, risk analysis and sustainability**. The penalty in the text is "two hundred
  shillings", which looks like a drafting error.

**Audit (s.22, s.23)**

- An annual audit by an auditor appointed by the AGM and **approved by the Registrar**. MoTIC publishes
  approved-auditor lists [S18].
- No more than **3 consecutive audits** by the same auditor.
- An annual **social audit** is also required.
- The auditor sends the report to the committee, and a copy of the accounts to the Registrar, within
  **3 months** of year-end.
- If there is no audit, the committee is **deemed to have vacated office**.

**Budget (s.24):** income and expenditure estimates for the next 12 months must be prepared at least
3 months before year-end, sent to the Registrar for an opinion, and then approved by the general meeting.

**Surplus**

- **Reserve fund at least 10 % of net surplus** each year. It is indivisible and invested in a
  cooperative bank or as the Registrar approves (s.47; reg. 34).
- **National Cooperative Education Fund 1 % of net surplus** (s.49).
- **5 % of net surplus budgeted for member education** (s.49A(6)), with an education plan filed 3
  months before year-end.
- Cooperative Development Revolving Fund 5 % of surplus (reg. 36). (UNVERIFIED that this still applies.)
- **Dividend: at most 10 % on paid-up shares** (reg. 40), only with the **Registrar's written consent**
  after the balance sheet is lodged (s.46).
- Share Transfer Fund up to 5 % of share capital (reg. 43).

**Meetings**

- The **AGM must be held within 3 months of year-end**, once the audit report is in. If it is not, the
  Registrar convenes it (reg. 18).
- AGM agenda (reg. 20):
  - confirm minutes;
  - receive the committee's report and the audited balance sheet;
  - adopt the accounts;
  - **decide how the surplus is distributed**;
  - elect the committee;
  - **set maximum borrowing for the year (Form V to the Registrar)**;
  - appoint the auditor.
- Quorum is set by the by-laws.
- Members may demand a special general meeting; if the committee does not act within 14 days, members
  may call it on 7 days' notice.

**Required records (reg. 12)**

- **Register of members:** name, membership number, address, **occupation**, date admitted, shareholding,
  date ceased, nominee(s).
- Minutes of general meetings.
- Minutes of committee meetings.
- Cash book.
- Fixed-asset register.

**Death and nominees**

- A member may nominate in writing, signed before **two witnesses**. They may name more than one
  nominee only if they hold more than one share, specifying how many shares each nominee gets.
- The nominee is recorded in the member register.
- **The nominee may be paid without waiting for probate** if the member was in credit (reg. 10).
- On death the share or interest passes to the nominee or legal representative (s.38).
- Past members stay liable for debts and guarantees (s.36).

**Annual return to the Registrar (Schedule form), within 4 months of year-end:**

- name, registration number and date;
- address and type;
- liability (limited to share value, a multiple of it, or unlimited);
- **number of members**;
- **number of shares issued**;
- **par value of one share**;
- total indebtedness;
- AGM date;
- maximum liability set by the AGM;
- officials (chair, vice-chair, secretary, treasurer, committee);
- the last audited balance sheet attached.

### 1.6 Model by-laws: what is fixed and what each SACCO chooses

MoTIC sells the model "Savings & Credit" by-laws as a printed booklet (UGX 15,000) [S16]. **I could not
find the model text online.** The table below separates what the law fixes from what is SACCO practice.

| Item | Fixed by law | Observed practice (examples) |
|---|---|---|
| Share value | Par value set in the by-laws (Tier 4 s.48) | UGX 20,000 per share, minimum 1 share (a Kampala SACCO, via search snippet [S19]). UGX 50,000 per share (MoLG staff SACCO [S20]). A sample employer SACCO by-law requires **50 shares** [S21] (UNVERIFIED: page not retrievable) |
| Entrance (membership) fee | Non-refundable, set by the by-laws | UGX 10,000 [S19]; UGX 50,000 [S21]; **UGX 100,000** (MoLG SACCO [S20]) |
| Max shareholding | One third of paid-up capital | n/a |
| Loan multiple | No statutory multiple. The credit policy must set a maximum per product and loan-to-collateral % | **3 × savings** is the common rule in VSLA methodology [S22] and in Kenyan SACCOs. One Ugandan SACCO requires **savings of 25 % of the loan** first (≈4×) [S19]. No authoritative Ugandan source found |
| Guarantors | Written consent and written notice of liability (SI 108 reg. 10). Officers may not guarantee (Coop s.55AP). Guarantor's own savings carry a first charge (s.55AK) | PDM: co-guarantors are members of the borrower's enterprise group [S23]. Managers must have 2 guarantors (s.18A). How many guarantors, and any exposure cap, are policy choices |
| Interest on savings | Committee sets basis and rate (Tier 4 s.49) | — |
| Dividend | At most 10 % on paid-up shares, Registrar consent, core capital at least 10 % | — |
| Reserve | At least 10 % of surplus | — |
| Exit | Shares are not refunded: transfer to another member or buy-back from the Share Transfer Fund. Savings are refundable after offset of debts and guarantees; exited members stay liable for guarantees [S21] | Notice periods vary (UNVERIFIED) |
| Death | Nominee paid without probate if in credit | — |

### 1.7 Data Protection and Privacy Act 2019 (primary [S10])

- **s.7:** Consent is needed before collecting or processing personal data, unless the processing is
  required by law, needed for a contract with the data subject, or needed for a legal obligation.
  SACCO membership and loans fall under the contract and legal-obligation bases.
- **s.8:** Data on children (members aged 12–17 are allowed under Coop s.13) needs a parent's or guardian's consent.
- **s.9:** **"Financial information" is special personal data.** It may be processed only under an
  employer's legal obligation, with free consent, or by a non-profit body for its own members without
  disclosure to third parties. A cooperative arguably fits (c). **Get explicit consent anyway.**
- **s.13:** The data subject must be told the purpose and other details before collection.
- **s.18:** Do not keep data longer than needed, unless the law requires it. SACCO Regs reg. 33
  requires **10 years** for member accounts, which is a legal basis to keep the ledger. Delete or
  de-identify afterwards.
- **s.19:** Storing data **outside Uganda** requires an adequate level of protection in that country
  **or the data subject's consent**. This is relevant to cloud hosting location.
- **s.20–23:** Security measures and processor contracts. **Breaches must be notified to the Authority
  immediately.** The PDPO decides whether data subjects are told.
- **s.24–28:** Rights of access, objection, **objection to automated decisions** (relevant to credit
  scoring), and rectification and erasure.
- **s.29 and Regulations 2021 (reg. 13/15):** Every data collector, processor or controller must
  **register with the PDPO** (part of NITA-U). Enforcement started 1 Dec 2022 [S24].
  - The registration fee is not confirmed (UNVERIFIED).
  - Both the SACCO and the software vendor (as processor) should register.
- **NIN.** The NIN is the standard KYC key, used by PDM forms, the UMRA register of committee members,
  and credit-reference identifiers. Store the NIN, not a copy of the ID card. Store ID card scans only
  with consent and for KYC. **Never treat the ID as security** (SI 108 reg. 8).

---

## 2. SACCO types and how they operate

| Type | Typical size | Money flows | Operating mode | Distinctive rules |
|---|---|---|---|---|
| **Community SACCO** (open bond: parish, market, trade) | 100s to 10,000s of members | Cash over the counter; MoMo increasingly; bank | **Office mode**: tellers, branch, daily cash book | Full Tier 4 regime if licensed; most are not licensed |
| **Staff / employee SACCO** (teachers, local government, police, prisons) | 100s to 1,000s | **Payroll check-off** for shares, savings and repayments | Office mode, monthly batch | Salary deductions are the main collection channel. The MoLG staff SACCO collects shares and savings by "authorised monthly salary deductions" [S20]. Teachers' SACCOs are fragile: **only 39 of 385 registered teachers' SACCOs are active** (MoTIC, 2025 press [S25]). There are disputes over deductions continuing after loans were repaid (Soroti Walimu SACCO; a 2 % default fine in agreements) [S26]. **Build a payroll reconciliation and a "stop deduction" letter.** |
| **Emyooga SACCO** (Presidential Initiative on Wealth and Job Creation, since 2019) | One SACCO per category per constituency; 18 categories (boda-boda, taxi, salons, welders, market vendors, journalists, elected leaders and others) | Seed capital from the Microfinance Support Centre (MSC): **UGX 30 m** per SACCO (**50 m** for elected leaders' SACCOs), plus **20 m** top-up for performers [S27][S28] | Hybrid: SACCO lends to **associations** (7–30 members), which on-lend to members | The state minister ordered loans at **8 % a year** [S27]. Recovery is poor (West Nile: UGX 1.54 bn recovered of more than 14 bn disbursed) [S27]. By July 2022, **6,644 SACCOs**, UGX 213 bn lent to 59,410 associations, UGX 72 bn savings mobilised [S28]. Software must track **association-level loans with member-level sub-ledgers** and MSC reporting |
| **PDM SACCO** (Parish Development Model, Pillar 3, launched Feb 2022) | One per parish (10,594 parishes); members are **enterprise groups** | **Parish Revolving Fund**: an annual grant, **UGX 100 m per parish**, paid into a PRF bank account and lent to households [S23]. Disbursement via the **Wendi** wallet (PostBank), input dealers or MoMo; cash discouraged [S11][S23] | Group-guaranteed; board-run; uses the government PDMIS for applications | Rules from the MoFPED PRF handbook [S23]: see below |
| **VSLA / self-help group** | 10–25 (up to about 30) members | Cash in a lockbox at meetings | **Meeting mode** | Weekly meetings; **1–5 shares per meeting** at a share value fixed for the cycle; loans up to **3 × savings**, usually 3 months or less; monthly service charge set by the group (about 10 %/month in Uganda guides); optional **social fund** (equal contribution each meeting, grants or interest-free loans); **3 padlocks with keys held by 3 non-committee members**; 5-person committee; **annual share-out** in proportion to shares [S22][S32]. Legally a "self-help group": registered by the district, share-out at least once a year, **exempt from the Lending Conditions Regs** [S1][S4] |

**PDM SACCO rules (PRF handbook [S23])**

- **Loan amount:** at most **UGX 1,000,000 per household, including charges and insurance**.
- **Interest:** **6 % a year (0.5 %/month)**, set as inflation + 1 % for FY2022/23.
- **Application fee:** at most **UGX 5,000**, which can be added to the loan.
- **Default surcharge:** **0.5 %/month** from the date of default.
- **No commissions** may be paid to officials.
- **Use of funds:** **at least 90 % of the loan goes to core inputs**, preferably paid straight to input dealers.
- **Insurance:** agricultural insurance (UAIS) is mandatory for farming.
- **Term:** working capital up to **1 year**, capital expenditure up to **3 years**. Grace period is decided case by case.
- **Default:** a scheduled payment more than **90 days** overdue, among other triggers.
- **Quotas:** women 30 %, youth 30 %, PWD and elderly 10 %, older persons 10 %, men and general 20 %.
- **Process:**
  1. Training.
  2. Application and business plan through the enterprise group, uploaded to **PDMIS**. Documents: application, enterprise group recommendation, photo, **NIN**.
  3. **Loan committee (3 members of the board)** appraises.
  4. Board approves; the decision is communicated within **2 days**.
  5. Loan agreement witnessed by **co-guarantors (enterprise group members)** and management.
  6. Disbursement.
- **Recovery:** the 24-month grace period ended for early cohorts, and districts are now enforcing
  repayment into SACCO Wendi accounts [S33].
- **Auditor General:** UGX 2.7 trillion disbursed versus UGX 9.3 bn recovered, as reported in [S15]. (UNVERIFIED: the magnitudes look inconsistent.)

**Group (meeting) mode vs office mode: what software must support**

| | Meeting mode (VSLA, PDM enterprise group, Emyooga association) | Office mode (community or staff SACCO) |
|---|---|---|
| Transaction capture | Batch at a meeting: one screen, all members, shares, social fund, repayments, fines | Single-member teller or MoMo transactions, any time |
| Connectivity | **Offline-first**; sync later | Online at the counter; offline fallback |
| Cash control | Lockbox count at the start and end of each meeting; 3 keyholders | Teller till, vault, EOD balancing, bank reconciliation |
| Period | **Cycle** (e.g. 52 weeks) ending in a share-out | **Calendar year** ending in an AGM, dividends and interest on savings |
| Interest | Flat "service charge" per month is customary (SHGs are exempt from SI 108) | **Reducing balance required** (SI 108 reg. 9) |
| Approvals | Group consensus in the meeting | Credit committee and delegated limits |
| Reporting | Share-out statement; district SHG register | UMRA Forms 1–7; Registrar annual return; AGM pack |

---

## 3. Money in and out

| Channel | Status Oct 2026 | Notes |
|---|---|---|
| **MTN MoMo Open API** (momodeveloper.mtn.com) | Current | Separate products: **Collections** (RequestToPay), **Disbursements** (Transfer, ValidateAccountHolder, balance, status), Remittances. Each has its own subscription key and API user. Sandbox first. **Production approval is per country**, through MTN Uganda's business team. Callbacks need a public HTTPS URL [S34][S35]. MTN's merchant page says merchants are not charged when customers pay a MoMoPay merchant [S36]. API collection pricing is contractual. Third-party estimates of about 1–2 % are UNVERIFIED |
| **Airtel Money Open API** (openapi.airtel.africa) | Current | OAuth2 client credentials, collections (USSD push) and disbursements, with a webhook. Merchant onboarding through Airtel Uganda. Fee estimates of about 1.5–2 % are UNVERIFIED [S37] |
| **Flutterwave** | Current | **BoU Payment Systems Operator licence, Aug 2024** [S38]. Uganda mobile money charge endpoint documented [S39]. Fees not published for Uganda (UNVERIFIED, about 1.4–3 %) |
| **Yo! Payments (Yo! Uganda)** | Current | Long-running local aggregator. Business account required. Official PHP SDK (`yo-uganda/yopaymentsphp8x` on Packagist) [S40]. Fees on request |
| **Relworx** | Current | REST API with API key and signed webhooks. MTN and Airtel UGX **500 – 5,000,000 per transaction**. Also Kenya and Tanzania [S41]. Fees on request |
| **Pegasus Technologies (PegPay)** | Current | BoU-licensed PSO since 2021. Mobile money aggregation and bulk payments [S42][S43]. A 2020 security incident cost banks and telcos about USD 3.2 m [S43]. No public API docs found |
| **Beyonic** | Legacy | Acquired by MFS Africa (now Onafriq) in 2020 [S44]. I could not confirm it still onboards new Ugandan merchants (UNVERIFIED). Do not plan on it |
| **Wendi (PostBank)** | Current, PDM only | Government wallet for PDM disbursements. 2,200 SACCOs onboarded by Oct 2023; UGX 200 bn to 200,000 beneficiaries by Dec 2023 [S11] |
| **Cash at the office** | Dominant | Needs teller tills, receipts (SI 108 reg. 16), and two-person vault control |
| **Payroll check-off** | Dominant for staff SACCOs | Government payroll (IPPS, now HCM) deductions. **I found no public circular on third-party deduction rules** (UNVERIFIED). Typical flow: SACCO sends a deduction schedule, the employer remits a lump sum and a list, the SACCO reconciles. Disputes are common (see §2) |
| **Bank** | Required | PRF money sits in a bank account. The 20 % liquidity deposit rule (Coop s.55AI) means every SACCO has a bank account to reconcile |

**Regulatory note:** payment aggregators must be licensed by BoU under the **National Payment Systems
Act 2020** as PSPs or PSOs. BoU publishes a list; the latest I saw referenced is 2 Oct 2024 [S45]. Check
the current list before signing with any aggregator.

**Mobile money float counts as a liquid asset** (Tier 4 s.2), so MoMo wallet balances belong in Form 4.

---

## 4. Loans

### 4.1 Products and prices observed

| SACCO / scheme | Product | Rate | Method | Source |
|---|---|---|---|---|
| MoLG staff SACCO | Savings-based loans up to UGX 30 m | **1.5 %/month** | Reducing | [S20] |
| CFU SACCO (Kampala) | Ordinary / school fees | **2.5 % / 3 % per month** | **Flat**. Advertised before SI 108; page now 404. Must convert to reducing | [S19] |
| General claim | "SACCOs lend at about 1 %/month reducing" | ~12 %/yr | Reducing | [S46] (press; generalisation) |
| PDM PRF | Up to UGX 1 m | 6 %/yr (0.5 %/month) | — | [S23] |
| Emyooga | Association loans | 8 %/yr (directed); 8–12 % reported | — | [S27] |
| Kampala mechanics (government) | UGX 0.7 m / 2 m / 4 m tool loans | 6 %/yr | "simple interest" | [S47] |
| Bank to SACCO (Stanbic wholesale) | Credit line | 10–12.5 %/yr | — | [S46] |
| Money lenders (for contrast) | — | Cap **2.8 %/month (33.6 %/yr)** | — | [S5] |
| VSLA | Member loans | About 10 %/month service charge | Flat on balance or principal | [S32] |

Typical product menu (practice): ordinary or development, emergency (short, small), school fees
(seasonal, January, May and August), salary or check-off, agricultural (seasonal bullet), asset or
boda-boda, top-up, and loans against fixed deposits.

### 4.2 Approval flow (what the law requires)

1. **Application.** Check membership, minimum shares and savings, and the credit check (Coop s.55AC).
   If a credit-bureau submission is planned, get consent (SI 108 reg. 14).
2. **Appraisal.** The loan officer or loan committee looks at repayment capacity, purpose, security and
   guarantors (SACCO Regs 36(1)(g)). PDM adds training, sweat capital and the 90 % input rule.
3. **Guarantors.** Each guarantor gets written notice of the amount and nature of their liability and
   gives **written consent** (SI 108 reg. 10). Board members, employees and supervisory committee
   members are excluded (s.55AP(3)).
4. **Approval.**
   - The credit committee decides by majority, or an officer approves within delegated limits that are
     reported to the committee (s.55AH).
   - **Insider loans go to the committee, and the interested member leaves the room** (reg. 38).
   - A rejected applicant can appeal to the board.
5. **Contract and disclosure.** The contract carries the 18 required fields. Rate is shown as % per
   year, with the total cost of credit, the schedule and a key-facts sheet. It is **signed and witnessed**.
6. **Cooling-off.** For 5 working days after signing, the borrower may cancel with a fee of at most 2 %.
7. **Disbursement.** To a MoMo wallet, bank, input dealer or cash. A receipt is issued.
8. **Monitoring.** Ageing and provisioning, demand notices, the 60-day wait before selling collateral,
   and the auction rules.

### 4.3 Flat vs reducing balance

- **Practice:** flat rates have been common, especially in community SACCOs and VSLAs. See CFU
  SACCO at 2.5 %/month flat [S19].
- **Law since Nov 2024:** SI 108 reg. 9(2) requires interest on **monthly outstanding principal** for
  every Tier 4 institution except self-help groups. Reg. 5(2)(m) requires the rate **as % per year**,
  and reg. 5(2)(f) requires the **total cost of credit including fees**. My reading: **a licensed or
  registered SACCO can no longer charge flat interest.** A VSLA registered as a self-help group still
  can. (Interpretation; the regulations do not mention "flat" explicitly.)
- **Engineering rule:**
  - The default schedule is reducing balance with equal instalments (annuity) or equal principal.
  - Allow "flat" only on SHG/VSLA-type groups.
  - Always show an APR-style annual rate and the total cost in UGX on the key-facts sheet and in the contract.

### 4.4 Penalties, top-ups, rescheduling, write-off

- **Penalty:** at most **½ of the monthly contract rate**, on **principal in arrears** only (SI 108 reg.
  4(5)). Example: a loan at 2 %/month carries a penalty of at most 1 %/month on overdue principal.
  "Flat fines" such as the 2 % recovery fine in teachers' SACCO agreements [S26] are risky; model them
  as a fee within the 100 % cap. PDM uses a 0.5 %/month surcharge.
- **Cap on non-performing loans:** interest plus fees may not exceed the principal owing at the date the
  loan became non-performing (SI 108 reg. 7; SACCO Regs 36(6)).
- **Top-up:** a new contract that settles the old balance with no early-settlement charge (reg. 12),
  with fresh disclosure and new guarantor consent.
- **Rescheduling:** a variation needs written consent (reg. 6). The loan keeps its delinquency class until
  it has performed for 6 months or 6 instalments, and can be upgraded at most twice. It is reported
  separately in Form 1.
- **Write-off:** only on the reg. 44 triggers, following the credit-policy procedure. Usually done after
  100 % provision; there is no statutory day count beyond "loss" at more than 180 days. Recoveries are
  income. Keep the written-off loans register.
- **Guarantor recovery:** offset against the guarantor's savings, shares and dividends under the first
  charge (s.55AK). The guarantor's withdrawals can be blocked while the guaranteed loan is in arrears.

### 4.5 Portfolio at risk (PAR)

The regulation does not use the term "PAR". Form 1 gives ageing buckets (1–60, 60–90, 90–180, more than
180 days) and Form 7 gives NPLs, meaning substandard and worse. Compute **PAR1, PAR30, PAR60, PAR90**
(outstanding principal of loans with any arrears beyond N days, divided by gross portfolio) alongside the
regulatory buckets. Boards and the Registrar's quarterly "portfolio and risk" report (s.55AS) expect these.

---

## 5. Year-end

Statutory calendar for a SACCO (financial year = calendar year):

| When | What | Basis |
|---|---|---|
| Weekly | Liquidity ratio on last-working-day balances | SI 51 reg. 29(5) |
| Monthly, by the 15th | Form 3 capital adequacy, Form 4 liquidity, savings statement | reg. 30, 34, 52 |
| Quarterly, by 15 Apr / 15 Jul / 15 Oct / 15 Jan | Forms 1, 2, 5, 6, 7 and equity return to UMRA; portfolio, OSS, risk and sustainability report to the Registrar | reg. 28, 45, 51, 53; Coop s.55AS |
| By about 30 Sep (3 months before year-end) | Budget estimates for the next year to the Registrar; member-education plan | Coop s.24, s.49A |
| By about 2 Oct (90 days before 31 Dec) | UMRA licence renewal application (UGX 300,000) | reg. 8 |
| 31 Dec | Year-end close | Tier 4 s.45 |
| Jan–Mar | External audit (an approved auditor, at most 3 years in a row); annual social audit | Coop s.22 |
| **By 31 Mar** | Audit report to the committee and accounts to the Registrar; **AGM held** | Coop s.22(5); Regs 1992 reg. 18 |
| By 30 Apr | Annual return to the Registrar (members, shares, par value, officers, audited balance sheet) | Regs 1992 Schedule |
| **By 31 May** | Annual IFRS return to UMRA | SI 51 reg. 54 |
| Annually | Stabilisation Fund 0.5 % of average total assets; Savings Protection premium 0.05 % (UNVERIFIED) of average savings; Audit and Supervision Fund fee to the Registrar | Tier 4 s.56; SI 51 reg. 46; Coop s.49B |

**Surplus appropriation order (to implement as a configurable waterfall)**

1. Net surplus after tax. (SACCOs are currently exempt from income tax until 30 Jun 2027 [S15].)
2. **Statutory reserve, at least 10 %.**
3. Education: **1 % to the National Cooperative Education Fund**, and **5 % budgeted for member education**.
4. **Share Transfer Fund, up to 5 % of share capital.**
5. Other reserves approved by the AGM.
6. **Dividend on shares: at most 10 % of paid-up capital.** Only if core capital is at least 10 % after
   the dividend, and only with the **Registrar's written consent**. UMRA may also bar dividends as a sanction.
7. Interest on savings is an **expense** (Form 6 line 4.2), not an appropriation. It is accrued through the year at the board's rate.
8. Patronage "bonus" (Coop s.2 "bonus": by volume of business) is optional.

**AGM pack:** minutes; board report; supervisory board report; credit committee report; audited
statements; surplus distribution proposal; next year's budget; borrowing limit (Form V); elections
(with vetting); auditor appointment.

---

## 6. Market: SACCO software sold in Uganda

| Product (vendor) | Exists? | Pricing (public) | Notes |
|---|---|---|---|
| **Mobis** (Ensibuuko, Kampala) | Yes | Not public | The incumbent. Cloud core plus **USSD and Android** member channels. More than 149,000 SACCO members served as of Jan 2025 [S48]. **Bought CARE's VSLA app *Chomoka*** in June 2024 [S49]. Holds an MDI licence (2022) [S49]. Partnered with MTN and Airtel |
| **Wakandi CAMS** (Wakandi, Kampala / Norway) | Yes | Not public | Cloud core with mobile money, USSD and "automated UMRA reports" (third-party listing). More than 200 SACCOs across UG, KE and TZ (May 2025). Raising capital [S50] |
| **awamo** | Yes (seen in 2019 study) | Not public | Digitised Nakasero Market Traders SACCO [S51]. Current status UNVERIFIED |
| **SaccoRa** | Yes | **UGX 50k / 70k / 100k per month** (100 / 500 / unlimited members; yearly 420k / 588k / 840k); 30-day trial | MoMo in the member app, Android member app, AGM statements. **UMRA reports not mentioned** [S52] |
| **SaccoSys** (Mbarara) | Yes | **UGX 800 per active member per month** or UGX 2,000 per member per quarter, **plus UGX 3,000,000 setup**, VAT exclusive | USSD, agency banking and approval workflows on Premium [S53] |
| **e.sacco** (PledgePay Uganda Ltd) | Yes | "Free to get started" | VSLA-to-SACCO; mobile-money first [S54] |
| **FamITech** | Yes | Free installation for SACCOs under 100 members; pay-as-you-use | [S55] (vendor, Dec 2025) |
| **Synsify** (custom builds) | Yes | From **USD 5,000 (≈UGX 18.5 m)** | Custom builds [S55b] |
| Savings Plus (FLT), iRembo Finance, SACCO APP (Fortis), Tukule | Listed | — | Small or local; Tukule is a hackathon project [S56] |
| **Craft Silicon Bankers Realm SACCO** | Product exists | — | Kenya-focused (Safaricom SACCO). **No Ugandan SACCO deployment verified** [S57] |
| **Mifos / Apache Fineract** | Open source | — | No Ugandan SACCO deployment verified (UNVERIFIED) |
| **Chomoka** | Yes (now Ensibuuko) | — | VSLA app, local-language versions in Uganda [S49] |
| "Kanzu Banking", "Neptune", "Smart Software", "SACCO 360", "Sacco Plus", "Pesa Plus", "DigiSave" | **Not verified** | — | Searches returned nothing Ugandan for these names. Treat them as unconfirmed |

**What users complain about (press and studies)**

- **Cost of the system** is felt by members, and SMS charges are disputed. One SACCO pays for mandatory
  alerts and charges members for optional ones [S58].
- **Low digital literacy** in rural groups means tablets and phones get abandoned [S58].
- **No MIS means fraud and opaque books.** Mercy Corps calls lack of oversight, high costs and fraud the
  main problems [S51]. Frauds keep happening: Kamu Kamu Traders SACCO, about UGX 3.1 bn missing, Mar 2026 [S59].
- **Payroll deduction errors** continue after loans are repaid [S26].
- **Data recovery and continuity** are weak. More than half of Kenyan SACCOs had no recovery plan (regional analogue) [S60].
- **Regulatory churn:** UMRA, then MoFPED, plus BoU, plus MoTIC. Cooperative leaders object to
  overlapping regulators [S13][S15], and SACCOs need software that keeps up.

---

## 7. What this means for Akiba Ledger

### 7.1 Compliance must-haves (blocking)

1. **Ledger and member register**
   - Double-entry ledger with a SACCO chart of accounts that maps line by line to **UMRA Forms 3–7** and to IFRS statements.
   - Member register with all reg. 12 fields: name, number, address, **occupation**, date joined,
     shares, date exited, **nominees (attested by 2 witnesses)**, gender, and date of birth (the 12–17 rule).
   - Keep member accounts **10 years**.
2. **Shares**
   - Par value and minimum shares per SACCO.
   - **Non-withdrawable**, enforce the one-third cap, never usable as collateral.
   - Exit by transfer to another member or buy-back from the Share Transfer Fund.
3. **Savings products**
   - Demand, fixed and compulsory; withdrawable vs non-withdrawable; interest basis and rate; minimum balance.
   - **Disclosures printed for each product.**
   - Block withdrawals while the member is in arrears or a guaranteed loan is in arrears (s.55AK).
   - Dormant workflow: 2 years, then the dormant register and notice; 3 years, then advertisement; 5 years, then transfer to the regulator.
4. **Loan engine**
   - **Reducing balance by default.** Flat rate only for self-help groups.
   - Hard limits:
     - total charges at most 100 % of principal a year;
     - penalty at most ½ the monthly rate, on overdue principal;
     - interest on non-performing loans capped at the principal at the NPL date;
     - no compound interest;
     - no early-settlement fee;
     - 5-working-day cooling-off.
   - **Single-borrower limit** (10 % of assets or 25 % of institutional capital) checked at approval.
   - **Insider flag** that routes the loan to the committee and records recusal.
5. **Loan documents**
   - Contract and key-facts generator with the 18 SI 108 fields, rate per year, total cost of credit in UGX, and a witness signature.
   - UMRA template-contract export for licence renewal.
6. **Guarantors**
   - Written or e-consent with a notice of exposure.
   - Officers, staff and supervisory board members blocked as guarantors.
   - Exposure tracking per guarantor (see defaults).
   - Offset of a guarantor's savings on default.
7. **Approvals**
   - Credit committee (odd number, at least 3) with majority voting.
   - Delegated limits per officer, with delegated approvals reported to the committee.
   - Appeal to the board.
   - Full audit trail: who, when, before and after.
8. **Provisioning and ageing**
   - The exact 1 / 5 / 25 / 50 / 100 % buckets by days and by instalments.
   - Interest suspense from substandard onwards.
   - Rescheduled loans tracked separately.
   - Upgrade after 6 months, at most twice.
   - Foreclosed-asset register with a 1-year timer and 25 % a year provisioning.
   - Write-off register.
   - PAR1, PAR30, PAR60 and PAR90.
9. **Regulatory returns**
   - One-click Form 1–7 exports (XLSX, PDF, CSV), monthly savings statement, Registrar annual return, and quarterly s.55AS report.
   - A **due-date calendar** with reminders before the 15th. Late returns cost UGX 500k plus 200k a day.
10. **Ratios dashboard:** core capital ≥10 %, institutional capital ≥8 %, liquidity ≥15 % (weekly) **and**
    20 % of savings in bank or government securities, external borrowing ≤25 %, non-earning assets ≤10 %,
    investments ≤40 % of core capital. Show a "**BoU threshold**" warning when voluntary savings approach
    UGX 1.5 bn and institutional capital approaches UGX 500 m.
11. **Receipts:** SMS or WhatsApp receipt for **every** transaction (reg. 16), and display of "Regulated by UMRA" plus licence details.
12. **Year-end**
    - Closing and appropriation waterfall: reserve ≥10 %, education 1 % plus 5 %, Share Transfer Fund ≤5 %, dividend ≤10 % with a "Registrar consent obtained" gate.
    - Interest-on-savings accrual.
    - AGM pack generator, budget estimates, and Form V borrowing limit.
13. **Data protection**
    - Consent capture for special personal data and for credit-bureau sharing.
    - NIN stored, ID scans optional.
    - **Hosting: Uganda or an adequate jurisdiction, or explicit consent** (s.19).
    - Breach-notification runbook.
    - PDPO registration for vendor and client.
    - Retention policy: ledger 10 years, then de-identify.
14. **Configurable regulator identity.** UMRA might become a MoFPED department, so the regulator's name
    and portal must not be hard-coded.

### 7.2 Adoption must-haves

- **Two modes, one product.** **Meeting mode** for VSLA, PDM enterprise groups and Emyooga
  associations: offline batch entry per meeting, cycle and share-out, social fund, fines, lockbox count,
  three keyholders. **Office mode** for SACCOs: teller, MoMo and check-off.
- **MoMo first:**
  - MTN and Airtel collections (member pays a loan or savings from their phone with a USSD push);
  - disbursements to the wallet;
  - automatic reconciliation;
  - an aggregator abstraction (Relworx / Yo! / Flutterwave) so the SACCO can start without its own MTN and Airtel API contracts.
- **Check-off:**
  - generate a deduction schedule per employer;
  - import the remittance list (CSV or Excel);
  - auto-allocate to shares, savings and loan;
  - flag over- and under-deductions;
  - produce a **"stop deduction" letter** when a loan is cleared.
- **PDM and Emyooga templates:**
  - PRF product preset: UGX 1 m cap including charges, 6 %/yr, 0.5 %/month default surcharge, UGX 5,000 fee, 90 % inputs, 1- or 3-year terms;
  - enterprise group as member, with co-guarantors from the same group;
  - special-interest-group quota tracking;
  - Emyooga association-level loans with member sub-ledgers.
- **Member self-service** by USSD, app or SMS balance. This is the main anti-fraud feature, because
  members see their own balances without waiting for an officer.
- **Low-literacy UX:** Luganda and other local language strings (also needed for SI 108 reg. 15), large
  numbers, voice or SMS reminders.
- **Clean migration** from passbooks and Excel, using the opening balances schedule MoTIC already asks
  for: shares, entrance fees, savings and loans per member.

### 7.3 Recommended defaults (all configurable)

| Setting | Default | Why |
|---|---|---|
| Financial year | 1 Jan – 31 Dec | Tier 4 s.45 |
| Share par value | UGX 10,000 per share; minimum 5 shares (UGX 50,000) for community, 1 share at UGX 50,000 for staff SACCOs | Observed values UGX 20k–50k [S19][S20] |
| Entrance fee | UGX 20,000 (non-refundable) | Observed 10k–100k |
| Loan multiple | **3 × (savings, excluding shares)**; first loan only after 3 months of membership | VSLA and SACCO norm [S22]; shares cannot be collateral |
| Interest method | **Reducing balance**, monthly instalments | SI 108 reg. 9 |
| Default rate | 1.5 %/month (18 %/yr) for staff and check-off SACCOs; 2 %/month (24 %/yr) for community SACCOs | Observed 1–3 %/month [S20][S46] |
| Penalty | **50 % of the monthly rate on overdue principal**, from day 1 past due, no compounding | SI 108 reg. 4(5) maximum |
| Processing fee | 1 % of principal (counted in total cost and the 100 % cap) | Practice; disclosure rule |
| Guarantors | 2 guarantors for any part of a loan above own savings; each guarantor's total exposure ≤ their free savings; at most 3 active guarantees per member | Practice (no statute); s.55AK offset makes "free savings" the natural cap |
| Single-borrower limit | min(10 % of total assets, 25 % of institutional capital) | Coop s.55AN(3) |
| Dormancy | 24 months without activity | Tier 4 s.53 |
| Reserve | 10 % of surplus (minimum, editable upward) | Tier 4 s.52 |
| Education | 1 % NCEF plus 5 % education budget | Coop s.49, s.49A |
| Dividend cap | 10 % of paid-up shares | Regs 1992 reg. 40 |
| Interest on savings | 0 % on compulsory savings, board-set rate on voluntary and fixed savings, accrued monthly | Committee decides (Tier 4 s.49) |
| VSLA mode | Share value fixed per cycle; 1–5 shares per meeting; loans ≤3 × shares, ≤3 months; service charge 10 %/month flat (SHG exempt from SI 108); social fund as a fixed amount per meeting | VSLA methodology [S22][S32] |

### 7.4 Pricing benchmarks

- Flat SaaS: **UGX 50k–100k per month** (SaccoRa) [S52].
- Per member: **UGX ~670–800 per active member per month, plus UGX 3 m setup** (SaccoSys) [S53].
- Custom build: **UGX ~18.5 m+** (Synsify).
- Free tiers exist for small groups (e.sacco, FamITech) [S54][S55].
- The SACCO's own regulatory cost: UMRA licence **UGX 300,000 a year**, application UGX 50,000, registration UGX 50,000 [S3][S16].

Suggested positioning (my recommendation, not market data):

| Tier | Who | Price |
|---|---|---|
| Free | Meeting mode, groups up to 30 members (VSLA, enterprise group) | Free |
| Starter | Up to 300 members | About UGX 40k per month |
| Compliance | UMRA returns, check-off, MoMo | About UGX 120k per month, or UGX 500 per active member per month, whichever is higher, with **no setup fee** |

Charge SMS at cost: the market uses about UGX 20–45 per SMS [S61]. Mobile money fees pass through.

---

## Appendix A. Package name and product name check (2026-10-21)

- `https://play.google.com/store/apps/details?id=com.solavia.akibaledger&hl=en` returned **HTTP 404**.
  A control package (`com.whatsapp`) returned 200 in the same run. **The package name is not used by any
  public Play listing.** A 404 cannot rule out an unpublished draft in someone else's console, but
  application IDs are globally unique at upload, so the real test is creating it in our Play Console.
- A Play Store search for "akiba ledger" returned no app with that name. Results were generic ledger apps
  plus `com.akiba.by_vetiva2` (Akiba by Vetiva, a Nigerian investment app).
- A web search for the exact phrase "Akiba Ledger" returned no product.
- **The word "Akiba" (Swahili for savings) is already used in East African finance:**
  - **Akiba / E-Savings Club (Uganda):** a group savings and SACCO app founded by Ivan Mworozi. **It is
    in the same niche.** Current activity UNVERIFIED [S62].
  - **Akiba Digital** (Cape Town; lists Uganda among its markets): a personal savings app [S62].
  - **Akiba Commercial Bank (Tanzania):** its "Akiba Fasta" accounts [S62]. Mwalimu Commercial Bank (TZ)
    has a separate product called "Akiba Yangu" [S62].
  - **M-Akiba (Kenya):** the government's mobile retail bond (from general knowledge; not re-verified
    here).
- **Risk:** this is not an exact clash, but "Akiba" on its own is weak as a mark, and a Ugandan SACCO app
  called "Akiba" existed. Always use the full name "Akiba Ledger" and run a URSB trademark search
  (class 9 and 36) before launch.

## Appendix B. Open questions to verify with a human (phone or visit)

1. Has the Tier 4 (Amendment) Act 2024 been assented and commenced? Who receives SACCO returns now:
   UMRA or the MoFPED department? What does `licensing.umra.go.ug` accept (form entry or upload, and in
   what format)?
2. Is the Savings Protection premium 0.05 % or 0.5 % of savings? Is the Stabilisation Fund 0.5 % of assets actually collected?
3. What does the MoTIC model "Savings & Credit" by-laws booklet say about share value, loan multiples, guarantors and notice periods? (Buy one for UGX 15,000.)
4. The government HCM payroll rules for third-party (SACCO) deductions: is there a cap, such as one third of net pay, and what is the approval form?
5. Actual MTN and Airtel API collection and disbursement tariffs for a SACCO merchant. Aggregator fees for Relworx, Yo! and Flutterwave.
6. The PDPO registration fee and renewal period.

---

## References

All accessed 2026-10-21.

| ID | Source | URL |
|---|---|---|
| S1 | Tier 4 Microfinance Institutions and Money Lenders Act, 2016 (Act 18 of 2016), ULII / Laws.Africa PDF (as at 28 Oct 2016) | https://ulii.org/akn/ug/act/2016/18 ; https://media.ulii.org/media/legislation/18120/source_file/df672c6a6c79035b/2016-18.pdf |
| S2 | UMRA, "Regulation" service page (licence validity, renewal, prudential ratios) and regulations list | https://umra.go.ug/service/regulation/ ; https://umra.go.ug/regulations-codes/ |
| S3 | The Tier 4 Microfinance and Money Lenders (SACCO) Regulations, 2020, SI 2020 No. 51 (scanned PDF, OCR'd) | https://umra.go.ug/wp-content/uploads/2021/09/THE-TIER-4-MICROFINANCE-AND-MONEYLENDERS-SACCO-REGULATIONS-2020.pdf |
| S4 | The Tier 4 MFI and Money Lenders (Lending Conditions) Regulations, 2024, SI 2024 No. 108 (scanned PDF, OCR'd) | https://umra.go.ug/wp-content/uploads/2025/01/Lending-Conditions.pdf |
| S5 | ICPAU, "Uganda has capped interest charged by money lenders" (Legal Notice 21 of 2024, 2.8 %/month) | https://www.icpau.co.ug/news/uganda-has-capped-interest-charged-money-lenders |
| S6 | AMFIU, "Analysis of Legal Notice No. 21 of 2024" (applies to money lenders only) | https://amfiu.org.ug/?p=25347 |
| S7 | Cooperative Societies Act, Cap. 107, consolidated to 20 Mar 2020 (incl. Act 5 of 2020), ULII / Laws.Africa | https://media.ulii.org/media/legislation/18212/source_file/98c6dcfad7377cd6/1991-8.pdf ; https://ulii.org/en/akn/ug/act/statute/1991/8/eng@2023-12-31 |
| S8 | Cooperative Societies Regulations, 1992 (copy hosted by Wakiso District) | https://wakiso.go.ug/wp-content/uploads/2025/08/COOP-SOCIETIES-REGULATIONS.pdf |
| S9 | ULII consolidated Cooperative Societies Act (notes Act 17 of 2023 Law Revision amendments) | https://ulii.org/en/akn/ug/act/statute/1991/8/eng@2023-12-31 |
| S10 | Data Protection and Privacy Act, 2019 (Parliament Library PDF) | https://bills.parliament.ug/attachments/Data%20Protection%20and%20Privacy%20Act%202019.pdf |
| S11 | UMRA, SACCO & Microfinance Guidebook 2024 (licensing table 2018–2023; PDM/Wendi figures) | https://umra.go.ug/wp-content/uploads/2024/10/Sacco-MicroFinance-GuideBook-FINAL-LIGHT-VERSION-1.pdf |
| S12 | Microfinance Deposit-Taking Institutions (Registered Societies) Regulations, 2023, SI 54 of 2023 | https://commons.laws.africa/akn/ug/act/si/2023/54/media/publication/ug-act-si-2023-54-publication-document.pdf |
| S13 | The Cooperator News, "BoU extends licensing deadline for large SACCOs to March 2027" (29 Sep 2026) | https://thecooperator.news/bou-extends-licensing-deadline-for-large-saccos-to-march-2027/ |
| S14 | Parliament of Uganda, "Finance Ministry absorbs three agencies" (7 Nov 2024); Bill CFI | https://www.parliament.go.ug/news/3407/finance-ministry-absorbs-three-agencies ; https://bills.parliament.ug/attachments/CFI%20for%20Tier%204%20Microfinance%20Institutions%20and%20Money%20Lenders%20(Amendment)Bill,2024.pdf |
| S15 | Daily Monitor, "Museveni halts central bank directive on Sacco licensing" (2 Feb 2026) | https://www.monitor.co.ug/uganda/news/national/museveni-halts-central-bank-directive-on-sacco-licensing-5345426 |
| S16 | MoTIC, Guidelines for Formation of a Cooperative Society | https://www.mtic.go.ug/cooperatives-guidelines/ |
| S17 | Cooperative Societies (Amendment) Act, 2022 / committee report (beneficial owners) | https://bills.parliament.ug/attachments/Cooperative%20Societies%20(Amendment)%20Act,%202022.pdf |
| S18 | MoTIC, Registrar of Cooperatives list of approved auditors 2023–2025 | https://www.mtic.go.ug/?p=8704 |
| S19 | CFU Foundation SACCO services / products page (search snippets; page now 404) | https://cfufoundation.org/sacco-service.php |
| S20 | Ministry of Local Government, "Join the MoLG SACCO today" | https://molg.go.ug/join-the-molg-sacco-today-save-unlock-affordable-credit/ |
| S21 | UNS SACCO by-laws, proposed revisions March 2021 (search snippet; download failed) | https://uns-sacco.com/wp-content/uploads/2021/03/SACCO-Byelaws-Proposed-revisions-March-2021.pdf |
| S22 | VSL Associates, "The VSLA methodology" | https://www.vsla.net/the-vsla-methodology/ |
| S23 | MoFPED, Parish Development Model Users Handbook under the Parish Revolving Fund (Feb 2023) | https://www.finance.go.ug/sites/default/files/2023-02/PARISH%20REVOLVING%20FUND%20USER%20HANDBOOK.pdf |
| S24 | Afriwise, "Registration and compliance … under the Data Protection and Privacy Act, 2019" | https://www.afriwise.com/blog/registration-and-compliance-for-ngos-under-the-data-protection-and-privacy-act-2019 |
| S25 | Daily Monitor, "Only 39 of 385 teachers' Saccos active…" | https://www.monitor.co.ug/uganda/news/national/only-39-of-385-teachers-saccos-active-as-ministry-demands-recovery-of-shs11-billion-5512688 |
| S26 | Daily Monitor, "Soroti teachers' Walimu Sacco in rift over salary deductions" | https://monitor.co.ug/uganda/news/national/soroti-teachers-walimu-sacco-in-rift-over-salary-deductions--3619424 |
| S27 | The Independent, "Emyooga defaulters face arrest"; URN, "Arua struggling to recover UGX 12.4b Emyooga funds"; Observer | https://independent.co.ug/emyooga-defaulters-face-arrest ; https://ugandaradionetwork.net/story/arua-struggling-to-recover-ugx-12-4b-emooyga-funds- ; https://observer.ug/?p=29704 |
| S28 | Business Times Uganda, "How SMEs will benefit from Emyooga funds" | https://businesstimesug.com/?p=3870 |
| S29 | Uhuru Institute, "Government sets up fund to protect SACCO members' savings" | https://www.uhuruinstitute.org/government-sets-up-fund-to-protect-sacco-members-savings/ |
| S30 | Daily Monitor, "Statistics show Saccos edge past 30,000 mark" | https://www.monitor.co.ug/uganda/news/national/statistics-show-saccos-edge-past-30-000-mark-5115252 |
| S31 | The Independent, "Seven of 90 large SACCOs licensed as BoU deadline approaches" | https://www.independent.co.ug/seven-of-90-large-saccos-licensed-as-bou-deadline-approaches/ |
| S32 | Helvetas VSLA topic sheet; IGAD resilience brief (10 % in Uganda); VSL programme guide | https://www.helvetas.org/Publications-PDFs/HSI_TopicSheet_VillageSavings.pdf ; https://resilience.igad.int/wp-content/uploads/2019/10/PIA-2-GP4-Building-resilience-through-VSLA-and-access-to-credit.pdf ; https://fsnnetwork.org/sites/default/files/2020-07/vsl_programme_guide_for_village_agents_-_version_1.04_english.pdf |
| S33 | Eagle Online, "Mbarara orders PDM beneficiaries to begin loan repayments…" (Apr 2026); Parliament "PDM loan repayment phase to begin in March" | https://eagle.co.ug/2026/04/07/mbarara-orders-pdm-beneficiaries-to-begin-loan-repayments-after-grace-period-ends ; https://gateway.parliament.go.ug/news/4291/pdm-loan-repayment-phase-begin-march |
| S34 | MTN Uganda, MTN Open API FAQ (endpoints) | https://www.mtn.co.ug/helppersonal/mtn-open-api/ |
| S35 | McTaba, "MoMo API integration Uganda" (developer guide) | https://mctaba.com/learn/uganda/momo-api-integration-uganda |
| S36 | MTN Uganda MoMo merchant page | https://mtn.co.ug/momo/merchant/ |
| S37 | Kolonell, "Airtel Money integration Uganda… 2026" (third-party guide) | https://kolonell.com/en/blog/airtel-money-integration-uganda-tanzania-zambia-guide-2026 |
| S38 | Launch Base Africa, "Flutterwave expands African reach with Ugandan licence" (28 Aug 2024) | https://launchbaseafrica.com/flutterwave-expands-african-reach-with-ugandan-license |
| S39 | Flutterwave developer docs, Uganda Mobile Money | https://developer.flutterwave.com/docs/uganda |
| S40 | YO-Uganda/YoPaymentsPHP (GitHub) and Packagist | https://github.com/YO-Uganda/YoPaymentsPHP ; https://packagist.org/packages/yo-uganda/yopaymentsphp8x |
| S41 | Relworx payments API docs | https://payments.relworx.com/docs/ |
| S42 | Pegasus Technologies | https://www.pegasus.co.ug/ |
| S43 | Daily Monitor, "Five companies issued with electronic money licences" (2021) | https://www.monitor.co.ug/uganda/business/finance/five-companies-issued-with-electronic-money-licences--3598358 |
| S44 | Onafriq (MFS Africa), "MFS Africa acquires Beyonic" | https://onafriq.com/view/mfs-africa-acquires-beyonic-bringing |
| S45 | Bank of Uganda, list of licensed PSPs/PSOs (2 Oct 2024) on X; NPS Act FAQ | https://x.com/BOU_Official/status/1842093343385190890 ; https://bou.or.ug/uploads/Frequently_asked_Questions_on_the_NPS_ACT_2020_and_the_NPS_Regulations_2021_5818aec7f5.pdf |
| S46 | Business Times Uganda, "Why are more Ugandans choosing SACCOs over banks"; AllAfrica, "Stanbic bets on cheap credit…" | https://businesstimesug.com/why-are-more-ugandans-choosing-saccos-over-banks-to-save/ ; https://allafrica.com/stories/202607080271.html |
| S47 | AllAfrica, "Museveni pledges to inject Shs1bn each into Kampala mechanics Sacco" (Dec 2025) | https://allafrica.com/stories/202512290084.html |
| S48 | GSMA Mobile for Development, Ensibuuko grantee page | https://www.gsma.com/solutions-and-impact/connectivity-for-good/mobile-for-development/digital-grantees-portfolio/ensibuuko-2/ |
| S49 | CARE, "CARE invests in Ugandan fintech Ensibuuko…" (June 2024) | https://www.care.org/?p=38104 |
| S50 | Citizen Digital, "Wakandi eyes Ksh 6.5 billion capital raise…"; Launch Base Africa (May 2025) | https://citizen.digital/article/wakandi-eyes-ksh65-billion-capital-raise-to-boost-sacco-digitisation-in-kenya-and-africa-n362700 ; https://launchbaseafrica.com/2025/05/14/norways-wakandi-seeks-50m-to-digitize-africas-saccos-can-it-outpace-local-rivals |
| S51 | Mercy Corps, "Digitization of SACCOs in Uganda: Drivers and Impact Study" (2019) | https://www.mercycorps.org/sites/default/files/2019-11/Digitization%20of%20SACCOs%20in%20Uganda%20-%20Drivers%20and%20Impact%20Study.pdf |
| S52 | SaccoRa pricing | https://saccoraapp.com/ |
| S53 | SaccoSys pricing | https://saccosys.com/ |
| S54 | e.sacco | https://esaccoug.com/ |
| S55 | FamITech, "Sacco management software in Uganda" | https://famitechsolutions.com/sacco-management-software-in-uganda/ |
| S55b | Synsify, SACCO management system | https://synsify.africa/services/sacco-management-system |
| S56 | MIT Solve, iRembo Finance; Devpost, Tukule | https://solve.mit.edu/solutions/84825 ; https://devpost.com/software/tukule |
| S57 | Craft Silicon, Bankers Realm SACCO solution | https://www.craftsilicon.com/industries/sacco/ |
| S58 | Daily Monitor, "How digitisation is transforming Saccos" | https://www.monitor.co.ug/uganda/business/prosper/how-digitisation-is-transforming-saccos-5481064 |
| S59 | Pulse Uganda, "Man vanishes with Shs 3 billion from Kampala traders Sacco" (Mar 2026) | https://www.pulse.ug/story/man-vanishes-with-shs-3-billion-from-kampala-traders-sacco-2026032720230586366 |
| S60 | Mwananchi / Nation, "Why your Sacco deposits are at risk" (Kenya) | https://mwananchi.co.tz/kenya/news/why-your-sacco-deposits-are-at-risk-3684464 |
| S61 | DEV Community, Yoola SMS (UGX 20/SMS); Relworx SMS (UGX 45/SMS) | https://dev.to/yoolasms/the-cheapest-sms-api-for-uganda-and-east-africa-ugx-20sms-pay-with-mobile-money-5a3k ; https://unified.relworx.com/ |
| S62 | Daily Monitor, "Software engineer solves financial inclusion dilemma" (Akiba, Uganda); Seedstars, Akiba / E-Savings Club Uganda; Digest Africa, Akiba Digital; ACB Bank, Akiba Fasta; Mwalimu Commercial Bank, Akiba Yangu | https://www.monitor.co.ug/uganda/business/prosper/software-engineer-solves-financial-inclusion-dilemma-1678924 ; https://seedstarsworld.com/startup/36740 ; https://digestafrica.com/companies/akiba-digital ; https://www.acbbank.co.tz/savings-accounts/akiba-fasta-account/ ; https://mwalimubank.co.tz/?p=7270 |
