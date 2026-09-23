import TierWorkplanPage from './TierWorkplanPage';

/**
 * Section Head workplan: departmental activities cascaded to this section are
 * reviewed here, broken into section-level activities and cascaded further
 * down to subsections.
 *
 * The "Add Section Workplan" flow (POST /workplans with a parent_objective_id)
 * inherits the parent's performance contract — section heads never re-pick a
 * contract. Same-level nesting under a section-level source is permitted
 * (relaxed level validation) because it models a work breakdown, not a
 * structural cascade; the per-row Cascade button pushes one level further
 * down to subsections (section → subsection, enforced as strictly-below by
 * cascadeAction).
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
