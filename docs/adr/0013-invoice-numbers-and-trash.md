# 13. Invoice numbers and the trash

* Status: accepted
* Date: 2026-11-01

## Context and problem statement

A document number identifies an invoice for the customer, the accountant and the tax authority. Rules about uninterrupted number series and about keeping documents are set by the accounting and tax rules of each country and are subject to professional tax and legal review. The software has to guarantee what it can on its own: no number is given twice, and nothing that was issued disappears without a trace. Several people can save invoices at the same moment, on SQLite and on PostgreSQL.

## Decision drivers

* Two simultaneous saves must never receive the same number.
* The same code must be correct on SQLite and PostgreSQL 17.
* A number, once given to a document, must not be given again, not even after the document is removed.
* A user must be able to remove a mistake, and the system must remember who did it, when and why.
* Numbers typed by hand or imported must not break the counter.

## Considered options

* A counter updated by one atomic statement per number
* A `MAX(number) + 1` query inside the transaction
* A number allocated in application code under an advisory lock

## Decision outcome

Chosen option: one atomic statement per number, because it needs no read before the write and means the same on both databases.

### Series and counter

* A firm has number series. A series has a format of text, date tokens (`{YYYY}`, `{YY}`, `{MM}`, `{DD}`) and exactly one sequence token (`{NNNN}`, whose length is the minimum width). The format is limited to letters, digits and `. _ / -`, so that a number keeps its identity when compared. The sequence is never cut.
* A format with a year token counts per year, so the sequence restarts each year. Any other format counts in one period. The period is taken from the issue date of the document, never from the day of saving, so that an invoice issued on 31 December and saved on 2 January belongs to the old year.
* The counter is a table row per series and period. A number is taken with `INSERT ... ON CONFLICT DO UPDATE ... RETURNING`: the row lock of the upsert makes a second transaction wait until the first one commits and then continue from its value. There is no read before it. This needs SQLite 3.35 or newer, the first version with `RETURNING`.
* The counter is taken inside the transaction that stores the invoice. If the save fails, the transaction ends and the number is returned with it.
* The format of a series cannot change once it holds a document. The next number can be moved forward, or back down to where the counter stands, and never into numbers already given. The largest sequence is the largest value of the counter column.

### Number key

* Two numbers are compared by their `number_key`: upper-case Latin letters and digits only, with diacritics removed. `2026/0001`, `2026-0001` and `2026 0001` are the same number. The key is unique per firm (`uniq_invoice_number`), in the trash as well.
* A number typed by hand, or one that an imported document holds, that stands in the way is skipped by the counter, up to 50 times; then the save is refused and says so.

### Gaps and the trash

* An invoice is never deleted. It goes to the trash with a reason of the user's own words, who did it and when, keeps its number and content and can be restored. A delivered invoice cannot be put in the trash.
* When an edit gives an invoice another number, the old one is recorded as an abandoned number of that firm. It stays taken for good and is not given again, not even to the invoice that gave it up.
* A gap in the sequence is therefore explained by the trash, the abandoned numbers and the audit log. Foresi keeps no separate register of gaps.
* Whether this is enough for the rules on uninterrupted numbering of a given country is a question for the user's adviser, and the matter is subject to professional tax and legal review.

### Retries

* A save that loses to another one, by a lock error or by a unique index, is run again as a whole from loading the invoice by its identifier, because a failed unit of work detaches everything it held. Reading again gives the answer that the first run could not have.

### Consequences

* Good: two saves can never take the same number, on either database.
* Good: no number is reused, and a missing number can be explained.
* Bad: a failed save can still leave a gap if the failure happens after the transaction committed in a part the user cannot see; this is not known to happen, and the audit log would show it.
* Bad: the hand-typed number path costs reads in a loop, bounded at 50.
* Bad: SQLite older than 3.35 is not supported.
