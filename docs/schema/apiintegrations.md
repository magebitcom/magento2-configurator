# API Integrations (`apiintegrations`)

Creates Magento API integrations, grants them ACL resource permissions, and
activates/authorises them (issuing an access token).

## Source format

```yaml
apiintegrations:
  - name: API Product Management        # required — integration name, also the uniqueness key
    email: apiprodmanagement@email.com
    setuptype: 1
    callbackurl:                        # endpoint URL (may be empty)
    identityurl:                        # identity link URL (may be empty)
    resources:                          # ACL resources to grant
      - 'Magento_Backend::admin'
      - 'Magento_Catalog::catalog'
      - 'Magento_Catalog::products'
      - 'Magento_Catalog::categories'
  - name: API Sales Management
    email: apisalesmanagement@email.com
    setuptype: 0
    callbackurl:
    identityurl:
    resources:
      - 'Magento_Backend::admin'
      - 'Magento_Sales::sales'
      - 'Magento_Sales::create'
```

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `apiintegrations` | yes | list | One entry per integration. |
| `apiintegrations[].name` | yes | string | Integration name. Used as the uniqueness key. An entry without a `name` is logged as an error and skipped. |
| `apiintegrations[].email` | yes | string | Contact email stored on the integration. |
| `apiintegrations[].callbackurl` | no | string | Stored as the integration `endpoint`. May be empty. |
| `apiintegrations[].identityurl` | no | string | Stored as the integration `identity_link_url`. May be empty. |
| `apiintegrations[].resources` | no | list | ACL resource ids granted to the integration (e.g. `Magento_Catalog::products`). Passed to the authorization service. |
| `apiintegrations[].setuptype` | no | int | Present in the sample but **not used** — the component always creates the integration with `setup_type = 0` (manual). |

On creation the integration is always given `status = 1` (active).

## Behaviour

- **Create-only / idempotent.** An integration is created only if no integration with
  the same `name` exists; an existing one is **skipped** (comment logged, recorded as
  skipped). There is no update path.
- **Permissions + authorisation.** After creating, the listed `resources` are granted
  and the integration is activated and authorised — a verifier/access token is created
  for its consumer.
- **Mode.** This component does not branch on create/maintain mode; behaviour is the
  same in both (create-if-missing).
- **Dry-run.** Logs `[dry-run] Would create API Integration "<name>"` and records the
  create, but does not create the integration, grant permissions, or issue a token.
- **Error handling.** Missing `apiintegrations` node records an error and returns. An
  entry without `name` is logged and skipped. Other per-entry `ComponentException`s are
  logged and recorded as errors without aborting the remaining entries.

## Notes / v2 changes

- `setuptype` from the sample is ignored; integrations are created with
  `setup_type = 0`.
- Dry-run support and the created/skipped result counters are v2 additions; the input
  contract is unchanged from v1.
