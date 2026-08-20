import { describe, expect, it } from 'vitest'

import { renderMarkdown } from '../lib/markdown'

describe('renderMarkdown', () => {
  it('renders CommonMark rather than printing it', () => {
    const html = renderMarkdown('A **bold** claim.\n\n- one\n- two')

    expect(html).toContain('<strong>bold</strong>')
    expect(html).toContain('<li>one</li>')
    expect(html).not.toContain('**bold**')
  })

  it('keeps the raw HTML an author folds a long prompt into', () => {
    const html = renderMarkdown(
      '<details><summary>Agent prompt</summary>\n\n```text\nGET /api/things\n```\n\n</details>',
    )

    expect(html).toContain('<details>')
    expect(html).toContain('<summary>Agent prompt</summary>')
    expect(html).toContain('<code')
  })

  it('strips script tags and their source from a hostile description', () => {
    const html = renderMarkdown('Fine.<script>alert(document.cookie)</script>')

    expect(html).not.toContain('<script')
    expect(html).not.toContain('alert(')
    expect(html).toContain('Fine.')
  })

  it('strips event handler attributes', () => {
    const html = renderMarkdown('<img src="x" onerror="alert(1)">')

    expect(html).not.toContain('onerror')
  })

  it('refuses a javascript: link but keeps an ordinary one', () => {
    expect(renderMarkdown('[go](javascript:alert(1))')).not.toContain('javascript:')
    expect(renderMarkdown('[go](https://example.com)')).toContain('href="https://example.com"')
  })

  it('returns an empty string for blank input so the caller can skip the node', () => {
    expect(renderMarkdown('')).toBe('')
    expect(renderMarkdown('   \n  ')).toBe('')
  })
})
