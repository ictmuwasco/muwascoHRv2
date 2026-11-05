import TierWorkplanPage from './TierWorkplanPage';

/**
 * Subsection Head workplan: section work cascaded to this subsection is broken
 * into detailed operational tasks and assigned to the employees under this
 * subsection's supervision; their progress rolls back up the hierarchy.
 *
 * No per-row cascade action: this is the last tier (work is handed to people,
 * not to smaller units) — assignment happens through the Add / Edit forms'
 * "Responsible Officer" field.
 */
export default function SubsectionHeadWorkplan() {
  return (
    <TierWorkplanPage
      view="subsection"
      title="Subsection Head Workplan"
      description="Break section work into tasks for employees in your subsection and monitor their completion."
    />
  );
}
