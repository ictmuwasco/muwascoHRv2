import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import NotificationSlideshow from '../components/NotificationSlideshow';
import { notificationService } from '../api/services/notificationService';
import api from '../utils/api';

const navigateMock = vi.hoisted(() => vi.fn());

vi.mock('react-router-dom', () => ({
  useNavigate: () => navigateMock,
}));

vi.mock('../api/services/notificationService', () => ({
  notificationService: { list: vi.fn() },
}));

vi.mock('../utils/api', () => ({
  default: { get: vi.fn() },
}));

vi.mock('../context/AuthContext', () => ({
  useAuth: () => ({ can: () => true }),
}));

/** Empty inbox page: nothing unread, so only prop-driven slides can appear. */
const emptyInbox = () => ({
  success: true,
  data: {
    notifications: [],
    unread_count: 0,
    total: 0,
    page: 1,
    per_page: 50,
    pages: 0,
    filter: 'unread',
    category: null,
    categories: [],
  },
});

describe('NotificationSlideshow', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(notificationService.list).mockResolvedValue(emptyInbox() as any);
    vi.mocked(api.get).mockResolvedValue({ data: { data: [] } } as any);
  });

  it('leads with pending leave approvals when the prop is set', async () => {
    render(<NotificationSlideshow pendingLeaveApprovals={30} />);

    expect(await screen.findByText('30')).toBeInTheDocument();
    expect(screen.getByText('Pending leave requests awaiting your approval')).toBeInTheDocument();
    // Only the active slide is mounted at a time.
    expect(screen.getByRole('button', { name: /Open details/ })).toBeInTheDocument();
  });

  it('summarises pending meeting invitations fetched from /my-meetings', async () => {
    vi.mocked(api.get).mockResolvedValue({
      data: {
        data: [
          // Future + still pending -> counted.
          { id: 1, meeting_date: '2999-01-01', invitation: { response_status: 'pending' } },
          // Already in the past -> ignored, same rule as PendingMeetingsCard.
          { id: 2, meeting_date: '2000-01-01', invitation: { response_status: 'pending' } },
          // Already answered -> ignored.
          { id: 3, meeting_date: '2999-01-01', invitation: { response_status: 'accepted' } },
        ],
      },
    } as any);

    render(<NotificationSlideshow />);

    expect(await screen.findByText('1')).toBeInTheDocument();
    expect(screen.getByText('Meeting invitation awaiting your response')).toBeInTheDocument();
  });

  it('builds unread totals plus per-category slides with one dot per slide', async () => {
    vi.mocked(notificationService.list).mockResolvedValue({
      success: true,
      data: {
        notifications: [
          { id: 1, category: 'leave' },
          { id: 2, category: 'leave' },
          { id: 3, category: 'leave' },
          { id: 4, category: 'meeting' },
        ],
        unread_count: 4,
        total: 4,
        page: 1,
        per_page: 50,
        pages: 1,
        filter: 'unread',
        category: null,
        categories: [],
      },
    } as any);

    render(<NotificationSlideshow />);

    // The unread total leads, then the two category summaries: 3 dots.
    expect(await screen.findByText('Unread notifications in your inbox')).toBeInTheDocument();
    expect(screen.getAllByLabelText(/^Go to summary/)).toHaveLength(3);

    // Dot #2 jumps to the biggest category slide.
    fireEvent.click(screen.getByLabelText(/^Go to summary 2/));
    expect(screen.getByText('Unread leave notifications')).toBeInTheDocument();
  });

  it('falls back to an all-clear slide when nothing needs attention', async () => {
    render(<NotificationSlideshow />);

    expect(await screen.findByText("You're all caught up")).toBeInTheDocument();
    expect(screen.getByText('All clear')).toBeInTheDocument();
  });

  it('navigates to the owning page when a slide is activated', async () => {
    render(<NotificationSlideshow pendingLeaveApprovals={5} />);

    const slide = await screen.findByRole('button', {
      name: 'Pending leave requests awaiting your approval. Open details.',
    });
    fireEvent.click(slide);

    expect(navigateMock).toHaveBeenCalledWith('/leave/manage/pending');
  });
});
