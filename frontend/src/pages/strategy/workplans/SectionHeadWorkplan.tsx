import TierWorkplanPage from './TierWorkplanPage';

/**
 * Section Head workplan: the department's activities given to this section are
 * offered as SOURCES in the "Add Section Workplan" form (not listed in this
 * table), where they are broken down into the section's own activities — those
 * own activities are what the table shows, and they cascade further down to
 * subsections.
 *
 * The "Add Section Workplan" flow (POST /workplans with a parent_objective_id)
 * inherits the parent's performance contract — section heads never re-pick a
 * contract. Same-level nesting under a section-level source is permitted
 * (relaxed level validation) because it models a work breakdown, not a
 * structural cascade; the per-row Cascade button pushes one level further
 * down to subsections (section → subsection, enforced as strictly-below by
 * cascadeAction) — and only appears when the section actually HAS subsections
 * to receive the work.
 */
export default function SectionHeadWorkplan() {
  return (
    <TierWorkplanPage
      view="section"
      title="Section Head Workplan"
      description="Review the departmental work cascaded to your section, plan section activities and cascade them to subsections."
      showSubsection
      showOfficer
    />
  );
}
