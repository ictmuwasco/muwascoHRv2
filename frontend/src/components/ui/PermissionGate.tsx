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

/** Renders children only when the user holds `<module>:<action>`. */
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

/** Create affordance — requires `<module>:create`. */
export function CanCreate({ module, children, fallback = null }: GateProps) {
  const { can } = useAuth();
  if (!can(module, 'create')) return <>{fallback}</>;
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
 * Default requirement is "update" (edit). Action keys are resolved through the
 * same dual-accept gates as canEdit/canDelete, so both catalog conventions
 * ("edit" and "update") work — a view-only user never sees the button either way.
 * Pass require="delete" for destructive buttons, "create" for Add buttons.
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
  const { can, canEdit, canDelete } = useAuth();
  const allowed =
    require === 'update'
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
