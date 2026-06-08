# SQL (`sql`)

Executes raw `.sql` files against the Magento database. Intended as a fallback for
cases where no dedicated configurator component exists.

## Source format

```yaml
sql:
  sitemap: ../configurator/Sql/sitemap.sql
```

The source YAML has a single top-level `sql` node mapping an arbitrary **name** to a
path of a `.sql` file. Paths are resolved relative to the Magento base path (`BP`).
Each referenced file contains raw SQL statements, for example:

```sql
-- Samples/Components/Sql/sitemap.sql
INSERT INTO `sitemap` (`sitemap_id`, `sitemap_type`, ...) VALUES (...);
```

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `sql` | yes | map | Top-level node. If missing or not an array, the component errors out. |
| `sql.<name>` | yes | string | A label of your choosing mapped to the path of a `.sql` file, resolved relative to the Magento base path (`BP`). The name is only used for logging. |

## Behaviour

- **Raw execution.** For each entry the file is read and its statements are run
  through `SqlSplitProcessor`, which splits the file into individual statements and
  executes them. There is no create/update/skip logic — the SQL does whatever it says.
- **Missing files are skipped.** If the resolved path does not exist, an error is
  logged (`<path> does not exist. Skipping.`) and processing continues with the next
  entry.
- **Dry-run.** Logs `[dry-run] Would execute SQL file "<name>" (<path>)`, records it as
  "created", and does **not** run any SQL.
- **No source node.** If the `sql` node is absent or not an array, the component records
  the error `No "sql" node found in the source data.` and returns.

## Notes / v2 changes

- v2 splits statement parsing/execution into a dedicated `SqlSplitProcessor` and adds
  **dry-run** support, which lists the files that would run without touching the
  database. The YAML contract (a `sql:` map of name → file path) is unchanged.
- Because statements run verbatim, this component is **not idempotent** unless the SQL
  itself is written to be re-runnable.
