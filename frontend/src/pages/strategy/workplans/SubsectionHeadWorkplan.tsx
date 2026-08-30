import TierWorkplanPage from './TierWorkplanPage';

/**
 * Subsection Head workplan: section work cascaded to this subsection is broken
 * into detailed operational tasks and assigned to the employees under this
 * subsection's supervision; their progress rolls back up the hierarchy.
 */
export default function SubsectionHeadWorkplan() {
  return (
    <TierWorkplanPage
      view="subsection"
      title="Subsection Head Workplan"
      description="Break cascaded section work into employee-level tasks for your subsection and monitor completion."
      showOfficer
    />
  );
}
