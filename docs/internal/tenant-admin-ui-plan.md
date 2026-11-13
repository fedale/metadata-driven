# Plan: tenants and members in the admin UI

Status: **agreed**; part A done (branch `tenant_admin_members`, committed); part A2, the
invitations, in progress on branch `tenant_admin_invitations`; part B planned and agreed
(2026-11-13), next. Discussed with the user on 2026-11-13. Builds on [multi-tenancy-plan.md](multi-tenancy-plan.md), whose
"Not in scope" left the admin UI out: until now tenants and memberships are managed from the
console only (decision 4 of that plan).

## Decisions (user, 2026-11-13)

1. **The members first** (part A): a page in each tenant's admin where its admins manage who
   may sign in there and with which roles. No new security decision is needed: it is the
   tenant's own admin, behind the tenant's own permissions.
2. **The operator's console later** (part B). Who may enter it: **a flag on the user**, an
   operator, set from the console; the console on a host of its own.
3. **The console's host is `console.<base host>`**, not `admin.<base host>`: a tenant's own admin
   is at `/admin`, and the two would be confused.
4. **No second factor for now**: sign-in throttling and the reserved host now; a second factor
   later, as a feature of its own for every user (enrolment, recovery codes).
5. **A tenant can be deleted from the console** too, confirmed by typing its slug, as
   `tenant:delete`; the default tenant can't.

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
- **No escalation** (found by the automated security review, 2026-11-13, in two rounds). You give
  and take only the roles you hold, directly or through the hierarchy
  (`AccessCheckerInterface::holds()`; the super admin holds them all). A member holding a role you
  don't is out of your reach: no roles form, no removal, and no adding back once removed (a
  removed member keeps their roles in the tenant). "A role you don't" means any item assigned to
  them directly, permissions given with `rbac:assign` included; and the check works on the
  account's canonical email, never the one typed (`canonicalEmail()`: the assignments are keyed
  by the stored, lower-case email) — both found by the review's third round. Without this, `admin.members` would be the
  power to make any account, one of your own included, super admin, or to take from those above.
  A forged request outside these rules is a 403.
- **Existing accounts are revealed**: the review flagged it too. Kept, as the user's to decide:
  see "Accounts are global" above.
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

- Access: `Permissions::MEMBERS`, synced by `access:sync`; `AccessCheckerInterface::canManageMembers()`
  and `holds()`; `TenantRolesInterface` (`roles()`, `rolesOf()`, `assignmentsOf()`,
  `setRoles()`), through fedale/rbac-bundle.
- User: `UserManagerInterface::members()` (the `Member` DTO: email, name, last sign-in) and
  `canonicalEmail()`; `MembershipRepository::membersWithProfiles()`.
- Admin: `AdminMemberController` (`/admin/members`, `/add`, `/{key}/roles`, `/{key}/remove`, a
  member named by the first 12 hex of the SHA-256 of their email), `templates/admin/members.html.twig`,
  the **Members** link of the top bar and of the admin's index; deptrac lets Admin use the User
  contract.
- Tests: `AdminMemberControllerTest` (12, five for the escalations). A test reading the RBAC assignments after a change
  follows the redirect first: the bundle keeps what it read during a request in memory.

### Documentation

