# Customer Groups (`customergroups`)

Creates customer groups, each associated with an existing tax class.

## Source format

```yaml
customergroups:
  - taxclass: Retail Customer      # required — name of an EXISTING tax class
    groups:
      - name: VIP                  # required — group code, max 32 characters
      - name: Subscriber
  - taxclass: Wholesale Customer
    groups:
      - name: Trade
```

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `customergroups` | yes | list | One entry per tax class. |
| `customergroups[].taxclass` | yes | string | Must match the **name** of a tax class already present in the database. If it does not resolve, every group under it is skipped (error logged). |
| `customergroups[].groups` | yes | list | Groups to create under this tax class. |
| `customergroups[].groups[].name` | yes | string | The customer group code. Max **32 characters** (Magento's `customer_group_code` column limit). Missing or over-long names are skipped (error logged). |

## Behaviour

- **Create-only / idempotent.** A group is created only if no group with the same
  code already exists; otherwise it is skipped (info logged). Running the component
  repeatedly is safe.
- **Tax class is resolved by name**, not id. The named tax class must already exist
  (e.g. created earlier in `master.yaml`, or a Magento default such as
  `Retail Customer`).
- **Per-entry resilience.** A bad entry (unknown tax class, missing/over-long name)
  is logged and skipped without aborting the rest of the run.

## Notes / v2 changes

- v2 creates groups via `Magento\Customer\Api\GroupRepositoryInterface` (the
  deprecated active-record `->save()` path was removed). The input contract above is
  unchanged from v1.
- `maintain` mode (updating an existing group's tax class) is **not yet implemented**
  for this component — currently existing groups are left untouched. This is part of
  the planned "consistent create/maintain across all components" v2 work.
