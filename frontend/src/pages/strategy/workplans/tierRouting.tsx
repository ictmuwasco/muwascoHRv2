import { Navigate } from 'react-router-dom';
import { useAuth } from '../../../context/AuthContext';

/** The four workplan tiers exposed as routes under /strategy/workplans. */
export type TierKey = 'managing-director' | 'department-head' | 'section-head' | 'subsection-head';

export interface TierTab {
  key: TierKey;
  view: 'md' | 'department' | 'section' | 'subsection';
  label: string;
  description: string;
}

export const TIER_TABS: TierTab[] = [
  {
    key: 'managing-director',
    view: 'md',
    label: 'Managing Director',
    description: 'Organisation-wide activities anchored to strategic goals.',
  },
  {
    key: 'department-head',
    view: 'department',
    label: 'Department Head',
    description: 'Department commitments translated into operational activities.',
  },
  {
    key: 'section-head',
    view: 'section',
    label: 'Section Head',
    description: 'Cascaded departmental work broken into section activities.',
  },
  {
    key: 'subsection-head',
    view: 'subsection',
    label: 'Subsection Head',
    description: 'Operational tasks assigned to supervised employees.',
  },
];

/**
 * Authenticated role -> landing tier. Mirrors the backend defaultView()
 * so clicking the single "Workplans" sidebar item lands every user on
 * their own organisational level.
 */
const ROLE_TIER: Record<string, TierKey> = {
  managing_director: 'managing-director',
  super_admin: 'managing-director',
  hr_manager: 'managing-director',
  dept_head: 'department-head',
  manager: 'department-head',
  officer: 'department-head',
  section_head: 'section-head',
  sub_section_head: 'subsection-head',
};

export function defaultTierForRole(role: string): TierKey {
  return ROLE_TIER[(role || '').toLowerCase()] ?? 'department-head';
}

/**
 * Which tier tabs a role may open. Mirrors WorkplanController::availableViews()
 * (the backend remains the authorisation authority for the underlying data).
 */
export function visibleTiersForRole(role: string): TierKey[] {
  switch ((role || '').toLowerCase()) {
    case 'super_admin':
    case 'hr_manager':
    case 'managing_director':
      return ['managing-director', 'department-head', 'section-head', 'subsection-head'];
    case 'dept_head':
      return ['department-head', 'section-head', 'subsection-head'];
    case 'section_head':
      return ['section-head', 'subsection-head'];
    case 'sub_section_head':
      return ['subsection-head'];
    default:
      return ['department-head'];
  }
}

/** Index route: bounce the user straight to their own workplan tier. */
export function WorkplanTierRedirect() {
  const { user } = useAuth();
  const role = (user?.role || '').toLowerCase();
  return <Navigate to={`/strategy/workplans/${defaultTierForRole(role)}`} replace />;
}
