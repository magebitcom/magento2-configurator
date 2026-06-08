# Sequence (`sequence`)

Creates the sales sequence tables (order, invoice, creditmemo, shipment) for given
store views, optionally overriding the prefix, suffix, start value, step, warning
value and max value. Useful for giving each store its own order-number series.

## Source format

```yaml
stores:
  default:                # store-view code (map key)
    prefix: PREFIX_
    startValue: 5000
  usa_en_us:
    prefix: USA_
    startValue: 1000
```

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `stores` | yes | map | Keyed by **store-view code**. Must be present and an array, otherwise the component errors out. |
| `stores.<code>` | yes | map | Per-store overrides. May be empty — any omitted key falls back to Magento's default sequence config. |
| `stores.<code>.prefix` | no | string | Sequence prefix. Defaults to the store **id** when not given. |
| `stores.<code>.suffix` | no | string | Sequence suffix. Defaults to the global sequence config. |
| `stores.<code>.startValue` | no | int | First number in the sequence. Defaults to the global sequence config. |
| `stores.<code>.step` | no | int | Increment step. Defaults to the global sequence config. |
| `stores.<code>.warningValue` | no | int | Warning threshold. Defaults to the global sequence config. |
| `stores.<code>.maxValue` | no | int | Maximum value. Defaults to the global sequence config. |

The sequence tables are created for **every** entity type in Magento's sequence
entity pool (order, invoice, creditmemo, shipment, …); you do not list entity types
in the YAML.

## Behaviour

- **Create-only.** For each store the component builds and creates a sequence table
  for every entity type. There is no diffing or update — handling of already-existing
  sequence tables is not yet implemented (marked with a `// todo` in the code), so
  re-running against stores that already have sequence tables may error per entity.
- **Mode.** The run mode is not consulted; behaviour is the same in create and
  maintain.
- **Dry-run.** With `--dry-run` no table is built; the component logs
  `[dry-run] Would create sequence table for <entityType>` and records each as
  created.
- **Per-entry resilience.** Each store is wrapped in its own try/catch (an unknown
  store code is logged and recorded as an error), and each entity-type creation is
  also individually guarded, so one failure does not abort the rest.

## Notes / v2 changes

- The `stores`-keyed override contract is unchanged from v1.
- v2 adds dry-run support and `ComponentResult` counts.
- Updating / reconciling existing sequence tables is still a known gap (`todo` in the
  component).
