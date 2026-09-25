import type { ReactNode } from 'react';
import { useAuth } from '../../context/AuthContext';
import Button from './Button';

/**
 * Declarative permission gates — the UI half of "view never unlocks mutation".
 *
 * The backend already enforces permissions on every request (a view-only user
 * gets 403 on write endpoints); these components make the UI seamless by not
 * rendering mutation affordances the user can never use. Each gate is a thin
 * wrapper over AuthContext so pages stay one line and consistent:
 *
 *   <CanCreate module="employees">…button…</CanCreate>
 *   <CanEdit   module="employees" item={row}>…edit button…</CanEdit>
 *   <CanDelete module="employees" item={row}>…delete button…</CanDelete>
 *   <PermButton module="employees" require="update" … />   // imperative form
 *
 * Behaviour rules:
 *   - Nothing is rendered when the permission is missing (no disabled ghosts,
 *     no tooltips promising actions the user cannot take).
 *   - `fallback` may supply alternative UI (e.g. a lock hint) when needed.
 *   - canEdit/canDelete also honour ownership/scoping rules inside AuthContext.
 *
 * ── The global rule (applies to EVERY page) ─────────────────────────────────
 *   1. `<module>:view` unlocks READING ONLY. It never renders Add / Edit /
 *      Delete / Export / Approve / Publish / Import affordances.
 *   2. `<module>:create`, `:edit`/`:update` and `:delete` are THREE INDEPENDENT
 *      grants on granular modules — holding one never implies another
 *      (employees, departments, holidays, users, meetings, financial_year,
 *      delegations).
 *   3. Workflow actions keep their own key: leave:approve / reject / invalidate,
 *      meetings:export / confirm, hr_policies:publish, delegations:cancel, …
 *   4. Single-write modules expose ONE write action covering the whole write set
 *      (AuthContext MANAGE_FULL_WRITE_MODULES / EDIT_FULL_WRITE_MODULES:
 *      strategic_plan, performance_contract, workplan, kpi, sectional_objective,
 *      performance, hr_policies, attendance, consent, notifications, system*,
 *      profile). CanCreate/CanEdit/CanDelete already resolve it, so pages still
 *      gate with one line.
 *
 * ── Gate matrix (keep in sync when a page gains a new affordance) ────────────
 *   Page / component                       Module                 Gate
 *   -------------------------------------  ---------------------  ---------------------------
 *   employee/Employees                     employees              PermButton create
 *   employee/EmployeeForm (route-gated)    employees              employees:create | :edit
 *   employee/EmployeeProfile               employees              CanEdit (NOK, dependants,
 *                                                                 documents, renew, convert)
 *   employee/Profile                       profile                CanEdit (single-write)
 *   hr-admin/Departments                   departments            CanCreate/CanEdit/CanDelete
 *   hr-admin/Holidays                      holidays               PermButton create/edit/delete
 *   hr-admin/FinancialYear                 financial_year         create card + allocate card
 *   hr-admin/Appraisal                     performance            manage (create + approve)
 *   hr-admin/AppraisalCycles               performance            cycles (page + writes)
 *   settings/Admin                         financial_year         create
 *   settings/HrPolicies                    hr_policies            manage / publish
 *   settings/ErrorMonitoring               system_errors          manage
 *   settings/SettingsUsersTab              users                  create/edit/delete
 *   settings/SettingsPermissionsTab        permission_overrides   manage
 *   leave/Leave                            leave                  apply
 *   leave/ManageLeave*Tab                  leave                  approve/reject/invalidate
 *   leave/LeaveProfile                     leave                  export → manage (see note)
 *   leave/LeaveRoster | LeaveOversight     leave:roster           page perm doubles as write
 *   meetings/*                             meetings               create/edit/delete/export/confirm
 *   reports/*                              reports                view (read) + export
 *   attendance/AttendanceDashboard         attendance             manage (page + CSV export)
 *   strategy/*                             strategic_plan /       view + manage (single-write)
 *                                          workplan / kpi / …
 *
 * NOTE on exports: where the catalog HAS a dedicated export action
 * (reports:export, meetings:export, audit:export) it is used verbatim. Where it
 * does not, the export affordance is deliberately gated on the module's WRITE
 * action — leave profile CSV → leave:manage, attendance CSV → attendance:manage,
 * workplan CSV → workplan manage. That is stricter than the API route table
 * (which currently allows those exports on `<module>:view`) and is intentional:
 * bulk data extraction must not come free with read access. Relax here — or add
 * dedicated `<module>:export` catalog actions + route gates + seeds — if a
 * read-only audience ever legitimately needs export.
 */

