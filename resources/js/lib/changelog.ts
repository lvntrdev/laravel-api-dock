import { collectOperations } from '@/lib/operations'
import type {
  ApiDockChangelogEntry,
  OpenApiDocument,
  OperationEntry,
} from '@/types/openapi'

// One `#[AiChangelog]` entry, carrying the operation it was written on. The
// per-operation history already lives in the spec; this only re-cuts it by date so
// the whole API's movement reads as one list instead of one operation at a time.
export interface ChangelogItem extends ApiDockChangelogEntry {
  operation: OperationEntry
}

export interface ChangelogRelease {
  date: string
  entries: ChangelogItem[]
}

/**
 * Newest release first, with an unparseable date sorted last rather than dropped:
 * the same rule the server applies inside a single operation, so the two views
 * never disagree about what "latest" means.
 */
export function collectChangelog(document: OpenApiDocument): ChangelogRelease[] {
  const releases = new Map<string, ChangelogItem[]>()

  for (const operation of collectOperations(document)) {
    for (const entry of operation.operation['x-api-dock-changelog'] ?? []) {
      const bucket = releases.get(entry.date)

      if (bucket) {
        bucket.push({ ...entry, operation })
        continue
      }

      releases.set(entry.date, [{ ...entry, operation }])
    }
  }

  return [...releases.entries()]
    .map(([date, entries]) => ({ date, entries }))
    .sort((left, right) => {
      const leftTime = Date.parse(left.date)
      const rightTime = Date.parse(right.date)

      if (Number.isNaN(leftTime) || Number.isNaN(rightTime)) {
        return Number.isNaN(leftTime) === Number.isNaN(rightTime)
          ? 0
          : Number.isNaN(leftTime)
            ? 1
            : -1
      }

      return rightTime - leftTime
    })
}

export function countChangelogEntries(releases: ChangelogRelease[]): number {
  return releases.reduce((total, release) => total + release.entries.length, 0)
}
