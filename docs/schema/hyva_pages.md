# Hyvä CMS Pages (`hyva_pages`)

Manages **Hyvä Commerce CMS** page-builder content (the JSON draft/published
content attached to a native CMS page) from configurator sources.

> **Requires the paid `Hyva_CmsMagento` module.** The component is optional: when
> that module is not installed it logs a notice and does nothing (and it has no
> effect on `setup:di:compile`). The backing native CMS page must already exist
> (create it with the [`pages`](pages.md) component first).

## Source format

```yaml
about-us:
  # one of: content / content_source, or draft_/published_ variants
  content_source: app/etc/configurator/HyvaPages/content/about-us.json
  is_liveview_enabled: true
  version: 2
  create_version_history: true
  version_name: "Initial import"
home:
  content: '{"contentId":"1","elements":[]}'
```

The source is a map keyed by the **native CMS page identifier**.

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `<identifier>` | yes | map | Key is the existing CMS page `identifier`. |
| `.content` | one of | string | Inline JSON applied to **both** draft and published. |
| `.content_source` | one of | string | Path (relative to Magento root) to a JSON file applied to both draft and published. |
| `.draft_content` / `.draft_content_source` | no | string | Set the draft content only (inline or file). |
| `.published_content` / `.published_content_source` | no | string | Set the published content only (inline or file). |
| `.is_liveview_enabled` | no | bool | Defaults to `true`. |
| `.version` | no | int | Per-entity version (see the README "Reconciliation" section). |
| `.create_version_history` | no | bool | Write a Hyvä version-history entry after saving. |
| `.version_name` / `.version_emoji` | no | string | Labels for the history entry. |

JSON from a file is used **as-is** (validated but not re-encoded) to avoid
double-escaping; the file should carry the exact escaping Hyvä expects.

## Behaviour

- **Mode + versioning** via the shared gate: in `create` mode existing Hyvä
  content is protected (skipped) unless `version` is bumped; `maintain` reconciles.
  Unchanged content (matching `is_liveview_enabled` + draft + published) is skipped.
- **Persistence** is a direct insert/update on `hyva_commerce_cms_page` (keyed by
  `cms_page_id`) to preserve JSON escaping.
- **Missing CMS page** → a `ComponentException` is logged/recorded (not a crash).
- **Dry-run** logs `[dry-run] Would create/update Hyvä CMS page "<id>"` and writes nothing.