access.md (the page, next to the commands), admin.md (the admin's pages), multi-tenancy.md
("Not yet" loses the members), status.md.

## Part A2: invitations

Decided by the user on 2026-11-13: adding an existing account by email told the admin that the
email had an account, and showed its profile's name ("Accounts are global" above). That is now
**limited**, with invitations, before part B.

- **The members page asks for an email only**, and always answers the same: an invitation is
  created, and its link shown once, for the admin to pass on (the application sends no email).
  No admin chooses another person's password any more. Pending invitations are listed with the
  email as the admin typed it, who invited, and when it expires, and can be revoked.
- **The invitation page**, `/invitation/{token}` on the tenant's host, public: whoever has an
  account confirms it with their password; whoever hasn't chooses a name and a password. Either
  way they become a member and are signed in.
- **Probing is costly and logged**: an invitation names one email, lasts 7 days, and is burned
  after 5 wrong passwords; it shows nothing of the account. What is left: whoever opens a link
  (its admin included) sees whether it asks for a password that must match. Hiding that would take
  sending the invitation to the email itself.
- **No escalation through an invitation**: when the account holds assignments in the tenant its
  inviter doesn't hold (a former member above them), the invitation records it, and those
  assignments are taken away when it is accepted. The admin's answer is the same either way.
- `core.tenant_invitation` (the landlord's, as `tenant_membership`): the email, a SHA-256 of the
  token (the token itself is shown once, never stored), who invited, created, expires, accepted,
  failed attempts, whether to keep the account's assignments.
- `tenant:member:add` (the operator's) still adds an existing account at once.

As built: `Version20261113100000`; `UserManagerInterface::invite()`, `invitations()`,
`revokeInvitation()`, `openInvitation()`, `acceptInvitation()` (`InvitationRepository`,
`DbalInvitationRepository`, the `Invitation` DTO); `TenantRolesInterface::revokeAll()`;
`InvitationController` (`/invitation/{token}`, public; `/invitation/welcome`). The account is
signed in at the request after the acceptance, not during it: fedale/rbac-bundle keeps the
assignments it read during a request (`MemoizedAssignmentStorage`, with no reset), so a user
loaded right after `revokeAll()` would carry the roles just taken, and be signed out at the next
request for it. Tests: `AdminMemberControllerTest` (14).

## Part B: the operator's console

Planned in detail on 2026-11-13, branch `tenant_admin_operator` (from `tenant_admin_members`),
with decisions 3 to 5 above.

### Who is an operator

- `app_user.is_operator`, false by default, a property of the domain `User`. Set and cleared from
  the console only: `tenant:operator:grant <email>`, `tenant:operator:revoke <email>`,
  `tenant:operator:list`. No page grants it, so a stolen operator session can't make more
  operators.
- An operator signs in with their ordinary account and password. The flag adds the console; it
  gives nothing inside a tenant (decision 3 of the multi-tenancy plan stands: inside a tenant an
  operator is a member with a role, or nobody).

### Where the console is

- On its own host, `<label>.<base host>`, the label `console` (`app.tenant.console_label`). The
  label is **reserved**: `tenant:create` refuses it as a slug, and so does a list of others no
  tenant should take (`admin`, `www`, `api`, `mail`, `static`).
- `HostTenantListener` recognises that host before looking up a tenant: the request runs in the
  landlord's session (`TenantSwitcher::landlord()`), and gets a request attribute saying so.
- Only the console's routes answer there (`/` redirects to `/tenants`, plus `/login`,
  `/logout`); every tenant route is a 404 on it, and every console route a 404 on a tenant's host.

### Signing in

- The same firewall and sign-in page. `UserProvider`, on the console's host, finds only active
  operators (anyone else fails like a wrong password), and gives them `ROLE_OPERATOR`;
  `access_control` requires it for the console's paths.
- The `SecurityUser` of a console session has no tenant, so phase 3's binding keeps it apart from
  every tenant's sessions, and a tenant's session from it. Revoking the flag signs the session out
  at its next request (the user is loaded again, and `isEqualTo()` compares the role).

### Pages

Each the counterpart of a `tenant:*` command, through the same services:

| Page | Does |
|---|---|
| `/tenants` | The tenants: slug, name, status, members, objects; links to each |
| `/tenants/new` | Create: slug, name, first admin (an existing account, or a new one with an initial password), a template from the indexes offered to every tenant |
| `/tenants/{slug}` | Suspend or resume; delete (decision 5); its members: add an account, remove one, give or take its super admin role |
| `/indexes` | The template indexes offered to every tenant (`tenant:template:repo`) |

### How the pages reach the services

The console is a module's UI (a new `Operator` module, or the Admin module's), which may use the
shared layer's ports but not its infrastructure. New ports in `src/Shared/Tenant/`:

- `TenantAdministration`: create, suspend, resume, delete (`TenantProvisioner` implements it).
- `TenantSessions::within(Tenant, callable)`: runs work in a tenant's session and comes back to
  the landlord's, for the steps that are a tenant's (its permissions, its first admin's role,
  its template). `tenant:create` then uses the same services instead of running commands.

### Safety

- **Sign-in throttling** (`symfony/rate-limiter`, `login_throttling` in security.yaml): 5 tries a
  minute per address and email, for every sign-in, tenants' included, which have none today.
- Every form with its CSRF token; deletion confirmed by typing the slug, as the command.
- Every action of the console in the log (`app.operator` channel): who, what, which tenant.

### Tests

Functional, on the console's host and a tenant's: a non-operator refused at sign-in, an operator
let in; the console's routes 404 on a tenant's host and the tenant's on the console's; each page's
action (create with an admin, suspend, resume, delete, members, indexes); a revoked flag signing
out; a reserved slug refused; the throttling after five tries.

### Documentation

multi-tenancy.md (the console, the operators, production: the console's host in DNS and TLS),
console.md (`tenant:operator:*`), access.md (operators), status.md.
