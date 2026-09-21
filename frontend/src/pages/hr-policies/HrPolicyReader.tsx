/**
 * HrPolicyReader - employee-facing policy reader (Phase 4-5, §19, §20, §21).
 *
 * Features:
 *  - Left navigation (chapter tree from section hierarchy)
 *  - Main content area with selected section + breadcrumbs
 *  - Full-text search with section title, excerpt, page reference
 *  - "Open Full Manual" (browser inline PDF viewer with print/download)
 *  - "Ask AI About This Policy" (§19) dispatches to the global AI widget
 *  - Bookmark / unbookmark sections (§20)
 *  - Recently viewed tracking (§21)
 *
 * All data is fetched via hrPolicyService. Unpublished/Draft/Review sections
 * are never returned to employees - the backend enforces this.
 */
import { useState, useEffect, useCallback, useRef } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { hrPolicyService } from '../../api/services/hrPolicyService';
import type {
  PolicySectionNode,
  PolicySectionDetail,
  PolicyBreadcrumb,
  PolicySearchHit,
} from '../../api/services/hrPolicyService';
import Button from '../../components/ui/Button';
import {
  Search,
  Bookmark,
  BookmarkCheck,
  FileText,
  ExternalLink,
  Loader2,
  ChevronRight,
  ChevronDown,
  HelpCircle,
  List,
  BookOpen,
} from 'lucide-react';

/**
 * Format raw section content (plain text with \n breaks) into rich HTML.
 * Detects bullet lists, numbered lists, headings and paragraphs so the
 * reader renders beautifully instead of blocks of plain text.
 */
const formatSectionContent = (content: string | null | undefined): string => {
  if (!content || !content.trim()) {
    return '<p class="text-gray-500 italic">No content available for this section.</p>';
  }

  // Split into lines and normalize
  const rawLines = String(content).replace(/\r/g, '').split('\n');
  const lines = rawLines.map((l) => l.trim());
  const blocks: string[] = [];
  let listBuffer: string[] = [];
  let listType: 'ul' | 'ol' | null = null;
  let paragraphBuffer: string[] = [];

  const flushList = () => {
    if (listBuffer.length === 0) return;
    const tag = listType === 'ol' ? 'ol' : 'ul';
    const listItems = listBuffer.map((text) => `<li>${text}</li>`).join('');
    blocks.push(`<${tag} class="policy-list">${listItems}</${tag}>`);
    listBuffer = [];
    listType = null;
  };

  const flushParagraphs = () => {
    if (paragraphBuffer.length === 0) return;
    const text = paragraphBuffer.join(' ');
    // Detect ALL-CAPS heading lines (likely sub-headings inside content)
    if (/^[A-Z][A-Z\s\d.-:]{3,}$/.test(text) && text.length < 60) {
      blocks.push(`<h3 class="policy-subheading">${esc(text)}</h3>`);
    } else {
      blocks.push(`<p>${esc(text)}</p>`);
    }
    paragraphBuffer = [];
  };

  for (const line of lines) {
    if (line === '') {
      flushList();
      flushParagraphs();
      continue;
    }

    // Numbered list item (starts with number + . or ))
    const olMatch = line.match(/^(\d+[.)])\s+(.*)$/);
    // Bullet list item
    const ulMatch = line.match(/^[-•*\u2022\u25aa\u25cf\u00b7]\s+(.*)$/);

    if (olMatch) {
      flushParagraphs();
      if (listType !== 'ol') {
        flushList();
        listType = 'ol';
      }
      listBuffer.push(`<strong>${esc(olMatch[1])}</strong> ${esc(olMatch[2])}`);
    } else if (ulMatch) {
      flushParagraphs();
      if (listType !== 'ul') {
        flushList();
        listType = 'ul';
      }
      listBuffer.push(esc(ulMatch[2]));
    } else {
      // Regular paragraph line
      flushList();
      paragraphBuffer.push(line);
    }
  }

  // Flush any remaining buffers
  flushList();
  flushParagraphs();

  return blocks.join('');
};

/** Escape HTML entities so content can never break layout. */
const esc = (str: string): string =>
  String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

