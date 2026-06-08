# Shipping Table Rates (`shippingtablerates`)

Creates table-rate shipping rows in the offline-shipping `tablerate` table, keyed per
website.

## Source format

```yaml
base:
  -
    dest_country_id: DE
    dest_region_code: BER
    dest_zip: 10405
    condition_name: package_value
    condition_value: 1.99
    price: 5.99
    cost: 1.99
  -
    dest_country_id: GB
    dest_region_code: "*"
    dest_zip: "*"
    condition_name: package_value
    condition_value: 0
    price: 3.99
    cost: 1
usa:
  -
    dest_country_id: US
    dest_region_code: "*"
    dest_zip: "*"
    condition_name: package_value
    condition_value: 1.99
    price: 4.99
    cost: 1
```

The top-level keys are **website codes**; each maps to a list of rate rows.

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `<website_code>` | yes | list | Top-level key is a website **code**. If no website matches the code, the component logs an error and stops. |
| `<website_code>[].dest_country_id` | yes | string | Destination country ISO code (e.g. `DE`, `GB`, `US`). Used together with `dest_region_code` to resolve the region id. |
| `<website_code>[].dest_region_code` | yes | string | Region code, or `"*"` for any region. Resolved to a `dest_region_id` via the directory region model; an unresolved region defaults to `0`. This key is removed before the DB insert. |
| `<website_code>[].dest_zip` | yes | string | Destination postcode, or `"*"` for any. |
| `<website_code>[].condition_name` | yes | string | Rate condition, e.g. `package_value`, `package_weight`, `package_qty`. |
| `<website_code>[].condition_value` | yes | number | Threshold for the condition. |
| `<website_code>[].price` | yes | number | Shipping price for the row. |
| `<website_code>[].cost` | yes | number | Shipping cost for the row. |

Note: the code also strips a `website_code` key from rows before insert if present, but
the website is taken from the top-level key, so it is not needed in each row.

### Fields written to the database

The insert uses the columns `website_id`, `dest_region_id`, `dest_country_id`,
`dest_zip`, `condition_name`, `condition_value`, `price`, `cost`. `website_id` and
`dest_region_id` are derived (from the website code and the region code respectively);
the rest come straight from the YAML row.

## Behaviour

- **Insert on duplicate.** Rows are written with `insertOnDuplicate`, so a matching row
  is updated rather than duplicated — repeated runs are safe.
- **Website resolved by code.** The top-level key is loaded as a website code; if it
  does not resolve to a website id, an error is logged and the component returns
  (stops processing further websites).
- **Region resolved by code + country.** `dest_region_code` + `dest_country_id` are
  resolved to a region id; if no region is found the id falls back to `0` (i.e. all
  regions / `"*"`).
- **Dry-run.** For each row, logs `[dry-run] Would create shipping rate #N for website
  <code>`, records it as "created", and performs no insert.
- **No source data.** If the data is empty the component records the error `No shipping
  table rate data found in the source data.`

## Notes / v2 changes

- v2 adds **dry-run** support (rows are counted and logged but not inserted). The YAML
  contract (website code → list of rate rows) is unchanged from v1.
