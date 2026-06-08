# Websites (`websites`)

Manages the store hierarchy: websites, store groups (stores) and store views.
Each website contains one or more store groups, and each store group contains one
or more store views and names one of them as its default store.

## Source format

```yaml
websites:
  base:                              # website code (map key)
    name: Configurator's Website UK
    store_groups:
      - group_id: 1                  # optional — load an existing store group by id
        name: Main Website Store
        root_category_id: 2
        default_store: default       # required — store-view code used as the group's default
        store_views:
          default:                   # store-view code (map key)
            name: Configurator's Store View
            is_active: 1
  usa:
    name: Configurator's Website US
    store_groups:
      - name: Configurator's Website Store US
        code: usa_en
        root_category_id: 2
        default_store: usa_en_us
        store_views:
          usa_en_us:
            name: Configurator's USA Store View
            is_active: 1
```

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `websites` | yes | map | Keyed by **website code**. The key is written to the website's `code`. |
| `websites.<code>.name` | recommended | string | Website name. Any other key here is set verbatim onto the website model (e.g. `sort_order`, `is_default`), so the map doubles as the website's column data. |
| `websites.<code>.store_groups` | yes | list | One or more store groups under the website. The component iterates this unconditionally, so it must be present. |
| `store_groups[].group_id` | no | int | If set, the store group is loaded by id; otherwise it is loaded/created by `name`. |
| `store_groups[].name` | yes | string | Store group name. Used as the load key when `group_id` is absent, and set onto the model. |
| `store_groups[].root_category_id` | recommended | int | Root category id for the group. Set verbatim onto the model. |
| `store_groups[].default_store` | yes | string | Store-view **code** to make the group's default store. Must resolve to a store view that belongs to this group, otherwise an error is recorded. |
| `store_groups[].store_views` | yes | map | Keyed by **store-view code**. Iterated unconditionally, so it must be present. |
| `store_views.<code>.name` | recommended | string | Store-view name. Other keys (e.g. `is_active`) are set verbatim onto the store-view model. |
| `store_views.<code>.is_active` | recommended | int | `1`/`0`. |

## Behaviour

- **Create + maintain (always-on diffing).** For websites, store groups and store
  views the component loads the existing record by code (or `group_id`), then
  compares every supplied scalar key against the live value. Differences are written
  and the record is saved; if nothing differs the record is skipped. This component
  does not read the run mode — it always behaves like maintain.
- **Default store** is resolved after all the group's store views are processed
  (so a newly created store view can be named). It is only changed if it currently
  differs.
- **Dry-run.** With `--dry-run` no `save()` is performed; the component logs
  `[dry-run] Would save …` for each website / store group / store view that would
  change, and the planned default-store change. Counts are still recorded.
- **Reindex.** If any website, store group or store view is newly created, a
  `catalog_product_price` reindex is run at the end of the component (logged only
  under dry-run).
- **Per-entry resilience.** Each website / group / store-view / default-store step
  is wrapped in its own try/catch — a `ComponentException` (e.g. a `default_store`
  that does not belong to the group) is logged and recorded as an error without
  aborting the rest of the run.

## Notes / v2 changes

- The input contract is unchanged from v1: website/group/store-view maps still carry
  arbitrary model columns that are written verbatim.
- v2 adds dry-run support (`[dry-run] Would save …`) and routes counts through
  `ComponentResult` (created / updated / skipped / errors).
- `store_groups` and `store_views` are iterated without an `isset` guard, so both
  keys must exist for every entry; a missing `store_groups`/`store_views` will raise
  an error rather than silently skip.
