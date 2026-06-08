# Admin Users (`adminusers`)

Creates admin users and assigns each to an existing admin role.

## Source format

```yaml
adminusers:
  - rolename: Sales Users
    users:
      - username: storeadmin
        firstname: store
        secondname: admin
        email: storeadmin@email.com
        password: fakepassword00
  - rolename: Tax Manager
    users:
      - username: taxmanager
        firstname: tax
        secondname: manager
        email:  taxmanager@email.com
        password: Fakepassword01
```

Users are grouped under the role they belong to. Multiple source files are merged under
the same `adminusers` node — the sample ships both `adminusers.yaml` and
`apiusers.yaml` (API users keyed by API role names such as `API Sales Users`), sharing
the same structure.

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `adminusers` | yes | list | Top-level node. Missing/non-array node produces an error. |
| `adminusers[].rolename` | yes | string | Name of an **existing** admin role to assign the users to. If it does not resolve, every user under it is errored and skipped. |
| `adminusers[].users` | yes | list | Users to create under this role. |
| `adminusers[].users[].username` | yes | string | Admin username. |
| `adminusers[].users[].firstname` | yes | string | First name. |
| `adminusers[].users[].secondname` | yes | string | Last name (mapped to `lastname`). |
| `adminusers[].users[].email` | yes | string | Email; also the uniqueness key for skip-if-exists. |
| `adminusers[].users[].password` | yes | string | Password (must satisfy Magento's admin password policy). |
| `adminusers[].users[].interface_locale` | no | string | Optional admin interface locale; set only when present. |

The five required fields are `username`, `firstname`, `secondname`, `email`,
`password`; a user missing or empty in any of them is skipped with an error.

## Behaviour

- **Create-only / idempotent by email.** A user is created only if no existing user
  has the same `email`; otherwise it is skipped (`recordSkipped`). Created users are
  set active with the resolved role id.
- **Role resolved by name.** `rolename` is looked up against existing roles. If it does
  not resolve, an error (`Admin Role "<name>" does not exist`) is recorded and all
  users under that role set are skipped — so the matching `adminroles` should run first.
- **Field validation.** Each user is checked for the five required fields before
  creation; missing/empty fields are recorded as an error and the user is skipped. The
  built user is also run through Magento's `$user->validate()`; failure is logged and
  the user is not saved.
- **No `adminusers` node** produces an error and the component returns.
- **Dry-run.** No users are written; a would-be-created user logs
  `[dry-run] Would create Admin User "<name>" (<email>)` and counts as created. Role
  resolution and field validation still run.
- **Error handling.** `interface_locale` is optional and applied only if present.
  Magento `ValidatorException` and `ComponentException` during creation are logged and
  recorded; the loop continues with the next user.

## Notes / v2 changes

- Existing users (matched by email) are never modified — the component is create-only;
  `mode` (create vs maintain) is not honoured.
- `secondname` in the YAML maps to the user's `lastname`.
