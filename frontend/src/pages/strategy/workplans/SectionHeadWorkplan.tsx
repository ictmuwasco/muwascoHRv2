import TierWorkplanPage from './TierWorkplanPage';

/**
 * Section Head workplan: departmental activities cascaded to this section are
 * reviewed here, broken into section-level activities and cascaded further
 * down to subsections.
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
