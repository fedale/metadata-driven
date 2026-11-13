# Plan: tenants and members in the admin UI

Status: **agreed**; part A implemented on branch `tenant_admin_members` (2026-11-13), waiting for
the user's review; part B to plan in detail. Discussed with the user on 2026-11-13. Builds on [multi-tenancy-plan.md](multi-tenancy-plan.md), whose
"Not in scope" left the admin UI out: until now tenants and memberships are managed from the
console only (decision 4 of that plan).

## Decisions (user, 2026-11-13)

1. **The members first** (part A): a page in each tenant's admin where its admins manage who
   may sign in there and with which roles. No new security decision is needed: it is the
   tenant's own admin, behind the tenant's own permissions.
2. **The operator's console later** (part B). Who may enter it: **a flag on the user**, an
   operator, set from the console; the console on a host of its own, `admin.<base host>`.

## Part A: the members page

`/admin/members`, linked from the admin's index, in each tenant.

### Who may use it

A new permission, `admin.members` (`Permissions::MEMBERS`), created by `access:sync` with the
others. Managing people is not managing the model: a tenant may let someone edit objects without
letting them add members, or the other way round. The super admin role passes it, as every check.

### What it shows

One row per member of the tenant: name, email, the tenant's roles they hold, last sign-in. The
roles are the tenant's RBAC items of type role, its super admin role included.

### What it does

- **Add a member**, by email. An existing account (users are global) becomes a member; a new
  email asks for a name and an initial password (12 characters at least, as `user:create`), and
  the account is created as a member. There is no email sending in the application yet, so no
  invitation: the admin passes the password on.
- **Change a member's roles**: a checkbox per role of the tenant (assign, revoke).
- **Remove a member**: they can no longer sign in to the tenant, and a session of theirs there
  ends at its next request. Their roles stay, unused (as `tenant:member:remove`).

### Guards

- **No lockout.** Your own row can't be changed at all: neither your roles nor your membership.
  Another admin does it. Simpler than working out which of your roles keeps `admin.members`
  through the role hierarchy, and as safe.
- **No escalation** (found by the automated security review, 2026-11-13). You give and take only
  the roles you hold, directly or through the hierarchy (`AccessCheckerInterface::holds()`; the
  super admin holds them all): the others a member has are shown, not offered, and kept when you
  save. You can't remove a member holding a role you don't. Without this, `admin.members` would
  be the power to make any account, one of your own included, super admin. A forged request
  outside these rules is a 403.
- **Accounts are global**, so adding an existing account by email tells the admin that the email
  has an account, and shows the name of its profile. That is how inviting by email works
  anywhere: the email is what the admin already knows. Nothing else of the account (its other
  tenants, its roles there) is shown.
- Every form has its CSRF token, as the rest of the admin.

### Tests

Functional: listing (nothing of another tenant), adding an existing account and a new one (and
a new one without a password refused), a role assigned and revoked, a member removed, your own
row neither shown with forms nor changeable by a forged request, a user without `admin.members`
refused, one with `admin.members` only let in. A removed member's session ending is
`TenantMembershipTest`'s.

### As built

- Access: `Permissions::MEMBERS`, synced by `access:sync`; `AccessCheckerInterface::canManageMembers()`;
  `TenantRolesInterface` (`roles()`, `rolesOf()`, `setRoles()`), through fedale/rbac-bundle.
- User: `UserManagerInterface::members()` (the `Member` DTO: email, name, last sign-in) and
  `exists()`; `MembershipRepository::membersWithProfiles()`.
- Admin: `AdminMemberController` (`/admin/members`, `/add`, `/{key}/roles`, `/{key}/remove`, a
  member named by the first 12 hex of the SHA-256 of their email), `templates/admin/members.html.twig`,
  the **Members** link of the top bar and of the admin's index; deptrac lets Admin use the User
  contract.
- Tests: `AdminMemberControllerTest` (8, one for the escalation). A test reading the RBAC assignments after a change
  follows the redirect first: the bundle keeps what it read during a request in memory.

### Documentation

access.md (the page, next to the commands), admin.md (the admin's pages), multi-tenancy.md
("Not yet" loses the members), status.md.

## Part B: the operator's console (later)

Sketched here, planned in detail when part A is done.

- `app_user.is_operator`, set and cleared from the console (`tenant:operator:grant|revoke
  <email>`); no page grants it.
- The console answers on `admin.<base host>`: the slug `admin` is reserved (`tenant:create`
  refuses it), and the request runs in the landlord's session.
- Pages: the tenants (list, create, suspend, resume, delete), a tenant's members, the template
  indexes offered to every tenant. Each the counterpart of a `tenant:*` command, through the
  same services.
- To decide then: whether an operator signs in there with their ordinary account and password
  (the flag on top), and whether the console needs a second factor.
