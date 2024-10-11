import { render, screen } from '@/test';

import { SectionStandardCard } from './SectionStandardCard';

describe('Component: SectionStandardCard', () => {
  it('renders without crashing', () => {
    // ARRANGE
    const { container } = render(
      <SectionStandardCard t_headingLabel="Heading">children</SectionStandardCard>,
    );

    // ASSERT
    expect(container).toBeTruthy();
  });

  it('renders an accessible heading element', () => {
    // ARRANGE
    render(<SectionStandardCard t_headingLabel="Hello">children</SectionStandardCard>);

    // ASSERT
    expect(screen.getByRole('heading', { name: /hello/i })).toBeVisible();
  });

  it('renders children', () => {
    // ARRANGE
    render(<SectionStandardCard t_headingLabel="Hello">children</SectionStandardCard>);

    // ASSERT
    expect(screen.getByText(/children/i)).toBeVisible();
  });
});
