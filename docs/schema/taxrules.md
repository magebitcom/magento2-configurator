# Tax Rules (`taxrules`)

Creates tax rules from a CSV file, resolving rate codes and tax-class names into ids.

## Source format

```csv
code,tax_rate_ids,customer_tax_class_ids,product_tax_class_ids,priority,calculate_subtotal,position
Tax Rule One,US-CA-*-Rate 1,Retail Customer,Taxable Goods,0,0,1
Tax Rate Two,US-NY-*-Rate 1,Retail Customer,Taxable Goods,1,1,2
```

The first row is a header of machine-name column keys; the component maps each data
row onto those keys by position, so the header columns must stay in this order.
`tax_rate_ids`, `customer_tax_class_ids` and `product_tax_class_ids` accept a
comma-separated list of **names/codes** (not numeric ids) — these are looked up and
converted to ids at runtime.

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `code` | yes | string | Tax rule code. Rows with an empty `code` (column 0) are skipped with an error. |
| `tax_rate_ids` | yes | string | Comma-separated tax **rate codes** (matched against `code` from the `taxrates` component). Resolved to rate ids. |
| `customer_tax_class_ids` | yes | string | Comma-separated customer tax-class **names**. Resolved to class ids; a missing class is created (type `CUSTOMER`) outside dry-run. |
| `product_tax_class_ids` | yes | string | Comma-separated product tax-class **names**. Resolved to class ids; a missing class is created (type `PRODUCT`) outside dry-run. |
| `priority` | yes | string | Rule priority. |
| `calculate_subtotal` | yes | string | `1`/`0` — calculate off subtotal only. |
| `position` | yes | string | Sort position. |

## Behaviour

- **Create-only / idempotent.** For each row the rule code is looked up; if a rule
  with that `code` already exists it is logged and skipped (`recordSkipped`).
  Otherwise the rule is created (`recordCreated`). Existing rules are never modified.
- **Name resolution.** `tax_rate_ids` are resolved from rate `code`s, and the two
  tax-class columns from class names. If a referenced tax class does not exist it is
  created on the fly with the matching class type (`CUSTOMER` / `PRODUCT`).
- **Missing code.** A row whose first column (`code`) is empty is skipped with an
  error logged; the run continues with the remaining rows.
- **No row data** (missing header row at index `0`) produces an error and the
  component returns immediately.
- **Dry-run.** No rules or tax classes are written. A would-be-created rule logs
  `[dry-run] Would create Tax Rule "<code>"` and is counted as created; a missing tax
  class logs `[dry-run] Would create missing tax class "<name>"` and is **not**
  created, so any rule depending on it would also not be created in a real run until
  the class exists.
- **Error handling.** A `ComponentException` while creating a rule is logged and
  recorded on the result; the loop continues.

## Notes / v2 changes

- Rule creation still uses the legacy `Magento\Tax\Model\Calculation\Rule` active-record
  `->save()` path (and `ClassModel::save()` for missing tax classes).
- `mode` (create vs maintain) is not honoured — the component is create-only and
  leaves existing rules untouched.
