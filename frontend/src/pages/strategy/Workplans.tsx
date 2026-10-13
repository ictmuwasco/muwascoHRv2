import { NavLink, Outlet } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { TIER_TABS, visibleTiersForRole } from './workplans/tierRouting';
import StrategyTabs from '../../components/strategy/StrategyTabs';

/**
 * Unified cascading workplan entry point.
 *
 * One module, four organisational levels (Managing Director → Department →
 * Section → Subsection). Clicking "Workplans" in the sidebar lands every
 * user on their own level via the role-based index redirect; privileged
 * users can inspect the other levels through the tier tabs below.
 */
export default function Workplans() {
  const { user } = useAuth();
  const role = (user?.role || '').toLowerCase();
  const visibleTiers = visibleTiersForRole(role);

  return (
    <div className="space-y-6">
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Workplans</h1>
          <p className="text-gray-500 dark:text-gray-400">
            One integrated cascade: plan at your level, execute through the next and report upward.
          </p>
        </div>
      </div>

      {/* Strategy & Performance module tabs (shared — includes KPIs link) */}
      <StrategyTabs />

      {/* Organisational cascade tiers — every role sees exactly the tabs it is
          allowed: super_admin all four, hr_manager Managing Director +
          Department Head, everyone else only their own level. */}
      {visibleTiers.length > 0 && (
        <div className="flex space-x-1 border-b overflow-x-auto">
          {TIER_TABS.filter((t) => visibleTiers.includes(t.key)).map((tier) => {
            const to = `/strategy/workplans/${tier.key}`;
            return (
              <NavLink
                key={tier.key}
                to={to}
                end
                title={tier.description}
                className={({ isActive }) =>
                  `px-4 py-2 text-sm font-medium whitespace-nowrap border-b-2 transition-colors ${
                    isActive
                      ? 'border-purple-600 text-purple-600'
                      : 'border-transparent text-gray-500 hover:text-gray-700'
                  }`
                }
              >
                {tier.label}
              </NavLink>
            );
          })}
        </div>
      )}

      <Outlet />
    </div>
  );
}
