import { describe, expect, it } from 'vitest'

import { collectChangelog, countChangelogEntries } from '@/lib/changelog'
import type { OpenApiDocument } from '@/types/openapi'

const document: OpenApiDocument = {
  openapi: '3.1.0',
  paths: {
    '/users': {
      get: {
        summary: 'List users',
        responses: { '200': { description: 'OK' } },
        'x-api-dock-changelog': [
          { date: '2026-02-01', summary: 'Cursor pagination added', breaking: false },
          { date: '2025-11-04', summary: 'Legacy page parameter removed', breaking: true },
        ],
      },
      post: {
        summary: 'Create user',
        responses: { '201': { description: 'Created' } },
        'x-api-dock-changelog': [
          { date: '2026-02-01', summary: 'Email is now required', breaking: true },
          { date: 'unreleased', summary: 'Draft note', breaking: false },
        ],
      },
    },
    '/health': {
      get: { summary: 'Health', responses: { '200': { description: 'OK' } } },
    },
  },
}

describe('collectChangelog', () => {
  it('groups entries from every operation by date, newest first', () => {
    const releases = collectChangelog(document)

    expect(releases.map((release) => release.date)).toEqual([
      '2026-02-01',
      '2025-11-04',
      'unreleased',
    ])
    expect(releases[0].entries.map((entry) => entry.operation.key)).toEqual([
      'get:/users',
      'post:/users',
    ])
    expect(releases[1].entries[0].breaking).toBe(true)
  })

  it('counts every entry and returns nothing for a spec without history', () => {
    expect(countChangelogEntries(collectChangelog(document))).toBe(4)
    expect(collectChangelog({ paths: { '/health': document.paths!['/health'] } })).toEqual([])
  })
})
