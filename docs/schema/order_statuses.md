# Order Statuses (`order_statuses`)

Creates custom sales order statuses and assigns each to an order state.

## Source format

```yaml
order_statuses:
  - state: processing
    statuses:
      - code: processing_custom1
        name: Processing Custom 1
      - code: processing_custom2
        name: Processing Custom 2
  - state: new
    statuses:
      - code: new_custom1
        name: New Custom 1
```

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `order_statuses` | yes | list | One entry per order state. If missing or not an array, the component errors out. |
| `order_statuses[].state` | yes | string | The order **state** (e.g. `new`, `processing`) the statuses are assigned to via `assignState()`. |
| `order_statuses[].statuses` | yes | list | The statuses to create under this state. |
| `order_statuses[].statuses[].code` | yes | string | The status code, stored as the `status` value (machine name). |
| `order_statuses[].statuses[].name` | yes | string | The status label shown in the admin. |

## Behaviour

- **Create + assign to state.** Each status is saved with its `code`/`name`, then
  assigned to the parent `state`. The assignment is made with `isDefault = false` and
  `visibleOnFront = true`.
- **Save is upsert-like.** The status is saved via the order-status resource model; a
  status sharing the same code is updated rather than duplicated. Errors raised while
  saving an individual status are caught and logged, and the status is still assigned
  to the state.
- **Dry-run.** Logs `[dry-run] Would create order status <name>` per status, records it
  as "created", and performs no save or state assignment.
- **No source node.** If `order_statuses` is absent or not an array, the component
  records the error `No "order_statuses" node found in the source data.`
- **Per-set resilience.** A `ComponentException` thrown while processing one state set
  is logged and recorded as an error without aborting the remaining sets.

## Notes / v2 changes

- v2 adds **dry-run** support (statuses are listed but not created/assigned). The YAML
  contract (`order_statuses` → `state` + `statuses` of `code`/`name`) is unchanged from
  v1.
