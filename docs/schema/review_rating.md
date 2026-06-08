# Review Ratings (`review_rating`)

Creates or updates product review rating criteria (e.g. *Quality*, *Value*, *Price*) and
seeds each with a 1-5 option scale.

## Source format

```yaml
review_rating:
  Quality:
    is_active: 1
    position: 0
    stores:
      - default
  Value:
    is_active: 1
    position: 1
    stores:
      - default
  Price:
    is_active: 1
    position: 2
    stores:
      - default
```

Each key under `review_rating` is the **rating code** (the visible name of the criterion).

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `review_rating` | yes | map | One entry per rating criterion. A missing / non-array node adds the error `No "review_rating" node found in the source data.` and aborts. |
| `review_rating.<code>` | yes | map | Map key is the `rating_code`. Used to look up an existing rating, so it is also the update key. |
| `review_rating.<code>.is_active` | no | int (0/1) | Whether the rating is active. Defaults to `0` if omitted. |
| `review_rating.<code>.position` | no | int | Sort order. Defaults to `0` if omitted. |
| `review_rating.<code>.stores` | no | string or list | Store **code(s)** the rating is assigned to. Each code is resolved to a store id via the store repository; an unknown code throws and the rating fails (logged). Omitting `stores` assigns the rating to no store. |

## Behaviour

- **Create or update, keyed on `rating_code`.** The rating is loaded by code; if it has an
  id it is counted as *updated*, otherwise *created*. The entity is always bound to the
  `product` review entity type.
- **Options.** After saving, the rating is topped up to **5 options** (values/positions
  `1`-`5`). Existing option codes are preserved; if the rating already has 5 options nothing
  is added. (Note: options are only set on the live save path, not during dry-run.)
- **Dry-run.** Logs `[dry-run] Would update review rating "<code>"` and, for any missing
  options, `[dry-run] Would create rating option N for rating "<code>"`, recording the
  create/update without saving.
- **Error cases.** Per-rating exceptions (e.g. an unresolvable store code) are caught,
  logged as `Failed updating review rating "<code>"…`, added to the result errors, and the
  next rating still processes.

## Notes / v2 changes

- `is_active` and `position` are optional and silently default to `0`; only the rating
  **code** (the map key) is truly required.
- The rating is hard-wired to the `product` entity type — there is no field to target other
  review entities.
- The 5-option scale is fixed (`MAX_NUM_RATINGS = 5`) and not configurable from YAML.