interface GateProps {
  /** RBAC module key, e.g. "employees", "hr_policies", "system". */
  module: string;
  children: ReactNode;
  /** Optional alternative content rendered when the permission is absent. */
  fallback?: ReactNode;
}

interface ItemGateProps extends GateProps {
  /** The affected record — lets ownership rules (if any) participate. */
  item?: unknown;
}

/**
 * Renders children only when the user holds `<module>:<action>` (defaults to
 * `<module>:view`).
 */
export function Can({
  module,
  action,
  children,
  fallback = null,
}: GateProps & { action?: string }) {
  const { can } = useAuth();
  if (!can(module, action)) return <>{fallback}</>;
  return <>{children}</>;
}

/**
 * Create affordance — requires an explicit `<module>:create` grant. For
 * single-write modules (AuthContext lists) the sole write action counts
 * (profile:edit → Add document/NOK/dependant; <module>:manage → New).
 */
export function CanCreate({ module, children, fallback = null }: GateProps) {
  const { canCreate } = useAuth();
  if (!canCreate(module)) return <>{fallback}</>;
  return <>{children}</>;
}

/** Edit affordance — requires `<module>:update` (never view alone). */
export function CanEdit({ module, children, fallback = null }: ItemGateProps) {
  const { canEdit } = useAuth();
  if (!canEdit(module)) return <>{fallback}</>;
  return <>{children}</>;
}

/** Delete affordance — requires `<module>:delete` (never view/update alone). */
export function CanDelete({ module, children, fallback = null }: ItemGateProps) {
  const { canDelete } = useAuth();
  if (!canDelete(module)) return <>{fallback}</>;
  return <>{children}</>;
}

interface PermButtonProps {
  module: string;
  /** "create" | "update" | "delete" | any catalog action key. */
  require?: string;
  children: ReactNode;
  onClick?: () => void;
  className?: string;
  disabled?: boolean;
  loading?: boolean;
  variant?: 'primary' | 'secondary' | 'danger' | 'success' | 'outline' | 'ghost';
  size?: 'sm' | 'md' | 'lg';
  type?: 'button' | 'submit';
  title?: string;
}

/**
 * Button that only renders for users holding the required permission.
 * Default requirement is "update" (edit). Action keys resolve through the
 * central gates so both catalog conventions work:
 *   require="create"  → AuthContext.canCreate  (create | single-write action)
 *   require="update"  → AuthContext.canEdit    (edit | update | single-write manage)
 *   require="edit"    → AuthContext.canEdit    (same as update — dual-accept)
 *   require="delete"  → AuthContext.canDelete  (explicit delete | single-write action)
 *   any other key     → can(module, key)       (e.g. "apply", "export", "confirm")
 * A view-only user never sees the button either way.
 */
export function PermButton({
  module,
  require = 'update',
  children,
  onClick,
  className = '',
  disabled = false,
  loading = false,
  variant = 'primary',
  size = 'md',
  type = 'button',
  title,
}: PermButtonProps) {
  const { can, canCreate, canEdit, canDelete } = useAuth();
  const allowed =
    require === 'create'
      ? canCreate(module)
      : require === 'update' || require === 'edit'
        ? canEdit(module)
        : require === 'delete'
          ? canDelete(module)
          : can(module, require);
  if (!allowed) return null;
  return (
    <Button
      variant={variant}
      size={size}
      onClick={onClick}
      disabled={disabled}
      loading={loading}
      className={className}
      type={type}
      title={title}
    >
      {children}
    </Button>
  );
}

export default Can;
