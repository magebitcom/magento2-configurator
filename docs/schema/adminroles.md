# Admin Roles (`adminroles`)

Creates admin (ACL) roles and assigns the resources each role may access.

## Source format

```yaml
adminroles:
  - name: "Sales Users"
    resources:
      - 'Magento_Backend::dashboard'
      - 'Magento_Backend::admin'
      - 'Magento_Sales::sales'
      - 'Magento_Sales::create'
      - 'Magento_Sales::actions_view'
      - 'Magento_Sales::actions_edit'
      - 'Magento_Sales::cancel'
      - 'Magento_Sales::hold'
      - 'Magento_Sales::unhold'
  - name: "Tax Manager"
    resources:
      - 'Magento_Backend::dashboard'
      - 'Magento_Sales::sales'
      - 'Magento_Sales::sales_operation'
  - name: "Admin"
    resources:
```

Multiple source files are merged under the same `adminroles` node — the sample ships
both `adminroles.yaml` (UI roles) and `apiroles.yaml` (API roles, e.g. `API Sales
Users`), referenced together under the `adminroles` master node. They share the exact
same structure.

### Fields

| Path | Required | Type | Notes |
|------|----------|------|-------|
| `adminroles` | yes | list | Top-level node. Missing/non-array node produces an error. |
| `adminroles[].name` | yes | string | Role name (`role_name`). Missing `name` raises an error for that entry. Used to match existing roles. |
| `adminroles[].resources` | no | list | ACL resource ids the role may access (e.g. `Magento_Sales::sales`). If omitted/empty (as in the `Admin` example) an error is logged and resources are left unchanged. |

## Behaviour

- **Create + update resources.** A role is matched by `role_name`. If it does not
  exist it is created (parent id `0`, role type group, admin user type) and counted as
  created. If it already exists creation is skipped (`recordSkipped`) but its
  resources are still (re)applied.
- **Resource assignment.** Resources are written via the rules model
  (`setRoleId()->setResources()->saveRel()`), so an existing role's accessible
  resources are overwritten to match the YAML on every run.
- **Missing `resources`.** When `resources` is `null` (key omitted), an error is
  logged (`resources are empty, please check your yaml file`) and the role's resources
  are not touched.
- **Missing `name`.** Raises a `ComponentException` for that entry (logged + recorded);
  the loop continues with the next entry.
- **No `adminroles` node** produces an error and the component returns.
- **Dry-run.** Nothing is written. Creating a role logs
  `[dry-run] Would create Admin Role "<name>"` and counts as created; resource updates
  log `[dry-run] Would update resources for Admin Role "<name>"`.

## Notes / v2 changes

- A role's resources are reconciled to the YAML on every run, even for already-existing
  roles — so this component effectively maintains ACL resources, while role creation
  itself is create-only.
- The `Admin` entry with an empty `resources:` is a sample placeholder; loading it as-is
  logs the "resources are empty" error and changes nothing.
