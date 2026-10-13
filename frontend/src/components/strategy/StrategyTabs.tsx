import { Link, useLocation } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';

interface StrategyTab {
  to: string;
  label: string;
  /** Route-guard permission for this tab — mirrors config/pagePermissions.jsx. */
  module: string;
  action: string;
}

/**
 * StrategyTabs — single shared sub-navigation for the Strategy & Performance
 * module (replaces the five copy-pasted TABS arrays that drifted apart).
 *
 * Before: only Kpis.tsx listed all six links — StrategicPlan,
 * PerformanceContracts, Workplans and StrategyReports omitted the KPIs and
 * Performance Appraisals links, so a user on any of those pages could never
 * reach the KPI scoring units for appraisals without going back through the
 * sidebar. SupervisorAppraisals (route /strategy/performance-appraisals) had
 * no module bar at all.
 *
 * After: every page renders this component, so the bar is identical
 * everywhere. Tabs the caller lacks the route permission for are hidden (UX
 * only — Guarded routes + the API enforce access independently), and the
 * Performance Appraisals tab keeps the Kpis.tsx rule (supervise permission
 * plus never officer / employee / bod_chairman, mirroring Sidebar.jsx
 * canViewSupervisorAppraisals).
 */
const ALL_TABS: StrategyTab[] = [
  { to: '/strategy/strategic-plan', label: 'Strategic Plan', module: 'strategic_plan', action: 'view' },
  {
    to: '/strategy/performance-contracts',
    label: 'Performance Contracts',
    module: 'performance_contract',
    action: 'view',
  },
  { to: '/strategy/workplans', label: 'Workplans', module: 'workplan', action: 'view' },
  { to: '/strategy/kpis', label: 'KPIs', module: 'sectional_objective', action: 'view' },
  {
    to: '/strategy/performance-appraisals',
    label: 'Performance Appraisals',
    module: 'performance',
    action: 'supervise',
  },
  { to: '/strategy/reports', label: 'Performance Reports', module: 'strategic_plan', action: 'view' },
];

const SUPERVISOR_EXCLUDED_ROLES = ['officer', 'employee', 'bod_chairman'];

export default function StrategyTabs() {
  const location = useLocation();
  const { user, can } = useAuth();

  const canViewSupervisorAppraisals =
    !!user &&
    !SUPERVISOR_EXCLUDED_ROLES.includes(String((user as any)?.role || '').toLowerCase()) &&
    can('performance', 'supervise');

  const visible = ALL_TABS.filter((tab) => {
    if (tab.to === '/strategy/performance-appraisals') return canViewSupervisorAppraisals;
    if (tab.to === '/strategy/kpis') {
      // KPI page gate is sectional_objective:view (pagePermissions), but the
      // sidebar treats kpi:view as an equivalent strategy signal — accept
      // either so the tab never disappears for a user who can open the page.
      return can('sectional_objective', 'view') || can('kpi', 'view');
    }
    return can(tab.module, tab.action);
  });

  // Nothing the caller may open (should not happen — every host page passed
  // its own route guard): render nothing rather than a dead bar.
  if (visible.length === 0) return null;

  const isActive = (to: string) => {
    // Workplans has tier sub-routes (/strategy/workplans/<tier>) that must
    // keep the parent tab highlighted.
    if (to === '/strategy/workplans') {
      return (
        location.pathname === to || location.pathname.startsWith('/strategy/workplans/')
      );
    }
    return location.pathname === to;
  };

  return (
    <div className="flex space-x-1 border-b overflow-x-auto">
      {visible.map((tab) => (
        <Link
          key={tab.to}
          to={tab.to}
          className={`px-4 py-2 text-sm font-medium whitespace-nowrap border-b-2 transition-colors ${
            isActive(tab.to)
              ? 'border-primary-600 text-primary-600'
              : 'border-transparent text-gray-500 hover:text-gray-700'
          }`}
        >
          {tab.label}
        </Link>
      ))}
    </div>
  );
}
