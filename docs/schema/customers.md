# Customers (`customers`)

Imports customers and their addresses from a CSV file via FireGento FastSimpleImport
(the `customer_composite` import entity), then reindexes the customer grid.

## Source format

```csv
email,_website,_store,firstname,gender,group_id,lastname,middlename,prefix,_address_city,_address_company,_address_country_id,_address_fax,_address_firstname,_address_lastname,_address_middlename,_address_postcode,_address_prefix,_address_region,_address_street,_address_suffix,_address_telephone,_address_vat_id,_address_default_billing_,_address_default_shipping_
test@test.com,base,admin,Test,,1,Test,,,Test,,GB,,Address 1 First Name,Address 2 Last Name,,123,,,Test,,123456789,,1,
,,,,,,,,,Another Test,,GB,,Address 2 First Name,Address 2 Last Name,,12345,,,Another Test,,23423,,,0
```

The first row is the header row; the column names are passed straight through to the
Magento `customer_composite` importer, so any column that importer accepts is valid.
A row with a **blank `email`** is treated as an additional address for the preceding
customer (it is not validated against the customer group), matching the standard
Magento composite-customer import convention.

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `email` | yes | string | Customer email. Required column — import aborts if the header is missing. A blank value marks the row as an extra address for the previous customer. |
| `_website` | yes | string | Website **code** the customer belongs to (required column). |
| `_store` | yes | string | Store **code** the customer is created in (required column). |
| `group_id` | no | int | Customer group id. If set on a customer row and the id does not match an existing group, it is replaced with the store's default group id (error logged). |
| `firstname`, `lastname`, `middlename`, `prefix`, `gender` | no | mixed | Standard customer attributes, passed to the importer as-is. |
| `_address_*` | no | mixed | Address attributes (e.g. `_address_city`, `_address_country_id`, `_address_street`, `_address_telephone`, `_address_postcode`, `_address_region`, `_address_default_billing_`, `_address_default_shipping_`). Passed through to the composite importer. |

Only `email`, `_website` and `_store` are enforced as required columns by the
component; everything else is optional and validated by Magento's importer.

## Behaviour

- **Header validation.** The CSV must contain the `email`, `_website` and `_store`
  columns. If any is missing, or the file has no data, the component records an error
  and stops without importing.
- **Group validation.** For each customer row (one with a non-empty email), `group_id`
  is checked against the existing customer groups. An invalid id is replaced with the
  store default group (`GroupManagementInterface::getDefaultGroup()`) and an error is
  logged, but the row is still imported.
- **Import behaviour is APPEND.** The importer runs with `Import::BEHAVIOR_APPEND`, so
  existing customers are updated and new ones created — there is no delete/replace.
- **Reindex.** After a successful import the `customer_grid` indexer is reindexed.
- **Dry-run.** Logs `[dry-run] Would import N customer row(s) and reindex the customer
  grid`, records the rows as "created", and performs no import or reindex.
- **Error handling.** Missing keys on a row are logged and skipped per column. Any
  exception thrown by the importer is caught, logged and recorded as an error.

## Notes / v2 changes

- v2 wires the importer through `FireGento\FastSimpleImport\Model\ImporterFactory` and
  resolves groups via `GroupRepositoryInterface` / `GroupManagementInterface`. The CSV
  contract above is unchanged from v1.
- Explicit **dry-run** support was added in v2 (`--dry-run`): the row count is reported
  but no data is written and no reindex occurs.