const HrPolicyReader = () => {
  const { id: sectionIdParam } = useParams();
  const navigate = useNavigate();

  const [docTitle, setDocTitle] = useState<string>('MUWASCO HR Policy & Procedures Manual');
  const [docVersion, setDocVersion] = useState<string>('');
  const [documentId, setDocumentId] = useState<number | null>(null);
  const [sections, setSections] = useState<PolicySectionNode[]>([]);
  const [currentSection, setCurrentSection] = useState<PolicySectionDetail | null>(null);
  const [breadcrumbs, setBreadcrumbs] = useState<PolicyBreadcrumb[]>([]);
  const [bookmarked, setBookmarked] = useState<boolean>(false);
  const [loading, setLoading] = useState<boolean>(true);
  const [sectionLoading, setSectionLoading] = useState<boolean>(false);
  const [expandedSections, setExpandedSections] = useState<Set<number>>(new Set());

  // Search
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [searchResults, setSearchResults] = useState<PolicySearchHit[]>([]);
  const [searchLoading, setSearchLoading] = useState<boolean>(false);
  const [showSearch, setShowSearch] = useState<boolean>(false);

  // Mobile two-view layout: phones show EITHER the table of contents OR the
  // section reader (a fixed-height flex-col container cannot fit both — the
  // contents panel used to consume all the height and hide the content).
  // Desktop (md+) ignores this state and shows both panels side-by-side.
  const [mobileView, setMobileView] = useState<'contents' | 'section'>('contents');
  const contentScrollRef = useRef<HTMLDivElement | null>(null);

  // Sync mobile view with the URL: opening a section shows the reader,
  // going back to /hr/policies shows the table of contents.
  useEffect(() => {
    setMobileView(sectionIdParam ? 'section' : 'contents');
  }, [sectionIdParam]);

  // When a new section loads on mobile, scroll the reader back to the top.
  useEffect(() => {
    contentScrollRef.current?.scrollTo({ top: 0 });
  }, [sectionIdParam]);

  // Fetch current policy metadata
  useEffect(() => {
    const loadCurrentPolicy = async () => {
      try {
        const res = await hrPolicyService.getCurrent();
        if (res?.policy) {
          setDocTitle(res.policy.title);
          setDocVersion(res.policy.version);
          setDocumentId(res.policy.id);
        }
      } catch (err) {
        console.error('Failed to fetch current policy:', err);
      }
    };
    loadCurrentPolicy();
  }, []);

  // Fetch the section tree when documentId is known
  useEffect(() => {
    if (!documentId) return;
    const loadSections = async () => {
      setLoading(true);
      try {
        const tree = await hrPolicyService.getSections(documentId);
        setSections(tree);
      } catch (err) {
        console.error('Failed to fetch sections:', err);
      } finally {
        setLoading(false);
      }
    };
    loadSections();
  }, [documentId]);

  // Load section content when URL has a section id
  useEffect(() => {
    if (!sectionIdParam) {
      setCurrentSection(null);
      setBreadcrumbs([]);
      setBookmarked(false);
      return;
    }
    const sectionId = parseInt(sectionIdParam, 10);
    if (isNaN(sectionId)) return;

    const loadSection = async () => {
      setSectionLoading(true);
      try {
        const res = await hrPolicyService.getSection(sectionId);
        setCurrentSection(res.section);
        setBreadcrumbs(res.breadcrumbs || []);
        setBookmarked(res.bookmarked || false);
      } catch (err) {
        console.error('Failed to fetch section:', err);
      } finally {
        setSectionLoading(false);
      }
    };
    loadSection();
  }, [sectionIdParam]);

  // Search with debounce
  const handleSearch = useCallback(async (query: string) => {
    setSearchQuery(query);
    if (!query.trim()) {
      setSearchResults([]);
      return;
    }
    setSearchLoading(true);
    try {
      const results = await hrPolicyService.search(query);
      setSearchResults(results);
    } catch (err) {
      console.error('Search failed:', err);
    } finally {
      setSearchLoading(false);
    }
  }, []);

  // Bookmark toggle
  const toggleBookmark = async () => {
    if (!currentSection) return;
    try {
      if (bookmarked) {
        await hrPolicyService.removeBookmark(currentSection.id);
        setBookmarked(false);
      } else {
        await hrPolicyService.addBookmark(currentSection.id);
        setBookmarked(true);
      }
    } catch (err) {
      console.error('Bookmark failed:', err);
    }
  };

  const toggleSectionExpand = (sectionId: number) => {
    setExpandedSections((prev) => {
      const next = new Set(prev);
      if (next.has(sectionId)) next.delete(sectionId);
      else next.add(sectionId);
      return next;
    });
  };

  const renderSectionTree = (_nodes: PolicySectionNode[]) => {
    const children = (nodeId: number | null) =>
      sections.filter((s) => s.parent_id === nodeId).sort((a, b) => a.sort_order - b.sort_order);

    const roots = sections
      .filter((s) => s.parent_id === null)
      .sort((a, b) => a.sort_order - b.sort_order);

    const renderNodes = (nodes: PolicySectionNode[], d: number) =>
      nodes.map((node) => {
        const hasChildren = sections.some((s) => s.parent_id === node.id);
        const isExpanded = expandedSections.has(node.id);
        const isActive = currentSection?.id === node.id;
        const paddingLeft = d * 12 + 8;

        return (
          <div key={node.id}>
            <div
              className={`flex items-center py-1.5 cursor-pointer transition-colors ${
                isActive
                  ? 'bg-primary-50 dark:bg-slate-700/40 text-primary-700 dark:text-primary-300 font-medium'
                  : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-slate-700'
              }`}
              style={{ paddingLeft }}
              onClick={() => navigate(`/hr/policies/sections/${node.id}`)}
            >
              {hasChildren && (
                <span
                  className="mr-1"
                  onClick={(e) => {
                    e.stopPropagation();
                    toggleSectionExpand(node.id);
                  }}
                >
                  {isExpanded ? (
                    <ChevronDown className="h-4 w-4" />
                  ) : (
                    <ChevronRight className="h-4 w-4" />
                  )}
                </span>
              )}
              {!hasChildren && <span className="w-4 mr-1" />}
              <span className="text-xs truncate">
                {node.section_number && `${node.section_number} `}
                {node.title}
                {node.page_start && ` — p.${node.page_start}`}
              </span>
            </div>
            {hasChildren && isExpanded && renderNodes(children(node.id), d + 1)}
          </div>
        );
      });

    return renderNodes(roots, 0);
  };

  // Ask AI about current section (§19)
  const askAiAboutSection = () => {
    if (!currentSection) return;
    const question = `What does section ${currentSection.section_number || ''} — "${currentSection.title}" mean?`;
    window.dispatchEvent(
      new CustomEvent('muwasco:ask-ai', {
        detail: { question, section: currentSection.section_number },
      }),
    );
  };

  // Open full manual (browser inline viewer)
  const openFullManual = () => {
    if (documentId) {
      window.open(hrPolicyService.fileUrl(documentId, false), '_blank');
    }
  };

  if (loading && !currentSection) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <Loader2 className="h-8 w-8 animate-spin text-primary-600" />
      </div>
    );
  }

  return (
    <div className="flex h-[calc(100vh-120px)] flex-col md:flex-row gap-4">
      {/* Mobile view toggle (visible below md) */}
      <div className="md:hidden flex items-center gap-2 flex-shrink-0">
        <button
          onClick={() => setMobileView('contents')}
          className={`flex-1 flex items-center justify-center gap-2 py-2.5 rounded-lg text-sm font-medium transition-colors ${
            mobileView === 'contents'
              ? 'bg-primary-600 text-white shadow'
              : 'bg-white dark:bg-slate-800 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-slate-700'
          }`}
        >
          <List className="h-4 w-4" />
          Contents
        </button>
        <button
          onClick={() => setMobileView('section')}
          className={`flex-1 flex items-center justify-center gap-2 py-2.5 rounded-lg text-sm font-medium transition-colors ${
            mobileView === 'section'
              ? 'bg-primary-600 text-white shadow'
              : 'bg-white dark:bg-slate-800 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-slate-700'
          }`}
        >
          <BookOpen className="h-4 w-4" />
          Read
        </button>
      </div>

      {/* Left navigation: section tree (hidden on mobile when reading) */}
      <div
        className={`${mobileView === 'contents' ? 'flex' : 'hidden'} md:flex w-full md:w-64 lg:w-72 flex-1 md:flex-none min-h-0 flex-col overflow-hidden border-r border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 rounded-lg`}
      >
        <div className="p-4 border-b dark:border-slate-700 flex-shrink-0">
          <h2 className="font-semibold text-gray-900 dark:text-gray-100">Table of Contents</h2>
          <p className="text-xs text-gray-500 dark:text-gray-400">{docTitle}</p>
          {docVersion && (
            <p className="text-xs text-gray-400 dark:text-gray-500">Version {docVersion}</p>
          )}
        </div>
        <div className="flex-1 overflow-y-auto min-h-0">
          {sections.length > 0 ? (
            renderSectionTree(sections)
          ) : (
            <p className="p-4 text-xs text-gray-400">No sections available.</p>
          )}
        </div>
      </div>

      {/* Main content (hidden on mobile when browsing contents) */}
      <div
        ref={contentScrollRef}
        className={`${mobileView === 'section' ? 'block' : 'hidden'} md:block flex-1 min-h-0 min-w-0 overflow-y-auto`}
      >
        {!currentSection ? (
          <div className="p-6 text-center">
            <FileText className="h-12 w-12 mx-auto text-gray-300 dark:text-gray-600 mb-4" />
            <h3 className="text-lg font-medium text-gray-700 dark:text-gray-300">
              Select a section to read
            </h3>
            <p className="text-sm text-gray-500 dark:text-gray-400 mt-2">
              Use the table of contents on the left to browse the HR Policy &amp; Procedures Manual.
            </p>
            {documentId && (
              <Button onClick={openFullManual} variant="outline" className="mt-4">
                <ExternalLink className="h-4 w-4 mr-2" />
                Open Full Manual
              </Button>
            )}
          </div>
        ) : sectionLoading ? (
          <div className="p-6 flex items-center justify-center">
            <Loader2 className="h-6 w-6 animate-spin text-primary-600" />
          </div>
        ) : (
          <div className="p-6">
            {/* Breadcrumbs */}
            <nav className="flex items-center space-x-1 text-xs text-gray-500 dark:text-gray-400 mb-4">
              <span
                className="cursor-pointer hover:text-gray-700 dark:hover:text-gray-300"
                onClick={() => navigate('/hr/policies')}
              >
                Home
              </span>
              {breadcrumbs.map((bc, i) => (
                <span key={bc.id} className="flex items-center">
                  <ChevronRight className="h-3 w-3 mx-1" />
                  <span
                    className={`cursor-pointer hover:text-gray-700 dark:hover:text-gray-300 ${
                      i === breadcrumbs.length - 1
                        ? 'text-gray-700 dark:text-gray-300 font-medium'
                        : ''
                    }`}
                    onClick={() => navigate(`/hr/policies/sections/${bc.id}`)}
                  >
                    {bc.section_number && `${bc.section_number} `}
                    {bc.title}
                  </span>
                </span>
              ))}
            </nav>

            {/* Section header */}
            <div className="section-header-wrap bg-gradient-to-r from-slate-50 via-white to-slate-50 dark:from-slate-800/50 dark:via-slate-800 dark:to-slate-800/50 border border-gray-200 dark:border-slate-700 rounded-xl p-4 md:p-6 mb-6">
              <div className="flex flex-col md:flex-row md:items-start md:justify-between gap-3 md:gap-4">
                <div className="min-w-0">
                  <h1 className="text-2xl md:text-3xl font-bold text-gray-900 dark:text-gray-100 tracking-tight leading-tight">
                    {currentSection.section_number && (
                      <span className="text-primary-600 dark:text-primary-400 inline-flex items-baseline gap-2 mr-2">
                        <span className="inline-flex items-center justify-center h-9 min-w-9 px-2 rounded-lg bg-primary-50 dark:bg-primary-900/40 border border-primary-100 dark:border-primary-800 text-primary-700 dark:text-primary-300 text-sm font-semibold">
                          {currentSection.section_number}
                        </span>
                      </span>
                    )}
                    <span>{currentSection.title}</span>
                  </h1>
                  <div className="flex flex-wrap items-center gap-3 mt-3">
                    {currentSection.page_start && (
                      <span className="inline-flex items-center text-xs font-medium text-gray-500 dark:text-gray-400 bg-gray-100 dark:bg-slate-700/50 px-2.5 py-1 rounded-full">
                        <FileText className="h-3.5 w-3.5 mr-1" />
                        Page {currentSection.page_start}
                        {currentSection.page_end &&
                          currentSection.page_end !== currentSection.page_start && (
                            <span>–{currentSection.page_end}</span>
                          )}
                      </span>
                    )}
                    <span className="inline-flex items-center text-xs font-medium text-gray-500 dark:text-gray-400 bg-gray-100 dark:bg-slate-700/50 px-2.5 py-1 rounded-full">
                      <Bookmark className="h-3.5 w-3.5 mr-1" />
                      {docTitle}
                    </span>
                  </div>
                </div>
                <div className="flex flex-wrap items-center gap-2 md:flex-nowrap md:space-x-2 flex-shrink-0">
                  <Button
                    variant={bookmarked ? 'primary' : 'outline'}
                    size="sm"
                    onClick={toggleBookmark}
                    title={bookmarked ? 'Remove bookmark' : 'Bookmark this section'}
                  >
                    {bookmarked ? (
                      <BookmarkCheck className="h-4 w-4" />
                    ) : (
                      <Bookmark className="h-4 w-4" />
                    )}
                    {bookmarked ? 'Bookmarked' : 'Bookmark'}
                  </Button>
                  <Button variant="outline" size="sm" onClick={askAiAboutSection}>
                    <HelpCircle className="h-4 w-4 mr-1" />
                    <span className="hidden sm:inline">Ask AI About This Policy</span>
                    <span className="sm:hidden">Ask AI</span>
                  </Button>
                  {documentId && (
                    <Button variant="outline" size="sm" onClick={openFullManual}>
                      <ExternalLink className="h-4 w-4" />
                    </Button>
                  )}
                </div>
              </div>
            </div>

            {/* Section content */}
            <div className="policy-content-wrap">
              {/* Decorative accent bar */}
              <div className="h-1 w-16 bg-gradient-to-r from-primary-600 to-primary-400 rounded-full mb-6 dark:from-primary-500 dark:to-primary-300" />

              {/* Content body with rich formatting */}
              <div
                className="policy-content prose prose-lg dark:prose-invert max-w-none text-gray-800 dark:text-gray-200"
                dangerouslySetInnerHTML={{
                  __html:
                    formatSectionContent(currentSection.content) ||
                    '<p class="text-gray-500 italic">No content available.</p>',
                }}
              />

              {/* Back & next navigation footer */}
              <div className="mt-10 pt-6 border-t border-gray-200 dark:border-slate-700 flex items-center justify-between">
                <Button variant="outline" size="sm" onClick={() => navigate('/hr/policies')}>
                  <ChevronRight className="h-4 w-4 mr-1 rotate-180" />
                  Back to Contents
                </Button>
                <span className="text-xs text-gray-400 dark:text-gray-500">
                  MUWASCO HR Policy &amp; Procedures Manual — v{docVersion || '1.0'}
                </span>
              </div>
            </div>
          </div>
        )}
      </div>

      {/* Search drawer */}
      {showSearch && (
        <div className="fixed inset-0 z-50 bg-black/50 flex items-start justify-center pt-20">
          <div className="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-3xl mx-4 mt-4">
            <div className="p-4 border-b dark:border-slate-700 flex items-center">
              <Search className="h-5 w-5 text-gray-400 mr-2" />
              <input
                type="text"
                placeholder="Search policy (e.g. annual leave, sick leave, disciplinary procedure)..."
                className="flex-1 bg-transparent outline-none text-gray-900 dark:text-gray-100"
                value={searchQuery}
                onChange={(e) => handleSearch(e.target.value)}
                autoFocus
              />
              <Button variant="outline" size="sm" onClick={() => setShowSearch(false)}>
                Close
              </Button>
            </div>
            <div className="p-4 overflow-y-auto" style={{ maxHeight: '60vh' }}>
              {searchLoading ? (
                <div className="flex items-center justify-center py-8">
                  <Loader2 className="h-6 w-6 animate-spin text-primary-600" />
                </div>
              ) : searchResults.length > 0 ? (
                <div className="space-y-3">
                  {searchResults.map((hit) => (
                    <div
                      key={`${hit.document_id}-${hit.section_id}`}
                      className="border dark:border-slate-700 rounded-lg p-3 cursor-pointer hover:bg-gray-50 dark:hover:bg-slate-700/50"
                      onClick={() => {
                        navigate(`/hr/policies/sections/${hit.section_id}`);
                        setShowSearch(false);
                        setSearchQuery('');
                        setSearchResults([]);
                      }}
                    >
                      <div className="flex items-start justify-between">
                        <div>
                          <p className="font-medium text-gray-900 dark:text-gray-200">
                            {hit.section_number && `${hit.section_number} `}
                            {hit.title}
                          </p>
                          <p className="text-xs text-gray-500 dark:text-gray-400 mt-1 line-clamp-2">
                            {hit.excerpt}
                          </p>
                        </div>
                        <span className="text-xs text-gray-400 dark:text-gray-500">
                          p.{hit.page_start}
                        </span>
                      </div>
                      {hit.parent_title && (
                        <p className="text-xs text-gray-400 dark:text-gray-500 mt-1">
                          Chapter: {hit.parent_title}
                        </p>
                      )}
                    </div>
                  ))}
                </div>
              ) : searchQuery.trim() ? (
                <p className="text-center py-6 text-gray-500 dark:text-gray-400">
                  No results found for "{searchQuery}"
                </p>
              ) : (
                <p className="text-center py-6 text-gray-500 dark:text-gray-400">
                  Enter a search term to find policy sections.
                </p>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Floating search toggle */}
      <div className="fixed bottom-20 right-5 z-30">
        <Button
          variant="outline"
          size="sm"
          onClick={() => setShowSearch(true)}
          className="shadow-lg"
          title="Search policy manual"
        >
          <Search className="h-4 w-4 mr-2" />
          Search Policy
        </Button>
      </div>
    </div>
  );
};

export default HrPolicyReader;
