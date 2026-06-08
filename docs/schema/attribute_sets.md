# Attribute Sets (`attribute_sets`)

Creates product attribute sets, optionally inheriting from an existing (skeleton)
set, and assigns attributes into named attribute groups within each set.

## Source format

```yaml
attribute_sets:
  - name: Default                    # required — attribute set name
    inherit: Default                 # optional — skeleton set to copy from
    groups:
      - name: General                # group display name
        code: general                # optional — group code (derived from name if omitted)
        attributes:
          - color
  - name: Shirts
    inherit: Default
    groups:
      - name: General
        code: general
        attributes:
          - colour
          - color
      - name: Prices
        code: prices
        attributes:
          - rrp
          - test_attr
  - name: Example Attribute Set 2     # groups without an explicit code
    inherit: Default
    groups:
      - name: Prices
        attributes:
          - rrp
  - name: Matthew Attribute Set 2     # no inherit, no skeleton
    groups:
      - name: Prices
        attributes:
          - rrp
```

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `attribute_sets` | yes | list | One entry per attribute set. Must be present and an array. |
| `attribute_sets[].name` | yes | string | Attribute set name (created against the catalog `product` entity). |
| `attribute_sets[].inherit` | no | string | Name of an existing attribute set used as the skeleton (`initFromSkeleton`). When omitted, the set is created bare. The named set must exist or an error is raised for that entry. |
| `attribute_sets[].groups` | no | list | Attribute groups to ensure inside the set. |
| `groups[].name` | yes | string | Group display name. |
| `groups[].code` | no | string | Group code. If omitted, it is derived from the name via `convertToAttributeGroupCode`. Used to detect whether the group already exists. |
| `groups[].attributes` | yes (within a group) | list | Attribute **codes** to add to the group. Each must already exist as a product attribute, otherwise a `ComponentException` is raised. |

## Behaviour

- **Create + idempotent groups.** The attribute set itself is created via
  `EavSetup::addAttributeSet` on every run (and counted as created). Within a set,
  each group is only created if it does not already exist (looked up by code);
  existing groups are reused and logged as existing. Attribute-to-group association
  is then applied for the listed attributes.
- **Mode.** The run mode is not consulted; behaviour is the same in create and
  maintain. There is no diff/update of the set's other properties beyond
  (re)creating it and reconciling groups/attributes.
- **Dry-run.** With `--dry-run` nothing is written; the component logs
  `[dry-run] Would create attribute set: "<name>"`, records it as created, and
  returns early — groups and attribute associations are **not** processed under
  dry-run.
- **Per-entry / per-group resilience.** Group processing catches
  `Zend_Db_Statement_Exception` and logs a hint that Magento sometimes uses different
  attribute codes vs. names (so you may need to set `code` explicitly) rather than
  aborting. A missing attribute code in a group's `attributes` list raises a
  `ComponentException` that is caught at the component level and recorded as an error.

## Notes / v2 changes

- The `name` / `inherit` / `groups` / `attributes` contract is unchanged from v1.
- v2 adds dry-run support and `ComponentResult` counts; note that dry-run reports
  only the set creation and skips group/attribute processing.
- The set is (re)created on every run with no existence check, so groups/attributes
  carry the idempotency — pair this component with `attributes` so the referenced
  attribute codes exist before it runs.
