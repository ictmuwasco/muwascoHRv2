import TierWorkplanPage from './TierWorkplanPage';

/**
 * Managing Director workplan: organisation-wide activities anchored directly
 * to strategic goals / targets (Strategic Objective → Organisation Goal →
 * MD Workplan Activity). MD activities may exist without a departmental
 * performance contract and can be flagged into the organisation's integrated
 * view. Cascading downward links them to departmental commitments.
 */
export default function ManagingDirectorWorkplan() {
  return (
    <TierWorkplanPage
      view="md"
      title="Managing Director Workplan"
      description="Plan organisation-wide activities against the strategic goals and cascade them to departments."
      allowContractless
      showOfficer
      showIntegratedFlag
    />
  );
}
