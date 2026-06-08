# Config (`config`)

Sets `core_config_data` values at the global, website or store-view scope. Multiple
source files are merged, so global defaults and per-website/per-store overrides can
live in separate YAML files.

## Source format

A source file is keyed by **scope**: `global`, `websites` or `stores`. Each scope
holds a flat list of `path` / `value` entries (websites and stores group those lists
under a website/store-view code).

Global scope (`global.yaml`):

```yaml
global:
  - path: general/country/default
    value: US
  - path: carriers/tablerate/active
    value: 1
  - path: carriers/tablerate/condition_name
    value: package_value
```

Website + store scope (`base-website-config.yaml`):

```yaml
websites:
  base:                              # website code
    - path: general/country/default
      value: GB
stores:
  default:                           # store-view code
    - path: general/locale/code
      value: en_GB
```

A config entry may also carry `encryption` and `version` (not shown in the samples):

```yaml
global:
  - path: some/secret/key
    value: my-secret
    encryption: 1                    # encrypt the value before saving
    version: 2                       # bump to force a re-apply in create mode
```

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| top-level key | yes | string | Must be one of `global`, `websites`, `stores`. Any other scope raises an error and aborts the file. |
| `global[]` | — | list | Flat list of config entries applied at default scope. |
| `websites.<code>[]` | — | list | Config entries for the website with that **code**. Unknown code raises an error for that entry. |
| `stores.<code>[]` | — | list | Config entries for the store view with that **code**. Unknown code raises an error for that entry. |
| `…[].path` | yes | string | `core_config_data` path, e.g. `general/locale/code`. |
| `…[].value` | yes | scalar | Value to store. For `design/theme/theme_id`, a non-int value is resolved to the theme id by full theme path. |
| `…[].encryption` | no | `1`/`0` | If `1`, the value is encrypted before saving. Encryption is also forced automatically when the path's backend model is Magento's `Encrypted` model. |
| `…[].version` | no | int | Version stamp. In **create** mode a bumped version forces the value to be re-applied even though it already exists (see Behaviour). |

## Behaviour

- **Idempotent.** Before writing, the current DB value at the target scope is read.
  If it equals the supplied value, the entry is skipped.
- **Mode (`create` vs `maintain`).**
  - `maintain` (default): the value is written whenever it differs from the DB
    value — existing manual changes are overwritten back to the configured value.
  - `create`: if a value already exists at that scope it is left alone, **unless**
    the entry carries a `version` newer than the last applied version for that
    path/scope (tracked via the version-management store), in which case it is
    re-applied. After a successful save the new version is recorded.
- **Dry-run.** With `--dry-run` nothing is saved; the component logs
  `[dry-run] Would set … = …` and records the entry as created.
- **Per-entry resilience.** Website/store lookups and saves are wrapped per entry;
  an unknown website/store code or other `ComponentException` is logged and recorded
  as an error without stopping the rest of the file. An invalid top-level scope,
  however, aborts processing of that file.

## Notes / v2 changes

- The `path` / `value` contract is unchanged from v1.
- v2 adds the optional **`version`** field and version-aware create mode: in create
  mode a value is normally written once and then left alone, but bumping `version`
  re-applies it. This lets you ship config changes that should overwrite an existing
  value only on demand.
- v2 adds dry-run support and routes created / skipped / error counts through
  `ComponentResult`.
- Automatic encryption detection (via the path's backend model) is in addition to
  the explicit `encryption: 1` flag.
