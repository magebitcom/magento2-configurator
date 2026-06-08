# Catalog Price Rules (`catalog_price_rules`)

Creates and maintains Magento catalog price rules, optionally applying all rules after
the import.

## Source format

```yaml
config:
  apply_all: true
rules:
  rule1:
    name: Test Rule
    description: Some crafty description
    is_active: 1
    sort_order: 100
    website_ids:
      - 1
    customer_group_ids:
      - 1
      - 2
    from_date:
    ### Date format dd/mm/yyyy
    to_date: 11/10/2021
    conditions_serialized: '{"type":"Magento\\CatalogRule\\Model\\Rule\\Condition\\Combine","attribute":null,"operator":null,"value":"1","is_value_processed":null,"aggregator":"all","conditions":[{"type":"Magento\\CatalogRule\\Model\\Rule\\Condition\\Product","attribute":"sku","operator":"==","value":"12asd","is_value_processed":false}]}'
    actions_serialized: '{"type":"Magento\\CatalogRule\\Model\\Rule\\Action\\Collection","attribute":null,"operator":"=","value":null}'
    ### Values: [by_fixed|by_percent|to_percent|to_fixed]
    simple_action: by_fixed
    ### Range: 0-100
    discount_amount: 20
    stop_rules_processing: 0
```

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `config` | no | map | Optional configuration node. Defaults to `[]` if omitted. |
| `config.apply_all` | no | bool | If `true`, runs `Rule\Job::applyAll()` after saving so price changes take effect. Any other value (or omitted) skips the apply step. |
| `rules` | yes | map | Map of rule keys (e.g. `rule1`) to rule definitions. If missing or not an array, the component errors out. The key is just an identifier in the file; rules are matched in Magento **by `name`**, not by this key. |
| `rules.<key>.name` | yes | string | Rule name. Used to look up an existing rule for update. |
| `rules.<key>.description` | no | string | Rule description. |
| `rules.<key>.is_active` | no | int | `1` active, `0` inactive. |
| `rules.<key>.sort_order` | no | int | Priority. |
| `rules.<key>.website_ids` | no | list | Website ids the rule applies to. |
| `rules.<key>.customer_group_ids` | no | list | Customer group ids (e.g. `0` = NOT LOGGED IN). |
| `rules.<key>.from_date` | no | string | Start date, `dd/mm/yyyy`. May be left blank. |
| `rules.<key>.to_date` | no | string | End date, `dd/mm/yyyy`. |
| `rules.<key>.conditions_serialized` | no | string | Serialized JSON of the rule conditions tree. |
| `rules.<key>.actions_serialized` | no | string | Serialized JSON of the rule actions. |
| `rules.<key>.simple_action` | no | string | One of `by_fixed`, `by_percent`, `to_percent`, `to_fixed`. |
| `rules.<key>.discount_amount` | no | int/float | Discount value (0–100 for percentage actions). |
| `rules.<key>.stop_rules_processing` | no | int | `1` stops further rule processing, `0` continues. |

All keys under a rule are written straight onto the Magento rule model, so any field
the `CatalogRule` model accepts is valid; the table above lists those used in the
sample.

## Behaviour

- **Create or update, matched by name.** For each rule the component loads the existing
  rule collection filtered by `name`. If exactly one exists it is updated; if none
  exists a new rule is created.
- **Duplicate-name guard.** If more than one Magento rule already has the same `name`,
  that rule entry is skipped with an error logged.
- **Field-level diff.** When updating, only fields whose values differ from what is
  already stored are changed; matching values are logged as comments and left as-is.
- **Apply all.** When `config.apply_all` is `true`, all catalog price rules are applied
  after the run so the indexed prices reflect the changes.
- **Dry-run.** Logs `[dry-run] Would process N Catalog Price Rule(s)`, records the count
  as "created", and does not touch the database or apply rules.
- **Error handling.** Exceptions thrown while saving an individual rule are caught and
  logged; processing continues with the remaining rules.

## Notes / v2 changes

- v2 moves the create/update/apply logic into `CatalogPriceRulesProcessor` and the
  component itself only validates input and handles dry-run. The YAML contract
  (`config` + `rules`) is unchanged from v1.
- Explicit **dry-run** support was added in v2.
