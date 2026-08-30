import TierWorkplanPage from './TierWorkplanPage';

/**
 * Department Head workplan: transforms the department's performance-contract
 * commitments into operational activities (Contract → Department Activity)
 * and cascades them down to sections.
 */
export default function DepartmentHeadWorkplan() {
  return (
    <TierWorkplanPage
      view="department"
      title="Department Head Workplan"
      description="Turn your department's performance-contract commitments into activities and cascade them to sections."
      showSection
      showSubsection
      showOfficer
      showCommitmentsPanel
    />
  );
}
